<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Integrations\Ordermentum\OrderInbox;
use App\Jobs\ProcessOrdermentumOrder;
use App\Jobs\PushChannelStock;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\IntegrationInbox;
use App\Models\Product;
use App\Models\SyncAttempt;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Sync status screen (spec B7). Actions only queue jobs; no API call happens in a request (NFR-2). */
class SyncController extends Controller
{
    private const CHANNEL = 'ordermentum';

    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', 'string', 'max:16'], 'q' => ['nullable', 'string', 'max:100']]);
        $listings = ChannelListing::where('channel', self::CHANNEL);
        $unmappedVariants = ChannelUnmappedVariant::where('channel', self::CHANNEL)->whereNull('resolved_at')->orderByDesc('last_seen_at')->limit(100)->get();
        $suggestions = Product::whereIn('sku', $unmappedVariants->pluck('external_sku')->filter()->map(fn ($s) => strtoupper($s)))
            ->with('units:id,product_id,name,base_qty,sellable')->get(['id', 'sku', 'name'])->keyBy('sku');

        return Inertia::render('sync/Index', [
            'switches' => [
                'push_enabled' => (bool) config('bwms.ordermentum.push_enabled'),
                'ingest_enabled' => (bool) config('bwms.ordermentum.ingest_enabled'),
                'webhook_configured' => filled(config('bwms.ordermentum.webhook_token')),
                'auth_mode' => filled(config('services.ordermentum.api_key')) ? 'api_key' : 'password (deprecated 1 Jan 2027)',
                'variant_fields' => config('bwms.ordermentum.order_mapping.variant'),
            ],
            'health' => [
                'last_push_ok' => SyncAttempt::where('channel', self::CHANNEL)->where('operation', 'push')->where('success', true)->max('created_at'),
                'last_order_ok' => IntegrationInbox::where('channel', self::CHANNEL)->where('status', IntegrationInbox::STATUS_PROCESSED)->max('processed_at'),
                'failures_24h' => SyncAttempt::where('channel', self::CHANNEL)->where('success', false)->where('created_at', '>', now()->subDay())->count(),
                'queue_depth' => Queue::size(),
                'listings' => (clone $listings)->count(),
                'listings_enabled' => (clone $listings)->where('sync_enabled', true)->count(),
            ],
            'failures' => (clone $listings)->whereNotNull('last_error')->with('product:id,sku,name')->orderByDesc('updated_at')->limit(50)->get()
                ->map(fn (ChannelListing $l) => $this->listingRow($l)),
            'mismatches' => (clone $listings)->where('sync_enabled', true)->whereNotNull('last_checked_at')
                ->whereColumn('last_remote_qty', '!=', 'last_pushed_qty')->with('product:id,sku,name')->limit(50)->get()
                ->map(fn (ChannelListing $l) => $this->listingRow($l)),
            'inbox' => IntegrationInbox::where('channel', self::CHANNEL)
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->latest('id')->limit(50)->get(['id', 'event', 'external_id', 'status', 'attempts', 'error', 'created_at', 'processed_at']),
            'inboxCounts' => IntegrationInbox::where('channel', self::CHANNEL)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
            'unmappedVariants' => $unmappedVariants->map(fn (ChannelUnmappedVariant $v) => [
                'id' => $v->id, 'external_variant_id' => $v->external_variant_id, 'external_sku' => $v->external_sku, 'name' => $v->name,
                'occurrences' => $v->occurrences, 'last_seen_at' => $v->last_seen_at->toIso8601String(),
                'suggestion' => $suggestions[strtoupper((string) $v->external_sku)] ?? null,
            ]),
            'unmappedProducts' => [
                'total' => Product::where('is_active', true)->whereDoesntHave('channelListings', fn ($q) => $q->where('channel', self::CHANNEL))->count(),
                'rows' => Product::where('is_active', true)->whereDoesntHave('channelListings', fn ($q) => $q->where('channel', self::CHANNEL))
                    ->orderBy('sku')->limit(50)->get(['id', 'sku', 'name']),
            ],
            'listings' => (clone $listings)->with('product:id,sku,name', 'unit:id,name,base_qty')
                ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('external_variant_id', 'like', "%{$t}%")
                    ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', "%{$t}%")->orWhere('name', 'like', "%{$t}%"))))
                ->orderBy('id')->paginate(50)->withQueryString()->through(fn (ChannelListing $l) => $this->listingRow($l)),
            'filters' => $filters,
            'canManage' => $request->user()->can('sync.manage'),
        ]);
    }

    public function push(ChannelListing $listing): RedirectResponse
    {
        PushChannelStock::dispatch($listing->product_id, true);
        Audit::record('sync_push_requested', $listing);

        return back()->with('success', 'Push queued.');
    }

    public function retryFailed(): RedirectResponse
    {
        $ids = ChannelListing::where('channel', self::CHANNEL)->whereNotNull('last_error')->distinct()->pluck('product_id');
        $ids->each(fn ($id) => PushChannelStock::dispatch($id, true));
        Audit::record('sync_retry_all', meta: ['products' => $ids->count()]);

        return back()->with('success', "{$ids->count()} push(es) queued.");
    }

    public function toggle(Request $request, ChannelListing $listing): RedirectResponse
    {
        $listing->update($request->validate(['sync_enabled' => ['required', 'boolean'], 'safety_buffer' => ['sometimes', 'integer', 'min:0', 'max:100000']]));
        if ($listing->sync_enabled) {
            PushChannelStock::dispatch($listing->product_id, true);
        }

        return back()->with('success', 'Listing saved.');
    }

    public function enableAll(): RedirectResponse
    {
        $count = 0;
        ChannelListing::where('channel', self::CHANNEL)->where('sync_enabled', false)->each(function (ChannelListing $l) use (&$count) {
            $l->update(['sync_enabled' => true]); // audited per listing
            $count++;
        });

        return back()->with('success', "{$count} listing(s) enabled. Run a full push when the push switch is on.");
    }

    public function replay(IntegrationInbox $inbox): RedirectResponse
    {
        if (! in_array($inbox->event, OrderInbox::ORDER_EVENTS, true) || ! $inbox->external_id) {
            throw ValidationException::withMessages(['inbox' => 'Only order events can be replayed.']);
        }
        $this->requeue($inbox);
        Audit::record('sync_inbox_replayed', meta: ['inbox_id' => $inbox->id, 'order' => $inbox->external_id]);

        return back()->with('success', 'Replay queued.');
    }

    public function map(Request $request, ChannelUnmappedVariant $variant): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_unit_id' => ['required', 'integer'],
            'sync_enabled' => ['boolean'],
        ]);
        $product = Product::findOrFail($data['product_id']);
        $unit = $product->units()->whereKey($data['product_unit_id'])->first();
        if (! $unit || ! $unit->sellable) {
            throw ValidationException::withMessages(['product_unit_id' => 'Choose a sellable pack size of this product.']);
        }
        if (ChannelListing::where('channel', self::CHANNEL)->where('product_id', $product->id)->where('product_unit_id', $unit->id)->exists()) {
            throw ValidationException::withMessages(['product_unit_id' => "{$product->sku} ({$unit->name}) is already mapped to another Ordermentum variant."]);
        }

        DB::transaction(function () use ($variant, $product, $unit, $data) {
            ChannelListing::create([
                'channel' => self::CHANNEL, 'product_id' => $product->id, 'product_unit_id' => $unit->id,
                'external_variant_id' => $variant->external_variant_id, 'external_sku' => $variant->external_sku,
                'external_location_id' => config('services.ordermentum.location_id'), 'sync_enabled' => (bool) ($data['sync_enabled'] ?? false),
            ]);
            $variant->forceFill(['resolved_at' => now()])->save();
        });

        // Orders that waited for this variant run again.
        IntegrationInbox::where('channel', self::CHANNEL)->where('status', IntegrationInbox::STATUS_NEEDS_MAPPING)
            ->where('error', 'like', '%'.$variant->external_variant_id.'%')->get()->each(fn ($i) => $this->requeue($i));

        return back()->with('success', "Mapped to {$product->sku}.");
    }

    private function requeue(IntegrationInbox $inbox): void
    {
        $enabled = (bool) config('bwms.ordermentum.ingest_enabled');
        $inbox->mark($enabled ? IntegrationInbox::STATUS_RECEIVED : IntegrationInbox::STATUS_HELD, $enabled ? null : 'Ingestion is switched off.');
        if ($enabled) {
            ProcessOrdermentumOrder::dispatch($inbox->external_id, $inbox->id);
        }
    }

    /** @return array<string, mixed> */
    private function listingRow(ChannelListing $l): array
    {
        return [
            'id' => $l->id,
            'product' => $l->product?->only(['id', 'sku', 'name']),
            'unit' => $l->relationLoaded('unit') ? $l->unit?->only(['name', 'base_qty']) : null,
            'external_variant_id' => $l->external_variant_id,
            'sync_enabled' => $l->sync_enabled,
            'safety_buffer' => $l->safety_buffer,
            'last_pushed_qty' => $l->last_pushed_qty,
            'last_pushed_at' => $l->last_pushed_at?->toIso8601String(),
            'last_response_code' => $l->last_response_code,
            'last_error' => $l->last_error,
            'last_remote_qty' => $l->last_remote_qty,
            'last_checked_at' => $l->last_checked_at?->toIso8601String(),
        ];
    }
}
