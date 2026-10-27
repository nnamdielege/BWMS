<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\CountTask;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\OrderPack;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Models\User;
use App\Services\Stock\CountTaskService;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Guided pick & pack by delivery run (MVP). Built on the existing pick list
 * machinery (FEFO allocation, short-ship policy at dispatch); this adds:
 *  - one pick list per batch of a run's orders, claimed by one picker
 *  - walk order: ambient lines by bin walk_seq, chilled lines last (no
 *    refrigerated transport), then earliest best-before
 *  - scan confirmation of bin and product, verified here against the bin's
 *    label code and the product's pack barcodes; otherwise an override reason
 *  - short picks raise a count task for the bin
 *  - per-order pack (all lines ticked, carton count) before the run dispatches
 */
class PickPackService
{
    public const OVERRIDE_REASONS = ['label_missing', 'label_damaged', 'scanner_fault', 'other'];

    public function __construct(private PickListService $picks, private CountTaskService $countTasks) {}

    /** Pick list for the run's orders that are waiting and not yet on a list, claimed by the picker. */
    public function startNew(DeliveryRun $run, User $picker): PickList
    {
        return DB::transaction(function () use ($run, $picker) {
            $run = DeliveryRun::lockForUpdate()->findOrFail($run->id);
            $this->assert($run->status === DeliveryRun::STATUS_OPEN, 'This run has been dispatched.');

            $ids = $this->waitingOrders($run)->pluck('id')->all();
            $this->assert($ids !== [], 'No orders on this run are waiting to be picked.');

            $pickList = $this->picks->create($picker, $run->site, $ids)['pickList'];
            $pickList->forceFill(['delivery_run_id' => $run->id, 'claimed_by' => $picker->id, 'claimed_at' => now()])->save();

            return $pickList;
        });
    }

    /** Open orders of the run with nothing on a pick list yet. */
    public function waitingOrders(DeliveryRun $run): Collection
    {
        return $run->orders()->whereIn('status', [OrderStatus::Open, OrderStatus::PartiallyDispatched])->where('site_id', $run->site_id)->get();
    }

    public function claim(PickList $pickList, User $picker, bool $takeOver = false): PickList
    {
        return DB::transaction(function () use ($pickList, $picker, $takeOver) {
            $pickList = PickList::lockForUpdate()->findOrFail($pickList->id);
            $this->assert($pickList->isOpen(), "{$pickList->number} is {$pickList->status}.");
            if ($pickList->claimed_by && $pickList->claimed_by !== $picker->id) {
                $this->assert($takeOver, "{$pickList->number} is being picked by ".($pickList->claimer?->name ?? 'someone else').'.');
                Audit::record('pick_list_taken_over', $pickList, meta: ['from' => $pickList->claimed_by]);
            }
            $pickList->forceFill(['claimed_by' => $picker->id, 'claimed_at' => now()])->save();

            return $pickList;
        });
    }

    /** Lines in walk order: ambient first by bin walk_seq, chilled last, then earliest best-before. */
    public function walkOrder(PickList $pickList): Collection
    {
        return $pickList->lines()
            ->with(['product.units.barcodes', 'location.parent', 'batch', 'orderLine.order.customer', 'orderLine.unit'])
            ->get()
            ->sortBy(fn (PickListLine $l) => [
                $l->product->is_chilled ? 1 : 0,
                $l->location->walk_seq,
                $l->location->code,
                $l->batch?->best_before?->format('Y-m-d') ?? '9999-12-31',
                $l->id,
            ])->values();
    }

