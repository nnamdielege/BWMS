<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/**
 * Real concurrency: separate PHP processes, each with its own DB connection,
 * create POs at the same instant. Data must be committed, hence DatabaseTruncation.
 *
 * @group concurrency
 */
class PurchaseOrderNumberConcurrencyTest extends TestCase
{
    use DatabaseTruncation, StockFixtures;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_concurrent_creation_gives_unique_gap_free_numbers()
    {
        $this->seedStockReferenceData();
        $product = Product::factory()->withPack('Carton', 12)->create();
        $args = [
            'user_id' => $this->userWithRole(Permissions::ROLE_PURCHASING)->id,
            'count' => 5,
            'start_at' => microtime(true) + 2.0,
            'data' => [
                'supplier_id' => Supplier::factory()->create()->id,
                'site_id' => $this->site()->id,
                'order_date' => now()->toDateString(),
                'lines' => [['product_id' => $product->id, 'product_unit_id' => ProductFactory::unitOf($product, 12)->id, 'qty_ordered' => 1, 'unit_cost' => '5.00']],
            ],
        ];
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => config('database.connections.'.config('database.default').'.database'),
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ];

        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $p = new Process([PHP_BINARY, base_path('tests/Support/po-number-worker.php'), json_encode($args)], base_path(), $env, null, 120);
            $p->start();
            $processes[] = $p;
        }
        $numbers = [];
        foreach ($processes as $p) {
            $p->wait();
            $out = json_decode(trim($p->getOutput()), true);
            is_array($out) || $this->fail("Worker failed (exit {$p->getExitCode()}): ".$p->getOutput().$p->getErrorOutput());
            $numbers = [...$numbers, ...$out];
        }

        $errors = array_filter($numbers, fn ($n) => str_starts_with($n, 'error:'));
        $this->assertSame([], array_values($errors));
        $date = now('Australia/Brisbane')->format('Ymd');
        $expected = array_map(fn ($n) => sprintf('PO-%s-%04d', $date, $n), range(1, 30));
        sort($numbers);
        $this->assertSame($expected, $numbers);
        $this->assertSame(30, PurchaseOrder::distinct()->count('number'));
    }
}
