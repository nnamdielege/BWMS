<?php

namespace Tests\Feature\Integrations;

use App\Enums\OrderChannel;
use App\Integrations\Ordermentum\ChannelRetryableError;
use App\Integrations\Ordermentum\StockPushService;
use App\Jobs\ProcessOrdermentumOrder;
use App\Jobs\PushOrdermentumStock;
use App\Jobs\ReconcileChannelListing;
use App\Models\ChannelListing;
use App\Models\IntegrationInbox;
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
        Queue::fake(); // runs are driven explicitly below
    }

    private function pusher(): StockPushService
    {
        return app(StockPushService::class);
    }

    private function pushRun(): PushOrdermentumStock
    {
        $job = (new PushOrdermentumStock)->withFakeQueueInteractions();
        $job->handle($this->pusher());

        return $job;
    }

    public function test_stock_changes_flag_listings_and_queue_one_debounced_run()
    {
        $p = Product::factory()->create();
        $q = Product::factory()->create();
        $a = $this->listing($p, 'var-p');
        $b = $this->listing($q, 'var-q');
        $off = $this->listing(Product::factory()->create(), 'var-off', enabled: false);

        $this->stockIn($p, 5);
        $this->stockIn($p, 3);
        $this->stockIn($q, 1);

        Queue::assertPushed(PushOrdermentumStock::class, 1); // unique until processing
        Queue::assertPushed(PushOrdermentumStock::class, fn ($j) => $j->delay !== null);
        $this->assertNotNull($a->fresh()->push_requested_at);
        $this->assertNotNull($b->fresh()->push_requested_at);
        $this->assertNull($off->fresh()->push_requested_at);
    }

    public function test_nothing_is_flagged_while_the_push_switch_is_off()
    {
        config(['bwms.ordermentum.push_enabled' => false]);
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');

        $this->stockIn($p, 5);

        Queue::assertNothingPushed();
        $this->assertNull($listing->fresh()->push_requested_at);
    }

    public function test_one_pending_item_is_put_as_available_in_packs_minus_buffer()
    {
        Http::fake(['stock.om.test/*' => Http::response(['ok' => true])]);
        $p = Product::factory()->withPack('Carton', 12)->create();
        $listing = $this->listing($p, 'var-ctn', unitBaseQty: 12);
        $listing->update(['safety_buffer' => 1]);
        $this->stockIn($p, 50);
        $this->manualOrder($this->userWithRole('warehouse_manager'), $p, 2); // 48 available

        $this->pushRun();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://stock.om.test/v1/items/var-ctn'
            && $r['available'] === 3 && $r['tracked'] === true && $r['allowOversell'] === false
            && $r->hasHeader('x-api-key', 'om_api_test') && $r->hasHeader('context-id', 'sup-1')); // floor(48/12) - 1
        $fresh = $listing->fresh();
        $this->assertSame(3, $fresh->last_pushed_qty);
        $this->assertNull($fresh->push_requested_at);
    }

    public function test_many_pending_items_go_by_bulk_patch_in_chunks()
    {
        config(['bwms.ordermentum.bulk_chunk' => 2]);
        Http::fake(['stock.om.test/*' => Http::response(['ok' => true])]);
        $listings = collect(range(1, 3))->map(function ($i) {
            $p = Product::factory()->create(['name' => "Tea {$i}"]);
            $this->stockIn($p, $i * 10);

            return tap($this->listing($p, "var-{$i}"))->update(['external_product_id' => "prod-{$i}"]);
        });
        StockPushService::request($listings->pluck('product_id'));

        $this->pushRun();

        Http::assertSentCount(2); // 2 + 1
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === 'https://stock.om.test/v1/items'
            && $r['actions'][0] === ['id' => 'var-1', 'productId' => 'prod-1', 'name' => 'Tea 1', 'action' => 'UPDATE', 'quantity' => 10, 'locationId' => 'loc-1']
            && $r['actions'][1]['quantity'] === 20);
        $this->assertSame([10, 20, 30], $listings->map(fn ($l) => $l->fresh()->last_pushed_qty)->all());
        $this->assertSame(3, SyncAttempt::where('operation', 'push_bulk')->where('success', true)->count());
    }

    public function test_pushes_wait_while_order_events_are_still_being_ingested()
    {
        Http::fake();
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');
        StockPushService::request([$p->id]);
        IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'order_created', 'external_id' => 'o-1', 'dedupe_key' => str_repeat('a', 64), 'payload' => '{}', 'status' => 'received']);

        $this->pushRun()->assertReleased(15);
        Http::assertNothingSent();
        $this->assertNotNull($listing->fresh()->push_requested_at, 'still pending');

        // A stuck event (older than the wait window) no longer blocks pushes.
        $this->travel(11)->minutes();
        Http::fake(['stock.om.test/*' => Http::response([])]);
        $this->pushRun()->assertNotReleased();
        Http::assertSentCount(1);
    }

    public function test_skus_on_an_order_held_for_mapping_are_not_pushed()
    {
        Http::fake(['stock.om.test/*' => Http::response([])]);
        $held = Product::factory()->create();
        $free = Product::factory()->create();
        $heldListing = $this->listing($held, 'var-held');
        $this->listing($free, 'var-free');
        IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'order_created', 'external_id' => 'o-9', 'dedupe_key' => str_repeat('b', 64),
            'payload' => '{}', 'status' => 'needs_mapping', 'meta' => ['variants' => ['var-held', 'var-unknown']]]);
        StockPushService::request([$held->id, $free->id]);

        $this->pushRun();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/items/var-free'));
        $this->assertStringContainsString('Held', $heldListing->fresh()->last_error);
    }

    public function test_a_change_during_a_push_stays_pending()
    {
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');
        StockPushService::request([$p->id]);
        Http::fake(['stock.om.test/*' => function () use ($p) {
            StockPushService::request([$p->id]); // a movement commits while the request is in flight

            return Http::response([]);
        }]);

        $this->pushRun();

        $this->assertNotNull($listing->fresh()->push_requested_at);
        Queue::assertPushed(PushOrdermentumStock::class); // follow-up run for the leftover
    }

    public function test_unchanged_quantity_is_skipped_within_a_day_unless_forced()
    {
        Http::fake(['stock.om.test/*' => Http::response([])]);
        $p = Product::factory()->create();
        $this->listing($p, 'var-1');
        $this->stockIn($p, 5);

        $this->pushRun();
        StockPushService::request([$p->id]);
        $this->pushRun();
        StockPushService::request([$p->id], force: true);
        $this->pushRun();

        Http::assertSentCount(2);
    }

    public function test_client_errors_are_shown_and_server_errors_retry_with_the_item_still_pending()
    {
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');
        Http::fake(['stock.om.test/*' => Http::sequence()->push(['message' => 'bad location'], 422)->push('oops', 503)]);

        StockPushService::request([$p->id]);
        $this->pushRun();
        $this->assertStringContainsString('HTTP 422', $listing->fresh()->last_error);
        $this->assertNull($listing->fresh()->push_requested_at, '4xx: not retried automatically');

        StockPushService::request([$p->id]);
        try {
            $this->pushRun();
            $this->fail('Expected a retryable error');
        } catch (ChannelRetryableError) {
            $this->assertNotNull($listing->fresh()->push_requested_at);
        }
    }

    public function test_rate_limit_beyond_client_wait_releases_the_run()
    {
        Http::fake(['stock.om.test/*' => Http::response([], 429, ['Retry-After' => '120'])]);
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');
        StockPushService::request([$p->id]);

        $this->pushRun()->assertReleased(120);

        $this->assertNotNull($listing->fresh()->push_requested_at);
        $this->assertFalse(SyncAttempt::sole()->success);
    }

    public function test_oversold_ordermentum_order_pushes_zero_not_negative()
    {
        Http::fake(['stock.om.test/*' => Http::response([])]);
        $p = Product::factory()->create();
        $this->listing($p, 'var-1');
        $this->stockIn($p, 2);
        $this->channelOrder(OrderChannel::Ordermentum, 'om-1', 1, [[$p, 5]]);

        $this->pushRun();

        Http::assertSent(fn (Request $r) => $r['available'] === 0);
    }

    public function test_jobs_finish_inside_the_queue_retry_after()
    {
        $retryAfter = config('queue.connections.database.retry_after');
        foreach ([new PushOrdermentumStock, new ReconcileChannelListing(1), new ProcessOrdermentumOrder('x')] as $job) {
            $this->assertLessThan($retryAfter, $job->timeout, $job::class);
        }
    }

    public function test_reconciliation_records_figure_and_tracking_and_repushes_a_mismatch()
    {
        Http::fake(['stock.om.test/v1/items/var-1*' => Http::response(['available' => 9, 'tracked' => true])]);
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');
        $this->stockIn($p, 4);
        ChannelListing::query()->update(['push_requested_at' => null]);

        (new ReconcileChannelListing($listing->id))->handle($this->pusher());

        $fresh = $listing->fresh();
        $this->assertSame([9, true, 'tracked'], [$fresh->last_remote_qty, $fresh->remote_tracked, $fresh->remote_status]);
        $this->assertSame('Mismatch: Ordermentum 9, BWMS 4', SyncAttempt::where('operation', 'check')->sole()->error);
        $this->assertTrue($fresh->push_force);
        $this->assertNotNull($fresh->push_requested_at);
    }

    public function test_reconciliation_flags_items_with_tracking_off()
    {
        Http::fake(['stock.om.test/*' => Http::response(['available' => 0, 'tracked' => false])]);
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1');

        (new ReconcileChannelListing($listing->id))->handle($this->pusher());

        $this->assertSame('untracked', $listing->fresh()->remote_status);
        $this->assertStringContainsString('tracking is off', SyncAttempt::sole()->error);
    }

    public function test_full_push_command_flags_enabled_listings_with_force()
    {
        $a = $this->listing(Product::factory()->create(), 'var-a');
        $b = $this->listing(Product::factory()->create(), 'var-b', enabled: false);

        $this->artisan('channels:ordermentum', ['action' => 'push'])->expectsOutputToContain('Queued 1 product push')->assertSuccessful();

        $this->assertTrue($a->fresh()->push_force);
        $this->assertNull($b->fresh()->push_requested_at);
        Queue::assertPushed(PushOrdermentumStock::class);
    }
}
