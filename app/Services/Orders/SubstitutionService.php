<?php

namespace App\Services\Orders;

use App\Events\OrderSubstitutionChanged;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderPack;
use App\Models\OrderSubstitution;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Reports\PackPricing;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\Audit;
use App\Support\PackQty;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The customer accepts a different product in place of an oversold or short line.
 *
 * The replaced quantity of the original is cancelled as "substituted" (not short, never
 * backordered) and a new line, pointing back at it, is added and reserved straight away,
 * oversold if it has to be. When the original is already on an open pick list, the
 * replaced quantity comes off that list (what's short first, then what's not picked yet)
 * and the substitute goes on it, so the picker sees it.
 *
 * Undo is allowed until the substitute is on a pick list or has gone.
 */
class SubstitutionService
{
    public function __construct(private ReservationService $reservations, private PickListService $picks, private AvailabilityService $availability) {}

    /**
     * @param  array{replace_base: int, product_id: int, product_unit_id: int, qty_base: int, unit_price?: ?string, keep_original_price?: bool, note: string}  $data
     * @param  PickListLine|null  $shortLine  substituting a short pick: its shortfall comes off first
     * @return array{substitution: OrderSubstitution, shortfall: int} shortfall: base units of the substitute not covered by stock
     */
    public function substitute(OrderLine $original, User $actor, array $data, ?PickListLine $shortLine = null): array
    {
        $result = DB::transaction(function () use ($original, $actor, $data, $shortLine) {
            $order = Order::lockForUpdate()->findOrFail($original->order_id);
            $original = OrderLine::lockForUpdate()->with(['product', 'unit'])->findOrFail($original->id);
            $this->assert($order->status->isActive(), "{$order->number} is {$order->status->label()}.");

            $replace = (int) $data['replace_base'];
            $outstanding = $original->outstandingBase();
            $this->assert($outstanding > 0, 'Nothing of that line is left to send.');
            $this->assert($replace > 0 && $replace <= $outstanding, 'Substitute between 1 and '.$this->label($outstanding, $original).' of the original.');
            $this->assert(trim($data['note']) !== '', 'Say who accepted the substitute, and how.');

            $product = Product::with(['units', 'channelListings'])->findOrFail($data['product_id']);
            $this->assert($product->is_active, "{$product->sku} is not active.");
            $unit = $product->units->firstWhere('id', (int) $data['product_unit_id']);
            $this->assert($unit !== null, 'Choose a pack of the substitute.');
            $this->assert($unit->sellable, "{$product->sku}: pack \"{$unit->name}\" is not sellable.");
            $qtyBase = (int) $data['qty_base'];
            $this->assert($qtyBase > 0, 'Enter how much of the substitute.');

            $pickList = $this->takeOffPickList($order, $original, $replace, $shortLine);

            $price = $this->price($original, $replace, $product, $unit->base_qty, $qtyBase, $data);
            $originalValue = OrderSubstitution::value($replace, $original->base_qty_per_unit, $original->unit_price);
            $substituteValue = OrderSubstitution::value($qtyBase, $unit->base_qty, $price);

            $substitution = OrderSubstitution::create([
                'order_id' => $order->id,
                'original_line_id' => $original->id,
                'original_product_id' => $original->product_id,
                'substitute_product_id' => $product->id,
                'substitute_unit_id' => $unit->id,
                'substitute_line_ref' => '',
                'original_qty_base' => $replace,
                'original_per' => $original->base_qty_per_unit,
                'original_unit_price' => $original->unit_price,
                'substitute_qty_base' => $qtyBase,
                'substitute_per' => $unit->base_qty,
                'substitute_unit_price' => $price,
                'kept_original_price' => (bool) ($data['keep_original_price'] ?? false),
                'price_difference' => $originalValue !== null && $substituteValue !== null ? round($substituteValue - $originalValue, 2) : null,
                'note' => mb_substr(trim($data['note']), 0, 500),
                'pick_list_id' => $shortLine?->pick_list_id,
                'created_by' => $actor->id,
            ]);

            $original->forceFill([
                'qty_cancelled_base' => $original->qty_cancelled_base + $replace,
                'qty_substituted_base' => $original->qty_substituted_base + $replace,
            ])->save();

            $line = $order->lines()->make();
            $line->forceFill([
                'substitute_for_line_id' => $original->id,
                'line_ref' => "bwms-sub-{$substitution->id}",
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'qty' => intdiv($qtyBase, max(1, $unit->base_qty)),
                'base_qty_per_unit' => $unit->base_qty,
                'qty_base' => $qtyBase,
                'unit_price' => $price,
                'sort_order' => $original->sort_order,
            ])->save();
            $substitution->forceFill(['substitute_line_id' => $line->id, 'substitute_line_ref' => $line->line_ref])->save();

            if ($pickList) {
                $this->picks->addLine($pickList, $line);
            }
            // Releases the original and reserves the substitute; flags the order oversold if it must.
            $this->reservations->syncOrder($order, true);
            $order->loadMissing('site');
            $shortfall = $product->is_stock_tracked ? min($qtyBase, max(0, -$this->availability->available($product, $order->site, current: true))) : 0;

            Audit::record('order_line_substituted', $order,
                old: ['sku' => $original->product->sku, 'qty' => $this->label($replace, $original), 'unit_price' => $original->unit_price],
                new: ['sku' => $product->sku, 'qty' => PackQty::label($qtyBase, $unit->base_qty, $unit->name, $product->base_unit), 'unit_price' => $price],
                meta: [
                    'substitution_id' => $substitution->id, 'line_ref' => $original->line_ref, 'note' => $substitution->note,
                    'price_difference' => $substitution->price_difference, 'kept_original_price' => $substitution->kept_original_price,
                    'pick_list' => $pickList?->number, 'shortfall_base' => $shortfall,
                ],
                userId: $actor->id);
            OrderSubstitutionChanged::dispatch($substitution->id, OrderSubstitutionChanged::SUBSTITUTED);

            return ['substitution' => $substitution, 'shortfall' => $shortfall];
        });

        return $result;
    }

