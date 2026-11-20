<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\PickListService;
use App\Services\Stock\BatchTrackingService;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StocktakeService;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class BatchTrackingOffTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $milk;

    private Location $binA;

    private Location $binB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->binA = Location::create(['type' => 'bin', 'parent_id' => $this->site()->id, 'code' => 'A-1', 'name' => 'A', 'is_sellable' => true]);
        $this->binB = Location::create(['type' => 'bin', 'parent_id' => $this->site()->id, 'code' => 'B-1', 'name' => 'B', 'is_sellable' => true]);
        $this->milk = Product::factory()->batchTracked()->create(['sku' => 'MILK-1']);
        $y1 = $this->batch($this->milk, 'Y1', $this->inDays(20));
        $y2 = $this->batch($this->milk, 'Y2', $this->inDays(40));
        $this->stockIn($this->milk, 6, $this->binA, $y1);
        $this->stockIn($this->milk, 4, $this->binA, $y2);
        $this->stockIn($this->milk, 3, $this->binB, $y1);
    }

    private function inDays(int $days): string
    {
        return Carbon::parse(BusinessDate::today())->addDays($days)->toDateString();
    }

    private function disable(bool $includeExpired = false, ?Product $product = null): Product
    {
        return app(BatchTrackingService::class)->disable($product ?? $this->milk, $includeExpired, $this->manager);
    }

    private function onHand(Location $bin, ?Batch $batch = null): int
    {
        return (int) StockBalance::where('product_id', $this->milk->id)->where('location_id', $bin->id)->where('batch_key', $batch?->id ?? 0)->value('on_hand');
    }

    public function test_each_bin_merges_into_one_unbatched_balance_and_on_hand_is_kept()
    {
        $this->disable();

        $this->assertFalse($this->milk->fresh()->is_batch_tracked);
        $this->assertSame([10, 3], [$this->onHand($this->binA), $this->onHand($this->binB)]);
        $this->assertSame(0, StockBalance::where('product_id', $this->milk->id)->where('batch_key', '!=', 0)->where('on_hand', '!=', 0)->count());
        $this->assertSame(13, (int) StockBalance::where('product_id', $this->milk->id)->sum('on_hand'));

        $moves = StockMovement::where('source_type', 'batch_disable')->get();
        $this->assertCount(5, $moves); // 2 batches out + 1 in in A, 1 out + 1 in in B
        $this->assertTrue($moves->every(fn ($m) => $m->type === MovementType::Adjustment && $m->reasonCode->code === 'BATCH_DISABLE'));
        $this->assertSame([0, 0], [$moves->where('location_id', $this->binA->id)->sum('qty'), $moves->where('location_id', $this->binB->id)->sum('qty')]);

        // Receiving and moves no longer take a lot / best-before.
        $this->stockIn($this->milk->fresh(), 2, $this->binA);
        $this->assertSame(12, $this->onHand($this->binA));
        $this->expectException(InvalidMovement::class);
        $this->stockIn($this->milk->fresh(), 1, $this->binA, Batch::where('lot_code', 'Y1')->first());
    }

    public function test_expired_batches_need_include_expired_or_a_write_off_first()
    {
        $old = $this->batch($this->milk, 'OLD', $this->inDays(-2));
        $this->stockIn($this->milk, 2, $this->binB, $old);

        $this->assertSame([['bin' => $this->binB->path(), 'lot' => 'OLD', 'best_before' => $this->inDays(-2), 'qty' => 2]], app(BatchTrackingService::class)->expired($this->milk));
        try {
            $this->disable();
            $this->fail('Expired stock merged without asking');
        } catch (InvalidMovement $e) {
            $this->assertStringContainsString('expired stock', $e->getMessage());
            $this->assertStringContainsString('lot OLD', $e->getMessage());
        }
        $this->assertTrue($this->milk->fresh()->is_batch_tracked);
        $this->assertSame(0, StockMovement::where('source_type', 'batch_disable')->count());

        $this->disable(includeExpired: true);
        $this->assertSame([10, 5], [$this->onHand($this->binA), $this->onHand($this->binB)]);
        $this->assertTrue(AuditLog::where('event', 'batch_tracking_disabled')->sole()->meta['expired_included']);
    }

    public function test_refused_on_an_open_pick_list_a_stocktake_in_progress_or_a_negative_bin()
    {
        $service = app(BatchTrackingService::class);

        $order = $this->manualOrder($this->manager, $this->milk, 2);
        $list = app(PickListService::class)->create($this->manager, $this->site(), [$order->id])['pickList'];
        $this->assertSame('On an open pick list. Dispatch or cancel it first.', $service->offBlocker($this->milk));
        app(PickListService::class)->cancel($list);
        $this->assertNull($service->offBlocker($this->milk->fresh()));

        $stocktake = app(StocktakeService::class)->create($this->manager, $this->site());
        $this->assertSame('On a stocktake in progress.', $service->offBlocker($this->milk));
        app(StocktakeService::class)->cancel($stocktake);

        $this->ledger()->postOne(new MovementDraft(product: $this->milk, location: $this->binB, qty: -5, type: MovementType::Sale, idempotencyKey: 't:'.Str::uuid(),
            batch: Batch::where('lot_code', 'Y1')->first(), allowNegative: true));
        $this->assertSame('A bin is below zero. Count it first.', $service->offBlocker($this->milk));
        $this->expectException(InvalidMovement::class);
        $this->disable();
    }

    public function test_page_preview_and_a_batch_of_products_where_some_are_skipped()
    {
        $soy = Product::factory()->batchTracked()->create(['sku' => 'SOY-1']);
        $this->stockIn($soy, 4, $this->binA, $this->batch($soy, 'S1', $this->inDays(10)));
        app(StocktakeService::class)->create($this->manager, $this->binB); // holds MILK-1 (it has stock in B), not SOY-1

        $this->actingAs($this->manager)->get(route('batch-tracking.index', ['mode' => 'off', 'products' => "{$this->milk->id},{$soy->id}"]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('products/BatchTrackingOff')
                ->where('rows.0.sku', 'MILK-1')->where('rows.0.blocker', 'On a stocktake in progress.')
                ->where('rows.1.bins.0.batches.0.lot', 'S1')->where('rows.1.blocker', null));

        $this->actingAs($this->manager)->post(route('batch-tracking.disable'), ['products' => [$this->milk->id, $soy->id]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'turned off for 1 product') && str_contains($m, 'Skipped: MILK-1: On a stocktake in progress.'));
        $this->assertFalse($soy->fresh()->is_batch_tracked);
        $this->assertTrue($this->milk->fresh()->is_batch_tracked);

        $log = AuditLog::where('event', 'batch_tracking_disabled')->sole();
        $this->assertSame([$soy->id, ['is_batch_tracked' => true], ['is_batch_tracked' => false], $this->manager->id],
            [(int) $log->auditable_id, $log->old_values, $log->new_values, $log->user_id]);
        $this->assertSame([['lot' => 'S1', 'best_before' => $this->inDays(10), 'qty' => 4, 'expired' => false]], $log->meta['bins'][0]['batches']);

        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $this->actingAs($staff)->post(route('batch-tracking.disable'), ['products' => [$this->milk->id]])->assertForbidden();
        $this->actingAs($this->manager)->get(route('stock.show', $this->milk))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('reasons', fn ($r) => collect($r)->pluck('code')->intersect(['BATCH_ENABLE', 'BATCH_DISABLE'])->isEmpty()));
    }
}
