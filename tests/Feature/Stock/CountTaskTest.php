<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\CountTask;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\PickListService;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class CountTaskTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
    }

    /** Picks and dispatches $qty after the bin was silently drained to $leftInBin, so dispatch goes negative. */
    private function dispatchIntoNegative(Product $p, int $qty, int $leftInBin): void
    {
        $order = $this->manualOrder($this->manager, $p, $qty);
        $list = app(PickListService::class)->create($this->manager, $this->site(), [$order->id])['pickList'];

        $onHand = $this->balance($p, $this->bin('STOCK'));
        $this->ledger()->postOne(new MovementDraft(
            product: $p, location: $this->bin('STOCK'), qty: $leftInBin - $onHand, type: MovementType::Sale,
            idempotencyKey: 'drain:'.Str::uuid(), allowNegative: true,
        ));

        $this->actingAs($this->manager)->put(route('pick-lists.record', $list), ['picked' => [$list->lines()->value('id') => $qty]])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('pick-lists.dispatch', $list))->assertSessionHasNoErrors();
    }

    public function test_negative_dispatch_is_not_blocked_and_opens_one_count_task_per_bin()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));

        $this->dispatchIntoNegative($p, 6, 2);

        $this->assertSame(-4, $this->balance($p, $this->bin('STOCK')));
        $task = CountTask::sole();
        $this->assertSame([CountTask::STATUS_OPEN, $this->bin('STOCK')->id, $p->id, -4, 1],
            [$task->status, $task->location_id, $task->product_id, $task->on_hand_at_raise, $task->times_raised]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'dispatch_negative_bin']);

        // A second negative dispatch from the same bin bumps the open task.
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $this->dispatchIntoNegative($p, 3, 1);
        $this->assertSame(2, CountTask::sole()->times_raised);
    }

    public function test_posting_a_stocktake_over_the_bin_closes_its_count_task()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $this->dispatchIntoNegative($p, 6, 2);

        $service = app(StocktakeService::class);
        $stocktake = $service->create($this->manager, $this->bin('STOCK'));
        foreach ($stocktake->lines as $line) {
            $service->recordCount($line, 1, $this->manager);
        }
        $service->post($stocktake->fresh(), $this->manager);

        $task = CountTask::sole();
        $this->assertSame(CountTask::STATUS_DONE, $task->status);
        $this->assertSame($stocktake->id, $task->stocktake_id);
        $this->assertSame(1, $this->balance($p, $this->bin('STOCK')));

        // A fresh negative opens a new task (the old one is closed).
        $this->dispatchIntoNegativeAfterRestock($p);
        $this->assertSame(1, CountTask::where('status', CountTask::STATUS_OPEN)->count());
    }

    private function dispatchIntoNegativeAfterRestock(Product $p): void
    {
        $this->stockIn($p, 5, $this->bin('STOCK'));
        $this->dispatchIntoNegative($p, 2, 0);
    }

    public function test_dashboard_shows_negative_bins_and_open_count_tasks_and_manager_can_dismiss()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $this->dispatchIntoNegative($p, 6, 2);

        $this->actingAs($this->manager)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('widgets.negative_bins.total', 1)
            ->where('widgets.negative_bins.rows.0.on_hand', -4)
            ->where('widgets.count_tasks.total', 1)
            ->where('widgets.count_tasks.can_manage', true));

        $task = CountTask::sole();
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $this->actingAs($staff)->post(route('count-tasks.dismiss', $task), ['note' => 'x'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('count-tasks.dismiss', $task), [])->assertSessionHasErrors('note');
        $this->actingAs($this->manager)->post(route('count-tasks.dismiss', $task), ['note' => 'Recounted by eye, stock was in overflow'])->assertSessionHasNoErrors();

        $this->assertSame(CountTask::STATUS_DISMISSED, $task->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'count_task_dismissed']);
        $this->actingAs($this->manager)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('widgets.count_tasks.total', 0));
    }
}
