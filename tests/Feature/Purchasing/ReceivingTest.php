<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receipt;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Stock\StockQuery;
use Database\Factories\ProductFactory;
use Database\Seeders\DefaultSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class ReceivingTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $buyer;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->buyer = $this->userWithRole(Permissions::ROLE_PURCHASING);
        $this->staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
    }

    /** A sent PO for 10 cartons (x12) at $24/carton. */
    private function sentPo(?Product $product = null, int $cartons = 10): PurchaseOrder
    {
        $product ??= Product::factory()->withPack('Carton', 12)->create();
        $service = app(PurchaseOrderService::class);
        $po = $service->saveDraft(null, [
            'supplier_id' => Supplier::factory()->create()->id,
            'site_id' => $this->site()->id,
            'order_date' => '2026-11-02',
            'lines' => [['product_id' => $product->id, 'product_unit_id' => ProductFactory::unitOf($product, 12)->id, 'qty_ordered' => $cartons, 'unit_cost' => '24.00']],
        ], $this->buyer);

        return $service->markSent($po, $this->buyer);
    }

    private function receive(PurchaseOrder $po, array $line, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->post(route('purchase-orders.receipts.store', $po), array_merge([
            'client_token' => (string) Str::uuid(),
            'lines' => [array_merge(['purchase_order_line_id' => $po->lines->first()->id], $line)],
        ], $extra));
    }

    public function test_partial_then_full_receipt_moves_stock_and_status()
    {
        $po = $this->sentPo();
        $product = $po->lines->first()->product;

        $this->receive($po, ['qty_accepted_base' => 48])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);
        $this->assertSame(48, $this->balance($product, $this->bin('STOCK')));

        $movement = StockMovement::where('type', MovementType::Receipt)->sole();
        $this->assertSame('2.0000', (string) $movement->unit_cost); // $24 / 12
        $this->assertSame('receipt', $movement->source_type);

        $this->receive($po, ['qty_accepted_base' => 72, 'location_id' => $this->bin('RECEIVING')->id])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);
        $this->assertSame(72, $this->balance($product, $this->bin('RECEIVING')));
    }

    public function test_receiving_requires_a_sent_po()
    {
        $product = Product::factory()->withPack('Carton', 12)->create();
        $draft = app(PurchaseOrderService::class)->saveDraft(null, [
            'supplier_id' => Supplier::factory()->create()->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-02',
            'lines' => [['product_id' => $product->id, 'product_unit_id' => ProductFactory::unitOf($product, 12)->id, 'qty_ordered' => 1, 'unit_cost' => '1']],
        ], $this->buyer);

        $this->receive($draft, ['qty_accepted_base' => 12])->assertSessionHasErrors('receipt');
        $this->assertSame(0, StockMovement::count());
    }

    public function test_damaged_goes_to_quarantine_and_rejected_is_not_stocked()
    {
        $po = $this->sentPo();
        $product = $po->lines->first()->product;

        $this->receive($po, ['qty_accepted_base' => 100, 'qty_damaged_base' => 6, 'qty_rejected_base' => 14, 'discrepancy_reason' => 'damaged', 'note' => 'Crushed pallet corner'])
            ->assertSessionHasNoErrors();

        $this->assertSame(100, $this->balance($product, $this->bin('STOCK')));
        $this->assertSame(6, $this->balance($product, $this->bin('QUARANTINE')));
        $this->assertSame(100, app(StockQuery::class)->sellableOnHand($product)); // lands sellable by default; quarantine never is
        $line = $po->lines->first()->fresh();
        $this->assertSame(106, $line->qty_received_base);
        $this->assertSame(14, $line->qty_rejected_base);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);
    }

    public function test_rejection_needs_a_reason()
    {
        $po = $this->sentPo();

        $this->receive($po, ['qty_rejected_base' => 12])->assertSessionHasErrors('receipt');
    }

    public function test_over_receipt_is_blocked_unless_a_manager_confirms()
    {
        $po = $this->sentPo(cartons: 1); // 12 ordered

        $this->receive($po, ['qty_accepted_base' => 13])->assertSessionHasErrors('receipt');
        $this->receive($po, ['qty_accepted_base' => 13], ['allow_over_receipt' => true])->assertSessionHasErrors('receipt'); // staff can't confirm

        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->receive($po, ['qty_accepted_base' => 13], ['allow_over_receipt' => true], $manager)->assertSessionHasNoErrors();
        $this->assertSame('over', Receipt::sole()->lines->first()->discrepancy_reason);
        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);
    }

    public function test_batch_tracked_products_need_lot_and_best_before_and_batch_records_supplier()
    {
        $product = Product::factory()->batchTracked()->withPack('Carton', 12)->create();
        $po = $this->sentPo($product);

        $this->receive($po, ['qty_accepted_base' => 12])->assertSessionHasErrors('receipt');
        $this->receive($po, ['qty_accepted_base' => 12, 'lot_code' => 'K-2611', 'best_before' => '2027-01-15'])->assertSessionHasNoErrors();

        $batch = $product->batches()->sole();
        $this->assertSame($po->supplier_id, $batch->supplier_id);
        $this->assertSame(12, $this->balance($product, $this->bin('STOCK'), $batch));
    }

    public function test_two_lots_on_one_po_line()
    {
        $product = Product::factory()->batchTracked()->withPack('Carton', 12)->create();
        $po = $this->sentPo($product, cartons: 2);
        $lineId = $po->lines->first()->id;

        $this->actingAs($this->staff)->post(route('purchase-orders.receipts.store', $po), [
            'client_token' => (string) Str::uuid(),
            'lines' => [
                ['purchase_order_line_id' => $lineId, 'qty_accepted_base' => 12, 'lot_code' => 'A', 'best_before' => '2027-01-10'],
                ['purchase_order_line_id' => $lineId, 'qty_accepted_base' => 12, 'lot_code' => 'B', 'best_before' => '2027-02-10'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $product->batches()->count());
        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);
    }

    public function test_double_submit_with_same_token_posts_once()
    {
        $po = $this->sentPo();
        $payload = ['client_token' => (string) Str::uuid(), 'lines' => [['purchase_order_line_id' => $po->lines->first()->id, 'qty_accepted_base' => 24]]];

        $this->actingAs($this->staff)->post(route('purchase-orders.receipts.store', $po), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post(route('purchase-orders.receipts.store', $po), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Receipt::count());
        $this->assertSame(24, $po->lines->first()->fresh()->qty_received_base);
    }

    public function test_destination_bin_must_belong_to_the_po_site()
    {
        $po = $this->sentPo();
        $other = Location::create(['type' => 'site', 'code' => 'SITE2', 'name' => 'Other', 'is_sellable' => false]);
        DefaultSiteSeeder::seedStandardBins($other);

        $this->receive($po, ['qty_accepted_base' => 12, 'location_id' => $this->bin('STOCK', $other)->id])->assertSessionHasErrors('receipt');
    }

    public function test_weighted_average_cost_updates_on_receipt()
    {
        $product = Product::factory()->withPack('Carton', 12)->create();
        $product->forceFill(['avg_cost' => '1.0000'])->save();
        $this->stockIn($product, 120); // 120 on hand at $1.00

        $po = $this->sentPo($product, cartons: 10); // 120 more at $2.00
        $this->receive($po, ['qty_accepted_base' => 120])->assertSessionHasNoErrors();

        $this->assertSame('1.5000', (string) $product->fresh()->avg_cost);
    }

    public function test_close_short_after_partial_receipt()
    {
        $po = $this->sentPo();
        $this->receive($po, ['qty_accepted_base' => 60]);

        $this->actingAs($this->buyer)->post(route('purchase-orders.close', $po), ['reason' => 'Supplier out of stock'])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Closed, $po->fresh()->status);
        $this->receive($po, ['qty_accepted_base' => 12])->assertSessionHasErrors('receipt');

        $this->actingAs($this->buyer)->post(route('purchase-orders.cancel', $po))->assertSessionHasErrors('po');
    }

    public function test_purchasing_role_cannot_receive_and_staff_cannot_manage()
    {
        $po = $this->sentPo();

        $this->receive($po, ['qty_accepted_base' => 12], [], $this->buyer)->assertForbidden();
        $this->actingAs($this->staff)->post(route('purchase-orders.close', $po), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->staff)->get(route('purchase-orders.receive', $po))->assertOk();
    }

    public function test_landing_bin_is_a_per_site_setting_with_receiving_and_put_away_as_an_option()
    {
        $site = $this->site();
        $this->assertSame($this->bin('STOCK')->id, $site->receive_location_id, 'Default: sellable STOCK');

        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $this->actingAs($admin)->put(route('locations.update', $site), [
            'type' => 'site', 'code' => $site->code, 'name' => $site->name, 'is_active' => true, 'receive_location_id' => $this->bin('RECEIVING')->id,
        ])->assertSessionHasNoErrors();

        $po = $this->sentPo();
        $product = $po->lines->first()->product;
        $this->receive($po, ['qty_accepted_base' => 24])->assertSessionHasNoErrors();

        $this->assertSame(24, $this->balance($product, $this->bin('RECEIVING')));
        $this->assertSame(0, app(StockQuery::class)->sellableOnHand($product), 'Not sellable until put away');
    }

    public function test_site_landing_bin_must_be_an_active_non_quarantine_bin_of_that_site()
    {
        $site = $this->site();
        $other = Location::create(['type' => 'site', 'code' => 'OTHER', 'name' => 'Other', 'is_sellable' => false]);
        DefaultSiteSeeder::seedStandardBins($other);
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $put = fn (int $binId) => $this->actingAs($admin)->put(route('locations.update', $site), [
            'type' => 'site', 'code' => $site->code, 'name' => $site->name, 'is_active' => true, 'receive_location_id' => $binId,
        ]);

        $put($this->bin('QUARANTINE')->id)->assertSessionHasErrors('receive_location_id');
        $put($this->bin('STOCK', $other)->id)->assertSessionHasErrors('receive_location_id');
        $this->assertSame($this->bin('STOCK')->id, $site->fresh()->receive_location_id);
    }

    public function test_deactivated_landing_bin_falls_back_to_a_sellable_bin()
    {
        $this->site()->update(['receive_location_id' => $this->bin('RECEIVING')->id]);
        $this->bin('RECEIVING')->update(['is_active' => false]);

        $po = $this->sentPo();
        $this->receive($po, ['qty_accepted_base' => 12])->assertSessionHasNoErrors();

        $this->assertSame(12, $this->balance($po->lines->first()->product, $this->bin('STOCK')));
    }
}
