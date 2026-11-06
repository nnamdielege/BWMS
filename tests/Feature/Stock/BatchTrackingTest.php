<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Range;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Orders\PickListService;
use App\Services\Stock\BatchTrackingService;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class BatchTrackingTest extends TestCase
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
        $site = $this->site();
        $this->binA = Location::create(['type' => 'bin', 'parent_id' => $site->id, 'code' => 'A-1', 'name' => 'A', 'is_sellable' => true, 'walk_seq' => 10]);
        $this->binB = Location::create(['type' => 'bin', 'parent_id' => $site->id, 'code' => 'B-1', 'name' => 'B', 'is_sellable' => true, 'walk_seq' => 20]);
        $this->milk = Product::factory()->create(['sku' => 'MILK-1']);
        $this->stockIn($this->milk, 12, $this->binA);
        $this->stockIn($this->milk, 5, $this->binB);
    }

    private function enable(array $bestBefore = [], ?Product $product = null): Product
    {
        $product ??= $this->milk;

        return app(BatchTrackingService::class)->enable($product, $bestBefore ?: [$this->binA->id => null, $this->binB->id => null], $this->manager);
    }

    private function batchIn(Location $bin): ?Batch
    {
        return StockBalance::where('product_id', $this->milk->id)->where('location_id', $bin->id)->where('on_hand', '>', 0)->first()?->batch;
    }

    public function test_on_hand_stays_the_same_and_each_bin_goes_into_an_unknown_batch()
    {
        $movementsBefore = StockMovement::count();

        $this->enable([$this->binA->id => '2026-12-01', $this->binB->id => null]);

        $this->assertTrue($this->milk->fresh()->is_batch_tracked);
        $this->assertSame(17, (int) StockBalance::where('product_id', $this->milk->id)->sum('on_hand'));
        $this->assertSame(12, $this->balance($this->milk, $this->binA, $this->batchIn($this->binA)));
        $this->assertSame(0, $this->balance($this->milk, $this->binA), 'Nothing left unbatched');
        $this->assertSame(0, $this->balance($this->milk, $this->binB));

        $this->assertSame(['UNKNOWN', '2026-12-01'], [$this->batchIn($this->binA)->lot_code, $this->batchIn($this->binA)->best_before->toDateString()]);
        $this->assertSame(['UNKNOWN', null], [$this->batchIn($this->binB)->lot_code, $this->batchIn($this->binB)->best_before]);
    }

    public function test_it_goes_through_the_ledger_with_a_net_change_of_zero_and_the_reason()
    {
        $last = StockMovement::max('id');

        $this->enable();

        $moves = StockMovement::where('id', '>', $last)->with('reasonCode')->get();
        $this->assertCount(4, $moves);
        $this->assertSame(0, (int) $moves->sum('qty'));
        $this->assertSame(['Batch tracking enabled'], $moves->pluck('reasonCode.name')->unique()->values()->all());
        $this->assertSame([MovementType::Adjustment], $moves->pluck('type')->unique()->values()->all());

        foreach ([$this->binA, $this->binB] as $bin) {
            foreach (StockBalance::where('product_id', $this->milk->id)->where('location_id', $bin->id)->get() as $balance) {
                $this->assertSame((int) $balance->on_hand, $this->ledgerSum($this->milk, $bin, $balance->batch), 'Balances still match the ledger');
            }
        }
        $this->artisan('stock:verify')->assertSuccessful();
    }

    public function test_it_is_written_to_the_audit_log_by_bin()
    {
        $this->enable([$this->binA->id => '2026-12-01', $this->binB->id => null]);

        $log = AuditLog::where('event', 'batch_tracking_enabled')->sole();
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertSame([12, 5], array_column($log->meta['bins'], 'qty'));
        $this->assertSame(['2026-12-01', null], array_column($log->meta['bins'], 'best_before'));
    }

    public function test_undated_unknown_stock_is_picked_first_ahead_of_batches_received_later()
    {
        $this->enable([$this->binA->id => null, $this->binB->id => null]);
        $fresh = $this->batch($this->milk, 'L100', now()->addDays(2)->toDateString());
        $this->stockIn($this->milk->fresh(), 50, $this->binA, $fresh);

        $result = app(FefoAllocator::class)->allocate($this->milk->fresh(), 20);

        $this->assertSame(
            [['UNKNOWN', 12], ['UNKNOWN', 5], ['L100', 3]],
            array_map(fn ($l) => [$l['balance']->batch->lot_code, $l['qty']], $result['lines']),
        );
    }

    public function test_it_reaches_a_real_pick_list_first_too()
    {
        $this->enable();
        $this->stockIn($this->milk->fresh(), 50, $this->binA, $this->batch($this->milk, 'L100', now()->addDays(2)->toDateString()));
        $order = $this->manualOrder($this->manager, $this->milk->fresh(), 3);

        $list = app(PickListService::class)->create($this->manager, $this->site(), [$order->id])['pickList'];

        $this->assertSame('UNKNOWN', $list->lines()->first()->batch->lot_code);
    }

    public function test_undated_unknown_stock_never_expires()
    {
        $this->enable();
        $this->travel(5)->years();

        $this->assertSame(0, app(FefoAllocator::class)->allocate($this->milk->fresh(), 17)['shortfall']);
    }

    public function test_unknown_stock_with_a_date_is_ordinary_fefo()
    {
        $this->enable([$this->binA->id => now()->addDays(30)->toDateString(), $this->binB->id => now()->addDays(30)->toDateString()]);
        $sooner = $this->batch($this->milk, 'L100', now()->addDays(2)->toDateString());
        $this->stockIn($this->milk->fresh(), 4, $this->binA, $sooner);

        $first = app(FefoAllocator::class)->allocate($this->milk->fresh(), 4)['lines'][0];

        $this->assertSame('L100', $first['balance']->batch->lot_code);
    }

    public function test_from_then_on_stock_needs_a_batch()
    {
        $this->enable();

        $this->expectException(InvalidMovement::class);
        $this->stockIn($this->milk->fresh(), 5, $this->binA);
    }

    public function test_the_ledger_exception_only_covers_taking_stock_out_of_the_unbatched_slot()
    {
        $this->enable();

        $this->expectException(InvalidMovement::class);
        $this->ledger()->postOne(new MovementDraft(
            product: $this->milk->fresh(), location: $this->binA, qty: 5, type: MovementType::Adjustment, idempotencyKey: (string) Str::uuid(),
            reason: $this->reason('BATCH_ENABLE'), leavesUnbatchedStock: true,
        ));
    }

    public function test_a_product_with_no_stock_just_changes_the_setting()
    {
        $empty = Product::factory()->create();

        app(BatchTrackingService::class)->enable($empty, [], $this->manager);

        $this->assertTrue($empty->fresh()->is_batch_tracked);
        $this->assertSame(1, AuditLog::where('event', 'batch_tracking_enabled')->count());
    }

    public function test_every_bin_must_be_accounted_for()
    {
        $this->expectExceptionMessage('bins changed since the preview');

        $this->enable([$this->binA->id => null]);
    }

    public function test_a_batch_tracked_product_is_refused()
    {
        $this->enable();

        $this->expectExceptionMessage('Already batch-tracked');
        $this->enable();
    }

    public function test_an_open_pick_list_blocks_it_until_dispatched_or_cancelled()
    {
        $order = $this->manualOrder($this->manager, $this->milk, 2);
        $list = app(PickListService::class)->create($this->manager, $this->site(), [$order->id])['pickList'];

        $this->assertStringContainsString('open pick list', app(BatchTrackingService::class)->blocker($this->milk));

        app(PickListService::class)->cancel($list);
        $this->assertNull(app(BatchTrackingService::class)->blocker($this->milk->fresh()));
    }

    public function test_a_stocktake_in_progress_blocks_it()
    {
        app(StocktakeService::class)->create($this->manager, $this->site());
        $this->assertSame(Stocktake::STATUS_COUNTING, Stocktake::sole()->status);

        $this->assertStringContainsString('stocktake', app(BatchTrackingService::class)->blocker($this->milk));
    }

    public function test_a_bin_below_zero_blocks_it()
    {
        $this->ledger()->postOne(new MovementDraft(
            product: $this->milk, location: $this->binB, qty: -9, type: MovementType::Adjustment, idempotencyKey: (string) Str::uuid(),
            reason: $this->reason('CORRECTION'), note: 'test', allowNegative: true,
        ));

        $this->assertStringContainsString('below zero', app(BatchTrackingService::class)->blocker($this->milk));
    }

    public function test_the_preview_lists_bins_and_ranges_and_changes_nothing()
    {
        $range = Range::create(['name' => 'Milks']);
        $this->milk->update(['range_id' => $range->id]);
        Product::factory()->create(['range_id' => $range->id, 'is_batch_tracked' => true]);

        $this->actingAs($this->manager)->get(route('batch-tracking.index', ['products' => (string) $this->milk->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->component('products/BatchTracking')
                ->where('rows.0.sku', 'MILK-1')
                ->where('rows.0.bins', fn ($bins) => collect($bins)->pluck('qty')->all() === [12, 5])
                ->where('rows.0.blocker', null)
                ->where('ranges', fn ($ranges) => count($ranges) === 1 && $ranges[0]['name'] === 'Milks' && $ranges[0]['count'] === 1 && $ranges[0]['ids'] === [$this->milk->id]));

        $this->assertFalse($this->milk->fresh()->is_batch_tracked);
    }

    public function test_a_range_applies_together_and_one_blocked_product_holds_nothing_else_back()
    {
        $juice = Product::factory()->create(['sku' => 'JUICE-1']);
        $this->stockIn($juice, 4, $this->binA);
        $held = Product::factory()->create(['sku' => 'HELD-1']);
        $this->stockIn($held, 4, $this->binA);
        $order = $this->manualOrder($this->manager, $held, 1);
        app(PickListService::class)->create($this->manager, $this->site(), [$order->id]);

        $this->actingAs($this->manager)->post(route('batch-tracking.apply'), ['products' => [
            ['id' => $this->milk->id, 'bins' => [['location_id' => $this->binA->id, 'best_before' => '2026-12-01'], ['location_id' => $this->binB->id, 'best_before' => null]]],
            ['id' => $held->id, 'bins' => [['location_id' => $this->binA->id, 'best_before' => null]]],
            ['id' => $juice->id, 'bins' => [['location_id' => $this->binA->id, 'best_before' => null]]],
        ]])->assertSessionHas('error', fn ($m) => str_contains($m, 'turned on for 2') && str_contains($m, 'HELD-1'));

        $this->assertTrue($this->milk->fresh()->is_batch_tracked);
        $this->assertTrue($juice->fresh()->is_batch_tracked);
        $this->assertFalse($held->fresh()->is_batch_tracked);
    }

    public function test_only_managers_and_admins_can_do_it()
    {
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        $this->actingAs($staff)->get(route('batch-tracking.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('batch-tracking.apply'), ['products' => [['id' => $this->milk->id, 'bins' => []]]])->assertForbidden();
    }

    public function test_the_product_form_sends_a_product_with_stock_here_instead()
    {
        $form = ['sku' => 'MILK-1', 'name' => 'Milk', 'base_unit' => 'each', 'is_batch_tracked' => true,
            'units' => [['id' => $this->milk->units()->value('id'), 'name' => 'Each', 'base_qty' => 1]]];

        $this->actingAs($this->manager)->put(route('products.update', $this->milk), $form)->assertSessionHasErrors('is_batch_tracked');
        $this->assertFalse($this->milk->fresh()->is_batch_tracked);

        $this->actingAs($this->manager)->get(route('products.edit', $this->milk))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('holdsStock', true));
    }
}
