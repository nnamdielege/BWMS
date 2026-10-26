<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Ordermentum\OrdermentumClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use LogicException;
use Tests\TestCase;

class OrdermentumProbeTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/bwms-om-probe-'.uniqid();
        $this->app->useStoragePath($this->storage);

        config(['services.ordermentum' => [
            'api_key' => null, 'username' => 'probe@example.test', 'password' => 'secret-pw',
            'auth_url' => 'https://auth.om.test/v1/auth', 'base_url' => 'https://api.om.test', 'stock_url' => 'https://stock.om.test',
            'supplier_id' => 'sup-1', 'location_id' => 'loc-1',
            'connect_timeout' => 5, 'timeout' => 15, 'max_retry_wait' => 30,
        ]]);
        $this->app->forgetInstance(OrdermentumClient::class);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function fakeLogin(): void
    {
        Http::fake(['auth.om.test/*' => Http::response(['access_token' => 'tok-1'])]);
    }

    public function test_order_probe_prints_raw_order_and_highlights_line_item_fields()
    {
        $this->fakeLogin();
        Http::fake(['api.om.test/v1/orders/*' => Http::response([
            'id' => 'ord-1', 'number' => 'OM1001', 'status' => 'Placed',
            'lineItems' => [['quantity' => 2, 'product' => ['id' => 'prod-9'], 'variantId' => 'var-9', 'price' => '10.00']],
        ])]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'ord-1'])
            ->expectsOutputToContain('Line array: lineItems (1 entries)')
            ->expectsTable(['Path', 'Value'], [
                ['lineItems.0.quantity', '2'],
                ['lineItems.0.product.id', 'prod-9'],
                ['lineItems.0.variantId', 'var-9'],
            ])
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://api.om.test/v1/orders/ord-1?includeLines=true'
            && $r->hasHeader('Authorization', 'Bearer tok-1'));
    }

    public function test_order_probe_warns_when_no_line_items_are_returned()
    {
        $this->fakeLogin();
        Http::fake(['api.om.test/*' => Http::response(['id' => 'ord-2', 'total' => 10])]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'ord-2', '--no-include-lines' => true])
            ->expectsOutputToContain('No line array found')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.om.test/v1/orders/ord-2');
    }

    public function test_stock_probe_prints_available_with_timestamp_and_records_the_reading()
    {
        $this->fakeLogin();
        Http::fake(['stock.om.test/*' => Http::response([
            'available' => 7, 'tracked' => true, 'allowOversell' => false, 'stock' => [['locationId' => 'loc-1', 'available' => 7]],
        ])]);

        $this->artisan('om:probe', ['what' => 'stock', 'id' => 'var-9', '--label' => 'before order'])
            ->expectsOutputToContain('Fetched ')
            ->expectsTable(['available', 'tracked', 'allowOversell'], [['7', 'true', 'false']])
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://stock.om.test/v1/items/var-9?locationId=loc-1'
            && $r->hasHeader('context-entity', 'supplier') && $r->hasHeader('context-id', 'sup-1')
            && $r->hasHeader('x-context-entity', 'supplier') && $r->hasHeader('x-context-id', 'sup-1'));

        $logged = json_decode(trim(File::get(storage_path('logs/om-probe.jsonl'))), true);
        $this->assertSame(['before order', 'var-9', 7], [$logged['label'], $logged['item_id'], $logged['available']]);
    }

    public function test_stock_probe_can_send_one_context_header_style_only()
    {
        $this->fakeLogin();
        Http::fake(['stock.om.test/*' => Http::response(['available' => 1])]);

        $this->artisan('om:probe', ['what' => 'stock', 'id' => 'var-9', '--headers' => 'x'])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://stock.om.test')
            && $r->hasHeader('x-context-id') && ! $r->hasHeader('context-id'));
    }

    public function test_probe_only_ever_reads_from_ordermentum()
    {
        $this->fakeLogin();
        Http::fake(['api.om.test/*' => Http::response(['id' => 'o']), 'stock.om.test/*' => Http::response(['available' => 0])]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'o'])->assertSuccessful();
        $this->artisan('om:probe', ['what' => 'stock', 'id' => 'v'])->assertSuccessful();

        // The only non-GET is the legacy login, which goes to the auth endpoint.
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && $r->url() !== 'https://auth.om.test/v1/auth');
    }

    public function test_api_key_mode_sends_x_api_key_and_skips_login()
    {
        config(['services.ordermentum.api_key' => 'om_api_test']);
        $this->app->forgetInstance(OrdermentumClient::class);
        Http::fake(['api.om.test/*' => Http::response(['id' => 'o'])]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'o'])->expectsOutputToContain('auth: api_key')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'om_api_test') && ! $r->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'auth.om.test'));
    }

    public function test_bearer_token_is_cached_and_refreshed_once_on_401()
    {
        Http::fake([
            'auth.om.test/*' => Http::sequence()->push(['access_token' => 'tok-old'])->push(['access_token' => 'tok-new']),
            'api.om.test/*' => Http::sequence()->push(['id' => 'a'])->push([], 401)->push(['id' => 'b']),
        ]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'a'])->assertSuccessful();
        $this->artisan('om:probe', ['what' => 'order', 'id' => 'b'])->assertSuccessful();

        Http::assertSentCount(5); // login, order a, order b (401), login, order b
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer tok-new'));
    }

    public function test_rate_limit_waits_out_short_retry_after_and_fails_on_long_one()
    {
        $this->fakeLogin();
        Http::fake(['api.om.test/*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '2'])->push(['id' => 'a'])
            ->push([], 429, ['Retry-After' => '120'])]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'a'])->assertSuccessful();
        Sleep::assertSleptTimes(1);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'b'])
            ->expectsOutputToContain('rate limit hit (429, Retry-After 120s)')
            ->assertFailed();
    }

    public function test_http_errors_and_missing_credentials_fail_without_leaking_secrets()
    {
        Http::fake(['auth.om.test/*' => Http::response(['error' => 'bad password secret-pw'], 401)]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'x'])
            ->expectsOutputToContain('Ordermentum login failed (HTTP 401).')
            ->doesntExpectOutputToContain('secret-pw')
            ->assertFailed();

        config(['services.ordermentum.username' => null]);
        $this->app->forgetInstance(OrdermentumClient::class);
        $this->artisan('om:probe', ['what' => 'order', 'id' => 'x'])->expectsOutputToContain('No Ordermentum credentials')->assertFailed();
    }

    public function test_client_refuses_to_call_inside_a_transaction()
    {
        Http::fake();

        $this->expectException(LogicException::class);
        DB::transaction(fn () => app(OrdermentumClient::class)->get('/v1/orders/x'));
    }
}
