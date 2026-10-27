<?php

namespace App\Services\Orders;

use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Events\OrderDispatched;
use App\Models\CountTask;
use App\Models\Location;
use App\Models\Order;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Stock\CountTaskService;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StockLedger;
use App\Support\Audit;
use App\Support\NumberSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pick lists (FEFO within the site) and dispatch.
 *
 * Stock does not move until dispatch: the pick list only plans where to take
 * it from. Allocation subtracts what other open pick lists already plan to
 * take from the same bin/batch, so two pickers are never sent for the same units.
 */
class PickListService
{
    public function __construct(
        private FefoAllocator $fefo,
        private StockLedger $ledger,
        private ReservationService $reservations,
        private CountTaskService $countTasks,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @return array{pickList: PickList, shortfalls: list<array{order: string, sku: string, short: int}>}
     */
    public function create(User $actor, Location $site, array $orderIds): array
    {
        return DB::transaction(function () use ($actor, $site, $orderIds) {
            $orders = Order::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->with('lines.product')->get();
            $this->assert($orders->isNotEmpty(), 'Choose at least one order.');

            foreach ($orders as $order) {
                $this->assert($order->site_id === $site->id, "{$order->number} is fulfilled from another site.");
                $this->assert(in_array($order->status, [OrderStatus::Open, OrderStatus::PartiallyDispatched], true), "{$order->number} is {$order->status->label()}.");
            }

            $pickList = PickList::create(['number' => NumberSequence::next('pick_list', 'PK'), 'site_id' => $site->id, 'status' => PickList::STATUS_OPEN, 'created_by' => $actor->id]);
            $allocated = $this->openAllocations($site);
            $shortfalls = [];

            foreach ($orders as $order) {
                foreach ($order->lines as $line) {
                    $need = $line->product->is_stock_tracked ? $line->outstandingBase() : 0; // services are not picked
                    if ($need <= 0) {
                        continue;
                    }
                    $result = $this->fefo->allocate($line->product, $need, $site, $allocated[$line->product_id] ?? []);
                    foreach ($result['lines'] as $alloc) {
                        $b = $alloc['balance'];
                        $pickList->lines()->create([
                            'order_line_id' => $line->id, 'product_id' => $line->product_id, 'location_id' => $b->location_id,
                            'batch_id' => $b->batch_id, 'batch_key' => $b->batch_key, 'qty_to_pick_base' => $alloc['qty'],
                        ]);
                        $key = "{$b->location_id}-{$b->batch_key}";
                        $allocated[$line->product_id][$key] = ($allocated[$line->product_id][$key] ?? 0) + $alloc['qty'];
                    }
                    if ($result['shortfall'] > 0) {
                        $shortfalls[] = ['order' => $order->number, 'sku' => $line->product->sku, 'short' => $result['shortfall']];
                    }
                }
                $order->forceFill(['status' => OrderStatus::Picking])->save();
            }

            $this->assert($pickList->lines()->exists(), 'Nothing can be picked: no sellable stock for these orders at this site.');

            return ['pickList' => $pickList, 'shortfalls' => $shortfalls];
        });
    }

    /** @param  array<int, int>  $picked  pick_list_line_id => qty picked */
    public function recordPicked(PickList $pickList, array $picked): void
    {
        $this->assert($pickList->isOpen(), "{$pickList->number} is {$pickList->status}.");
        foreach ($pickList->lines()->whereIn('id', array_keys($picked))->get() as $line) {
            $qty = (int) $picked[$line->id];
            $this->assert($qty >= 0 && $qty <= $line->qty_to_pick_base, "Picked quantity must be between 0 and {$line->qty_to_pick_base}.");
            $line->forceFill(['qty_picked_base' => $qty])->save();
        }
    }

    /**
     * Post `sale` movements for everything picked, consume reservations and
     * update orders. Unpicked remainder follows each order's short-ship policy:
     * backorder (stays reserved) or cancel (released).
     */
    public function dispatch(PickList $pickList, User $actor): PickList
    {
        return DB::transaction(function () use ($pickList, $actor) {
            $pickList = PickList::lockForUpdate()->findOrFail($pickList->id);
            if ($pickList->status === PickList::STATUS_DISPATCHED) {
                return $pickList; // double submit
            }
            $this->assert($pickList->isOpen(), "{$pickList->number} is {$pickList->status}.");

            $lines = $pickList->lines()->with(['orderLine.order', 'product', 'location', 'batch'])->get();
            $this->assert($lines->whereNull('qty_picked_base')->isEmpty(), 'Confirm the picked quantity on every line first.');

            // Lock order: products before stock balances (same as reservations and receiving).
            Product::whereIn('id', $lines->pluck('product_id')->unique()->sort()->values())->orderBy('id')->lockForUpdate()->get(['id']);

            $drafts = [];
            foreach ($lines->where('qty_picked_base', '>', 0) as $line) {
                $drafts[] = new MovementDraft(
                    product: $line->product,
                    location: $line->location,
                    qty: -$line->qty_picked_base,
                    type: MovementType::Sale,
                    idempotencyKey: "disp:{$line->id}",
                    batch: $line->batch,
                    sourceType: 'order',
                    sourceId: $line->orderLine->order_id,
                    note: $line->orderLine->order->number,
                    // Goods physically left; a ledger shortfall is a count problem to fix, not a reason to stop the truck.
                    allowNegative: true,
                );
                $line->orderLine->increment('qty_dispatched_base', $line->qty_picked_base);
            }
            if ($drafts !== []) {
                $this->ledger->post($drafts, $actor);
            }

            $orders = Order::whereIn('id', $lines->pluck('orderLine.order_id')->unique())->with('lines.product')->get();
            foreach ($orders as $order) {
                // Non-stock-tracked lines (services) go out with the goods: complete them, no movement.
                foreach ($order->lines->filter(fn ($l) => ! $l->product->is_stock_tracked && $l->outstandingBase() > 0) as $l) {
                    $l->forceFill(['qty_dispatched_base' => $l->qty_dispatched_base + $l->outstandingBase()])->save();
                }
                if ($order->short_ship_policy === 'cancel') {
                    foreach ($order->lines as $l) {
                        $l->forceFill(['qty_cancelled_base' => $l->qty_cancelled_base + $l->outstandingBase()])->save();
                    }
                }
                $outstanding = $order->lines->sum(fn ($l) => $l->fresh()->outstandingBase());
                $order->forceFill(['status' => $outstanding > 0 ? OrderStatus::PartiallyDispatched : OrderStatus::Dispatched])->save();
                $this->reservations->syncOrder($order, true);
            }

            $pickList->forceFill(['status' => PickList::STATUS_DISPATCHED, 'dispatched_by' => $actor->id, 'dispatched_at' => now()])->save();
            $this->flagNegativeBins($pickList, $lines);
            OrderDispatched::dispatch($pickList->id, $orders->pluck('id')->all());

            return $pickList;
        });
    }

    public function cancel(PickList $pickList): PickList
    {
        return DB::transaction(function () use ($pickList) {
            $pickList = PickList::lockForUpdate()->findOrFail($pickList->id);
            $this->assert($pickList->isOpen(), "{$pickList->number} is {$pickList->status}.");

            $orderIds = $pickList->lines()->join('order_lines', 'order_lines.id', '=', 'pick_list_lines.order_line_id')->distinct()->pluck('order_lines.order_id');
            $pickList->forceFill(['status' => PickList::STATUS_CANCELLED])->save();
            foreach (Order::whereIn('id', $orderIds)->with('lines')->get() as $order) {
                if ($order->status === OrderStatus::Picking) {
                    $dispatched = $order->lines->sum('qty_dispatched_base') > 0;
                    $order->forceFill(['status' => $dispatched ? OrderStatus::PartiallyDispatched : OrderStatus::Open])->save();
                }
            }

            return $pickList;
        });
    }

    /** @return array<int, array<string, int>> product_id => ["{location}-{batch_key}" => qty] */
    private function openAllocations(Location $site): array
    {
        $out = [];
        PickListLine::query()
            ->whereHas('pickList', fn ($q) => $q->where('status', PickList::STATUS_OPEN)->where('site_id', $site->id))
            ->get(['product_id', 'location_id', 'batch_key', 'qty_to_pick_base'])
            ->each(function ($l) use (&$out) {
                $key = "{$l->location_id}-{$l->batch_key}";
                $out[$l->product_id][$key] = ($out[$l->product_id][$key] ?? 0) + $l->qty_to_pick_base;
            });

        return $out;
    }

    /** Dispatch is never blocked; a bin it took below zero is logged and gets a count task. */
    private function flagNegativeBins(PickList $pickList, $lines): void
    {
        foreach ($lines as $line) {
            $onHand = (int) DB::table('stock_balances')->where('product_id', $line->product_id)
                ->where('location_id', $line->location_id)->where('batch_key', $line->batch_key)->value('on_hand');
            if ($onHand < 0) {
                Log::warning('Dispatch took a bin negative; count it', ['sku' => $line->product->sku, 'bin' => $line->location->path(), 'on_hand' => $onHand]);
                Audit::record('dispatch_negative_bin', $line->product, meta: ['bin' => $line->location->path(), 'on_hand' => $onHand, 'pick_list' => $pickList->number]);
                $this->countTasks->raise($line->location, $line->product, CountTask::REASON_NEGATIVE_AFTER_DISPATCH, 'pick_list', $pickList->id, $onHand);
            }
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidMovement($message);
        }
    }
}
