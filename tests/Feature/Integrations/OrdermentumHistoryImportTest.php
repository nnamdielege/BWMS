<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Ordermentum\OrdermentumClient;
use App\Models\IntegrationInbox;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\SalesHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\OrdermentumFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** channels:ordermentum-history: sales from before BWMS into sales_history, never orders. */
class OrdermentumHistoryImportTest extends TestCase
{
    use OrdermentumFixtures, RefreshDatabase, StockFixtures;

    private Product $milk;

    /** @var list<array<string, mixed>> what the list endpoint returns */
    private array $orders;

    private int $writes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Australia/Brisbane'));
        $this->seedStockReferenceData();
        $this->configureOrdermentum(['since' => '2026-11-06']);
        $this->milk = Product::factory()->withPack('Carton', 6)->create(['sku' => 'MILK']);
        $this->listing($this->milk, 'V1', unitBaseQty: 6);

        // Delivery dates are Brisbane midnight, sent as 14:00Z the day before.
        $this->orders = [
            ['id' => 'A', 'status' => 'Paid', 'deliveryDate' => '2026-10-01T14:00:00.000Z', 'lineItems' => [['id' => 'l1', 'variantId' => 'V1', 'quantity' => 2]]],
            ['id' => 'B', 'status' => 'Cancelled', 'cancelledAt' => '2026-10-01T00:00:00Z', 'deliveryDate' => '2026-10-02T14:00:00.000Z', 'lineItems' => [['id' => 'l1', 'variantId' => 'V1', 'quantity' => 9]]],
            ['id' => 'C', 'status' => 'Paid', 'deliveryDate' => '2026-11-09T14:00:00.000Z', 'lineItems' => [['id' => 'l1', 'variantId' => 'V1', 'quantity' => 9]]], // after go-live: BWMS's own
            ['id' => 'D', 'status' => 'Paid', 'deliveryDate' => '2026-10-04T14:00:00.000Z'], // the list leaves its lines out
            ['id' => 'E', 'status' => 'Paid', 'deliveryDate' => '2025-01-01T14:00:00.000Z', 'lineItems' => [['id' => 'l1', 'variantId' => 'V1', 'quantity' => 9]]], // too old
        ];
        Http::fake(function (Request $r) {
            if ($r->method() !== 'GET') {
                $this->writes++;
            }
            if (str_contains($r->url(), '/v2/orders')) {
                return Http::response(['data' => $this->orders, 'links' => ['next' => null]]);
            }
            if (str_contains($r->url(), '/v1/orders/D')) {
                return Http::response(['id' => 'D', 'status' => 'Paid', 'deliveryDate' => '2026-10-04T14:00:00.000Z',
                    'lineItems' => [['id' => 'l1', 'variantId' => 'V1', 'quantity' => 1], ['id' => 'l2', 'variantId' => 'UNMAPPED', 'quantity' => 3]]]);
            }

            if (preg_match('#/v1/orders/(F\d)#', $r->url(), $m)) {
                return Http::response(['id' => $m[1], 'status' => 'Paid', 'deliveryDate' => '2026-10-09T14:00:00.000Z', 'lineItems' => [['id' => 'l1', 'variantId' => 'V1', 'quantity' => 1]]]);
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rows(): array
    {
        return SalesHistory::orderBy('external_ref')->orderBy('line_ref')->get()
            ->map(fn ($h) => [$h->source, $h->external_ref, $h->line_ref, $h->sale_date->toDateString(), $h->qty_base, $h->product_id])->all();
    }

    public function test_it_imports_sold_lines_before_go_live_in_base_units()
    {
        $this->artisan('channels:ordermentum-history')->assertSuccessful();

        $this->assertSame([
            ['ordermentum_history', 'A', 'l1', '2026-10-02', 12, $this->milk->id], // 2 cartons of 6
            ['ordermentum_history', 'D', 'l1', '2026-10-05', 6, $this->milk->id],
        ], $this->rows());
    }

    public function test_it_never_creates_orders_reservations_or_inbox_entries_and_never_writes_to_ordermentum()
    {
        $this->artisan('channels:ordermentum-history')->assertSuccessful();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, IntegrationInbox::count());
        $this->assertSame(0, $this->writes);
    }

    public function test_running_it_again_changes_nothing_and_a_cancelled_order_is_taken_out()
    {
        $this->artisan('channels:ordermentum-history')->assertSuccessful();
        $first = $this->rows();
        $ids = SalesHistory::orderBy('id')->pluck('id')->all();

        $this->artisan('channels:ordermentum-history')->assertSuccessful();
        $this->assertSame($first, $this->rows());
        $this->assertSame($ids, SalesHistory::orderBy('id')->pluck('id')->all(), 'updated in place, not added again');

        $this->orders[0]['status'] = 'Cancelled';
        $this->orders[0]['cancelledAt'] = '2026-10-03T00:00:00Z';
        $this->artisan('channels:ordermentum-history')->assertSuccessful();
        $this->assertSame([['ordermentum_history', 'D', 'l1', '2026-10-05', 6, $this->milk->id]], $this->rows());
    }

    public function test_it_waits_out_the_order_endpoint_rate_limit_instead_of_skipping_orders()
    {
        // As live: single-order fetches are limited per minute, so most of them must wait.
        config(['services.ordermentum.rate_limits' => ['core:GET:/v1/orders' => 1]]);
        $this->app->forgetInstance(OrdermentumClient::class);
        $this->orders = [
            ['id' => 'F1', 'status' => 'Paid', 'deliveryDate' => '2026-10-09T14:00:00.000Z'],
            ['id' => 'F2', 'status' => 'Paid', 'deliveryDate' => '2026-10-09T14:00:00.000Z'],
            ['id' => 'F3', 'status' => 'Paid', 'deliveryDate' => '2026-10-09T14:00:00.000Z'],
        ];

        $this->artisan('channels:ordermentum-history')->doesntExpectOutputToContain('could not be read')->assertSuccessful();

        $this->assertSame(['F1', 'F2', 'F3'], SalesHistory::orderBy('external_ref')->pluck('external_ref')->all());
    }

    public function test_dry_run_writes_nothing_and_reports_the_counts()
    {
        $this->artisan('channels:ordermentum-history', ['--dry-run' => true])
            ->expectsOutputToContain('dry run: nothing is written')
            ->expectsOutputToContain('Statuses seen: Paid 4, Cancelled 1')
            ->expectsOutputToContain('Unmapped variants: UNMAPPED')
            ->assertSuccessful();

        $this->assertSame(0, SalesHistory::count());
    }
}
