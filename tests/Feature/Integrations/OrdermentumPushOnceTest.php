<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Ordermentum\OrdermentumClient;
use App\Models\ChannelListing;
use App\Models\IntegrationInbox;
use App\Models\Product;
use App\Models\SyncAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OrdermentumFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** channels:ordermentum push-once: a reviewed one-off push while ongoing push is off. */
class OrdermentumPushOnceTest extends TestCase
{
    use OrdermentumFixtures, RefreshDatabase, StockFixtures;

    private ChannelListing $milk;

    private ChannelListing $tea;

    /** @var list<Request> */
    private array $sent = [];

    /** @var array<string, int> variant => status to answer */
    private array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Australia/Brisbane'));
        $this->seedStockReferenceData();
        $this->configureOrdermentum(['push' => false]);
        Queue::fake();

        $milk = Product::factory()->withPack('Carton', 6)->create(['sku' => 'MILK']);
        $tea = Product::factory()->create(['sku' => 'TEA']);
        $this->stockIn($milk, 20); // 3 cartons and 2 over
        $this->stockIn($tea, 4);
        $this->milk = $this->listing($milk, 'V-MILK', unitBaseQty: 6);
        $this->milk->forceFill(['safety_buffer' => 1, 'last_pushed_qty' => 0, 'last_pushed_at' => Carbon::parse('2026-11-01 08:00')])->save();
        $this->tea = $this->listing($tea, 'V-TEA');
        $this->tea->forceFill(['last_pushed_qty' => 7, 'last_pushed_at' => Carbon::parse('2026-11-02 08:00')])->save();
        // Left out: a listing switched off, and a product not sold on Ordermentum.
        $this->listing(Product::factory()->create(['sku' => 'OFF']), 'V-OFF', enabled: false);
        $this->listing(Product::factory()->create(['sku' => 'PORTAL', 'sell_channels' => 'portal']), 'V-PORTAL');

