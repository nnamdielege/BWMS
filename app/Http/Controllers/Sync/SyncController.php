<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Integrations\Ordermentum\OrderInbox;
use App\Jobs\ProcessOrdermentumOrder;
use App\Jobs\PushOrdermentumStock;
use App\Jobs\PushPortalStock;
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
        $unmappedVariants = ChannelUnmappedVariant::whereNull('resolved_at')->orderByDesc('last_seen_at')->limit(100)->get();
        $suggestions = Product::whereIn('sku', $unmappedVariants->pluck('external_sku')->filter()->map(fn ($s) => strtoupper($s)))
            ->with('units:id,product_id,name,base_qty,sellable')->get(['id', 'sku', 'name'])->keyBy('sku');

        return Inertia::render('sync/Index', [
            'switches' => [
                'push_enabled' => (bool) config('bwms.ordermentum.push_enabled'),
                'ingest_enabled' => (bool) config('bwms.ordermentum.ingest_enabled'),
                'webhook_configured' => filled(config('bwms.ordermentum.webhook_token')),
                'api_key_set' => filled(config('services.ordermentum.api_key')),
                'signature_mode' => config('bwms.ordermentum.webhook_signature.mode'),
                'variant_fields' => config('bwms.ordermentum.order_mapping.variant'),
                'variant_confirmed' => in_array(config('bwms.ordermentum.order_mapping.variant')[0] ?? null, config('bwms.ordermentum.order_mapping.variant_confirmed'), true),
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
            // Ordermentum only decrements tracked items at order placement (confirmed 2026-10-27).
            'untracked' => (clone $listings)->whereIn('remote_status', ['untracked', 'missing'])->with('product:id,sku,name')->orderBy('id')->limit(200)->get()
                ->map(fn (ChannelListing $l) => $this->listingRow($l)),
            'inbox' => IntegrationInbox::where('channel', self::CHANNEL)
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->latest('id')->limit(50)->get(['id', 'event', 'external_id', 'status', 'signature_status', 'attempts', 'error', 'created_at', 'processed_at']),
            'inboxCounts' => IntegrationInbox::where('channel', self::CHANNEL)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
            'unmappedVariants' => $unmappedVariants->map(fn (ChannelUnmappedVariant $v) => [
                'id' => $v->id, 'channel' => $v->channel, 'external_variant_id' => $v->external_variant_id, 'external_sku' => $v->external_sku, 'name' => $v->name,
                'occurrences' => $v->occurrences, 'last_seen_at' => $v->last_seen_at->toIso8601String(),
                'suggestion' => $suggestions[strtoupper((string) $v->external_sku)] ?? null,
            ]),
            'unmappedProducts' => [
                // Only products meant to be sold on Ordermentum; sell channel "none"/"portal" are never unmapped here.
                'total' => Product::where('is_active', true)->whereIn('sell_channels', Product::channelsIncluding(self::CHANNEL))->whereDoesntHave('channelListings', fn ($q) => $q->where('channel', self::CHANNEL))->count(),
                'rows' => Product::where('is_active', true)->whereIn('sell_channels', Product::channelsIncluding(self::CHANNEL))->whereDoesntHave('channelListings', fn ($q) => $q->where('channel', self::CHANNEL))
                    ->orderBy('sku')->limit(50)->get(['id', 'sku', 'name']),
            ],
            'listings' => (clone $listings)->with('product:id,sku,name', 'unit:id,name,base_qty')
                ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('external_variant_id', 'like', "%{$t}%")
                    ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', "%{$t}%")->orWhere('name', 'like', "%{$t}%"))))
                ->orderBy('id')->paginate(50)->withQueryString()->through(fn (ChannelListing $l) => $this->listingRow($l)),
            'portal' => $this->portalSection(),
            'filters' => $filters,
            'canManage' => $request->user()->can('sync.manage'),
        ]);
    }

    public function push(ChannelListing $listing): RedirectResponse
    {
        $switch = $listing->channel === 'portal' ? 'portal.push_enabled' : 'ordermentum.push_enabled';
        if (! config("bwms.{$switch}")) {
            return back()->with('error', 'Stock push is switched off for this channel.');
        }
        $this->requestPush($listing->channel, [$listing->product_id]);
        Audit::record('sync_push_requested', $listing);

        return back()->with('success', 'Push queued.');
    }

    public function retryFailed(): RedirectResponse
    {
        $ids = collect();
        foreach (['ordermentum', 'portal'] as $channel) {
            $channelIds = ChannelListing::where('channel', $channel)->whereNotNull('last_error')->distinct()->pluck('product_id');
            $this->requestPush($channel, $channelIds);
            $ids = $ids->merge($channelIds);
        }
        Audit::record('sync_retry_all', meta: ['products' => $ids->count()]);

        return back()->with('success', "{$ids->count()} push(es) queued.");
    }

    public function toggle(Request $request, ChannelListing $listing): RedirectResponse
    {
        $listing->update($request->validate(['sync_enabled' => ['required', 'boolean'], 'safety_buffer' => ['sometimes', 'integer', 'min:0', 'max:100000']]));
        if ($listing->sync_enabled) {
            $this->requestPush($listing->channel, [$listing->product_id]);
        }

        return back()->with('success', 'Listing saved.');
    }

    public function enableAll(Request $request): RedirectResponse
    {
        $channel = $request->validate(['channel' => ['nullable', 'in:ordermentum,portal']])['channel'] ?? self::CHANNEL;
        $productIds = [];
        ChannelListing::where('channel', $channel)->where('sync_enabled', false)->each(function (ChannelListing $l) use (&$productIds) {
            $l->update(['sync_enabled' => true]); // audited per listing
            $productIds[] = $l->product_id;
        });
        // Queues a forced push of everything just enabled (a no-op while the channel's push switch is off).
        $this->requestPush($channel, $productIds);

        return back()->with('success', count($productIds).' listing(s) enabled.'.(config("bwms.{$channel}.push_enabled") ? ' A full push is queued.' : ' Push is switched off for this channel.'));
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
        if (ChannelListing::where('channel', $variant->channel)->where('product_id', $product->id)->where('product_unit_id', $unit->id)->exists()) {
            throw ValidationException::withMessages(['product_unit_id' => "{$product->sku} ({$unit->name}) is already mapped to another {$variant->channel} item."]);
        }

        DB::transaction(function () use ($variant, $product, $unit, $data) {
            ChannelListing::create([
                'channel' => $variant->channel, 'product_id' => $product->id, 'product_unit_id' => $unit->id,
                'external_variant_id' => $variant->external_variant_id, 'external_sku' => $variant->external_sku,
                // Portal listings are keyed by the portal product ID; the portal retries its own refused calls.
                'external_product_id' => $variant->channel === 'portal' ? $variant->external_variant_id : null,
                'external_location_id' => $variant->channel === 'ordermentum' ? config('services.ordermentum.location_id') : null,
                'sync_enabled' => (bool) ($data['sync_enabled'] ?? false),
            ]);
            $variant->forceFill(['resolved_at' => now()])->save();
            $product->addSellChannel($variant->channel);
        });

        // Ordermentum orders that waited for this variant run again (the portal retries its own calls).
        if ($variant->channel === self::CHANNEL) {
            IntegrationInbox::where('channel', self::CHANNEL)->where('status', IntegrationInbox::STATUS_NEEDS_MAPPING)
                ->where('error', 'like', '%'.$variant->external_variant_id.'%')->get()->each(fn ($i) => $this->requeue($i));
        }

        return back()->with('success', "Mapped to {$product->sku}.");
    }

    private function requestPush(string $channel, iterable $productIds): void
    {
        $channel === 'portal'
            ? PushPortalStock::request($productIds, force: true)
            : PushOrdermentumStock::request($productIds, force: true);
    }

    /** @return array<string, mixed> */
    private function portalSection(): array
    {
        $listings = ChannelListing::where('channel', 'portal');

        return [
            'api_enabled' => (bool) config('bwms.portal.api_enabled'),
            'inbound_keys' => count(config('bwms.portal.inbound_keys')),
            'push_enabled' => (bool) config('bwms.portal.push_enabled'),
            'events_enabled' => (bool) config('bwms.portal.events_enabled'),
            'outbound_configured' => filled(config('bwms.portal.base_url')) && filled(config('bwms.portal.outbound_secret')),
            'listings' => (clone $listings)->count(),
            'listings_enabled' => (clone $listings)->where('sync_enabled', true)->count(),
            'last_push_ok' => SyncAttempt::where('channel', 'portal')->where('operation', 'push')->where('success', true)->max('created_at'),
            'failures_24h' => SyncAttempt::where('channel', 'portal')->where('success', false)->where('created_at', '>', now()->subDay())->count(),
            'push_failures' => (clone $listings)->whereNotNull('last_error')->with('product:id,sku,name')->limit(50)->get()->map(fn (ChannelListing $l) => $this->listingRow($l)),
            // B4.3: reservations refused for stock (the portal may already have accepted the order offline) or invalid.
            'refused' => IntegrationInbox::where('channel', 'portal')->whereIn('status', ['rejected', IntegrationInbox::STATUS_FAILED])
                ->latest('id')->limit(50)->get(['id', 'event', 'external_id', 'status', 'error', 'created_at']),
        ];
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
            'remote_status' => $l->remote_status,
            'pending' => $l->push_requested_at !== null,
        ];
    }
}
