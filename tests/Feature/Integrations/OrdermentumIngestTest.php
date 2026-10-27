<?php

namespace Tests\Feature\Integrations;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Integrations\Ordermentum\OrderIngestor;
use App\Integrations\Ordermentum\OrdermentumOrderMapper;
use App\Integrations\Ordermentum\StockPushService;
use App\Integrations\Ordermentum\UnmappableOrder;
use App\Jobs\ProcessOrdermentumOrder;
use App\Jobs\PushOrdermentumStock;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\Customer;
use App\Models\IntegrationInbox;
use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrdermentumFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class OrdermentumIngestTest extends TestCase
{
    use OrdermentumFixtures, RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->configureOrdermentum(['push' => false]);
    }

    private function omOrder(string $id, array $lines, string $updatedAt = '2026-11-02T01:00:00.000Z', array $extra = []): array
    {
        return ['id' => $id, 'number' => 'OM1', 'status' => 'Placed', 'retailerId' => 'ret-abc-123', 'updatedAt' => $updatedAt,
            'deliveryDate' => '2026-11-03T00:00:00.000Z', 'lineItems' => $lines, ...$extra];
    }

    private function fakeOrder(array $order): void
    {
        Http::fake(['api.om.test/v1/orders/'.$order['id'].'*' => Http::response($order)]);
    }

    /** A webhook as Ordermentum sends it: x-ordermentum-signature = hex HMAC-SHA256 of the raw body. */
    private function webhook(array $payload, ?string $token = 'hook-secret-123', ?string $signature = null, string $secret = 'sig-secret')
    {
        $raw = json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORDERMENTUM_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, $secret)];

        return $this->call('POST', '/webhooks/ordermentum'.($token !== null ? '/'.$token : ''), [], [], [], $server, $raw);
    }

    public function test_webhook_requires_the_path_token_stores_raw_and_returns_fast_without_calling_out()
    {
        Queue::fake();
        Http::fake();

        $this->webhook(['event' => 'order_created', 'data' => ['id' => 'o-1']], 'wrong')->assertNotFound();

        $payload = ['event' => 'order_created', 'timestamp' => 't', 'data' => ['id' => 'o-1', 'updatedAt' => '2026-11-02T01:00:00Z', 'total' => 10]];
        $this->webhook($payload)->assertOk()->assertJson(['ok' => true]);

        $inbox = IntegrationInbox::sole();
        $this->assertSame(['order_created', 'o-1', 'received'], [$inbox->event, $inbox->external_id, $inbox->status]);
        $this->assertSame($payload, json_decode($inbox->payload, true));
        Queue::assertPushed(ProcessOrdermentumOrder::class, fn ($j) => $j->orderId === 'o-1' && $j->inboxId === $inbox->id);
        Http::assertNothingSent(); // NFR-2: no API call in the web request
    }

    public function test_redelivered_webhook_is_stored_once_and_other_events_are_ignored()
    {
        Queue::fake();
        $payload = ['event' => 'order_updated', 'data' => ['id' => 'o-1', 'updatedAt' => '2026-11-02T01:00:00Z']];

        $this->webhook($payload)->assertOk();
        $this->webhook($payload)->assertOk();
        $this->webhook(['event' => 'invoice_created', 'data' => ['id' => 'inv-1']])->assertOk();

        $this->assertSame(1, IntegrationInbox::where('external_id', 'o-1')->count());
        $this->assertSame('ignored', IntegrationInbox::where('external_id', 'inv-1')->value('status'));
        Queue::assertPushed(ProcessOrdermentumOrder::class, 1);
    }

    public function test_webhook_is_held_while_ingestion_is_off()
    {
        Queue::fake();
        config(['bwms.ordermentum.ingest_enabled' => false]);

        $this->webhook(['event' => 'order_created', 'data' => ['id' => 'o-1']])->assertOk();

        $this->assertSame('held', IntegrationInbox::sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_order_is_fetched_mapped_by_line_item_quantity_and_reserved()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10);
        $this->listing($p, 'var-1', enabled: false); // listings map orders whether or not stock push is on
        $this->fakeOrder($this->omOrder('o-1', [['id' => 'li-1', 'variantId' => 'var-1', 'quantity' => 4, 'price' => '5.50']]));

        $this->webhook(['event' => 'order_created', 'data' => ['id' => 'o-1', 'updatedAt' => 'x']])->assertOk(); // sync queue: processed now

        $order = Order::where('channel', 'ordermentum')->where('external_ref', 'o-1')->sole();
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertSame([4, '5.5000', 'li-1'], [$order->lines->first()->qty, (string) $order->lines->first()->unit_price, $order->lines->first()->line_ref]);
        $this->assertSame(6, app(AvailabilityService::class)->available($p));
        $this->assertSame('2026-11-03', $order->requested_delivery_date->toDateString());
        $this->assertSame('ret-abc-123', Customer::find($order->customer_id)->ordermentum_retailer_id);
        $this->assertSame('processed', IntegrationInbox::sole()->status);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.om.test/v1/orders/o-1?includeLines=true');
    }

    public function test_ordermentum_order_beyond_stock_is_accepted_and_flagged()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 2);
        $this->listing($p, 'var-1');
        $this->fakeOrder($this->omOrder('o-1', [['variantId' => 'var-1', 'quantity' => 5]]));

        app(OrderIngestor::class)->ingest('o-1');

        $this->assertTrue(Order::sole()->oversold);
    }

    public function test_updates_apply_in_version_order_and_cancellation_releases_stock()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10);
        $this->listing($p, 'var-1');
        // Successive API reads of the same order (the webhook is only a hint).
        Http::fake(['api.om.test/v1/orders/o-1*' => Http::sequence()
            ->push($this->omOrder('o-1', [['id' => 'a', 'variantId' => 'var-1', 'quantity' => 3]], '2026-11-02T01:00:00.000Z'))
            ->push($this->omOrder('o-1', [['id' => 'a', 'variantId' => 'var-1', 'quantity' => 5]], '2026-11-02T01:00:00.400Z')) // same second, later ms
            ->push($this->omOrder('o-1', [['id' => 'a', 'variantId' => 'var-1', 'quantity' => 9]], '2026-11-02T00:59:00.000Z')) // stale
            ->push($this->omOrder('o-1', [], '2026-11-02T02:00:00.000Z', ['cancelledAt' => '2026-11-02T02:00:00.000Z']))]);
        $ingest = fn () => app(OrderIngestor::class)->ingest('o-1');

        $ingest();
        $ingest();
        $this->assertSame(5, app(AvailabilityService::class)->available($p));

        $ingest();
        $this->assertSame(5, app(AvailabilityService::class)->available($p));

        $ingest();
        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);
        $this->assertSame(10, app(AvailabilityService::class)->available($p));
    }

    public function test_unmapped_variant_holds_the_order_until_mapped_on_the_sync_screen()
    {
        Queue::fake();
        $p = Product::factory()->create(['sku' => 'DASH-VAN']);
        $this->stockIn($p, 10);
        $this->fakeOrder($this->omOrder('o-1', [['variantId' => 'var-new', 'sku' => 'dash-van', 'name' => 'Dash Vanilla', 'quantity' => 2]]));
        $inbox = IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'order_created', 'external_id' => 'o-1', 'dedupe_key' => str_repeat('a', 64), 'payload' => '{}']);

        $status = app(OrderIngestor::class)->ingest('o-1', $inbox);

        $this->assertSame('needs_mapping', $status);
        $this->assertSame(0, Order::count(), 'No order and no reservation until mapped');
        $variant = ChannelUnmappedVariant::sole();
        $this->assertSame(['var-new', 'dash-van'], [$variant->external_variant_id, $variant->external_sku]);

        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->actingAs($manager)->get(route('sync.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sync/Index')
            ->where('unmappedVariants.0.suggestion.sku', 'DASH-VAN')
            ->where('inboxCounts.needs_mapping', 1));

        $this->actingAs($manager)->post(route('sync.unmapped.map', $variant), [
            'product_id' => $p->id, 'product_unit_id' => $p->units()->value('id'),
        ])->assertSessionHasNoErrors();

        $this->assertSame($p->id, ChannelListing::where('external_variant_id', 'var-new')->value('product_id'));
        $this->assertNotNull($variant->fresh()->resolved_at);
        Queue::assertPushed(ProcessOrdermentumOrder::class, fn ($j) => $j->orderId === 'o-1' && $j->inboxId === $inbox->id);

        (new ProcessOrdermentumOrder('o-1', $inbox->id))->handle(app(OrderIngestor::class));
        $this->assertSame(8, app(AvailabilityService::class)->available($p));
        $this->assertSame('processed', $inbox->fresh()->status);
    }

    public function test_unknown_variant_field_fails_with_the_keys_found_and_works_once_configured()
    {
        $p = Product::factory()->create();
        $this->listing($p, 'var-1');
        $this->fakeOrder($this->omOrder('o-1', [['id' => 'a', 'product' => ['id' => 'prod-1', 'variant' => 'var-1'], 'quantity' => 1]]));
        $inbox = IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'poll', 'external_id' => 'o-1', 'dedupe_key' => str_repeat('b', 64), 'payload' => '{}']);

        app(OrderIngestor::class)->ingest('o-1', $inbox);

        $this->assertSame('failed', $inbox->fresh()->status);
        $this->assertStringContainsString('Line keys: id, product, quantity', $inbox->fresh()->error);
        $this->assertStringContainsString('ORDERMENTUM_LINE_VARIANT_FIELDS', $inbox->fresh()->error);

        // After om:probe shows the field: one config change, then replay.
        config(['bwms.ordermentum.order_mapping.variant' => ['product.variant']]);
        $this->app->forgetInstance(OrdermentumOrderMapper::class);
        app(OrderIngestor::class)->ingest('o-1', $inbox->fresh());
        $this->assertSame('processed', $inbox->fresh()->status);
        $this->assertSame(1, Order::sole()->lines->first()->qty);
    }

    public function test_mapper_rejects_fractional_quantities_and_missing_lines()
    {
        $mapper = new OrdermentumOrderMapper(config('bwms.ordermentum.order_mapping'));

        $this->assertSame(3, $mapper->map($this->omOrder('o', [['variantId' => 'v', 'quantity' => '3']]))['lines'][0]['quantity']);
        foreach ([[['variantId' => 'v', 'quantity' => 1.5]], null] as $lines) {
            try {
                $mapper->map($lines === null ? ['id' => 'o', 'status' => 'Placed'] : $this->omOrder('o', $lines));
                $this->fail('Expected UnmappableOrder');
            } catch (UnmappableOrder $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_poll_queues_new_and_changed_orders_only()
    {
        Queue::fake();
        $p = Product::factory()->create();
        Order::unguarded(fn () => Order::forceCreate(['number' => 'SO-X', 'channel' => OrderChannel::Ordermentum, 'external_ref' => 'known', 'status' => OrderStatus::Open,
            'site_id' => $this->site()->id, 'external_version' => (int) Carbon::parse('2026-11-02T01:00:00Z')->getPreciseTimestamp(3)]));
        Http::fake(['api.om.test/v2/orders*' => Http::response(['data' => [
            ['id' => 'known', 'updatedAt' => '2026-11-02T01:00:00Z'],
            ['id' => 'new-one', 'updatedAt' => '2026-11-02T03:00:00Z'],
        ], 'links' => ['next' => null]])]);

        $this->artisan('channels:poll-ordermentum')->expectsOutputToContain('Queued 1 order')->assertSuccessful();

        Queue::assertPushed(ProcessOrdermentumOrder::class, fn ($j) => $j->orderId === 'new-one');
        Queue::assertNotPushed(ProcessOrdermentumOrder::class, fn ($j) => $j->orderId === 'known');
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'supplierId=sup-1') && $r->method() === 'GET');
    }

    public function test_sync_screen_actions_need_sync_manage_and_only_queue_work()
    {
        Queue::fake();
        Http::fake();
        $p = Product::factory()->create();
        $listing = $this->listing($p, 'var-1', enabled: false);
        $inbox = IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'order_created', 'external_id' => 'o-9', 'dedupe_key' => str_repeat('c', 64), 'payload' => '{}', 'status' => 'failed']);

        $readOnly = $this->userWithRole(Permissions::ROLE_READ_ONLY);
        $this->actingAs($readOnly)->get(route('sync.index'))->assertOk();
        $this->actingAs($readOnly)->post(route('sync.inbox.replay', $inbox))->assertForbidden();
        $this->actingAs($readOnly)->put(route('sync.listings.update', $listing), ['sync_enabled' => true])->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('sync.index'))->assertForbidden();

        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->actingAs($manager)->put(route('sync.listings.update', $listing), ['sync_enabled' => true])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('sync.inbox.replay', $inbox))->assertSessionHasNoErrors();

        $this->assertTrue($listing->fresh()->sync_enabled);
        $this->assertSame('received', $inbox->fresh()->status);
        Queue::assertPushed(ProcessOrdermentumOrder::class);
        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['event' => 'sync_inbox_replayed']);
    }

    public function test_webhook_signature_is_enforced_in_hex_or_base64_and_the_encoding_is_recorded()
    {
        Queue::fake();
        Log::spy();
        $a = ['event' => 'order_created', 'data' => ['id' => 'o-1', 'updatedAt' => 'u1']];
        $b = ['event' => 'order_updated', 'data' => ['id' => 'o-1', 'updatedAt' => 'u2']];

        $this->webhook($a)->assertOk();
        $this->webhook($b, signature: base64_encode(hash_hmac('sha256', json_encode($b), 'sig-secret', true)))->assertOk();
        $this->assertSame(['valid_hex', 'valid_base64'], IntegrationInbox::orderBy('id')->pluck('signature_status')->all());

        $c = ['event' => 'order_updated', 'data' => ['id' => 'o-1', 'updatedAt' => 'u3']];
        $this->webhook($c, signature: 'forged')->assertStatus(401);
        $this->webhook($c, signature: hash_hmac('sha256', json_encode($c), 'wrong-secret'))->assertStatus(401);
        $this->assertSame(2, IntegrationInbox::count(), 'Rejected deliveries are not stored');

        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $msg === 'Ordermentum webhook received'
            && in_array('x-ordermentum-signature', $ctx['header_names'], true) && $ctx['signature'] === 'valid_base64'
            && ! str_contains(json_encode($ctx), 'order_') && ! str_contains(json_encode($ctx), 'hook-secret-123'));
    }

    public function test_missing_signature_or_unset_secret_is_rejected()
    {
        Queue::fake();
        Log::spy();
        $payload = ['event' => 'order_created', 'data' => ['id' => 'o-1']];

        $this->webhook($payload, signature: '')->assertStatus(401);

        config(['bwms.ordermentum.webhook_signature.secret' => null]);
        $this->webhook($payload)->assertStatus(401);
        Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_contains($msg, 'ORDERMENTUM_WEBHOOK_SECRET is not set'));
        $this->assertSame(0, IntegrationInbox::count());
    }

    public function test_path_token_becomes_optional_only_while_signatures_are_enforced()
    {
        Queue::fake();
        $payload = ['event' => 'order_created', 'data' => ['id' => 'o-1', 'updatedAt' => 'u1']];

        $this->webhook($payload, token: null)->assertNotFound(); // token still required by default

        config(['bwms.ordermentum.webhook_require_token' => false]);
        $this->webhook($payload, token: null)->assertOk();
        $this->webhook($payload, token: 'wrong')->assertNotFound(); // a token, if given, must still match
        $this->webhook(['event' => 'order_updated', 'data' => ['id' => 'o-1']], token: null, signature: 'forged')->assertStatus(401);

        config(['bwms.ordermentum.webhook_signature.mode' => 'report']);
        $this->webhook($payload, token: null)->assertNotFound(); // never token-free without enforcement
    }

    public function test_cancel_releases_the_reservation_and_the_absolute_push_restores_ordermentum_stock()
    {
        // Ordermentum does NOT restore stock on cancel (confirmed 2026-10-27); BWMS's push must.
        config(['bwms.ordermentum.push_enabled' => true]);
        Queue::fake();
        $p = Product::factory()->create();
        $this->stockIn($p, 10);
        $this->listing($p, 'var-1');
        Http::fake([
            'api.om.test/v1/orders/o-5*' => Http::sequence()
                ->push($this->omOrder('o-5', [['id' => 'a', 'variantId' => 'var-1', 'quantity' => 3]], '2026-11-02T01:00:00.000Z'))
                ->push($this->omOrder('o-5', [], '2026-11-02T02:00:00.000Z', ['cancelledAt' => '2026-11-02T02:00:00.000Z'])),
            'stock.om.test/*' => Http::response([]),
        ]);
        $push = fn () => (new PushOrdermentumStock)->handle(app(StockPushService::class));

        app(OrderIngestor::class)->ingest('o-5');
        $push();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['available'] === 7);

        app(OrderIngestor::class)->ingest('o-5'); // cancelled
        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);
        $this->assertSame(10, app(AvailabilityService::class)->available($p));
        $this->assertTrue(ChannelListing::sole()->push_force);

        $push();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['available'] === 10);
        $this->assertSame(10, ChannelListing::sole()->last_pushed_qty);
    }

    public function test_held_order_records_its_variants_and_an_edit_forces_a_repush_of_its_skus()
    {
        config(['bwms.ordermentum.push_enabled' => true]);
        Queue::fake();
        $p = Product::factory()->create();
        $this->stockIn($p, 10);
        $listing = $this->listing($p, 'var-1');

        $inbox = IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'order_created', 'external_id' => 'o-2', 'dedupe_key' => str_repeat('d', 64), 'payload' => '{}']);
        $this->fakeOrder($this->omOrder('o-2', [['variantId' => 'var-1', 'quantity' => 1], ['variantId' => 'var-new', 'quantity' => 1]]));
        app(OrderIngestor::class)->ingest('o-2', $inbox);
        $this->assertSame(['var-1', 'var-new'], $inbox->fresh()->meta['variants']);

        Http::fake(['api.om.test/v1/orders/o-3*' => Http::sequence()
            ->push($this->omOrder('o-3', [['id' => 'a', 'variantId' => 'var-1', 'quantity' => 2]], '2026-11-02T01:00:00.000Z'))
            ->push($this->omOrder('o-3', [['id' => 'a', 'variantId' => 'var-1', 'quantity' => 1]], '2026-11-02T02:00:00.000Z'))]);
        app(OrderIngestor::class)->ingest('o-3');
        ChannelListing::query()->update(['push_requested_at' => null, 'push_force' => false]);

        app(OrderIngestor::class)->ingest('o-3'); // edit: Ordermentum may or may not have restored stock, so re-send ours

        $this->assertTrue($listing->fresh()->push_force);
        $this->assertNotNull($listing->fresh()->push_requested_at);
    }

    public function test_poll_only_advances_its_checkpoint_after_reading_every_page()
    {
        Queue::fake();
        Cache::forever('ordermentum:last_poll', '2026-11-01T00:00:00Z');
        Http::fake(['api.om.test/v2/orders*' => Http::response(['data' => [['id' => 'o-1', 'updatedAt' => '2026-11-02T00:00:00Z']], 'links' => ['next' => 'more']])]);

        $this->artisan('channels:poll-ordermentum', ['--pages' => 2])->expectsOutputToContain('checkpoint was not advanced')->assertSuccessful();

        $this->assertSame('2026-11-01T00:00:00Z', Cache::get('ordermentum:last_poll'));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'updatedAt[gte]=2026-10-31T23:45:00'));
    }

    public function test_poll_can_catch_up_from_a_given_cutover_time()
    {
        Queue::fake();
        Http::fake(['api.om.test/v2/orders*' => Http::response(['data' => [], 'links' => ['next' => null]])]);

        $this->artisan('channels:poll-ordermentum', ['--since' => '2026-11-30 18:00'])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'updatedAt[gte]=2026-11-30T18:00:00+10:00'));
    }

    public function test_ordermentum_delivery_address_is_kept_on_the_order_for_its_run()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 5);
        $this->listing($p, 'var-1');
        $this->fakeOrder($this->omOrder('o-7', [['variantId' => 'var-1', 'quantity' => 1]], extra: [
            'address' => ['number' => '12', 'street1' => 'Oxley Ave', 'suburb' => 'Woody Point', 'postcode' => '4019', 'latitude' => -27.26, 'longitude' => 153.1],
            'deliveryInstructions' => 'Rear door',
        ]));

        app(OrderIngestor::class)->ingest('o-7');

        $order = Order::sole();
        $this->assertSame(['12 Oxley Ave', 'Woody Point', '4019', 'Rear door'], [$order->delivery_street, $order->delivery_suburb, $order->delivery_postcode, $order->delivery_instructions]);
        $this->assertSame('-27.2600000', (string) $order->delivery_lat);
    }
}
