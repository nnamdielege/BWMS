<?php

namespace App\Services\Reports;

use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductUnit;

/**
 * Cost and sell price of one product, per the pack the reports count it in.
 *
 * The pack is the default sale pack (the one the default sell price is for), else the
 * largest sellable pack, else the base unit. Cost is avg_cost (per base unit) × pack
 * size. Sell is the product's default sell price; without one, the Ordermentum listing
 * price restated for the pack. Load `units` and `channelListings` first.
 */
final class PackPricing
{
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_ORDERMENTUM = 'ordermentum';

    public function __construct(
        public readonly string $packName,
        public readonly int $packQty,
        public readonly ?float $costPerPack,
        public readonly ?float $sellPerPack,
        public readonly ?string $sellSource,
    ) {}

    public static function for(Product $product): self
    {
        $pack = self::reportPack($product);
        $qty = $pack?->base_qty ?? 1;
        $cost = (float) $product->avg_cost > 0 ? MarginMath::perPack((float) $product->avg_cost, $qty) : null;
        [$sell, $source] = self::sell($product, $qty);

        return new self($pack?->name ?? $product->base_unit, $qty, $cost, $sell, $source);
    }

    /** Sell price for any pack of the product, e.g. an order line with no price of its own. */
    public static function sellFor(Product $product, int $packQty): ?float
    {
        return self::sell($product, $packQty)[0];
    }

    public static function reportPack(Product $product): ?ProductUnit
    {
        return $product->units->firstWhere('default_sale', true)
            ?? $product->units->where('sellable', true)->sortByDesc('base_qty')->first()
            ?? $product->units->sortBy('base_qty')->first();
    }

    /** @return array{0: ?float, 1: ?string} */
    private static function sell(Product $product, int $packQty): array
    {
        if ((float) $product->sell_price > 0) {
            $defaultQty = self::reportPack($product)?->base_qty ?? 1;

            return [MarginMath::convertPackPrice((float) $product->sell_price, $defaultQty, $packQty), self::SOURCE_DEFAULT];
        }

        /** @var ?ChannelListing $listing the one on this very pack first, so nothing is restated if it needn't be */
        $listing = $product->channelListings
            ->where('channel', 'ordermentum')->filter(fn (ChannelListing $l) => (float) $l->price > 0)
            ->sortBy(fn (ChannelListing $l) => $product->units->firstWhere('id', $l->product_unit_id)?->base_qty === $packQty ? 0 : 1)
            ->first();
        $listingQty = $listing ? $product->units->firstWhere('id', $listing->product_unit_id)?->base_qty : null;
        if (! $listing || ! $listingQty) {
            return [null, null];
        }

        return [MarginMath::convertPackPrice((float) $listing->price, $listingQty, $packQty), self::SOURCE_ORDERMENTUM];
    }
}
