<?php

namespace Tests\Feature\Integrations;

use App\Authorization\Permissions;
use App\Enums\OrderStatus;
use App\Integrations\Portal\PortalClient;
use App\Integrations\Portal\PortalSignature;
use App\Integrations\Portal\PortalStockPushService;
use App\Integrations\Portal\PortalUnavailable;
use App\Jobs\PushPortalStock;
use App\Jobs\SendPortalOrderEvent;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\Customer;
use App\Models\IntegrationInbox;
use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\AvailabilityService;
use App\Services\Orders\PickListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class PortalIntegrationTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        config(['bwms.portal' => array_merge(config('bwms.portal'), [
            'api_enabled' => true, 'inbound_keys' => ['k1' => 'secret-one', 'k2' => 'secret-two'],
            'base_url' => 'https://portal.test', 'outbound_key_id' => 'bwms', 'outbound_secret' => 'out-secret',
            'push_enabled' => false, 'events_enabled' => false,
        ])]);
        $this->app->forgetInstance(PortalClient::class);

        // Portal product 501 is sold by the carton of 12.
        $this->product = Product::factory()->withPack('Carton', 12)->create(['sku' => 'DASH-VAN', 'sell_channels' => 'both']);
        ChannelListing::create(['channel' => 'portal', 'product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->where('base_qty', 12)->value('id'),
            'external_product_id' => '501', 'external_variant_id' => '501']);
        $this->stockIn($this->product, 60);
    }

    private function signed(string $method, string $uri, ?array $body = null, array $headers = [], string $key = 'k1', ?string $secret = null, ?int $ts = null)
    {
        $raw = $body === null ? '' : json_encode($body);
        $ts = (string) ($ts ?? time());
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BWMS_KEY_ID' => $key, 'HTTP_X_BWMS_TIMESTAMP' => $ts,
            'HTTP_X_BWMS_SIGNATURE' => PortalSignature::sign($ts, $raw, $secret ?? config("bwms.portal.inbound_keys.{$key}", 'x'))];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $raw);
    }

    private function reserve(array $lines, string $ref = 'P-1001', array $extra = [])
    {
        return $this->signed('POST', '/api/portal/v1/reservations', array_merge([
            'order_ref' => $ref, 'customer' => ['portal_user_id' => 77, 'business_name' => 'Cafe Test'],
            'requested_delivery_date' => '2026-11-05', 'lines' => $lines,
        ], $extra), ['Idempotency-Key' => "portal:order:{$ref}"]);
    }

    private function available(): int
    {
        return app(AvailabilityService::class)->available($this->product);
    }

    public function test_requests_must_be_signed_recent_and_from_a_known_key()
    {
        $body = ['order_ref' => 'X', 'lines' => []];
        $this->signed('GET', '/api/portal/v1/stock?product_ids=501', key: 'nope')->assertStatus(401)->assertJson(['error' => 'unknown_key']);
        $this->signed('GET', '/api/portal/v1/stock?product_ids=501', ts: time() - 301)->assertStatus(401)->assertJson(['error' => 'stale_timestamp']);
        $this->signed('POST', '/api/portal/v1/reservations', $body, secret: 'wrong')->assertStatus(401)->assertJson(['error' => 'bad_signature']);
        $this->signed('GET', '/api/portal/v1/stock?product_ids=501', key: 'k2')->assertOk(); // second key: rotation

        config(['bwms.portal.api_enabled' => false]);
        $this->signed('GET', '/api/portal/v1/stock?product_ids=501')->assertStatus(503);
    }

    public function test_reservation_is_confirmed_in_portal_packs_and_recorded()
    {
        $this->reserve([['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => 2, 'unit_quantity' => 12]])
            ->assertCreated()
            ->assertJson(['status' => 'confirmed', 'lines' => [['line_ref' => 'L1', 'reserved_qty' => 2]]])
            ->assertJsonMissingPath('warnings');

        $this->assertSame(36, $this->available()); // 60 - 24 base units
        $order = Order::where('channel', 'portal')->where('external_ref', 'P-1001')->sole();
        $this->assertSame('2026-11-05', $order->requested_delivery_date->toDateString());
        $this->assertSame('Cafe Test', Customer::where('portal_user_id', 77)->sole()->name);
        $this->assertSame('processed', IntegrationInbox::where('channel', 'portal')->sole()->status);
    }

    public function test_replays_are_idempotent_and_conflicting_bodies_are_refused()
    {
        $lines = [['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => 1]];
        $first = $this->reserve($lines)->assertCreated();
        $this->reserve($lines)->assertCreated()->assertHeader('Idempotent-Replay', 'true')->assertExactJson($first->json());
        $this->assertSame(1, Order::count());
        $this->assertSame(48, $this->available());

        $this->reserve([['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => 3]])->assertStatus(422)->assertJson(['error' => 'idempotency_conflict']);
        $this->signed('POST', '/api/portal/v1/reservations', ['order_ref' => 'P-9', 'lines' => $lines])->assertStatus(400);
    }

    public function test_insufficient_stock_is_409_all_or_nothing_and_listed_on_the_sync_screen()
    {
        $other = Product::factory()->create();
        ChannelListing::create(['channel' => 'portal', 'product_id' => $other->id, 'product_unit_id' => $other->units()->value('id'), 'external_product_id' => '502', 'external_variant_id' => '502']);
        $this->stockIn($other, 10);

        $this->reserve([['line_ref' => 'L1', 'portal_product_id' => 502, 'qty' => 2], ['line_ref' => 'L2', 'portal_product_id' => 501, 'qty' => 6]])
            ->assertStatus(409)
            ->assertExactJson(['status' => 'insufficient_stock', 'lines' => [['line_ref' => 'L2', 'requested' => 6, 'available' => 5]]]);

        $this->assertSame(0, Order::count());
        $this->assertSame(60, $this->available());
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))->get(route('sync.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('portal.refused.0.status', 'rejected')->where('portal.refused.0.external_id', 'P-1001'));
    }

    public function test_unknown_product_is_422_and_can_be_mapped_on_the_sync_screen()
    {
        $this->reserve([['line_ref' => 'L1', 'portal_product_id' => 999, 'qty' => 1]])
            ->assertStatus(422)->assertJson(['status' => 'unknown_product', 'lines' => [['line_ref' => 'L1', 'portal_product_id' => 999]]]);

        $variant = ChannelUnmappedVariant::where('channel', 'portal')->sole();
        $p = Product::factory()->create();
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))
            ->post(route('sync.unmapped.map', $variant), ['product_id' => $p->id, 'product_unit_id' => $p->units()->value('id')])->assertSessionHasNoErrors();

        $listing = ChannelListing::where('channel', 'portal')->where('external_variant_id', '999')->sole();
        $this->assertSame(['999', null], [$listing->external_product_id, $listing->external_location_id]);
    }

    public function test_full_state_updates_apply_by_version_and_cancel_releases()
    {
        $this->reserve([['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => 2]])->assertCreated();
        $put = fn (int $v, string $status, int $qty) => $this->signed('PUT', '/api/portal/v1/orders/P-1001',
            ['version' => $v, 'status' => $status, 'lines' => [['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => $qty]]],
            ['Idempotency-Key' => "portal:order:P-1001:v{$v}:{$status}:{$qty}"]);

        $put(2, 'confirmed', 1)->assertOk()->assertJson(['status' => 'confirmed', 'lines' => [['reserved_qty' => 1]]]);
        $this->assertSame(48, $this->available());

        $put(1, 'confirmed', 4)->assertOk()->assertJson(['lines' => [['reserved_qty' => 1]]]); // stale: unchanged
        $put(3, 'confirmed', 9)->assertStatus(409); // beyond stock: nothing changes
        $this->assertSame(48, $this->available());

        $put(4, 'cancelled', 1)->assertOk()->assertJson(['status' => 'cancelled']);
        $this->assertSame(60, $this->available());
        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);

        $this->signed('PUT', '/api/portal/v1/orders/NEVER', ['version' => 1, 'status' => 'cancelled', 'lines' => []], ['Idempotency-Key' => 'k-never'])
            ->assertNotFound()->assertJson(['status' => 'unknown_order']);
    }

    public function test_order_and_stock_reads_are_in_portal_packs()
    {
        $this->reserve([['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => 2]]);

        $this->signed('GET', '/api/portal/v1/orders/P-1001')->assertOk()
            ->assertJson(['status' => 'confirmed', 'reservation_status' => 'confirmed', 'lines' => [['line_ref' => 'L1', 'reserved_qty' => 2, 'dispatched_qty' => 0]]]);
        $this->signed('GET', '/api/portal/v1/orders/NOPE')->assertNotFound();
        $this->signed('GET', '/api/portal/v1/stock?product_ids=501,777')->assertOk()->assertExactJson([['portal_product_id' => 501, 'available' => 3]]);
    }

    public function test_unit_quantity_mismatch_is_reported_as_a_warning()
    {
        $this->reserve([['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => 1, 'unit_quantity' => 24]])
            ->assertCreated()->assertJsonPath('warnings.0', 'line L1: portal unit_quantity 24 differs from the mapped pack (12)');
    }

    public function test_stock_changes_push_signed_batches_to_the_portal()
    {
        config(['bwms.portal.push_enabled' => true]);
        Queue::fake();
        $listing = ChannelListing::where('channel', 'portal')->sole();
        $listing->update(['sync_enabled' => true]);

        $this->stockIn($this->product, 12); // 72 base -> 6 cartons
        Queue::assertPushed(PushPortalStock::class);
        $this->assertNotNull($listing->fresh()->push_requested_at);

        Http::fake(['portal.test/*' => Http::sequence()->push(['ok' => true])->push('down', 503)]);
        (new PushPortalStock)->handle(app(PortalStockPushService::class));

        Http::assertSent(function (Request $r) {
            $ts = $r->header('X-BWMS-Timestamp')[0];

            return $r->method() === 'PUT' && $r->url() === 'https://portal.test/api/bwms/v1/stock'
                && $r->data()[0]['portal_product_id'] === 501 && $r->data()[0]['available'] === 6 && isset($r->data()[0]['as_of'])
                && $r->header('X-BWMS-Key-Id')[0] === 'bwms'
                && hash_equals(PortalSignature::sign($ts, $r->body(), 'out-secret'), $r->header('X-BWMS-Signature')[0]);
        });
        $this->assertSame(6, $listing->fresh()->last_pushed_qty);
        $this->assertNull($listing->fresh()->push_requested_at);

        // Portal down (second response): the item stays pending and the job retries.
        PushPortalStock::request([$this->product->id], force: true);
        $this->expectException(PortalUnavailable::class);
        try {
            (new PushPortalStock)->handle(app(PortalStockPushService::class));
        } finally {
            $this->assertNotNull($listing->fresh()->push_requested_at);
        }
    }

    public function test_dispatch_sends_an_order_event_with_portal_quantities()
    {
        config(['bwms.portal.events_enabled' => true]);
        $this->reserve([['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => 2]])->assertCreated();
        $order = Order::sole();
        $picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $list = app(PickListService::class)->create($picker, $this->site(), [$order->id])['pickList'];
        Http::fake(['portal.test/*' => Http::response(['ok' => true])]);
        Queue::fake([SendPortalOrderEvent::class]);

        $this->actingAs($picker)->put(route('pick-lists.record', $list), ['picked' => [$list->lines()->value('id') => 12]])->assertSessionHasNoErrors(); // short: 1 of 2 cartons
        $this->actingAs($picker)->post(route('pick-lists.dispatch', $list))->assertSessionHasNoErrors();

        Queue::assertPushed(SendPortalOrderEvent::class, fn ($j) => $j->orderId === $order->id && $j->pickListId === $list->id);
        (new SendPortalOrderEvent($order->id, $list->id))->handle(app(PortalClient::class));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://portal.test/api/bwms/v1/order-events'
            && $r['order_ref'] === 'P-1001' && $r['event'] === 'short_shipped' && $r['lines'] === [['line_ref' => 'L1', 'qty' => 1]]
            && $r->header('Idempotency-Key')[0] === "bwms:order-event:P-1001:{$list->id}");
    }

    public function test_bulk_mapping_command()
    {
        $p = Product::factory()->withPack('Case', 6)->create(['sku' => 'TONIC-1']);
        $file = sys_get_temp_dir().'/portal-map-'.uniqid().'.csv';
        File::put($file, "portal_product_id,sku,pack_qty\n601,TONIC-1,6\n501,DASH-VAN,\n602,NOPE,\n");

        $this->artisan('channels:portal-map', ['file' => $file])
            ->expectsTable(['Created', 'Already mapped', 'Errors'], [[1, 1, 1]])
            ->assertFailed();
        File::delete($file);

        $listing = ChannelListing::where('channel', 'portal')->where('external_product_id', '601')->sole();
        $this->assertSame([$p->id, 6, false], [$listing->product_id, $listing->unit->base_qty, $listing->sync_enabled]);
    }
}