        Http::fake(function (Request $r) {
            $this->sent[] = $r;
            $variant = basename(parse_url($r->url(), PHP_URL_PATH));

            return Http::response(['ok' => true], $this->answer[$variant] ?? 200);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_dry_run_shows_each_listing_and_sends_nothing()
    {
        $this->artisan('channels:ordermentum', ['action' => 'push-once', '--dry-run' => true])
            ->expectsTable(['SKU', 'Pack', 'WMS available', 'Buffer', 'Last pushed', 'Last pushed at', 'Would send'], [
                ['MILK', 'Carton (6)', 3, 1, 0, '2026-11-01 18:00', 2],
                ['TEA', 'Each', 4, '', 7, '2026-11-02 18:00', 4],
            ])
            ->expectsOutputToContain('Dry run: nothing sent')
            ->assertSuccessful();

        $this->assertSame([], $this->sent);
        $this->assertSame(0, SyncAttempt::count());
        $this->assertSame(0, $this->milk->fresh()->last_pushed_qty);
    }

    public function test_sending_needs_yes()
    {
        $this->artisan('channels:ordermentum', ['action' => 'push-once'])->expectsOutputToContain('--yes')->assertFailed();

        $this->assertSame([], $this->sent);
    }

    public function test_yes_sends_each_figure_once_records_it_and_leaves_ongoing_push_off()
    {
        $this->artisan('channels:ordermentum', ['action' => 'push-once', '--yes' => true])->expectsOutputToContain('Sent 2, failed 0')->assertSuccessful();

        $this->assertCount(2, $this->sent);
        $bodies = collect($this->sent)->mapWithKeys(fn (Request $r) => [basename(parse_url($r->url(), PHP_URL_PATH)) => $r->data()]);
        $this->assertSame('PUT', $this->sent[0]->method());
        $this->assertSame(2, $bodies['V-MILK']['available']);
        $this->assertSame(4, $bodies['V-TEA']['available']);
        $this->assertSame(2, SyncAttempt::where('operation', 'push')->where('success', true)->count());
        $this->assertSame(2, $this->milk->fresh()->last_pushed_qty);
        $this->assertTrue($this->milk->fresh()->last_pushed_at->equalTo(now()));
        $this->assertSame(4, $this->tea->fresh()->last_pushed_qty);

        // Ongoing push is still off: a stock change after it flags nothing and queues nothing.
        $this->assertFalse(config('bwms.ordermentum.push_enabled'));
        $this->stockIn($this->milk->product, 6);
        $this->assertNull($this->milk->fresh()->push_requested_at);
        Queue::assertNothingPushed();
    }

    public function test_only_zero_pushed_and_sku_narrow_it()
    {
        $this->artisan('channels:ordermentum', ['action' => 'push-once', '--only-zero-pushed' => true, '--yes' => true])->assertSuccessful();
        $this->assertSame(['V-MILK'], array_map(fn (Request $r) => basename(parse_url($r->url(), PHP_URL_PATH)), $this->sent));

        $this->sent = [];
        $this->artisan('channels:ordermentum', ['action' => 'push-once', '--sku' => ['tea'], '--yes' => true])->assertSuccessful();
        $this->assertSame(['V-TEA'], array_map(fn (Request $r) => basename(parse_url($r->url(), PHP_URL_PATH)), $this->sent));
    }

    public function test_it_waits_out_the_rate_limit()
    {
        config(['services.ordermentum.rate_limits' => ['stock:PUT' => 1]]);
        $this->app->forgetInstance(OrdermentumClient::class);

        $this->artisan('channels:ordermentum', ['action' => 'push-once', '--yes' => true])->expectsOutputToContain('Sent 2, failed 0')->assertSuccessful();

        $this->assertCount(2, $this->sent);
        $this->assertSame(4, $this->tea->fresh()->last_pushed_qty);
    }

    public function test_a_refused_item_is_logged_and_the_rest_still_go()
    {
        $this->answer['V-MILK'] = 422;

        $this->artisan('channels:ordermentum', ['action' => 'push-once', '--yes' => true])
            ->expectsOutputToContain('MILK: HTTP 422')->expectsOutputToContain('Sent 1, failed 1')->assertFailed();

        $this->assertSame(0, $this->milk->fresh()->last_pushed_qty, 'not recorded as pushed');
        $this->assertSame(1, SyncAttempt::where('success', false)->where('response_code', 422)->count());
        $this->assertSame(4, $this->tea->fresh()->last_pushed_qty);
    }

    public function test_items_on_an_order_waiting_for_mapping_are_held_and_pending_order_events_stop_it()
    {
        IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'order', 'external_id' => 'OM-9', 'dedupe_key' => 'om-9', 'status' => IntegrationInbox::STATUS_NEEDS_MAPPING,
            'payload' => '{}', 'meta' => ['variants' => ['V-MILK']]]);

        $this->artisan('channels:ordermentum', ['action' => 'push-once', '--yes' => true])->expectsOutputToContain('1 held')->assertSuccessful();
        $this->assertSame(['V-TEA'], array_map(fn (Request $r) => basename(parse_url($r->url(), PHP_URL_PATH)), $this->sent));

        $this->sent = [];
        IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'order', 'external_id' => 'OM-10', 'dedupe_key' => 'om-10', 'status' => IntegrationInbox::STATUS_RECEIVED, 'payload' => '{}']);
        $this->artisan('channels:ordermentum', ['action' => 'push-once', '--yes' => true])->expectsOutputToContain('still being processed')->assertFailed();
        $this->assertSame([], $this->sent);
    }

    public function test_the_ongoing_actions_still_need_the_switch()
    {
        $this->artisan('channels:ordermentum', ['action' => 'push'])->expectsOutputToContain('switched off')->assertSuccessful();
        Queue::assertNothingPushed();
    }
}
