<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Integrations\Portal\IdempotencyStore;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\Customer;
use App\Models\IntegrationInbox;
use App\Models\Location;
use App\Models\Order;
use App\Services\Orders\AvailabilityService;
use App\Services\Orders\ChannelOrderData;
use App\Services\Orders\Exceptions\InsufficientAvailability;
use App\Services\Orders\OrderService;
use App\Services\Stock\Exceptions\InvalidMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Portal -> BWMS API v1 (spec B4.3-B4.4). Requests are HMAC-verified by the
 * portal.signature middleware. Every mutating call is idempotent and stored in
 * integration_inbox. Portal orders are all-or-nothing reservations (D-3): a
 * shortfall answers 409 and reserves nothing. Quantities on the wire are in
 * the portal's packs (the mapped listing's pack), never base units.
 */
class PortalApiController extends Controller
{
    private const CHANNEL = 'portal';

    public function __construct(private OrderService $orders, private IdempotencyStore $idempotency, private AvailabilityService $availability) {}

    /** POST /reservations: synchronous reservation at order placement (portal timeout 3s). */
    public function reserve(Request $request): JsonResponse
    {
        return $this->idempotency->remember($request, self::CHANNEL, function () use ($request) {
            $data = $this->validateOrder($request, withVersion: false);
            if ($data instanceof JsonResponse) {
                return $data;
            }

            return $this->apply($request, 'reservation', $data, version: 0, status: 'confirmed', created: 201);
        });
    }

    /** PUT /orders/{ref}: full-state upsert after payment, edit or cancellation. Stale versions change nothing. */
    public function upsert(Request $request, string $orderRef): JsonResponse
    {
        return $this->idempotency->remember($request, self::CHANNEL, function () use ($request, $orderRef) {
            $request->merge(['order_ref' => $orderRef]);
            $data = $this->validateOrder($request, withVersion: true);
            if ($data instanceof JsonResponse) {
                return $data;
            }

            return $this->apply($request, 'order_update', $data, version: (int) $data['version'], status: $data['status'], created: 200);
        });
    }

    /** GET /orders/{ref}: reconciliation and support. */
    public function show(string $orderRef): JsonResponse
    {
        $order = $this->find($orderRef);

        return $order ? response()->json($this->orderBody($order, withDispatched: true)) : response()->json(['status' => 'unknown_order'], 404);
    }

