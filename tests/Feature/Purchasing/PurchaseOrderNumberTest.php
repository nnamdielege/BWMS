<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** PO-YYYYMMDD-NNNN: Brisbane creation date, sequence restarting at 0001 each day. */
class PurchaseOrderNumberTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $buyer;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->buyer = $this->userWithRole(Permissions::ROLE_PURCHASING);
        $product = Product::factory()->withPack('Carton', 12)->create();
        $this->data = [
            'supplier_id' => Supplier::factory()->create()->id,
            'site_id' => $this->site()->id,
            'order_date' => '2026-11-13',
            'lines' => [['product_id' => $product->id, 'product_unit_id' => ProductFactory::unitOf($product, 12)->id, 'qty_ordered' => 1, 'unit_cost' => '5.00']],
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function create(string $brisbaneTime): string
    {
        Carbon::setTestNow(Carbon::parse($brisbaneTime, 'Australia/Brisbane'));

        return app(PurchaseOrderService::class)->saveDraft(null, $this->data, $this->buyer)->number;
    }

    public function test_the_first_po_of_a_day_is_0001()
    {
        $this->assertSame('PO-20261113-0001', $this->create('2026-11-13 08:15'));
    }

    public function test_several_in_one_day_count_up_and_editing_keeps_the_number()
    {
        $this->assertSame('PO-20261113-0001', $this->create('2026-11-13 08:00'));
        $this->assertSame('PO-20261113-0002', $this->create('2026-11-13 11:30'));
        $this->assertSame('PO-20261113-0003', $this->create('2026-11-13 16:45'));

        $po = PurchaseOrder::where('number', 'PO-20261113-0002')->sole();
        Carbon::setTestNow(Carbon::parse('2026-11-14 09:00', 'Australia/Brisbane'));
        $this->assertSame('PO-20261113-0002', app(PurchaseOrderService::class)->saveDraft($po, $this->data, $this->buyer)->number);
    }

    public function test_it_rolls_over_at_brisbane_midnight_not_utc()
    {
        $this->assertSame('PO-20261113-0001', $this->create('2026-11-13 23:59:59'));
        $this->assertSame('PO-20261114-0001', $this->create('2026-11-14 00:00:00'));
        // 09:30 Brisbane is still the 13th in UTC: the Brisbane date counts.
        $this->assertSame('PO-20261114-0002', $this->create('2026-11-14 09:30'));
        $this->assertSame('PO-20261115-0001', $this->create('2026-11-15 00:00:01'));
    }

    public function test_existing_numbers_are_kept_and_never_reused()
    {
        // An old-format PO and a legacy import that already used today's first number.
        $old = PurchaseOrder::where('number', $this->create('2026-11-01 10:00'))->sole();
        $old->forceFill(['number' => 'PO-2026-00007', 'status' => PurchaseOrderStatus::Sent])->save();
        $legacy = PurchaseOrder::where('number', $this->create('2026-11-02 10:00'))->sole();
        $legacy->forceFill(['number' => 'PO-20261113-0001'])->save();

        $this->assertSame('PO-20261113-0002', $this->create('2026-11-13 10:00'));
        $this->assertSame('PO-2026-00007', $old->fresh()->number);
    }

    public function test_the_list_and_pdf_use_the_number()
    {
        $number = $this->create('2026-11-13 10:00');
        $po = PurchaseOrder::where('number', $number)->sole();

        $this->actingAs($this->buyer)->get(route('purchase-orders.index'))
            ->assertInertia(fn ($page) => $page->where('orders.data.0.number', 'PO-20261113-0001'));
        $this->actingAs($this->buyer)->get(route('purchase-orders.pdf', $po))
            ->assertHeader('Content-Disposition', 'inline; filename="PO-20261113-0001.pdf"');
    }
}
