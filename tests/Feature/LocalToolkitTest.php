<?php

namespace Tests\Feature;

use App\Integrations\Ordermentum\LocalOrderFixtures;
use App\Integrations\Ordermentum\OrderIngestor;
use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Jobs\PushOrdermentumStock;
use App\Models\ChannelListing;
use App\Models\IntegrationInbox;
use App\Models\Order;
use App\Models\Product;
use App\Support\DatabaseSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OrdermentumFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** The local test toolkit (docs/TESTING.md): dev-reset guards, read-only Ordermentum, simulators. */
class LocalToolkitTest extends TestCase
{
    use OrdermentumFixtures, RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->configureOrdermentum(['push' => false, 'ingest' => true]);
        config(['bwms.ordermentum.local_order_fixtures' => true, 'app.url' => 'http://bwms.local.test']);
        Storage::fake('local');
    }

    private function mappedProduct(string $sku, string $variant, int $stock = 10): Product
    {
        $p = Product::factory()->create(['sku' => $sku]);
        $this->listing($p, $variant);
        $this->stockIn($p, $stock);

        return $p;
    }

    public function test_dev_reset_only_runs_locally_on_an_allow_listed_local_database()
    {
        $this->artisan('bwms:dev-reset', ['--force' => true])->expectsOutputToContain('APP_ENV is "testing"')->assertFailed();

        $this->app['env'] = 'local';
        $connection = config('database.default');
        config(['bwms.dev_reset_databases' => ['bwms_next']]);
        $this->assertStringContainsString('not in BWMS_DEV_RESET_DATABASES', DatabaseSafety::devResetRefusal());

        config(['bwms.dev_reset_databases' => [DatabaseSafety::databaseName()], "database.connections.{$connection}.host" => '10.0.0.5']);
        $this->assertStringContainsString('not this machine', DatabaseSafety::devResetRefusal());

        config(["database.connections.{$connection}.host" => '127.0.0.1']);
        $this->assertNull(DatabaseSafety::devResetRefusal());
    }

    public function test_read_only_client_refuses_every_write_and_still_reads()
    {
        config(['services.ordermentum.read_only' => true]);
        $this->app->forgetInstance(OrdermentumClient::class);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $client = app(OrdermentumClient::class);

        foreach (['putStock' => '/v1/items/v-1', 'patchStock' => '/v1/items'] as $method => $path) {
            try {
                $client->{$method}($path, ['quantity' => 1]);
                $this->fail("{$method} should have been refused");
            } catch (OrdermentumException $e) {
                $this->assertStringContainsString('read-only', $e->getMessage());
            }
        }
        Http::assertNothingSent();

        $this->assertTrue($client->get('/v1/orders/o-1')->successful());
        Http::assertSentCount(1);
    }

    public function test_ingestion_with_push_off_only_reads_from_ordermentum()
    {
        Queue::fake();
        $this->mappedProduct('ING-1', 'var-ing');
        Http::fake(['api.om.test/v1/orders/*' => Http::response([
            'id' => 'o-ro', 'status' => 'Placed', 'updatedAt' => '2026-11-02T01:00:00.000Z',
            'lineItems' => [['id' => 'l1', 'variantId' => 'var-ing', 'quantity' => 3, 'price' => '1.00']],
        ])]);

        $this->assertSame('processed', app(OrderIngestor::class)->ingest('o-ro'));

        $this->assertSame(3, Order::sole()->lines->sole()->reservation->qty_base);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET');
        Queue::assertNotPushed(PushOrdermentumStock::class);
    }

    public function test_a_local_fixture_replaces_the_fetch_so_edits_and_cancels_need_no_ordermentum()
    {
        Http::preventStrayRequests();
        $this->mappedProduct('FIX-1', 'var-fix');
        $fixtures = app(LocalOrderFixtures::class);
        $order = ['id' => 'o-fix', 'status' => 'Placed', 'updatedAt' => '2026-11-02T01:00:00.000Z',
            'lineItems' => [['id' => 'l1', 'variantId' => 'var-fix', 'quantity' => 2, 'price' => '1.00']]];

        $fixtures->put('o-fix', $order);
        app(OrderIngestor::class)->ingest('o-fix');
        $fixtures->put('o-fix', ['status' => 'cancelled', 'updatedAt' => '2026-11-02T02:00:00.000Z'] + $order);
        app(OrderIngestor::class)->ingest('o-fix');

        $this->assertSame('cancelled', Order::sole()->status->value);

        $this->app['env'] = 'production';
        $this->assertFalse($fixtures->enabled(), 'Never outside local/testing');
    }

    public function test_webhook_simulator_signs_like_ordermentum_and_bad_signature_gets_401()
    {
        $this->mappedProduct('SIM-1', 'var-sim');
        Http::fake(['bwms.local.test/*' => Http::response(['ok' => true])]);

        $this->artisan('om:simulate-webhook', ['orderId' => 'o-sim', '--lines' => 'SIM-1:4'])->assertSuccessful();
        $this->artisan('om:simulate-webhook', ['orderId' => 'o-sim', '--bad-signature' => true])->assertFailed(); // the fake answers 200, not 401

        [$good, $bad] = Http::recorded()->map(fn ($pair) => $pair[0])->values()->all();
        $this->assertSame('http://bwms.local.test/webhooks/ordermentum/hook-secret-123', $good->url());

        // Replay what the simulator sent into the real endpoint.
        $replay = fn (Request $r) => $this->call('POST', parse_url($r->url(), PHP_URL_PATH), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ORDERMENTUM_SIGNATURE' => $r->header('x-ordermentum-signature')[0]], $r->body());
        $replay($good)->assertOk();
        $replay($bad)->assertStatus(401);

        $order = Order::where('external_ref', 'o-sim')->sole();
        $this->assertSame(4, $order->lines->sole()->reservation->qty_base);
        $this->assertSame('valid_hex', IntegrationInbox::sole()->signature_status);
    }

    public function test_portal_simulator_sends_signed_calls_and_a_retry_reuses_the_key()
    {
        config(['bwms.portal.api_enabled' => true, 'bwms.portal.inbound_keys' => ['local' => 'local-secret']]);
        $p = Product::factory()->create(['sku' => 'POR-1']);
        $this->stockIn($p, 10);
        ChannelListing::create(['channel' => 'portal', 'product_id' => $p->id, 'product_unit_id' => $p->units()->value('id'),
            'external_product_id' => '501', 'external_variant_id' => '501', 'sync_enabled' => false]);
        Http::fake(['bwms.local.test/*' => Http::response(['status' => 'confirmed'], 201)]);

        $this->artisan('portal:simulate', ['action' => 'reserve', '--order' => 'SIM-T1', '--lines' => '501:2', '--retry' => true])->assertSuccessful();

        $sent = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(2, $sent);
        $this->assertSame($sent[0]->header('Idempotency-Key'), $sent[1]->header('Idempotency-Key'));

        $replay = fn (Request $r) => $this->call('POST', parse_url($r->url(), PHP_URL_PATH), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BWMS_KEY_ID' => $r->header('X-BWMS-Key-Id')[0], 'HTTP_X_BWMS_TIMESTAMP' => $r->header('X-BWMS-Timestamp')[0],
            'HTTP_X_BWMS_SIGNATURE' => $r->header('X-BWMS-Signature')[0], 'HTTP_IDEMPOTENCY_KEY' => $r->header('Idempotency-Key')[0],
        ], $r->body());
        $replay($sent[0])->assertCreated()->assertJsonPath('lines.0.reserved_qty', 2);
        $replay($sent[1])->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(1, Order::where('external_ref', 'SIM-T1')->count());
    }
}
