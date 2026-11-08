<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\StocktakeExport;
use App\Services\Stock\StocktakeService;
use App\Services\Stock\StocktakeUpload;
use App\Support\PackQty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class StocktakeController extends Controller
{
    public function __construct(private StocktakeService $stocktakes, private StocktakeUpload $uploads) {}

    public function index(Request $request): Response
    {
        return Inertia::render('stocktakes/Index', [
            'stocktakes' => Stocktake::with(['scope', 'creator:id,name'])->withCount('lines')->latest('id')->paginate(30)
                ->through(fn (Stocktake $s) => [
                    'id' => $s->id,
                    'number' => $s->number,
                    'scope' => $s->scope->path(),
                    'status' => $s->status,
                    'lines' => $s->lines_count,
                    'created_by' => $s->creator->name,
                    'snapshot_at' => $s->snapshot_at->toIso8601String(),
                ]),
            'scopes' => Location::where('is_active', true)->with('parent.parent')->orderBy('type')->orderBy('code')->get()
                ->map(fn (Location $l) => ['id' => $l->id, 'label' => $l->path().' ('.$l->type.')']),
            'canManage' => $request->user()->can('stocktakes.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope_location_id' => ['required', 'integer', 'exists:locations,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $stocktake = $this->stocktakes->create($request->user(), Location::findOrFail($data['scope_location_id']), $data['note'] ?? null);

        return redirect()->route('stocktakes.show', $stocktake)->with('success', "Stocktake {$stocktake->number} started.");
    }

    public function show(Request $request, Stocktake $stocktake): Response
    {
        $stocktake->load(['scope', 'lines.product:id,sku,name,base_unit', 'lines.product.units:id,product_id,name,base_qty', 'lines.location.parent', 'lines.batch']);

        return Inertia::render('stocktakes/Show', [
            'stocktake' => [
                'id' => $stocktake->id,
                'number' => $stocktake->number,
                'status' => $stocktake->status,
                'scope' => $stocktake->scope->path(),
                'note' => $stocktake->note,
                'snapshot_at' => $stocktake->snapshot_at->toIso8601String(),
                'posted_at' => $stocktake->posted_at?->toIso8601String(),
            ],
            'lines' => $stocktake->lines->sortBy([fn ($l) => $l->location->code, fn ($l) => $l->product->name])->values()
                ->map(fn (StocktakeLine $l) => [
                    'id' => $l->id,
                    'product' => $l->product->only(['id', 'sku', 'name', 'base_unit']),
                    'location' => $l->location->path(),
                    'batch' => $l->batch?->label(),
                    'snapshot_qty' => $l->snapshot_qty,
                    'counted_qty' => $l->counted_qty,
                    'pack' => $this->countPack($l->product),
                    'system_qty_at_count' => $l->system_qty_at_count,
                    'variance' => $l->posted_variance ?? $l->variance(),
                    'count_note' => $l->count_note,
                ]),
            'upload' => $this->uploadPreview($request, $stocktake),
            'bins' => Location::bins()->where('is_active', true)->with('parent.parent')->orderBy('code')->get()
                ->map(fn (Location $l) => ['id' => $l->id, 'label' => $l->path()]),
            'can' => [
                'count' => $request->user()->can('stocktakes.count'),
                'manage' => $request->user()->can('stocktakes.manage'),
            ],
        ]);
    }

    /** Results (xlsx, csv) or a blank count sheet (xlsx, pdf), in any status. Costs for managers and admins only. */
    public function export(Request $request, Stocktake $stocktake, string $kind, string $format, StocktakeExport $exports): HttpResponse
    {
        abort_unless(in_array($format, StocktakeExport::FORMATS[$kind] ?? [], true), 404);

        $file = $exports->export($stocktake, $kind, $format, $request->user()->can('stocktakes.manage'));
        // The PDF opens in the browser to print; the rest download.
        $disposition = $format === 'pdf' ? 'inline' : 'attachment';

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => "{$disposition}; filename=\"{$file['filename']}\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    public function count(Request $request, Stocktake $stocktake, StocktakeLine $line): RedirectResponse
    {
        abort_unless($line->stocktake_id === $stocktake->id, 404);
        $data = $request->validate(PackQty::rules());
        if (PackQty::isBlank($data)) {
            throw ValidationException::withMessages(['stocktake' => 'Enter the count.']);
        }
        $counted = PackQty::toBase($data, $this->countPack($line->product)['per']);

        $this->guard(fn () => $this->stocktakes->recordCount($line, $counted, $request->user()));

        return back();
    }

    public function addLine(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'exists:products,sku'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'lot_code' => ['nullable', 'string', 'max:64'],
            'best_before' => ['nullable', 'date'],
        ]);
        $product = Product::where('sku', strtoupper($data['sku']))->firstOrFail();
        $batch = ! empty($data['lot_code']) ? Batch::findOrCreateFor($product, $data['lot_code'], $data['best_before'] ?? null) : null;

        $this->guard(fn () => $this->stocktakes->addLine($stocktake, $product, Location::findOrFail($data['location_id']), $batch));

        return back()->with('success', "Line added for {$product->sku}.");
    }

    /** Reads a filled-in count sheet or results export into a preview on the stocktake's page. */
    public function upload(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120']]);
        $file = $request->file('file');

        try {
            $plan = $this->uploads->preview($stocktake, $request->user(), $file->getRealPath(), $file->getClientOriginalExtension(), $file->getClientOriginalName());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $request->session()->put($this->uploadSession($stocktake), $plan['token']);

        return back();
    }

    /** Records the previewed counts. Never posts. */
    public function applyUpload(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'uuid']]);
        $result = null;
        $this->guard(function () use ($request, $stocktake, $data, &$result) {
            $result = $this->uploads->apply($stocktake, $request->user(), $data['token']);
        });
        $request->session()->forget($this->uploadSession($stocktake));

        return back()->with('success', "Counts recorded for {$result['counted']} line(s)".($result['added'] ? ", {$result['added']} line(s) added as found" : '').'. Nothing is posted yet.');
    }

    public function discardUpload(Request $request, Stocktake $stocktake): RedirectResponse
    {
        if ($token = $request->session()->pull($this->uploadSession($stocktake))) {
            $this->uploads->discard($request->user(), $token);
        }

        return back();
    }

    public function post(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $data = $request->validate(['zero_uncounted' => ['boolean']]);

        $this->guard(fn () => $this->stocktakes->post($stocktake, $request->user(), (bool) ($data['zero_uncounted'] ?? false)));

        return back()->with('success', "Stocktake {$stocktake->number} posted.");
    }

    public function cancel(Stocktake $stocktake): RedirectResponse
    {
        $this->guard(fn () => $this->stocktakes->cancel($stocktake));

        return back()->with('success', "Stocktake {$stocktake->number} cancelled.");
    }

    private function uploadPreview(Request $request, Stocktake $stocktake): ?array
    {
        $token = $request->session()->get($this->uploadSession($stocktake));
        $plan = $token && $stocktake->isOpen() && $request->user()->can('stocktakes.manage') ? $this->uploads->get($request->user(), $token) : null;
        if (! $plan) {
            return null;
        }

        return [
            'token' => $plan['token'],
            'filename' => $plan['filename'],
            'expires_at' => now()->setTimestamp($plan['expires_at'])->toIso8601String(),
            'summary' => $this->uploads->summary($plan),
            'matched' => array_values(array_filter($plan['matched'], fn ($m) => ! $m['unchanged'])),
            'new' => $plan['new'],
            'errors' => $plan['errors'],
            'blank' => $plan['blank'],
        ];
    }

    private function uploadSession(Stocktake $stocktake): string
    {
        return "stocktake_upload.{$stocktake->id}";
    }

    /** @return array{per: int, name: string, base_unit: string} */
    private function countPack(Product $product): array
    {
        $pack = PackQty::largestPack($product);

        return PackQty::forInput($pack?->base_qty ?? 1, $pack?->name ?? $product->base_unit, $product->base_unit);
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['stocktake' => $e->getMessage()]);
        }
    }
}
