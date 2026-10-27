<?php

namespace Tests\Feature\Warehouse;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\Order;
use App\Models\Product;
use App\Services\Delivery\DeliveryScheduler;
use Carbon\CarbonImmutable;
use Database\Seeders\DeliveryAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class DeliverySchedulerTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->seed(DeliveryAreaSeeder::class);
    }

    private function scheduler(): DeliveryScheduler
    {
        return app(DeliveryScheduler::class);
    }

    private function area(string $code): DeliveryArea
    {
        return DeliveryArea::where('code', $code)->sole();
    }

    /** Placed at a Brisbane wall-clock time; returns the run date chosen. */
    private function next(string $code, string $placedBrisbane): string
    {
        return $this->scheduler()->nextRunDate($this->area($code), CarbonImmutable::parse($placedBrisbane, 'Australia/Brisbane'))->toDateString();
    }

    public function test_fixed_weekday_areas_honour_the_1pm_cutoff_the_business_day_before()
    {
        // 2026-11-02 is a Monday.
        $this->assertSame('2026-11-03', $this->next('GOLDCOAST', '2026-11-02 12:59'), 'Gold Coast is Tuesday; in before Monday 1 pm');
        $this->assertSame('2026-11-10', $this->next('GOLDCOAST', '2026-11-02 13:00'), 'Missed the cutoff: next Tuesday');
        $this->assertSame('2026-11-09', $this->next('NORTH', '2026-11-06 12:00'), 'Monday run: cutoff is Friday 1 pm');
        $this->assertSame('2026-11-16', $this->next('NORTH', '2026-11-06 14:00'));
        $this->assertSame('2026-11-06', $this->next('CBDSOUTH', '2026-11-05 09:00'), 'Friday area');
        $this->assertSame('2026-11-04', $this->next('SUNCOAST', '2026-11-02 10:00'), 'Wednesday area');
    }

    public function test_northern_zones_get_next_day_delivery_on_any_weekday()
    {
        $this->assertSame('2026-11-04', $this->next('ZONE1', '2026-11-03 10:00'), 'Tue morning -> Wed');
        $this->assertSame('2026-11-05', $this->next('ZONE2', '2026-11-03 13:30'), 'Tue after 1 pm -> Thu');
        $this->assertSame('2026-11-09', $this->next('ZONE3', '2026-11-06 12:00'), 'Fri before 1 pm -> Mon');
        $this->assertSame('2026-11-10', $this->next('ZONE1', '2026-11-06 14:00'), 'Fri after 1 pm -> Tue (Monday cutoff was Friday 1 pm)');
        $this->assertSame('2026-11-10', $this->next('ZONE1', '2026-11-07 10:00'), 'Saturday is past the Monday cutoff (Friday 1 pm) -> Tuesday');
    }

    public function test_area_matching_prefers_a_suburb_entry_over_the_whole_postcode()
    {
        $this->area('NORTH')->places()->create(['postcode' => '4019', 'suburb' => 'Clontarf Special']);

        $this->assertSame('ZONE1', $this->scheduler()->areaFor('4019', 'Margate')->code);
        $this->assertSame('NORTH', $this->scheduler()->areaFor('4019', 'clontarf special')->code);
        $this->assertNull($this->scheduler()->areaFor('9999', null));
        $this->assertNull($this->scheduler()->areaFor('abc', null));
    }

    public function test_orders_get_runs_from_their_address_requested_date_or_stay_unassigned()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00', 'Australia/Brisbane'));
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $p = Product::factory()->create();
        $this->stockIn($p, 50);

        $gold = Customer::factory()->create(['delivery_postcode' => '4217', 'delivery_suburb' => 'Surfers Paradise']);
        $order = $this->manualOrder($manager, $p, 1, customer: $gold);
        $this->assertSame(['2026-11-03', 'GOLDCOAST', 'auto'], [$order->deliveryRun->run_date->toDateString(), $order->deliveryRun->area->code, $order->run_assignment]);

        $nowhere = Customer::factory()->create(['delivery_postcode' => '0800']);
        $this->assertNull($this->manualOrder($manager, $p, 1, customer: $nowhere)->delivery_run_id);

        // Ordermentum order with its own delivery address and requested date.
        $om = $this->channelOrder(OrderChannel::Ordermentum, 'om-1', 1, [[$p, 1]], delivery: ['postcode' => '4020', 'suburb' => 'Redcliffe'], date: '2026-11-05');
        $this->assertSame(['2026-11-05', 'ZONE1'], [$om->deliveryRun->run_date->toDateString(), $om->deliveryRun->area->code]);

        // Manual override sticks across later channel updates.
        app(DeliveryScheduler::class)->assignManually($om, $this->area('NORTH'), CarbonImmutable::parse('2026-11-09'));
        $om = $this->channelOrder(OrderChannel::Ordermentum, 'om-1', 2, [[$p, 2]], delivery: ['postcode' => '4020', 'suburb' => 'Redcliffe'], date: '2026-11-05');
        $this->assertSame(['2026-11-09', 'NORTH', 'manual'], [$om->deliveryRun->run_date->toDateString(), $om->deliveryRun->area->code, $om->run_assignment]);
        Carbon::setTestNow();
    }

    public function test_customer_address_change_moves_waiting_orders_and_manual_override_from_the_order_page()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00', 'Australia/Brisbane'));
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $p = Product::factory()->create();
        $this->stockIn($p, 10);
        $c = Customer::factory()->create(['delivery_postcode' => '0800']);
        $order = $this->manualOrder($manager, $p, 1, customer: $c);
        $this->assertNull($order->delivery_run_id);

        $this->actingAs($manager)->put(route('customers.update', $c), [
            'code' => $c->code, 'name' => $c->name, 'is_active' => true, 'delivery_postcode' => '4300', 'delivery_suburb' => 'Ipswich',
        ])->assertSessionHasNoErrors();
        $this->assertSame('WEST', $order->fresh()->deliveryRun->area->code);

        $this->actingAs($manager)->put(route('orders.run', $order), ['delivery_area_id' => $this->area('CBDSOUTH')->id, 'run_date' => '2026-11-06'])->assertSessionHasNoErrors();
        $this->assertSame(['CBDSOUTH', 'manual'], [$order->fresh()->deliveryRun->area->code, $order->fresh()->run_assignment]);
        Carbon::setTestNow();
    }
}
