<?php

namespace App\Services\Catalogue;

use App\Models\Barcode;
use App\Models\PickList;
use App\Models\ProductUnit;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correcting barcodes: change one, remove one, or move one to another pack (any product).
 * Each is one audit entry on the product: old → new, the pack, and who did it.
 *
 * Removing or moving the last barcode off a pack is allowed, but warned about when an open
 * pick list still has that product to pick: pickers will have to enter the quantity.
 */
class BarcodeService
{
    public function update(Barcode $barcode, string $code): Barcode
    {
        $code = BarcodeCaptureService::checkCode($code);
        $barcode->loadMissing('unit.product');
        if ($code === $barcode->barcode) {
            return $barcode;
        }
        $this->refuseIfTaken($code, $barcode);
        $old = $barcode->barcode;

        Audit::withoutModelEvents(fn () => $barcode->forceFill(['barcode' => $code, 'type' => Barcode::guessType($code)])->save());
        Audit::record('barcode_changed', $barcode->unit->product, ['barcode' => $old], ['barcode' => $code], self::where($barcode->unit));

        return $barcode;
    }

    /** @return ?string a warning when the pack is left with no barcode mid-pick */
    public function remove(Barcode $barcode): ?string
    {
        $barcode->loadMissing('unit.product');
        $unit = $barcode->unit;

        DB::transaction(function () use ($barcode, $unit) {
            Audit::withoutModelEvents(fn () => $barcode->delete());
            Audit::record('barcode_removed', $unit->product, ['barcode' => $barcode->barcode], null, self::where($unit));
        });

        return self::pickWarning($unit);
    }

    /** Off its pack and onto $to in one step. @return ?string a warning for the pack it left */
    public function move(Barcode $barcode, ProductUnit $to): ?string
    {
        $barcode->loadMissing('unit.product');
        $from = $barcode->unit;
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['barcode' => "{$barcode->barcode} is already on this pack."]);
        }
        $to->loadMissing('product');

        DB::transaction(function () use ($barcode, $from, $to) {
            Audit::withoutModelEvents(fn () => $barcode->forceFill(['product_unit_id' => $to->id])->save());
            $meta = ['barcode' => $barcode->barcode];
            Audit::record('barcode_moved', $to->product, self::where($from), self::where($to), $meta);
            if ($from->product_id !== $to->product_id) {
                Audit::record('barcode_moved', $from->product, self::where($from), self::where($to), $meta);
            }
        });

        return self::pickWarning($from->fresh());
    }

    /** Open pick lists with this product still to pick. */
    public static function openPickLists(int $productId): int
    {
        return (int) DB::table('pick_list_lines as l')->join('pick_lists as p', 'p.id', '=', 'l.pick_list_id')
            ->where('p.status', PickList::STATUS_OPEN)->where('l.product_id', $productId)->whereNull('l.qty_picked_base')
            ->distinct()->count('p.id');
    }

    public static function pickWarning(ProductUnit $unit): ?string
    {
        if ($unit->barcodes()->exists()) {
            return null;
        }
        $lists = self::openPickLists($unit->product_id);
        if ($lists === 0) {
            return null;
        }
        $unit->loadMissing('product');

        return "{$unit->product->sku} {$unit->name} now has no barcode, and {$lists} open pick list".($lists === 1 ? '' : 's')
            .' still need it: pickers will have to enter the quantity.';
    }

    /** @return array{product: string, pack: string} */
    private static function where(ProductUnit $unit): array
    {
        return ['product' => trim("{$unit->product->sku} {$unit->product->name}"), 'pack' => $unit->name];
    }

    private function refuseIfTaken(string $code, Barcode $barcode): void
    {
        $holder = BarcodeCaptureService::holder($code, $barcode->unit);
        if ($holder) {
            throw ValidationException::withMessages(['barcode' => "This barcode is on {$holder['sku']} {$holder['name']} ({$holder['pack']})."]);
        }
    }
}
