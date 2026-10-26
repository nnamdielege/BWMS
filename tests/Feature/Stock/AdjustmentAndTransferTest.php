<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class AdjustmentAndTransferTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
    }

    public function test_write_off_via_http_and_double_submit_is_idempotent()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10);
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $payload = ['client_token' => (string) Str::uuid(), 'location_id' => $this->bin()->id, 'qty' => -3, 'reason_code_id' => $this->reason('DAMAGED')->id];

        $this->actingAs($manager)->post(route('stock.adjust', $p), $payload)->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('stock.adjust', $p), $payload)->assertSessionHasNoErrors();

        $this->assertSame(7, $this->balance($p));
        $movement = StockMovement::where('type', 'write_off')->sole();
        $this->assertSame($manager->id, $movement->created_by);
    }

    public function test_adjustment_cannot_take_stock_negative()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 1);

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))
            ->post(route('stock.adjust', $p), ['client_token' => (string) Str::uuid(), 'location_id' => $this->bin()->id, 'qty' => -2, 'reason_code_id' => $this->reason('DAMAGED')->id])
            ->assertSessionHasErrors('qty');

        $this->assertSame(1, $this->balance($p));
    }

    public function test_found_stock_creates_batch_for_tracked_products()
    {
        $p = Product::factory()->batchTracked()->create();

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))
            ->post(route('stock.adjust', $p), [
                'client_token' => (string) Str::uuid(), 'location_id' => $this->bin()->id, 'qty' => 6,
                'reason_code_id' => $this->reason('FOUND')->id, 'note' => 'Behind pallet',
                'new_batch' => ['lot_code' => 'L77', 'best_before' => '2027-01-31'],
            ])->assertSessionHasNoErrors();

        $batch = $p->batches()->sole();
        $this->assertSame('L77', $batch->lot_code);
        $this->assertSame(6, $this->balance($p, null, $batch));
    }

    public function test_staff_cannot_adjust_but_can_transfer()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10);
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        $this->actingAs($staff)->post(route('stock.adjust', $p), ['client_token' => (string) Str::uuid(), 'location_id' => $this->bin()->id, 'qty' => -1, 'reason_code_id' => $this->reason('DAMAGED')->id])
            ->assertForbidden();

        $this->actingAs($staff)->post(route('stock.transfer', $p), [
            'client_token' => (string) Str::uuid(), 'from_location_id' => $this->bin()->id, 'to_location_id' => $this->bin('QUARANTINE')->id, 'qty' => 4,
        ])->assertSessionHasNoErrors();

        $this->assertSame(6, $this->balance($p));
        $this->assertSame(4, $this->balance($p, $this->bin('QUARANTINE')));
    }

    public function test_transfer_cannot_exceed_bin_stock_and_is_atomic()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 2);

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))->post(route('stock.transfer', $p), [
            'client_token' => (string) Str::uuid(), 'from_location_id' => $this->bin()->id, 'to_location_id' => $this->bin('QUARANTINE')->id, 'qty' => 5,
        ])->assertSessionHasErrors('qty');

        $this->assertSame(2, $this->balance($p));
        $this->assertSame(0, $this->balance($p, $this->bin('QUARANTINE')));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_stock_pages_render_for_viewers()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 3);
        $viewer = $this->userWithRole(Permissions::ROLE_READ_ONLY);

        $this->actingAs($viewer)->get(route('stock.index'))->assertOk();
        $this->actingAs($viewer)->get(route('stock.show', $p))->assertOk();
        $this->actingAs($viewer)->get(route('stock.movements', ['sku' => $p->sku]))->assertOk();
    }
}
