<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\DeliveryRun;
use App\Models\Location;
use App\Models\Order;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Delivery\DeliveryScheduler;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\StockQuery;
use App\Support\Audit;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pulls orders from a later run onto an earlier day, to fill a delivery day.
 * The order keeps its area, so runs stay date + area and pick lists stay clean.
 * It only changes dates: stock is already reserved, and no reservation or
 * movement is touched. The order remembers where it came from, for Undo and for
 * the review flag if the source later sends another date (DeliveryScheduler).
 */
class ConsolidationService
{
    public const REASON = 'consolidated';

    public const NOTICE_SETTING = 'orders.consolidation_notice';

    private const ACTIVE = [OrderStatus::Open, OrderStatus::Picking, OrderStatus::PartiallyDispatched];

    public function __construct(private DeliveryScheduler $scheduler, private StockQuery $stock) {}

    /** Active orders on a run, on these days, with what the screen shows. */
    public function ordersBetween(string $from, string $to): Collection
    {
        return Order::with(['customer', 'lines.product:id,sku,is_stock_tracked', 'deliveryRun.area'])
            ->whereIn('status', self::ACTIVE)->whereNotNull('delivery_run_id')
            ->whereDate('delivery_date', '>=', $from)->whereDate('delivery_date', '<=', $to)
            ->orderBy('delivery_date')->orderBy('id')->get();
    }

    /** @return array<int, string> order_id => pick list number, for orders on a list that wasn't cancelled */
    public function pickLists(Collection $orders): array
    {
        return PickListLine::query()->join('order_lines', 'order_lines.id', '=', 'pick_list_lines.order_line_id')
            ->join('pick_lists', 'pick_lists.id', '=', 'pick_list_lines.pick_list_id')
            ->whereIn('order_lines.order_id', $orders->pluck('id'))->where('pick_lists.status', '!=', 'cancelled')
            ->pluck('pick_lists.number', 'order_lines.order_id')->all();
    }

    /** Sell units still to go out, and their value when the order carries prices. @return array{packs: int|float, value: ?string} */
    public function size(Order $order): array
    {
        $packs = 0;
        $value = '0';
        $priced = false;
        foreach ($order->lines as $line) {
            $qty = $line->outstandingBase() / max(1, $line->base_qty_per_unit);
            $packs += $qty;
            if ($line->unit_price !== null) {
                $priced = true;
                $value = bcadd($value, bcmul((string) $qty, (string) $line->unit_price, 4), 4);
            }
        }

        return ['packs' => $packs == (int) $packs ? (int) $packs : round($packs, 2), 'value' => $priced ? bcadd($value, '0', 2) : null];
    }

