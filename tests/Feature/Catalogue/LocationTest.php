<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Models\Location;
use Database\Seeders\DefaultSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_site_seeder_is_idempotent()
    {
        $this->seed(DefaultSiteSeeder::class);
        $this->seed(DefaultSiteSeeder::class);

        $this->assertSame(1, Location::where('type', 'site')->count());
        $this->assertSame(['QUARANTINE', 'RECEIVING', 'STOCK'], Location::bins()->orderBy('code')->pluck('code')->all());
        $this->assertSame(['STOCK'], Location::sellable()->pluck('code')->all());
    }

    public function test_manager_can_add_zone_and_bin()
    {
        $this->seed(DefaultSiteSeeder::class);
        $site = Location::where('code', 'MAIN')->first();
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);

        $this->actingAs($manager)->post(route('locations.store'), ['type' => 'zone', 'parent_id' => $site->id, 'code' => 'cool', 'name' => 'Cool room'])
            ->assertSessionHasNoErrors();
        $zone = Location::where('code', 'COOL')->firstOrFail();

        $this->actingAs($manager)->post(route('locations.store'), ['type' => 'bin', 'parent_id' => $zone->id, 'code' => 'C-01', 'name' => 'Shelf 1', 'is_sellable' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('MAIN / COOL / C-01', Location::where('code', 'C-01')->first()->path());
    }

    public function test_hierarchy_rules_are_enforced()
    {
        $this->seed(DefaultSiteSeeder::class);
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $bin = Location::where('code', 'STOCK')->first();
        $site = Location::where('code', 'MAIN')->first();

        $this->actingAs($manager)->post(route('locations.store'), ['type' => 'bin', 'parent_id' => $bin->id, 'code' => 'X', 'name' => 'X'])
            ->assertSessionHasErrors('parent_id');
        $this->actingAs($manager)->post(route('locations.store'), ['type' => 'bin', 'code' => 'Y', 'name' => 'Y'])
            ->assertSessionHasErrors('parent_id');
        $this->actingAs($manager)->put(route('locations.update', $site), ['type' => 'bin', 'parent_id' => null, 'code' => 'MAIN', 'name' => 'x'])
            ->assertSessionHasErrors();
    }

    public function test_quarantine_bins_are_never_sellable()
    {
        $this->seed(DefaultSiteSeeder::class);
        $site = Location::where('code', 'MAIN')->first();

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))
            ->post(route('locations.store'), ['type' => 'bin', 'parent_id' => $site->id, 'code' => 'Q2', 'name' => 'Q2', 'is_quarantine' => true, 'is_sellable' => true]);

        $this->assertFalse(Location::where('code', 'Q2')->first()->is_sellable);
    }

    public function test_staff_cannot_manage_locations()
    {
        $this->seed(DefaultSiteSeeder::class);
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        $this->actingAs($staff)->get(route('locations.index'))->assertOk();
        $this->actingAs($staff)->post(route('locations.store'), ['type' => 'site', 'code' => 'S2', 'name' => 'S2'])->assertForbidden();
    }
}
