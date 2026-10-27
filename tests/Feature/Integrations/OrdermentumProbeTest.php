<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Models\ChannelListing;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use LogicException;
use Tests\Support\OrdermentumFixtures;
use Tests\TestCase;

class OrdermentumProbeTest extends TestCase
{
    use OrdermentumFixtures, RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/bwms-om-probe-'.uniqid();
        $this->app->useStoragePath($this->storage);
        $this->configureOrdermentum();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function order(array $lines = [['id' => 'li-1', 'quantity' => 2, 'product' => ['id' => 'prod-9'], 'variantId' => 'aaaaaaaa-0000-4000-8000-000000000009', 'price' => '10.00']]): array
    {
        return ['id' => 'ord-1', 'status' => 'Placed', 'retailer' => ['name' => 'Secret Cafe', 'address' => '1 Private St'], 'notes' => 'Back door code 1234', 'lineItems' => $lines];
    }

    public function test_order_probe_prints_raw_order_and_highlights_line_item_fields()
    {
        Http::fake(['api.om.test/v1/orders/*' => Http::response($this->order())]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'ord-1'])
            ->expectsOutputToContain('Line array: lineItems (1 entries)')
            ->expectsTable(['Path', 'Value'], [
                ['lineItems.0.quantity', '2'],
                ['lineItems.0.product.id', 'prod-9'],
                ['lineItems.0.variantId', 'aaaaaaaa-0000-4000-8000-000000000009'],
            ])
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://api.om.test/v1/orders/ord-1?includeLines=true'
            && $r->hasHeader('x-api-key', 'om_api_test') && ! $r->hasHeader('Authorization'));
    }

    public function test_structure_mode_shows_field_names_and_line_ids_but_no_customer_details()
    {
        Http::fake(['api.om.test/v1/orders/*' => Http::response($this->order())]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'ord-1', '--structure' => true])
            ->expectsOutputToContain('aaaaaaaa-0000-4000-8000-000000000009') // lineItems.0.variantId row (one line satisfies one expectation)
            ->expectsOutputToContain('retailer.name')
            ->doesntExpectOutputToContain('Secret Cafe')
            ->doesntExpectOutputToContain('1 Private St')
            ->doesntExpectOutputToContain('Back door')
            ->assertSuccessful();
    }

    public function test_order_probe_with_headers_tests_each_style_against_the_line_ids()
    {
        Http::fake([
            'api.om.test/v1/orders/*' => Http::response($this->order()),
            'stock.om.test/*' => fn (Request $r) => $r->hasHeader('context-id') ? Http::response(['tracked' => true]) : Http::response([], 403),
        ]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'ord-1', '--headers' => 'both', '--structure' => true])
            ->expectsTable(['Line field', 'Headers', 'HTTP', 'tracked'], [
                ['lineItems.0.variantId', 'plain', 200, 'true'],
                ['lineItems.0.variantId', 'x', 403, '-'],
            ])
            ->assertSuccessful();
    }

    public function test_order_probe_warns_when_no_line_items_are_returned()
    {
        Http::fake(['api.om.test/*' => Http::response(['id' => 'ord-2', 'total' => 10])]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'ord-2', '--no-include-lines' => true])
            ->expectsOutputToContain('No line array found')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.om.test/v1/orders/ord-2');
    }

    public function test_stock_probe_prints_available_with_timestamp_and_records_the_reading()
    {
        Http::fake(['stock.om.test/*' => Http::response([
            'available' => 7, 'tracked' => true, 'allowOversell' => false, 'stock' => [['locationId' => 'loc-1', 'available' => 7]],
        ])]);

        $this->artisan('om:probe', ['what' => 'stock', 'id' => 'var-9', '--label' => 'baseline'])
            ->expectsOutputToContain('Fetched ')
            ->expectsTable(['available', 'tracked', 'allowOversell'], [['7', 'true', 'false']])
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://stock.om.test/v1/items/var-9?locationId=loc-1'
            && $r->hasHeader('context-entity', 'supplier') && $r->hasHeader('context-id', 'sup-1') && ! $r->hasHeader('x-context-id'));

        $logged = json_decode(trim(File::get(storage_path('logs/om-probe.jsonl'))), true);
        $this->assertSame(['baseline', 'var-9', 7, true], [$logged['label'], $logged['item_id'], $logged['available'], $logged['tracked']]);
    }

    public function test_stock_probe_can_send_one_context_header_style_only()
    {
        Http::fake(['stock.om.test/*' => Http::response(['available' => 1])]);

        $this->artisan('om:probe', ['what' => 'stock', 'id' => 'var-9', '--headers' => 'x'])->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->hasHeader('x-context-id') && ! $r->hasHeader('context-id'));
    }

    public function test_tracking_lists_mapped_items_with_tracking_off_and_stores_it_on_the_listing()
    {
        $make = function (string $sku, string $variant) {
            $p = Product::factory()->create(['sku' => $sku]);

            return ChannelListing::create(['channel' => 'ordermentum', 'product_id' => $p->id, 'product_unit_id' => $p->units()->value('id'), 'external_variant_id' => $variant]);
        };
        $tracked = $make('TRK-1', 'v-tracked');
        $untracked = $make('UNT-1', 'v-untracked');
        $missing = $make('MIS-1', 'v-missing');
        Http::fake([
            'stock.om.test/v1/items/v-tracked*' => Http::response(['tracked' => true, 'available' => 3]),
            'stock.om.test/v1/items/v-untracked*' => Http::response(['tracked' => false, 'available' => 0]),
            'stock.om.test/v1/items/v-missing*' => Http::response([], 404),
        ]);

        $this->artisan('om:probe', ['what' => 'tracking'])
            ->expectsTable(['Tracked', 'Not tracked', 'Not found (404)', 'Error'], [[1, 1, 1, 0]])
            ->expectsTable(['SKU', 'Variant', 'State', 'HTTP'], [['UNT-1', 'v-untracked', 'untracked', 200], ['MIS-1', 'v-missing', 'missing', 404]])
            ->assertSuccessful();

        $this->assertSame(['tracked', 'untracked', 'missing'], [$tracked->fresh()->remote_status, $untracked->fresh()->remote_status, $missing->fresh()->remote_status]);
        $this->assertFalse($untracked->fresh()->remote_tracked);
    }

    public function test_recent_lists_order_ids_with_status_only()
    {
        Http::fake(['api.om.test/v2/orders*' => Http::response(['data' => [
            ['id' => 'o-1', 'status' => 'Placed', 'createdAt' => 'c', 'updatedAt' => 'u', 'retailerId' => 'r-1', 'reference' => 'Secret ref'],
        ]])]);

        $this->artisan('om:probe', ['what' => 'recent', '--limit' => 3])
            ->expectsTable(['id', 'status', 'createdAt', 'updatedAt', 'cancelled'], [['o-1', 'Placed', 'c', 'u', '']])
            ->doesntExpectOutputToContain('Secret ref')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pageSize=3') && str_contains($r->url(), 'supplierId=sup-1'));
    }

    public function test_probe_only_ever_reads_from_ordermentum()
    {
        Http::fake(['api.om.test/*' => Http::response(['id' => 'o']), 'stock.om.test/*' => Http::response(['available' => 0])]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'o'])->assertSuccessful();
        $this->artisan('om:probe', ['what' => 'stock', 'id' => 'v'])->assertSuccessful();
        $this->artisan('om:probe', ['what' => 'recent'])->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_missing_api_key_fails_clearly_and_the_token_is_never_logged()
    {
        Log::spy();
        Http::fake(['api.om.test/*' => Http::response(['error' => 'nope'], 500)]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'x'])->doesntExpectOutputToContain('om_api_test')->assertFailed();
        Log::shouldNotHaveReceived('info', fn ($m, $ctx = []) => str_contains($m.json_encode($ctx), 'om_api_test'));

        config(['services.ordermentum.api_key' => null]);
        $this->app->forgetInstance(OrdermentumClient::class);
        $this->artisan('om:probe', ['what' => 'order', 'id' => 'x'])->expectsOutputToContain('ORDERMENTUM_API_KEY is not set')->assertFailed();
    }

    public function test_rate_limit_waits_out_short_retry_after_and_fails_on_long_one()
    {
        Http::fake(['api.om.test/*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '2'])->push(['id' => 'a'])
            ->push([], 429, ['Retry-After' => '120'])]);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'a'])->assertSuccessful();
        Sleep::assertSleptTimes(1);

        $this->artisan('om:probe', ['what' => 'order', 'id' => 'b'])
            ->expectsOutputToContain('rate limit hit (429, Retry-After 120s)')
            ->assertFailed();
    }

    public function test_client_side_budget_is_per_endpoint()
    {
        config(['services.ordermentum.rate_limits' => ['stock:PUT' => 2, 'stock:GET' => 100]]);
        config(['services.ordermentum.max_retry_wait' => 5]);
        $this->app->forgetInstance(OrdermentumClient::class);
        Http::fake(['stock.om.test/*' => Http::response([])]);
        $client = app(OrdermentumClient::class);

        $client->putStock('/v1/items/a', ['available' => 1]);
        $client->putStock('/v1/items/b', ['available' => 1]);
        $client->getStock('/v1/items/c'); // other endpoint group: unaffected

        try {
            $client->putStock('/v1/items/d', ['available' => 1]);
            $this->fail('Expected the PUT budget to be exhausted');
        } catch (OrdermentumException $e) {
            $this->assertSame(429, $e->status);
            $this->assertGreaterThan(5, $e->retryAfter, 'Longer than max_retry_wait: a job releases instead of sleeping');
        }
        Http::assertSentCount(3);
    }

    public function test_client_refuses_to_call_inside_a_transaction()
    {
        Http::fake();

        $this->expectException(LogicException::class);
        DB::transaction(fn () => app(OrdermentumClient::class)->get('/v1/orders/x'));
    }

    public function test_secret_reports_found_and_length_only_and_can_write_the_env_file()
    {
        $envDir = sys_get_temp_dir().'/bwms-env-'.uniqid();
        File::ensureDirectoryExists($envDir);
        File::put($envDir.'/'.$this->app->environmentFile(), "APP_NAME=BWMS\nORDERMENTUM_WEBHOOK_SECRET=old\n");
        $this->app->useEnvironmentPath($envDir);
        Http::fake(['api.om.test/v1/suppliers/sup-1' => Http::response(['id' => 'sup-1', 'name' => 'x', 'settings' => ['sharedSecret' => 'Sh4red-S3cret']])]);

        $this->artisan('om:probe', ['what' => 'secret'])
            ->expectsOutputToContain('sharedSecret: found (length 13)')
            ->doesntExpectOutputToContain('Sh4red-S3cret')
            ->assertSuccessful();
        $this->assertStringContainsString('=old', File::get($envDir.'/'.$this->app->environmentFile()), 'Not written without --write-env');

        $this->artisan('om:probe', ['what' => 'secret', '--write-env' => true])->doesntExpectOutputToContain('Sh4red-S3cret')->assertSuccessful();
        $env = File::get($envDir.'/'.$this->app->environmentFile());
        $this->assertStringContainsString('ORDERMENTUM_WEBHOOK_SECRET="Sh4red-S3cret"', $env);
        $this->assertSame(1, substr_count($env, 'ORDERMENTUM_WEBHOOK_SECRET='));
        File::deleteDirectory($envDir);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET');
    }

    public function test_write_test_refuses_without_confirm()
    {
        Http::fake();
        $this->artisan('om:probe', ['what' => 'write-test', 'id' => 'v-1'])->expectsOutputToContain('--confirm')->assertFailed();
        Http::assertNothingSent();
    }

    private function mappedVariant(string $variant = 'v-1'): void
    {
        $p = Product::factory()->create(['name' => 'Quiet Tea']);
        ChannelListing::create(['channel' => 'ordermentum', 'product_id' => $p->id, 'product_unit_id' => $p->units()->value('id'),
            'external_variant_id' => $variant, 'external_product_id' => 'prod-1']);
    }

    public function test_write_test_refuses_when_there_is_no_stock_figure_at_the_location()
    {
        $this->mappedVariant();
        Http::fake(['stock.om.test/*' => Http::response(['available' => null, 'tracked' => true, 'stock' => []])]);

        $this->artisan('om:probe', ['what' => 'write-test', 'id' => 'v-1', '--confirm' => true])
            ->expectsOutputToContain('nothing was written')->assertFailed();

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    }

    public function test_write_test_rewrites_the_same_value_through_put_and_patch()
    {
        $this->mappedVariant();
        Http::fake(['stock.om.test/*' => fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['available' => 7, 'tracked' => true, 'allowOversell' => false])
            : Http::response(['ok' => true])]);

        $this->artisan('om:probe', ['what' => 'write-test', 'id' => 'v-1', '--confirm' => true])
            ->expectsTable(['Step', 'HTTP', 'available after', 'Value'], [
                ['read', 200, 7, 'baseline'],
                ['PUT (context-*)', 200, 7, 'unchanged'],
                ['PUT (x-context-*)', 200, 7, 'unchanged'],
                ['PATCH UPDATE', 200, 7, 'unchanged'],
            ])
            ->expectsOutputToContain('available stayed at 7')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['available'] === 7 && $r['tracked'] === true && $r->hasHeader('context-id') && ! $r->hasHeader('x-context-id'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('x-context-id') && ! $r->hasHeader('context-id'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['actions'][0]['action'] === 'UPDATE' && $r['actions'][0]['quantity'] === 7
            && $r['actions'][0]['productId'] === 'prod-1' && $r['actions'][0]['name'] === 'Quiet Tea');
    }

    public function test_write_test_stops_and_restores_if_the_figure_changes()
    {
        $this->mappedVariant();
        $reads = 0;
        Http::fake(['stock.om.test/*' => function (Request $r) use (&$reads) {
            if ($r->method() !== 'GET') {
                return Http::response(['ok' => true]);
            }

            return Http::response(['available' => ++$reads === 1 ? 7 : 6, 'tracked' => true]);
        }]);

        $this->artisan('om:probe', ['what' => 'write-test', 'id' => 'v-1', '--confirm' => true])
            ->expectsOutputToContain('Put the original 7 back')
            ->assertFailed();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH'); // stopped after the first change
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['available'] === 7);
    }

    public function test_bulk_patch_calls_are_spaced_but_not_rate_capped()
    {
        config(['services.ordermentum.min_interval' => ['stock:PATCH' => 5]]);
        $this->app->forgetInstance(OrdermentumClient::class);
        Http::fake(['stock.om.test/*' => Http::response([])]);
        $client = app(OrdermentumClient::class);

        $client->patchStock('/v1/items', ['actions' => []]);
        $client->patchStock('/v1/items', ['actions' => []]);
        $client->putStock('/v1/items/a', ['available' => 1]); // other group: not spaced

        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds >= 4900 && $duration->totalMilliseconds <= 5000);
        Http::assertSentCount(3);
    }
}