    /**
     * Lines the order can't fill by $target because their stock is still on a PO
     * due after it, by the order's original date at the latest. On hand counts
     * against orders delivering by $target only: a later order waits its turn.
     *
     * @return list<string>
     */
    public function stockWarnings(Order $order, string $target): array
    {
        $site = $order->site;
        $original = $order->delivery_date->toDateString();
        $day = CarbonImmutable::parse($target)->format('D');
        $warnings = [];

        foreach ($order->lines as $line) {
            $need = $line->outstandingBase();
            if ($need <= 0 || ! $line->product->is_stock_tracked) {
                continue;
            }
            $free = $this->freeBy($line->product, $site, $target, $order->id);
            if ($free >= $need) {
                continue;
            }
            $incoming = PurchaseOrderLine::query()->select('purchase_order_lines.*')
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
                ->where('purchase_order_lines.product_id', $line->product_id)->where('purchase_orders.site_id', $order->site_id)
                ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value])
                ->whereDate('purchase_orders.expected_date', '>', $target)->whereDate('purchase_orders.expected_date', '<=', $original)
                ->whereColumn('purchase_order_lines.qty_received_base', '<', 'purchase_order_lines.qty_ordered_base')
                ->orderBy('purchase_orders.expected_date')->with('purchaseOrder:id,number,expected_date')->first();
            if ($incoming) {
                $po = $incoming->purchaseOrder;
                $warnings[] = "Stock for {$line->product->sku} arrives {$po->expected_date->format('D')} via {$po->number}; not available for {$day}";
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Moves what it can. One order that can't move doesn't stop the others.
     *
     * @param  list<int>  $orderIds
     * @return array{moved: int, skipped: list<string>, warnings: list<string>}
     */
    public function move(array $orderIds, string $target, User $actor): array
    {
        $result = ['moved' => 0, 'skipped' => [], 'warnings' => []];
        foreach (Order::whereIn('id', $orderIds)->orderBy('id')->pluck('id') as $id) {
            try {
                $warnings = DB::transaction(fn () => $this->moveOne($id, $target, $actor));
                $result['moved']++;
                $result['warnings'] = [...$result['warnings'], ...$warnings];
            } catch (InvalidMovement $e) {
                $result['skipped'][] = $e->getMessage();
            }
        }
        $result['warnings'] = array_values(array_unique($result['warnings']));

        return $result;
    }

    /** @return list<string> stock warnings */
    private function moveOne(int $id, string $target, User $actor): array
    {
        $order = Order::with(['lines.product:id,sku,is_stock_tracked', 'deliveryRun.area', 'site'])->lockForUpdate()->findOrFail($id);
        $from = $order->delivery_date?->toDateString();

        $this->assert($order->status->isActive(), "{$order->number} is {$order->status->label()}.");
        $this->assert($order->deliveryRun !== null, "{$order->number} has no run yet.");
        $this->assert(! $this->scheduler->onPickList($order), "{$order->number} is already on a pick list.");
        $this->assert($target < $from, "{$order->number} is for {$from}: it can only move to an earlier day.");
        $this->assert($target >= BusinessDate::today(), 'The target date has passed.');
        $this->assertRunOpen($order, $target);

        $warnings = $this->stockWarnings($order, $target);
        $this->scheduler->consolidate($order, $target);
        Audit::record('order_consolidated', $order, ['delivery_date' => $from], ['delivery_date' => $target], [
            'reason' => self::REASON, 'original_date' => $order->original_delivery_date->toDateString(), 'warnings' => $warnings,
        ], $actor->id);

        return $warnings;
    }

    /** Back on its original date and run. @throws InvalidMovement */
    public function undo(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = Order::with('deliveryRun.area')->lockForUpdate()->findOrFail($order->id);
            $this->assert($order->delivery_date_reason === self::REASON, "{$order->number} wasn't consolidated.");
            $this->assert($order->status->isActive(), "{$order->number} is {$order->status->label()}.");
            $this->assert(! $this->scheduler->onPickList($order), "{$order->number} is already on a pick list.");
            $from = $order->delivery_date->toDateString();
            $original = $order->original_delivery_date->toDateString();
            $this->assertRunOpen($order, $original);

            $this->scheduler->undoConsolidation($order);
            Audit::record('order_consolidation_undone', $order, ['delivery_date' => $from], ['delivery_date' => $original], ['reason' => self::REASON], $actor->id);

            return $order;
        });
    }

    /** @param  array<int, ?string>  $labels  run_id => trip label */
    public function saveTrips(array $labels): void
    {
        foreach (DeliveryRun::whereIn('id', array_keys($labels))->get() as $run) {
            $run->forceFill(['trip_label' => trim((string) $labels[$run->id]) ?: null])->save();
        }
    }

    private function assertRunOpen(Order $order, string $date): void
    {
        $run = DeliveryRun::where('site_id', $order->site_id)->where('delivery_area_id', $order->deliveryRun->delivery_area_id)->whereDate('run_date', $date)->first();
        $this->assert($run === null || $run->status === DeliveryRun::STATUS_OPEN, "{$order->number}: the {$order->deliveryRun->area->name} run for {$date} has been dispatched.");
    }

    /** Sellable on hand less what's reserved for other orders going out by $date. */
    private function freeBy(Product $product, Location $site, string $date, int $orderId): int
    {
        $reserved = (int) Reservation::query()->join('order_lines', 'order_lines.id', '=', 'reservations.order_line_id')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('reservations.product_id', $product->id)->where('reservations.site_id', $site->id)
            ->whereDate('orders.delivery_date', '<=', $date)->where('orders.id', '!=', $orderId)
            ->sum('reservations.qty_base');

        return $this->stock->sellableOnHand($product, $site) - $reserved;
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidMovement($message);
        }
    }
}