    /** GET /stock?product_ids=1,2: available packs per portal product (never negative; D-3). */
    public function stock(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('product_ids')))->map(fn ($v) => trim($v))->filter(fn ($v) => ctype_digit($v))->take(500);
        $site = $this->site();

        return response()->json(ChannelListing::where('channel', self::CHANNEL)->whereIn('external_product_id', $ids)->with(['product', 'unit'])->get()
            ->map(fn (ChannelListing $l) => [
                'portal_product_id' => (int) $l->external_product_id,
                'available' => intdiv(max(0, $this->availability->available($l->product, $site)), max(1, $l->unit->base_qty)),
            ])->values());
    }

    /** @return array<string, mixed>|JsonResponse */
    private function validateOrder(Request $request, bool $withVersion): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_ref' => ['required', 'string', 'max:128'],
            'version' => [$withVersion ? 'required' : 'prohibited', 'integer', 'min:1'],
            'status' => [$withVersion ? 'required' : 'prohibited', 'in:confirmed,cancelled'],
            'customer.portal_user_id' => ['nullable', 'integer', 'min:1'],
            'customer.business_name' => ['nullable', 'string', 'max:255'],
            'requested_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'delivery.street' => ['nullable', 'string', 'max:255'],
            'delivery.suburb' => ['nullable', 'string', 'max:100'],
            'delivery.postcode' => ['nullable', 'regex:/^\d{4}$/'],
            'delivery.instructions' => ['nullable', 'string', 'max:1000'],
            'lines' => [$withVersion ? 'present' : 'required', 'array', 'max:500'],
            'lines.*.line_ref' => ['required', 'string', 'max:64', 'distinct'],
            'lines.*.portal_product_id' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'lines.*.unit_quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.unit' => ['nullable', 'string', 'max:64'],
        ]);

        return $validator->fails()
            ? response()->json(['status' => 'invalid', 'errors' => $validator->errors()], 422)
            : $validator->validated();
    }

    private function apply(Request $request, string $event, array $data, int $version, string $status, int $created): JsonResponse
    {
        $lines = collect($data['lines']);
        $listings = ChannelListing::where('channel', self::CHANNEL)->whereIn('external_product_id', $lines->pluck('portal_product_id')->map(fn ($id) => (string) $id))
            ->with(['unit', 'product:id,base_unit'])->get()->keyBy('external_product_id');

        $unknown = $lines->reject(fn ($l) => isset($listings[(string) $l['portal_product_id']]));
        if ($unknown->isNotEmpty()) {
            $unknown->each(fn ($l) => $this->rememberUnmapped((string) $l['portal_product_id']));
            $body = ['status' => 'unknown_product', 'lines' => $unknown->map(fn ($l) => ['line_ref' => $l['line_ref'], 'portal_product_id' => $l['portal_product_id']])->values()];

            return $this->respond($request, $event, $data['order_ref'], IntegrationInbox::STATUS_FAILED, 'Unknown portal product(s); map them on the sync screen.', $body, 422);
        }

        $warnings = $lines->map(fn ($l) => $this->unitWarning($l, $listings[(string) $l['portal_product_id']]))->filter()->values()->all();

        $customer = isset($data['customer']['portal_user_id'])
            ? Customer::firstOrCreate(['portal_user_id' => $data['customer']['portal_user_id']], [
                'code' => 'P-'.$data['customer']['portal_user_id'],
                'name' => $data['customer']['business_name'] ?? 'Portal customer '.$data['customer']['portal_user_id'],
                'is_active' => true,
            ])
            : null;

        try {
            $order = $this->orders->upsertFromChannel(new ChannelOrderData(
                channel: OrderChannel::Portal,
                externalRef: $data['order_ref'],
                version: $version,
                status: $status,
                siteId: $this->site()->id,
                lines: $lines->map(fn ($l) => [
                    'line_ref' => $l['line_ref'],
                    'product_id' => $listings[(string) $l['portal_product_id']]->product_id,
                    'product_unit_id' => $listings[(string) $l['portal_product_id']]->product_unit_id,
                    'qty' => (int) $l['qty'],
                ])->values()->all(),
                customerId: $customer?->id,
                requestedDeliveryDate: $data['requested_delivery_date'] ?? null,
                delivery: array_filter((array) ($data['delivery'] ?? []), fn ($v) => $v !== null && $v !== ''),
            ));
        } catch (InsufficientAvailability $e) {
            $byRef = $lines->keyBy('line_ref');
            $body = ['status' => 'insufficient_stock', 'lines' => collect($e->lines)->map(function ($short) use ($byRef, $listings) {
                $line = $byRef[$short['line_ref']] ?? null;
                $pack = $line ? max(1, $listings[(string) $line['portal_product_id']]->unit->base_qty) : 1;

                return ['line_ref' => $short['line_ref'], 'requested' => $line ? (int) $line['qty'] : null, 'available' => intdiv(max(0, $short['available']), $pack)];
            })->values()];

            return $this->respond($request, $event, $data['order_ref'], 'rejected', $e->getMessage(), $body, 409);
        } catch (InvalidMovement $e) {
            // e.g. cancelling an order BWMS never saw, or changing one already dispatched
            $code = $this->find($data['order_ref']) ? 422 : 404;

            return $this->respond($request, $event, $data['order_ref'], IntegrationInbox::STATUS_FAILED, $e->getMessage(),
                ['status' => $code === 404 ? 'unknown_order' : 'invalid', 'message' => $e->getMessage()], $code);
        }

        $body = $this->orderBody($order->fresh(['lines.reservation']));
        if ($warnings !== []) {
            $body['warnings'] = $warnings;
        }

        return $this->respond($request, $event, $data['order_ref'], IntegrationInbox::STATUS_PROCESSED, $warnings ? implode('; ', $warnings) : null, $body, $created);
    }

    /**
     * The pack is fixed at mapping time; unit_quantity x unit only describes the
     * portal's sell unit and can be written "1 x carton" or "12 x bottle". So it is
     * only checked when the portal also sends `unit` (agreed 2026-10-27):
     * unit = BWMS base unit -> unit_quantity must equal the pack size;
     * unit = the pack's name -> unit_quantity must be 1; anything else is flagged.
     */
    private function unitWarning(array $line, ChannelListing $listing): ?string
    {
        if (blank($line['unit'] ?? null) || ! isset($line['unit_quantity'])) {
            return null;
        }
        $unit = mb_strtolower(trim($line['unit']));
        $qty = (int) $line['unit_quantity'];
        $pack = $listing->unit;
        $prefix = "line {$line['line_ref']}: portal \"{$qty} x {$line['unit']}\"";

        return match (true) {
            $unit === mb_strtolower($listing->product->base_unit) => $qty === $pack->base_qty ? null : "{$prefix} differs from the mapped pack ({$pack->name} = {$pack->base_qty} {$listing->product->base_unit})",
            $unit === mb_strtolower($pack->name) => $qty === 1 ? null : "{$prefix} differs from the mapped pack (1 {$pack->name})",
            default => "{$prefix} matches neither the base unit ({$listing->product->base_unit}) nor the mapped pack ({$pack->name})",
        };
    }

    /** Stores the call in the inbox (B4.3 step 4) and answers. */
    private function respond(Request $request, string $event, string $orderRef, string $status, ?string $error, array $body, int $code): JsonResponse
    {
        IntegrationInbox::insertOrIgnore([
            'channel' => self::CHANNEL,
            'event' => $event,
            'external_id' => mb_substr($orderRef, 0, 64),
            'dedupe_key' => hash('sha256', $request->header('Idempotency-Key').'|'.$request->getContent()),
            'status' => $status,
            'headers' => json_encode(['idempotency-key' => $request->header('Idempotency-Key'), 'key-id' => $request->attributes->get('portal_key_id')]),
            'payload' => $request->getContent(),
            'error' => $error ? mb_strimwidth($error, 0, 1000, '...') : null,
            'meta' => json_encode(['response_status' => $code]),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($body, $code);
    }

    /** @return array<string, mixed> */
    private function orderBody(Order $order, bool $withDispatched = false): array
    {
        $order->loadMissing('lines.reservation');
        $packs = fn (int $base, int $per) => $base % max(1, $per) === 0 ? intdiv($base, max(1, $per)) : round($base / max(1, $per), 3);

        return [
            'status' => $order->status === OrderStatus::Cancelled ? 'cancelled' : 'confirmed',
            'bwms_status' => $order->status->value,
            'reservation_status' => 'confirmed',
            'reservation_id' => $order->id,
            'lines' => $order->lines->map(fn ($l) => array_filter([
                'line_ref' => $l->line_ref,
                'reserved_qty' => $packs((int) ($l->reservation->qty_base ?? 0), $l->base_qty_per_unit),
                'dispatched_qty' => $withDispatched ? $packs((int) $l->qty_dispatched_base, $l->base_qty_per_unit) : null,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    private function rememberUnmapped(string $portalProductId): void
    {
        $row = ChannelUnmappedVariant::firstOrNew(['channel' => self::CHANNEL, 'external_variant_id' => $portalProductId]);
        $row->forceFill([
            'occurrences' => $row->exists ? $row->occurrences + 1 : 1,
            'first_seen_at' => $row->first_seen_at ?? now(), 'last_seen_at' => now(), 'resolved_at' => null,
        ])->save();
    }

    private function find(string $orderRef): ?Order
    {
        return Order::where('channel', OrderChannel::Portal)->where('external_ref', $orderRef)->first();
    }

    private function site(): Location
    {
        return Location::whereNull('parent_id')->where('type', Location::TYPE_SITE)->where('code', config('bwms.portal.site_code'))->firstOrFail();
    }
}
