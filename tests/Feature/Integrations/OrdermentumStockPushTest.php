<?php

namespace Tests\Feature\Integrations;

use App\Enums\OrderChannel;
use App\Integrations\Ordermentum\ChannelRetryableError;
use App\Integrations\Ordermentum\StockPushService;
use App\Jobs\ProcessOrdermentumOrder;
use App\Jobs\PushChannelStock;
use App\Jobs\ReconcileChannelListing;
use App\Models\Product;
use App\Models\SyncAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OrderFixtures;
use Tests\Support\OrdermentumFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class OrdermentumStockPushTest extends TestCase
{
    use OrderFixtures, OrdermentumFixtures, RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->configureOrdermentum();
    }

    private function pusher(): StockPushService
    {
        return app(StockPushService::class);
    }

    public function test_stock_change_queues_one_debounced_push_per_product()
    {
        Queue::fake();
        $p = Product::factory()->create();
        $this->listing($p, 'var-1');
        $other = Product::factory()->create(); // no listing: nothing queued

        $this->stockIn($p, 5);
        $this->stockIn($p, 3); // burst: still one job (unique until processing)
        $this->stockIn($other, 1);

        Queue::assertPushed(PushChannelStock::class, 1);
        Queue::assertPushed(PushChannelStock::class, fn (PushChannelStock $job) => $job->productId === $p->id && $job->delay !== null);
    }

    public function test_nothing_is_queued_while_the_push_switch_is_off_or_the_listing_is_disabled()
    {
        Queue::fake();
        $p = Product::factory()->create();
        $this->listing($p, 'var-1', enabled: false);
        $this->stockIn($p, 5);

        config(['bwms.ordermentum.push_enabled' => false]);
        $q = Product::factory()->create();
        $this->listing($q, 'var-2');
        $this->stockIn($q, 5);

        Queue::assertNothingPushed();
    }

    public function test_push_sends_available_in_packs_minus_buffer_never_negative()
    {
        Http::fake(['stock.om.test/*' => Http::response(['ok' => true])]);
        $p = Product::factory()->withPack('Carton', 12)->create();
        $listing = $this->listing($p, 'var-ctn', unitBaseQty: 12);
        $listing->update(['safety_buffer' => 1]);
        $this->stockIn($p, 50);
        $this->manualOrder($this->userWithRole('warehouse_manager'), $p, 2); // 2 each reserved -> 48 available

        $this->pusher()->push($listing->fresh());

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://stock.om.test/v1/items/var-ctn'
            && $r['available'] === 3 && $r['tracked'] === true && $r['allowOversell'] === false
            && $r['locationId'] === 'loc-1' && $r['supplierId'] === 'sup-1'
            && $r->hasHeader('x-api-key', 'om_api_test') && $r->hasHeader('context-id', 'sup-1')); // floor(48/12) - 1
        $this->assertSame(3, $listing->fresh()->last_pushed_qty);
        $this->assertTrue(SyncAttempt::sole()->success);

        // Oversold (Ordermentum order beyond stock) pushes 0, not a negative.
        $this->channelOrder(OrderChannel::Ordermentum, 'om-1', 1, [[$p, 100]]);
        $this->pusher()->push($listing->fresh());
        Http::assertSent(fn (Request $r) => $r['available'] === 0);
    }

    public function test_unchanged_quantity_is_not_pushed_again_within_a_day_unless_forced()
    {
        Queue::fake(); // only the explicit pushes below
        Http::fake(['stock.om.test/*' => Http::response([])]);
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');
        $this->stockIn($p, 5);

        $this->assertTrue($this->pusher()->push($listing->fresh()));
        $this->assertFalse($this->pusher()->push($listing->fresh()));
        $this->assertTrue($this->pusher()->push($listing->fresh(), force: true));
        Http::assertSentCount(2);
    }

    public function test_client_errors_are_recorded_and_server_errors_retry()
    {
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');

        Http::fake(['stock.om.test/*' => Http::sequence()->push(['message' => 'bad location'], 422)->push('oops', 503)]);

        $this->pusher()->push($listing->fresh());
        $this->assertStringContainsString('HTTP 422', $listing->fresh()->last_error);

        $this->expectException(ChannelRetryableError::class);
        $this->pusher()->push($listing->fresh());
    }

    public function test_rate_limit_beyond_client_wait_releases_the_job()
    {
        Http::fake(['stock.om.test/*' => Http::response([], 429, ['Retry-After' => '120'])]);
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');

        $job = (new PushChannelStock($p->id))->withFakeQueueInteractions();
        $job->handle($this->pusher());

        $job->assertReleased(120);
        $this->assertFalse(SyncAttempt::sole()->success);
        $this->assertNull($listing->fresh()->last_pushed_qty);
    }

    public function test_job_does_nothing_while_push_is_switched_off()
    {
        Http::fake();
        config(['bwms.ordermentum.push_enabled' => false]);
        $p = Product::factory()->create();
        $this->listing($p, 'var-1');

        (new PushChannelStock($p->id))->handle($this->pusher());

        Http::assertNothingSent();
    }

    public function test_jobs_finish_well_inside_the_queue_retry_after()
    {
        $retryAfter = config('queue.connections.database.retry_after');
        foreach ([new PushChannelStock(1), new ReconcileChannelListing(1), new ProcessOrdermentumOrder('x')] as $job) {
            $this->assertLessThan($retryAfter, $job->timeout, $job::class);
        }
    }

    public function test_reconciliation_records_the_remote_figure_and_repushes_a_mismatch()
    {
        Queue::fake();
        Http::fake(['stock.om.test/v1/items/var-1*' => Http::response(['available' => 9])]);
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');
        $this->stockIn($p, 4);
        Queue::fake(); // ignore the push queued by stockIn

        (new ReconcileChannelListing($listing->id))->handle($this->pusher());

        $this->assertSame(9, $listing->fresh()->last_remote_qty);
        $this->assertSame('Mismatch: Ordermentum 9, BWMS 4', SyncAttempt::where('operation', 'check')->sole()->error);
        Queue::assertPushed(PushChannelStock::class, fn ($j) => $j->productId === $p->id && $j->force);
    }

    public function test_full_push_command_queues_forced_pushes_for_enabled_listings()
    {
        Queue::fake();
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $this->listing($a, 'var-a');
        $this->listing($b, 'var-b', enabled: false);

        $this->artisan('channels:ordermentum', ['action' => 'push'])->expectsOutputToContain('Queued 1 push')->assertSuccessful();

        Queue::assertPushed(PushChannelStock::class, fn ($j) => $j->productId === $a->id && $j->force);
        Queue::assertNotPushed(PushChannelStock::class, fn ($j) => $j->productId === $b->id);
    }
}
