<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Catalogue\PackSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Pre-stocktake pack setup (D-1): CSV template, preview, apply once. */
class ProductPackController extends Controller
{
    public function __construct(private PackSetupService $packs) {}

    public function index(Request $request): Response
    {
        return Inertia::render('products/Packs', [
            'defaultCount' => PackSetupService::defaultPackProducts()->count(),
            'defaultProducts' => PackSetupService::defaultPackProducts()->orderBy('sku')->limit(300)->get(['id', 'sku', 'name']),
            'preview' => $request->session()->get('pack_preview'),
            'canEdit' => $request->user()->can('products.manage'),
        ]);
    }

    /** One row per pack for every product; edit and upload it back. */
    public function export(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['sku', 'base_unit', 'pack_name', 'qty_per_pack', 'legacy_unit', 'name']);
            Product::with('units')->orderBy('sku')->chunk(500, function ($products) use ($out) {
                foreach ($products as $p) {
                    $single = $p->units->count() === 1;
                    foreach ($p->units->sortBy('base_qty') as $u) {
                        /** @var ProductUnit $u */
                        fputcsv($out, [$p->sku, $p->base_unit, $u->name, $u->base_qty, $single ? 'yes' : '', $p->name]);
                    }
                }
            });
            fclose($out);
        }, 'product-packs-'.now(config('bwms.business_timezone'))->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function preview(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $result = $this->packs->preview($request->file('file')->getRealPath());
        $token = (string) Str::uuid();
        Cache::put("pack-setup:{$request->user()->id}:{$token}", $result['plans'], now()->addMinutes(30));

        return back()->with('pack_preview', ['token' => $token, 'plans' => $result['plans'], 'errors' => $result['errors']]);
    }

    /** The token is consumed, so a double submit changes nothing. */
    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'uuid']]);
        $plans = Cache::pull("pack-setup:{$request->user()->id}:{$data['token']}");
        if ($plans === null) {
            return back()->with('error', 'That preview has expired or was already applied. Upload the file again.');
        }
        $done = $this->packs->apply($plans, $request->user());

        return back()->with('success', "Packs set up for {$done} product(s).");
    }
}
