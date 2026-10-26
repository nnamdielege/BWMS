<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Idempotently ensures the default site exists with the minimum bins every
 * flow needs. Multi-site is fully supported; this only seeds the first one
 * (Clontarf Warehouse). Bin codes are unique per site, not globally.
 */
class DefaultSiteSeeder extends Seeder
{
    public function run(): void
    {
        $site = Location::firstOrCreate(
            ['parent_id' => null, 'code' => config('bwms.default_site.code')],
            ['type' => Location::TYPE_SITE, 'name' => config('bwms.default_site.name'), 'is_sellable' => false],
        );

        self::seedStandardBins($site);
    }

    /** RECEIVING, STOCK and QUARANTINE bins under a site (also used when adding sites). */
    public static function seedStandardBins(Location $site): void
    {
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
