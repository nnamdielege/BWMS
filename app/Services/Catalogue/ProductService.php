<?php

namespace App\Services\Catalogue;

use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a product with its pack sizes and barcodes atomically.
 */
class ProductService
{
    /**
     * @param  array<string, mixed>  $data  validated ProductRequest data
     */
    public function save(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->fill(Arr::except($data, ['units']))->save();

            $units = collect($data['units']);
            $this->assertBarcodesFree($product, $units->pluck('barcodes')->flatten()->all());

            $keptIds = [];
            foreach ($units as $row) {
                $unit = isset($row['id'])
                    ? $product->units()->whereKey($row['id'])->firstOrFail()
                    : new ProductUnit(['product_id' => $product->id]);

                $unit->product()->associate($product);
                $unit->fill([
                    'name' => $row['name'],
                    'code' => $row['code'] ?? null,
                    'base_qty' => (int) $row['base_qty'],
                    'is_base' => (int) $row['base_qty'] === 1,
                    'purchasable' => (bool) ($row['purchasable'] ?? true),
                    'sellable' => (bool) ($row['sellable'] ?? true),
                    'default_purchase' => (bool) ($row['default_purchase'] ?? false),
                    'default_sale' => (bool) ($row['default_sale'] ?? false),
                ]);
                $this->saveUnit($unit);
                $keptIds[] = $unit->id;

                $this->syncBarcodes($unit, $row['barcodes'] ?? []);
            }

            $this->deleteRemovedUnits($product, $keptIds);

            return $product->load('units.barcodes');
        });
    }

    private function saveUnit(ProductUnit $unit): void
    {
        // A pack size's base quantity is immutable once saved: historic
        // quantities entered in that unit would otherwise change meaning.
        if ($unit->exists && $unit->isDirty('base_qty')) {
            throw ValidationException::withMessages([
                'units' => "The quantity of pack size \"{$unit->getOriginal('name')}\" cannot be changed. Add a new pack size instead.",
            ]);
        }

        $unit->save();
    }

    /** @param  list<string>  $codes */
    private function syncBarcodes(ProductUnit $unit, array $codes): void
    {
        $unit->barcodes()->whereNotIn('barcode', $codes)->get()->each->delete();

        $existing = $unit->barcodes()->pluck('barcode')->all();
        foreach (array_diff($codes, $existing) as $code) {
            $unit->barcodes()->create(['barcode' => $code, 'type' => Barcode::guessType($code)]);
        }
    }

    /** @param  list<string>  $codes */
    private function assertBarcodesFree(Product $product, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        $taken = Barcode::whereIn('barcode', $codes)
            ->whereHas('unit', fn ($q) => $q->where('product_id', '!=', $product->id))
            ->with('unit.product:id,sku')
            ->get();

        if ($taken->isNotEmpty()) {
            $list = $taken->map(fn (Barcode $b) => "{$b->barcode} (on {$b->unit->product->sku})")->implode(', ');
            throw ValidationException::withMessages(['units' => "Barcode already in use: {$list}."]);
        }
    }

    /** @param  list<int>  $keptIds */
    private function deleteRemovedUnits(Product $product, array $keptIds): void
    {
        foreach ($product->units()->whereNotIn('id', $keptIds)->get() as $unit) {
            try {
                $unit->barcodes()->delete();
                $unit->delete();
            } catch (QueryException) {
                // Referenced by orders/receipts/listings (FK restrict).
                throw ValidationException::withMessages([
                    'units' => "Pack size \"{$unit->name}\" is in use and cannot be removed.",
                ]);
            }
        }
    }
}