    /** Puts the original back and removes the substitute line. Not once the substitute is on a pick list or has gone. */
    public function undo(OrderSubstitution $substitution, User $actor): OrderSubstitution
    {
        return DB::transaction(function () use ($substitution, $actor) {
            $order = Order::lockForUpdate()->findOrFail($substitution->order_id);
            $substitution = OrderSubstitution::lockForUpdate()->findOrFail($substitution->id);
            $this->assert($substitution->undone_at === null, 'That substitution has already been undone.');
            $this->assert($order->status->isActive(), "{$order->number} is {$order->status->label()}.");

            $line = OrderLine::lockForUpdate()->with('product')->find($substitution->substitute_line_id);
            $original = OrderLine::lockForUpdate()->with(['product', 'unit'])->findOrFail($substitution->original_line_id);
            $this->assert($line !== null, 'The substitute line is gone.');
            $this->assert($line->qty_dispatched_base === 0, 'The substitute has already been dispatched.');
            $this->assert(! OrderSubstitution::where('original_line_id', $line->id)->exists(), 'That substitute was substituted itself, so it can no longer be undone.');
            $picks = PickListLine::where('order_line_id', $line->id)->with('pickList:id,number,status')->get();
            $live = $picks->first(fn (PickListLine $p) => $p->pickList->status !== PickList::STATUS_CANCELLED);
            $this->assert($live === null, "The substitute is on pick list {$live?->pickList->number}. Undo isn't possible once it's being picked.");
            $onList = PickListLine::where('order_line_id', $original->id)->whereHas('pickList', fn ($q) => $q->where('status', PickList::STATUS_OPEN))->exists();
            $this->assert(! $onList, 'The original line is on an open pick list. Cancel the pick list first.');

            // As much as is still cancelled as substituted: a channel may have lowered the line since.
            $restore = min($substitution->original_qty_base, $original->qty_substituted_base, $original->qty_cancelled_base);
            $original->forceFill([
                'qty_cancelled_base' => $original->qty_cancelled_base - $restore,
                'qty_substituted_base' => $original->qty_substituted_base - $restore,
            ])->save();

            // Release the substitute's stock, then take the line away. Lines on cancelled lists never moved stock.
            $line->forceFill(['qty_cancelled_base' => $line->qty_base])->save();
            $this->reservations->syncOrder($order, true);
            PickListLine::whereIn('id', $picks->pluck('id'))->delete();
            $line->reservation()->delete();
            $substitution->forceFill(['undone_at' => now(), 'undone_by' => $actor->id, 'substitute_line_id' => null])->save();
            $line->delete();

            Audit::record('order_substitution_undone', $order,
                old: ['sku' => $substitution->substituteProduct->sku, 'qty_base' => $substitution->substitute_qty_base],
                new: ['sku' => $original->product->sku, 'qty' => $this->label($restore, $original)],
                meta: ['substitution_id' => $substitution->id, 'line_ref' => $original->line_ref],
                userId: $actor->id);
            OrderSubstitutionChanged::dispatch($substitution->id, OrderSubstitutionChanged::UNDONE);

            return $substitution;
        });
    }

    /** Can this one still be undone? (Same rules as undo(), for the button.) */
    public function canUndo(OrderSubstitution $substitution): bool
    {
        if ($substitution->undone_at !== null || $substitution->substitute_line_id === null) {
            return false;
        }
        $line = $substitution->substituteLine;
        if (! $line || $line->qty_dispatched_base > 0 || OrderSubstitution::where('original_line_id', $line->id)->exists()) {
            return false;
        }
        $lineIds = [$line->id, $substitution->original_line_id];

        return ! PickListLine::where('order_line_id', $line->id)->whereHas('pickList', fn ($q) => $q->where('status', '!=', PickList::STATUS_CANCELLED))->exists()
            && ! PickListLine::whereIn('order_line_id', $lineIds)->whereHas('pickList', fn ($q) => $q->where('status', PickList::STATUS_OPEN))->exists();
    }

