<?php

namespace App\Jobs;

use App\Enums\OrderChannel;
use App\Models\Order;
use App\Models\PickList;
use App\Models\PickListLine;

/**
 * Dispatch notification: what left the warehouse on one pick list for one
 * portal order. "short_shipped" when less was picked than planned. The
 * Idempotency-Key is stable per pick list.
 */
class SendPortalOrderEvent extends SendsPortalOrderEvent
{
    public function __construct(public int $orderId, public int $pickListId) {}

    public static function payload(Order $order, PickList $pickList): array
    {
        $lines = PickListLine::where('pick_list_id', $pickList->id)
            ->whereIn('order_line_id', $order->lines()->pluck('id'))
            ->get()->groupBy('order_line_id');

        $short = false;
        $out = [];
        foreach ($order->lines as $line) {
            if (! isset($lines[$line->id])) {
                continue;
            }
            $picked = (int) $lines[$line->id]->sum('qty_picked_base');
            $short = $short || $picked < (int) $lines[$line->id]->sum('qty_to_pick_base');
            $out[] = ['line_ref' => $line->line_ref, 'qty' => self::packs($picked, $line->base_qty_per_unit)];
        }

        return [
            'order_ref' => $order->external_ref,
            'event' => $short ? 'short_shipped' : 'dispatched',
            'lines' => $out,
            'at' => $pickList->dispatched_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    protected function order(): ?Order
    {
        return Order::with('lines')->find($this->orderId);
    }

    protected function body(Order $order): ?array
    {
        $pickList = PickList::find($this->pickListId);

        return $order->channel === OrderChannel::Portal && $pickList?->dispatched_at ? self::payload($order, $pickList) : null;
    }

    protected function idempotencyKey(Order $order): string
    {
        return "bwms:order-event:{$order->external_ref}:{$this->pickListId}";
    }
}
