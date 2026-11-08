<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Stock\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class OversoldRecheckTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->milk = Product::factory()->create(['sku' => 'MILK']);
    }

    private function oversold(string $ref, int $qty): Order
    {
        $order = $this->channelOrder(OrderChannel::Ordermentum, $ref, 1, [[$this->milk, $qty]]);
        $this->assertTrue($order->oversold);

        return $order;
    }

    private function cleared(Order $order): int
    {
        return AuditLog::where('event', 'order_oversold_cleared')->where('auditable_id', $order->id)->count();
    }

    public function test_receiving_clears_the_flag_once_the_order_is_fully_covered()
    {
        $this->stockIn($this->milk, 2);
        $order = $this->oversold('om-1', 5);

        $this->stockIn($this->milk, 2);
        $this->assertTrue($order->fresh()->oversold, '4 of 5: still short');
        $this->assertSame(0, $this->cleared($order));

        $this->stockIn($this->milk, 1);
        $this->assertFalse($order->fresh()->oversold);
        $this->assertNotNull($order->fresh()->oversold_at, 'when it was oversold is kept');
        $entry = AuditLog::where('event', 'order_oversold_cleared')->sole();
        $this->assertSame([$order->id, ['oversold' => true], ['oversold' => false]], [(int) $entry->auditable_id, $entry->old_values, $entry->new_values]);
        $this->assertSame('stock_changed', $entry->meta['trigger']);
    }

    public function test_stock_goes_to_the_oldest_flagged_order_first()
    {
        $older = $this->oversold('om-1', 5);
        $newer = $this->oversold('om-2', 5);

        $this->stockIn($this->milk, 5);

        $this->assertFalse($older->fresh()->oversold);
        $this->assertTrue($newer->fresh()->oversold);
    }

    public function test_an_order_cancelled_elsewhere_frees_stock_for_a_flagged_order()
    {
        $this->stockIn($this->milk, 5);
        $this->channelOrder(OrderChannel::Portal, 'P-1', 1, [[$this->milk, 5]]);
        $flagged = $this->oversold('om-1', 3);

        $this->channelOrder(OrderChannel::Portal, 'P-1', 2, [[$this->milk, 5]], 'cancelled');

        $this->assertFalse($flagged->fresh()->oversold);
    }

    public function test_a_posted_stocktake_finding_stock_clears_the_flag_and_a_shortage_keeps_it()
    {
        $this->stockIn($this->milk, 2);
        $order = $this->oversold('om-1', 4);
        $stocktakes = app(StocktakeService::class);

        $shortCount = $stocktakes->create($this->manager, $this->bin());
        $stocktakes->recordCount($shortCount->lines()->sole(), 3, $this->manager);
        $stocktakes->post($shortCount, $this->manager);
        $this->assertTrue($order->fresh()->oversold, '3 of 4');

        $found = $stocktakes->create($this->manager, $this->bin());
        $stocktakes->recordCount($found->lines()->sole(), 4, $this->manager);
        $stocktakes->post($found, $this->manager);
        $this->assertFalse($order->fresh()->oversold);
    }

    public function test_the_command_dry_run_lists_stale_flags_and_the_real_run_clears_them()
    {
        $this->stockIn($this->milk, 10);
        $stale = $this->channelOrder(OrderChannel::Portal, 'P-1', 1, [[$this->milk, 4]]);
        $cancelled = $this->channelOrder(OrderChannel::Portal, 'P-2', 1, [[$this->milk, 1]]);
        $this->channelOrder(OrderChannel::Portal, 'P-2', 2, [[$this->milk, 1]], 'cancelled');
        $cancelled->refresh()->forceFill(['oversold' => true])->save();
        $short = $this->channelOrder(OrderChannel::Ordermentum, 'om-1', 1, [[$this->milk, 9]]);
        // A flag from before they re-checked themselves.
        $stale->forceFill(['oversold' => true, 'oversold_at' => now()])->save();
        $this->assertTrue($short->fresh()->oversold, '13 reserved against 10');

        $this->assertSame(0, Artisan::call('orders:recheck-oversold', ['--dry-run' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('2 flagged open order(s): 1 would be cleared, 1 still short. Dry run: nothing changed.', $out);
        $this->assertMatchesRegularExpression("/{$stale->number}.*would clear/", $out);
        $this->assertMatchesRegularExpression("/{$short->number}.*still short.*MILK/", $out);
        $this->assertTrue($stale->fresh()->oversold);
        $this->assertSame(0, AuditLog::where('event', 'order_oversold_cleared')->count());

        $this->assertSame(0, Artisan::call('orders:recheck-oversold'));
        $this->assertStringContainsString('1 cleared, 1 still short.', Artisan::output());
        $this->assertFalse($stale->fresh()->oversold, 'the stale flag falls on the flagged order holding stock last, the Ordermentum one');
        $this->assertTrue($short->fresh()->oversold);
        $this->assertSame('orders:recheck-oversold', AuditLog::where('event', 'order_oversold_cleared')->sole()->meta['trigger']);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertTrue($cancelled->fresh()->oversold, 'closed orders are left alone');
    }
}
