<?php

namespace Tests\Feature\Stock;

use App\Console\Commands\VerifyStockBalances;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Orders\AvailabilityService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/**
 * Real concurrency: each worker is a separate PHP process with its own DB
 * connection, released at the same instant. Data must be committed for the
 * workers to see it, hence DatabaseTruncation instead of RefreshDatabase.
 *
 * @group concurrency
 */
class StockLedgerConcurrencyTest extends TestCase
{
    use DatabaseTruncation, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
    }

    protected function tearDown(): void
    {
        // Leave the database empty so later RefreshDatabase tests start clean
        // (this class commits real rows).
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_concurrent_withdrawals_never_oversell()
    {
        $product = Product::factory()->create();
        $this->stockIn($product, 10);

        // 8 workers each try to take 3 from 10: exactly 3 can succeed.
        $results = $this->runWorkers(8, fn (int $i) => ['withdraw', [
            'product_id' => $product->id, 'location_id' => $this->bin()->id, 'qty' => 3, 'key' => "w:{$i}",
        ]]);

        $this->assertSame(3, count(array_filter($results, fn ($r) => $r === 'ok')), json_encode($results));
        $this->assertSame(5, count(array_filter($results, fn ($r) => $r === 'rejected:InsufficientStock')), json_encode($results));
        $this->assertSame(1, $this->balance($product));
        $this->assertSame(1, $this->ledgerSum($product));
        $this->assertSame([], VerifyStockBalances::findDrift());
    }

    public function test_concurrent_duplicate_delivery_posts_exactly_once()
    {
        $product = Product::factory()->create();

        $results = $this->runWorkers(6, fn () => ['same-key', [
            'product_id' => $product->id, 'location_id' => $this->bin()->id, 'qty' => 5, 'key' => 'dup:order-42:line-1',
        ]]);

        $ids = array_unique(array_map(fn ($r) => $r, $results));
        $this->assertCount(1, $ids, 'All workers should get the same movement back: '.json_encode($results));
        $this->assertStringStartsWith('ok:', $ids[0]);
        $this->assertSame(1, StockMovement::where('idempotency_key', 'dup:order-42:line-1')->count());
        $this->assertSame(5, $this->balance($product));
    }

    public function test_many_interleaved_movements_lose_no_updates()
    {
        $product = Product::factory()->create();
        $workers = 6;
        $iterations = 25;

        $results = $this->runWorkers($workers, fn (int $i) => ['mixed', [
            'product_id' => $product->id, 'location_id' => $this->bin()->id, 'iterations' => $iterations, 'seed' => 1000 + $i, 'key' => "mix:{$i}",
        ]]);

        $this->assertSame($workers * $iterations, count(array_filter($results, fn ($r) => $r === 'ok')), json_encode(array_slice($results, 0, 5)));
        $this->assertSame($workers * $iterations, StockMovement::where('product_id', $product->id)->count());
        $this->assertSame($this->ledgerSum($product), $this->balance($product), 'Projection must equal ledger: an update was lost.');
        $this->assertSame([], VerifyStockBalances::findDrift());
    }

    public function test_concurrent_portal_reservations_never_oversell()
    {
        $product = Product::factory()->create();
        $this->stockIn($product, 10);

        // 8 portal orders of 3 against 10 available: exactly 3 may be accepted (D-3).
        $results = $this->runWorkers(8, fn (int $i) => ['reserve', [
            'product_id' => $product->id, 'site_id' => $this->site()->id,
            'unit_id' => $product->units()->value('id'), 'qty' => 3, 'key' => "P-race-{$i}",
        ]]);

        $this->assertSame(3, count(array_filter($results, fn ($r) => $r === 'ok')), json_encode($results));
        $this->assertSame(5, count(array_filter($results, fn ($r) => $r === 'rejected:InsufficientAvailability')), json_encode($results));
        $this->assertSame(1, app(AvailabilityService::class)->available($product));
    }

    public function test_crossing_multi_slot_postings_do_not_deadlock_or_drift()
    {
        $product = Product::factory()->create();
        $a = $this->bin('STOCK');
        $b = Location::factory()->create(['parent_id' => $a->parent_id, 'code' => 'STOCK-B']);
        $this->stockIn($product, 100, $a);
        $this->stockIn($product, 100, $b);

        $results = $this->runWorkers(6, fn (int $i) => ['crossing-transfers', [
            'product_id' => $product->id, 'a' => $a->id, 'b' => $b->id, 'iterations' => 15,
            'direction' => $i % 2 ? 'ab' : 'ba', 'key' => "x:{$i}",
        ]]);

        $this->assertSame(6 * 15, count(array_filter($results, fn ($r) => $r === 'ok')), json_encode(array_values(array_filter($results, fn ($r) => $r !== 'ok'))));
        // Equal numbers each way: both bins end where they started.
        $this->assertSame(100, $this->balance($product, $a));
        $this->assertSame(100, $this->balance($product, $b));
        $this->assertSame([], VerifyStockBalances::findDrift());
    }

    /**
     * @param  callable(int): array{0: string, 1: array<string, mixed>}  $make
     * @return list<string>
     */
    private function runWorkers(int $count, callable $make): array
    {
        $startAt = microtime(true) + 2.0; // time for every process to boot
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ];

        $processes = [];
        for ($i = 0; $i < $count; $i++) {
            [$scenario, $args] = $make($i);
            $args['start_at'] = $startAt;
            $process = new Process([PHP_BINARY, base_path('tests/Support/ledger-worker.php'), $scenario, json_encode($args)], base_path(), $env, null, 120);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $decoded = json_decode(trim($process->getOutput()), true);
            if (! is_array($decoded)) {
                $this->fail("Worker failed (exit {$process->getExitCode()}): ".$process->getOutput().$process->getErrorOutput());
            }
            $results = [...$results, ...$decoded];
        }

        return $results;
    }
}