    /**
     * Record one line. A scan is verified here: the bin code must be this line's
     * bin, each product code a barcode of this product. Without both, an override
     * reason is required (and audited). A short pick raises a count task for the bin.
     *
     * @param  list<string>  $productBarcodes
     */
    public function recordLine(PickListLine $line, User $picker, int $qtyBase, ?string $binBarcode, array $productBarcodes, ?string $overrideReason): PickListLine
    {
        return DB::transaction(function () use ($line, $picker, $qtyBase, $binBarcode, $productBarcodes, $overrideReason) {
            $line = PickListLine::lockForUpdate()->with(['pickList', 'location', 'product.units.barcodes'])->findOrFail($line->id);
            $list = $line->pickList;
            $this->assert($list->isOpen(), "{$list->number} is {$list->status}.");
            $this->assert($list->claimed_by === null || $list->claimed_by === $picker->id, "{$list->number} is being picked by someone else; take it over first.");
            $this->assert($qtyBase >= 0 && $qtyBase <= $line->qty_to_pick_base, "Picked quantity must be between 0 and {$line->qty_to_pick_base}.");

            $binOk = false;
            if (filled($binBarcode)) {
                $this->assert(hash_equals((string) $line->location->barcode, trim($binBarcode)), "Wrong bin: scanned {$binBarcode}, expected {$line->location->code}.");
                $binOk = true;
            }
            $known = $line->product->units->flatMap(fn ($u) => $u->barcodes->pluck('barcode'))->all();
            foreach ($productBarcodes as $code) {
                $this->assert(in_array(trim($code), $known, true), "Barcode {$code} is not {$line->product->sku}.");
            }
            $productOk = $productBarcodes !== [];

            if ($qtyBase > 0 && ! ($binOk && $productOk)) {
                $this->assert(in_array($overrideReason, self::OVERRIDE_REASONS, true), 'Scan the bin and the product, or choose why not.');
            }

            $line->forceFill([
                'qty_picked_base' => $qtyBase,
                'bin_scanned_at' => $binOk ? now() : null,
                'product_scanned_at' => $productOk ? now() : null,
                'override_reason' => $binOk && $productOk ? null : $overrideReason,
                'picked_by' => $picker->id,
                'picked_at' => now(),
            ])->save();

            if ($line->override_reason) {
                Audit::record('pick_scan_override', $line->product, meta: ['pick_list' => $list->number, 'bin' => $line->location->code, 'reason' => $line->override_reason]);
            }
            if ($qtyBase < $line->qty_to_pick_base) {
                $onHand = (int) DB::table('stock_balances')->where('product_id', $line->product_id)->where('location_id', $line->location_id)
                    ->where('batch_key', $line->batch_key)->value('on_hand');
                $this->countTasks->raise($line->location, $line->product, CountTask::REASON_SHORT_PICK, 'pick_list', $list->id, $onHand);
            }

            return $line;
        });
    }

    /** @param  list<int>  $checkedLineIds */
    public function pack(PickList $pickList, Order $order, User $packer, int $cartons, array $checkedLineIds): OrderPack
    {
        return DB::transaction(function () use ($pickList, $order, $packer, $cartons, $checkedLineIds) {
            $this->assert($pickList->isOpen(), "{$pickList->number} is {$pickList->status}.");
            $lines = $pickList->lines()->whereHas('orderLine', fn ($q) => $q->where('order_id', $order->id))->get();
            $this->assert($lines->isNotEmpty(), "{$order->number} is not on {$pickList->number}.");
            $this->assert($lines->every(fn ($l) => $l->qty_picked_base !== null), 'Finish picking every line of this order first.');
            $this->assert($lines->pluck('id')->sort()->values()->all() === collect($checkedLineIds)->map(fn ($i) => (int) $i)->sort()->values()->all(), 'Tick every line as packed.');
            $picked = (int) $lines->sum('qty_picked_base');
            $this->assert($picked === 0 || $cartons >= 1, 'Enter how many cartons.');

            $pack = OrderPack::updateOrCreate(
                ['order_id' => $order->id, 'pick_list_id' => $pickList->id],
                ['cartons' => $cartons, 'packed_by' => $packer->id, 'packed_at' => now()],
            );
            Audit::record('order_packed', $order, meta: ['pick_list' => $pickList->number, 'cartons' => $cartons]);

            return $pack;
        });
    }

    /** Hand-off: dispatch every open pick list of the run once each of its orders is packed. */
    public function dispatchRun(DeliveryRun $run, User $actor): DeliveryRun
    {
        $lists = $run->pickLists()->where('status', PickList::STATUS_OPEN)->get();
        $this->assert($lists->isNotEmpty(), 'Nothing on this run is picked and waiting to go.');

        foreach ($lists as $list) {
            $orderIds = $list->lines()->join('order_lines', 'order_lines.id', '=', 'pick_list_lines.order_line_id')->distinct()->pluck('order_lines.order_id');
            $packed = OrderPack::where('pick_list_id', $list->id)->whereIn('order_id', $orderIds)->count();
            $this->assert($packed === $orderIds->count(), "Pack every order on {$list->number} before dispatch.");
        }
        foreach ($lists as $list) {
            $this->picks->dispatch($list, $actor);
        }

        $stillActive = $run->orders()->whereIn('status', [OrderStatus::Open, OrderStatus::Picking, OrderStatus::PartiallyDispatched])->exists();
        if (! $stillActive) {
            $run->forceFill(['status' => DeliveryRun::STATUS_DISPATCHED, 'dispatched_at' => now()])->save();
        }
        Audit::record('run_dispatched', null, meta: ['run' => $run->id, 'pick_lists' => $lists->pluck('number')->all()]);

        return $run->fresh();
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidMovement($message);
        }
    }
}
