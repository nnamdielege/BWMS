<?php

namespace App\Services\Catalogue;

use App\Models\Barcode;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Rules\Gtin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Walking the warehouse with a handheld, giving each pack its barcode. Counts
 * active stock-tracked products: a product has barcodes once any pack does,
 * because a scan of any of its packs is enough to pick it.
 */
class BarcodeCaptureService
{
    /** @return array{have: int, total: int} */
    public function progress(): array
    {
        return ['have' => $this->scope()->whereHas('units.barcodes')->count(), 'total' => $this->scope()->count()];
    }

    /**
     * Products with no barcode yet, in the order you'd walk to them: by the
     * earliest bin holding each, and those with no stock last.
     *
     * @return Collection<int, array{id: int, sku: string, name: string, bin: ?string}>
     */
    public function missing(): Collection
    {
        $products = $this->scope()->whereDoesntHave('units.barcodes')->orderBy('sku')->get(['id', 'sku', 'name']);
        $bins = StockBalance::whereIn('product_id', $products->pluck('id'))->where('on_hand', '>', 0)->with('location')->get()
            ->groupBy('product_id')->map(fn ($balances) => $balances->pluck('location')->sortBy(['walk_seq', 'code'])->first());

        return $products->sortBy(function (Product $p) use ($bins) {
            $bin = $bins[$p->id] ?? null;

            return [$bin ? 0 : 1, $bin?->walk_seq ?? 0, $bin?->code ?? '', $p->sku];
        })->map(fn (Product $p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'bin' => ($bins[$p->id] ?? null)?->code])->values();
    }

    /**
     * What a scan or typed search finds: a barcode or SKU is exact, anything else searches.
     *
     * @return Collection<int, Product>
     */
    public function find(string $query): Collection
    {
        $query = trim($query);
        $exact = Barcode::where('barcode', $query)->first()?->unit->product ?? Product::where('sku', $query)->first();

        return $exact ? collect([$exact]) : Product::search($query)->where('is_active', true)->orderBy('name')->limit(20)->get();
    }

    /** @throws ValidationException with what's wrong, for the person holding the scanner */
    public function add(ProductUnit $unit, string $code): Barcode
    {
        $code = trim($code);
        $this->refuseIf(! preg_match('/^[A-Za-z0-9\-]{1,64}$/', $code), "\"{$code}\" isn't a barcode.");
        $gtinLength = ctype_digit($code) && in_array(strlen($code), [8, 12, 13, 14], true);
        $this->refuseIf($gtinLength && ! Gtin::isValid($code), "{$code} has a wrong check digit. Scan it again.");

        $bin = Location::where('barcode', $code)->first();
        $this->refuseIf($bin !== null, "That's the label for bin {$bin?->code}, not a product.");
        $this->refuseIfTaken($code, $unit);

        try {
            return $unit->barcodes()->create(['barcode' => $code, 'type' => Barcode::guessType($code)]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                $this->refuseIfTaken($code, $unit); // scanned on another handheld in the same moment
            }
            throw $e;
        }
    }

    private function refuseIfTaken(string $code, ProductUnit $unit): void
    {
        $taken = Barcode::where('barcode', $code)->with('unit.product:id,sku,name')->first();
        if (! $taken) {
            return;
        }
        $this->refuseIf($taken->product_unit_id === $unit->id, "{$code} is already on this pack.");
        $product = $taken->unit->product;
        $this->refuseIf(true, "{$code} is already on {$product->sku} {$product->name} ({$taken->unit->name}).");
    }

    private function refuseIf(bool $condition, string $message): void
    {
        if ($condition) {
            throw ValidationException::withMessages(['barcode' => $message]);
        }
    }

    private function scope(): Builder
    {
        return Product::where('is_active', true)->where('is_stock_tracked', true);
    }
}