    /** Staff changed the Ordermentum order by hand (or put it back, after an undo). */
    public function markChannelDone(OrderSubstitution $substitution, User $actor, bool $done = true): void
    {
        $column = $substitution->undone_at && $substitution->channel_done_at ? 'channel_undo_done' : 'channel_done';
        $substitution->forceFill(["{$column}_at" => $done ? now() : null, "{$column}_by" => $done ? $actor->id : null])->save();
        Audit::record($done ? 'substitution_channel_done' : 'substitution_channel_reopened', $substitution->order, meta: ['substitution_id' => $substitution->id, 'step' => $column], userId: $actor->id);
    }

    /**
     * Ordermentum substitutions staff still have to make by hand: new ones not yet ticked,
     * and ones undone in BWMS after they were made in Ordermentum.
     *
     * @return Collection<int, OrderSubstitution>
     */
    public static function channelToDo(string $channel): Collection
    {
        return OrderSubstitution::query()
            ->whereHas('order', fn ($q) => $q->where('channel', $channel))
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('undone_at')->whereNull('channel_done_at'))
                ->orWhere(fn ($w) => $w->whereNotNull('undone_at')->whereNotNull('channel_done_at')->whereNull('channel_undo_done_at')))
            ->with(['order:id,number,external_ref', 'originalLine.unit:id,name', 'originalProduct:id,sku,name,base_unit', 'substituteProduct:id,sku,name,base_unit', 'substituteUnit:id,name', 'creator:id,name'])
            ->orderBy('id')->limit(200)->get();
    }

    /**
     * The quantity to replace comes off the open pick list the original is on: the short
     * pick being substituted first, then lines with no stock, then shorts already counted,
     * then lines not picked yet. Returns that list, which the substitute is then added to.
     */
    private function takeOffPickList(Order $order, OrderLine $original, int $replace, ?PickListLine $shortLine): ?PickList
    {
        $lines = PickListLine::where('order_line_id', $original->id)->whereHas('pickList', fn ($q) => $q->where('status', PickList::STATUS_OPEN))
            ->lockForUpdate()->with('pickList')->get();
        if ($shortLine) {
            $this->assert($lines->contains('id', $shortLine->id), 'That pick line is not for this order line, or its list is closed.');
        }
        if ($lines->isEmpty()) {
            return null;
        }
        $pickList = $lines->first()->pickList;
        $this->assert(! OrderPack::where('pick_list_id', $pickList->id)->where('order_id', $order->id)->exists(), "{$order->number} is already packed on {$pickList->number}.");

        // Anything of the line not planned on the list (normally nothing) goes first.
        $left = max(0, $replace - max(0, $original->outstandingBase() - (int) $lines->sum('qty_to_pick_base')));
        $ordered = $lines->sortBy(fn (PickListLine $l) => [
            $l->id === $shortLine?->id ? 0 : 1,
            $l->no_stock ? 0 : 1,
            $l->qty_picked_base !== null ? 0 : 1,
            -$l->id,
        ]);
        $room = $ordered->sum(fn (PickListLine $l) => $l->qty_to_pick_base - ($l->no_stock ? 0 : (int) $l->qty_picked_base));
        $this->assert($room >= $left, "Only {$this->label($room, $original)} of this line is still short or unpicked on {$pickList->number}; the rest has been picked.");

        foreach ($ordered as $l) {
            if ($left <= 0) {
                break;
            }
            $picked = $l->no_stock ? 0 : (int) $l->qty_picked_base;
            $take = min($left, $l->qty_to_pick_base - $picked);
            if ($take <= 0) {
                continue;
            }
            $left -= $take;
            $remaining = $l->qty_to_pick_base - $take;
            if ($remaining === 0) {
                $l->delete();
            } else {
                $l->forceFill(['qty_to_pick_base' => $remaining])->save();
            }
        }

        return $pickList;
    }

    /** Per pack of the substitute: as entered, or what keeps the customer's total the same, or the default sell price. */
    private function price(OrderLine $original, int $replace, Product $product, int $per, int $qtyBase, array $data): ?string
    {
        if ($data['keep_original_price'] ?? false) {
            if ($original->unit_price === null) {
                return null;
            }
            $total = $replace / max(1, $original->base_qty_per_unit) * (float) $original->unit_price;

            return number_format($total / ($qtyBase / max(1, $per)), 4, '.', '');
        }
        if (($data['unit_price'] ?? null) !== null && $data['unit_price'] !== '') {
            return number_format((float) $data['unit_price'], 4, '.', '');
        }
        $sell = PackPricing::sellFor($product, $per);

        return $sell === null ? null : number_format($sell, 4, '.', '');
    }

    private function label(int $base, OrderLine $line): string
    {
        return PackQty::label($base, $line->base_qty_per_unit, $line->unit->name, $line->product->base_unit);
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidMovement($message);
        }
    }
}
