<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\OrderLine;
use App\Support\PackQty;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Open orders holding part of a pack, from before quantities were typed in packs
 * (a Case of 12 dispatched as 5 bottles, the rest still reserved). Read-only:
 * it lists them, a person fixes them.
 */
class PackMismatches extends Command
{
    protected $signature = 'orders:pack-mismatches';

    protected $description = 'List open orders whose lines hold or have shipped part of a pack (read-only)';

    public function handle(): int
    {
        $lines = OrderLine::query()
            ->where('base_qty_per_unit', '>', 1)
            ->whereHas('order', fn ($q) => $q->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::cases(), fn ($s) => $s->isActive()))))
            ->with(['order.customer:id,name', 'product:id,sku,base_unit', 'unit:id,name', 'reservation'])
            ->orderBy('order_id')->orderBy('id')
            ->get();

        $held = $lines->filter(fn (OrderLine $l) => ($l->qty_dispatched_base + $this->reserved($l)) % $l->base_qty_per_unit !== 0);
        $split = $lines->filter(fn (OrderLine $l) => $l->qty_dispatched_base % $l->base_qty_per_unit !== 0);

        $this->info("Dispatched + reserved isn't a whole number of packs: {$held->count()} line(s) on {$held->unique('order_id')->count()} order(s).");
        $this->table($this->headers(), $this->rows($held));
        $this->newLine();
        $this->info("Part of a pack already dispatched: {$split->count()} line(s) on {$split->unique('order_id')->count()} order(s).");
        $this->line('These add up to whole packs once the reserved rest ships, but the customer got loose units.');
        $this->table($this->headers(), $this->rows($split));

        return self::SUCCESS;
    }

    private function reserved(OrderLine $line): int
    {
        return (int) ($line->reservation?->qty_base ?? 0);
    }

    private function headers(): array
    {
        return ['Order', 'Customer', 'Status', 'SKU', 'Ordered', 'Dispatched', 'Reserved', 'Dispatched + reserved', 'Pick lists'];
    }

    private function rows(Collection $lines): array
    {
        return $lines->map(function (OrderLine $l) {
            $label = fn (int $base) => PackQty::label($base, $l->base_qty_per_unit, $l->unit->name, $l->product->base_unit)." ({$base})";

            return [
                $l->order->number,
                $l->order->customer?->name,
                $l->order->status->value,
                $l->product->sku,
                $label($l->qty_base),
                $label($l->qty_dispatched_base),
                $label($this->reserved($l)),
                $label($l->qty_dispatched_base + $this->reserved($l)),
                $l->pickLines()->with('pickList:id,number')->get()->pluck('pickList.number')->unique()->implode(', '),
            ];
        })->values()->all();
    }
}
