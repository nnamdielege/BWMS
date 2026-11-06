<?php

namespace App\Http\Controllers;

use App\Jobs\FetchOrdermentumCatalogue;
use App\Models\Range;
use App\Services\Catalogue\Import\ImportFileReader;
use App\Services\Catalogue\Import\ImportPreviewStore;
use App\Services\Catalogue\Import\ProductImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Catalogue → Import products: fetch or upload, preview with edits, apply once. */
class ProductImportController extends Controller
{
    private const SESSION = 'product_import_token';

    public function __construct(private ImportPreviewStore $previews, private ProductImportService $imports) {}

    public function index(Request $request): Response
    {
        $token = $request->session()->get(self::SESSION);
        $preview = $token ? $this->previews->get($request->user()->id, $token) : null;

        return Inertia::render('products/Import', [
            'preview' => $preview ? [
                'token' => $token,
                'source' => $preview['source'],
                'status' => $preview['status'],
                'error' => $preview['error'] ?? null,
                'rows' => $preview['rows'] ?? [],
                'summary' => $preview['summary'] ?? null,
                'edits' => (object) ($preview['edits'] ?? []),
                'expires_at' => $preview['expires_at'],
            ] : null,
            'ranges' => Range::orderBy('name')->pluck('name'),
        ]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ImportFileReader::COLUMNS);
            fclose($out);
        }, 'product-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /** Reads Ordermentum in a job; the page polls until the preview is there. */
    public function fetch(Request $request): RedirectResponse
    {
        $token = (string) Str::uuid();
        $this->previews->fetching($request->user()->id, $token, 'ordermentum');
        $request->session()->put(self::SESSION, $token);
        FetchOrdermentumCatalogue::dispatch($request->user()->id, $token);

        return back();
    }

    public function upload(Request $request, ImportFileReader $reader): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120']]);
        $file = $request->file('file');

        try {
            $items = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $token = (string) Str::uuid();
        $this->previews->ready($request->user()->id, $token, 'file', $items);
        $request->session()->put(self::SESSION, $token);

        return back();
    }

    /** Re-check: plans again with the edits, keeping the original expiry. */
    public function revise(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $preview = $this->previews->get($request->user()->id, $data['token']);
        if (! $this->usable($preview)) {
            return back()->with('error', 'That preview has expired. Start the import again.');
        }
        $this->previews->ready($request->user()->id, $data['token'], $preview['source'], $preview['items'], $data['edits'] ?? [], $preview['expires_at']);

        return back();
    }

    /** The preview is taken out first, so a double submit changes nothing. */
    public function apply(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()->id;
        $preview = $this->previews->pull($userId, $data['token']);
        if (! $this->usable($preview)) {
            return back()->with('error', 'That preview has expired or was already applied. Start the import again.');
        }

        try {
            $result = $this->imports->apply($preview['items'], $data['edits'] ?? [], $request->user(), $preview['source']);
        } catch (ValidationException $e) {
            $this->previews->restore($userId, $data['token'], $preview);
            throw $e;
        }
        $request->session()->forget(self::SESSION);

        $message = "Created {$result['created']}, updated {$result['updated']}, mapped {$result['mapped']}, {$result['skipped']} left alone.";
        if ($result['replayed'] > 0) {
            $message .= " {$result['replayed']} held Ordermentum order event(s) queued again.";
        }

        return $result['failed']
            ? back()->with('error', "{$message} {$result['failed']} Import again for the rest.")
            : back()->with('success', $message);
    }

    /** @return array{token: string, edits?: array<string, array<string, mixed>>} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'token' => ['required', 'uuid'],
            'edits' => ['nullable', 'array', 'max:'.ImportFileReader::MAX_ROWS],
            'edits.*' => ['array'],
            'edits.*.included' => ['sometimes', 'boolean'],
            'edits.*.overwrite' => ['sometimes', 'boolean'],
            'edits.*.pack_name' => ['nullable', 'string', 'max:64'],
            'edits.*.pack_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'edits.*.base_unit' => ['nullable', 'string', 'max:32'],
            'edits.*.range' => ['nullable', 'string', 'max:255'],
            'edits.*.batch_tracked' => ['sometimes', 'boolean'],
            'edits.*.sell_ordermentum' => ['sometimes', 'boolean'],
            'edits.*.sell_portal' => ['sometimes', 'boolean'],
        ]);
    }

    private function usable(?array $preview): bool
    {
        return ($preview['status'] ?? null) === 'ready';
    }
}
