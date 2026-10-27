<?php

namespace Tests\Support;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StockLedger;
use Database\Seeders\DefaultSiteSeeder;
use Database\Seeders\ReasonCodeSeeder;
use Illuminate\Support\Str;

trait StockFixtures
{
    protected function seedStockReferenceData(): void
    {
        $this->seed([DefaultSiteSeeder::class, ReasonCodeSeeder::class]);
    }

    protected function site(?string $code = null): Location
    {
        return Location::whereNull('parent_id')->where('code', $code ?? config('bwms.default_site.code'))->firstOrFail();
    }

    /** A bin by code within a site (default site unless given); codes repeat across sites. */
    protected function bin(string $code = 'STOCK', ?Location $site = null): Location
    {
        return Location::where('parent_id', ($site ?? $this->site())->id)->where('code', $code)->firstOrFail();
    }

    /** An optional, non-sellable RECEIVING bin (not seeded by default; D-14 put-away option). */
    protected function receivingBin(?Location $site = null): Location
    {
        $site ??= $this->site();

        return Location::firstOrCreate(['parent_id' => $site->id, 'code' => 'RECEIVING'], [
            'type' => Location::TYPE_BIN, 'name' => 'Receiving dock', 'is_receiving' => true, 'is_sellable' => false, 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    protected function reason(string $code): ReasonCode
    {
        return ReasonCode::where('code', $code)->firstOrFail();
    }

    protected function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    protected function batch(Product $product, string $lot, ?string $bestBefore): Batch
    {
        return Batch::findOrCreateFor($product, $lot, $bestBefore);
    }

    /** Put stock in via a receipt movement (no PO needed at this layer). */
    protected function stockIn(Product $product, int $qty, ?Location $bin = null, ?Batch $batch = null): StockMovement
    {
        return $this->ledger()->postOne(new MovementDraft(
            product: $product,
            location: $bin ?? $this->bin(),
            qty: $qty,
            type: MovementType::Receipt,
            idempotencyKey: 'test:'.Str::uuid(),
            batch: $batch,
        ));
    }

    protected function balance(Product $product, ?Location $bin = null, ?Batch $batch = null): int
    {
        return (int) StockBalance::where('product_id', $product->id)
            ->where('location_id', ($bin ?? $this->bin())->id)
            ->where('batch_key', $batch?->id ?? 0)
            ->value('on_hand');
    }

    protected function ledgerSum(Product $product, ?Location $bin = null, ?Batch $batch = null): int
    {
        return (int) StockMovement::where('product_id', $product->id)
            ->where('location_id', ($bin ?? $this->bin())->id)
            ->where('batch_id', $batch?->id)
            ->sum('qty');
    }
}
