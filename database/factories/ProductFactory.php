<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'name' => ucwords(fake()->words(3, true)),
            'base_unit' => 'each',
            'is_batch_tracked' => false,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Product $product) {
            if (! $product->units()->exists()) {
                $product->units()->create(['name' => 'Each', 'base_qty' => 1, 'is_base' => true, 'default_purchase' => true, 'default_sale' => true]);
            }
        });
    }

    public function batchTracked(int $shelfLifeDays = 30): static
    {
        return $this->state(['is_batch_tracked' => true, 'shelf_life_days' => $shelfLifeDays]);
    }

    /** Adds a pack size (e.g. carton of 12) after creation. */
    public function withPack(string $name = 'Carton', int $baseQty = 12): static
    {
        return $this->afterCreating(fn (Product $p) => $p->units()->create(['name' => $name, 'base_qty' => $baseQty]))
            ->afterCreating(fn (Product $p) => $p->unsetRelation('units'));
    }

    public static function unitOf(Product $product, int $baseQty): ProductUnit
    {
        return $product->units()->where('base_qty', $baseQty)->firstOrFail();
    }
}
