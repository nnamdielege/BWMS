<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'parent_id' => fn () => Location::firstOrCreate(
                ['parent_id' => null, 'code' => 'TEST'],
                ['type' => Location::TYPE_SITE, 'name' => 'Test site', 'is_sellable' => false],
            )->id,
            'type' => Location::TYPE_BIN,
            'code' => strtoupper(fake()->unique()->bothify('BIN-###')),
            'name' => 'Bin',
            'is_sellable' => true,
            'is_quarantine' => false,
            'is_receiving' => false,
            'is_active' => true,
        ];
    }

    public function quarantine(): static
    {
        return $this->state(['is_quarantine' => true, 'is_sellable' => false]);
    }

    public function notSellable(): static
    {
        return $this->state(['is_sellable' => false]);
    }
}
