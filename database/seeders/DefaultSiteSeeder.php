<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Idempotently ensures one site with the minimum bins every flow needs.
 * Spec D-6/Q6: model multi-location, default to one site.
 */
class DefaultSiteSeeder extends Seeder
{
    public function run(): void
    {
        $site = Location::firstOrCreate(
            ['parent_id' => null, 'code' => 'MAIN'],
            ['type' => Location::TYPE_SITE, 'name' => 'Main warehouse', 'is_sellable' => false],
        );

        $bins = [
            ['code' => 'RECEIVING', 'name' => 'Receiving dock', 'is_receiving' => true, 'is_sellable' => false, 'sort_order' => 1],
            ['code' => 'STOCK', 'name' => 'General stock', 'is_sellable' => true, 'sort_order' => 2],
            ['code' => 'QUARANTINE', 'name' => 'Quarantine / damaged', 'is_quarantine' => true, 'is_sellable' => false, 'sort_order' => 9],
        ];

        foreach ($bins as $bin) {
            Location::firstOrCreate(
                ['parent_id' => $site->id, 'code' => $bin['code']],
                ['type' => Location::TYPE_BIN, ...$bin],
            );
        }
    }
}
