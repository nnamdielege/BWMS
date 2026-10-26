<?php

namespace Tests\Feature\Stock;

use App\Console\Commands\VerifyStockBalances;
use App\Enums\MovementType;
use App\Events\StockChanged;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Stock\Exceptions\IdempotencyConflict;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\MovementDraft;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
    }

    private function draft(Product $p, int $qty, MovementType $type = MovementType::Receipt, array $extra = []): MovementDraft
    {
        return new MovementDraft(...array_merge([
            'product' => $p, 'location' => $this->bin(), 'qty' => $qty, 'type' => $type,
            'idempotencyKey' => 'k:'.uniqid('', true),
        ], $extra));
    }

    // ---- Integrity -----------------------------------------------------

    public function test_movement_updates_balance_and_projection_equals_ledger()
    {
        $p = Product::factory()->create();

        $this->stockIn($p, 10);
        $this->ledger()->postOne($this->draft($p, -4, MovementType::Sale));

        $this->assertSame(6, $this->balance($p));
        $this->assertSame(6, $this->ledgerSum($p));
        $this->assertSame([], VerifyStockBalances::findDrift());
    }

    public function test_movements_are_immutable_in_eloquent()
    {
        $m = $this->stockIn(Product::factory()->create(), 5);

        $this->expectException(LogicException::class);
        $m->update(['qty' => 500]);
    }

    public function test_movements_cannot_be_deleted_in_eloquent()
    {
        $m = $this->stockIn(Product::factory()->create(), 5);

        $this->expectException(LogicException::class);
        $m->delete();
    }

    public function test_database_trigger_blocks_raw_update_and_delete()
    {
        $m = $this->stockIn(Product::factory()->create(), 5);

        try {
            DB::table('stock_movements')->where('id', $m->id)->update(['qty' => 999]);
            $this->fail('Raw UPDATE should have been rejected by the trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('stock_movements')->where('id', $m->id)->delete();
            $this->fail('Raw DELETE should have been rejected by the trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(5, StockMovement::find($m->id)->qty);
    }

    public function test_verify_detects_drift_and_rebuild_repairs_it()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10);

        StockBalance::where('product_id', $p->id)->update(['on_hand' => 7]); // simulate corruption
        $this->artisan('stock:verify')->assertFailed();

        $this->artisan('stock:rebuild-balances', ['--force' => true])->assertSuccessful();
        $this->artisan('stock:verify')->assertSuccessful();
        $this->assertSame(10, $this->balance($p));
    }

    public function test_multi_line_posting_is_atomic()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 2);

        try {
            $this->ledger()->post([
                $this->draft($p, 5),                       // valid
                $this->draft($p, -50, MovementType::Sale), // would go negative overall
            ]);
            $this->fail('Expected InsufficientStock');
        } catch (InsufficientStock) {
        }

        $this->assertSame(2, $this->balance($p));
        $this->assertSame(1, StockMovement::where('product_id', $p->id)->count());
    }

    // ---- Negative stock ------------------------------------------------

    public function test_withdrawal_below_zero_is_rejected()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 3);

        $this->expectException(InsufficientStock::class);
        $this->ledger()->postOne($this->draft($p, -4, MovementType::Sale));
    }

    public function test_withdrawal_below_zero_is_allowed_when_flagged()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 3);

        $this->ledger()->postOne($this->draft($p, -4, MovementType::Sale, ['allowNegative' => true]));

        $this->assertSame(-1, $this->balance($p));
    }

    public function test_receipt_into_negative_balance_is_allowed()
    {
        $p = Product::factory()->create();
        $this->ledger()->postOne($this->draft($p, -2, MovementType::Sale, ['allowNegative' => true]));

        $this->stockIn($p, 5);

        $this->assertSame(3, $this->balance($p));
    }

    // ---- Idempotency ---------------------------------------------------

    public function test_reposting_same_key_returns_original_and_changes_nothing()
    {
        $p = Product::factory()->create();
        $draft = $this->draft($p, 5, MovementType::Receipt, ['idempotencyKey' => 'po:1:line:1']);

        $first = $this->ledger()->postOne($draft);
        $second = $this->ledger()->postOne($draft);

        $this->assertTrue($first->is($second));
        $this->assertSame(5, $this->balance($p));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_same_key_with_different_content_is_a_conflict()
    {
        $p = Product::factory()->create();
        $this->ledger()->postOne($this->draft($p, 5, MovementType::Receipt, ['idempotencyKey' => 'po:1:line:1']));

        $this->expectException(IdempotencyConflict::class);
        $this->ledger()->postOne($this->draft($p, 6, MovementType::Receipt, ['idempotencyKey' => 'po:1:line:1']));
    }

    public function test_partial_duplicate_batch_is_a_conflict()
    {
        $p = Product::factory()->create();
        $this->ledger()->postOne($this->draft($p, 5, MovementType::Receipt, ['idempotencyKey' => 'a']));

        $this->expectException(IdempotencyConflict::class);
        $this->ledger()->post([
            $this->draft($p, 5, MovementType::Receipt, ['idempotencyKey' => 'a']),
            $this->draft($p, 1, MovementType::Receipt, ['idempotencyKey' => 'b']),
        ]);
    }

    // ---- Validation ----------------------------------------------------

    public function test_sign_rules_per_type()
    {
        $p = Product::factory()->create();

        $this->expectException(InvalidMovement::class);
        $this->ledger()->postOne($this->draft($p, -1, MovementType::Receipt));
    }

    public function test_zero_quantity_rejected()
    {
        $this->expectException(InvalidMovement::class);
        $this->ledger()->postOne($this->draft(Product::factory()->create(), 0));
    }

    public function test_stock_only_in_active_bins()
    {
        $p = Product::factory()->create();

        $this->expectException(InvalidMovement::class);
        $this->ledger()->postOne($this->draft($p, 1, MovementType::Receipt, ['location' => Location::where('code', 'MAIN')->first()]));
    }

    public function test_batch_rules()
    {
        $tracked = Product::factory()->batchTracked()->create();
        $plain = Product::factory()->create();
        $otherBatch = $this->batch($plain->fresh()->is_batch_tracked ? $plain : Product::factory()->batchTracked()->create(), 'L1', '2030-01-01');

        $cases = [
            'tracked without batch' => $this->draft($tracked, 1),
            'untracked with batch' => $this->draft($plain, 1, MovementType::Receipt, ['batch' => $this->batch($tracked, 'X', null)]),
            'batch of another product' => $this->draft($tracked, 1, MovementType::Receipt, ['batch' => $otherBatch]),
        ];

        foreach ($cases as $label => $draft) {
            try {
                $this->ledger()->postOne($draft);
                $this->fail("Expected rejection: {$label}");
            } catch (InvalidMovement) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_adjustments_need_a_matching_reason_and_note_when_required()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10);

        $cases = [
            'no reason' => $this->draft($p, -1, MovementType::Adjustment),
            'wrong direction' => $this->draft($p, 1, MovementType::Adjustment, ['reason' => $this->reason('LOST'), 'note' => 'x']),
            'note required' => $this->draft($p, -1, MovementType::Adjustment, ['reason' => $this->reason('LOST')]),
            'write-off reason on adjustment' => $this->draft($p, -1, MovementType::Adjustment, ['reason' => $this->reason('DAMAGED')]),
            'reason on a receipt' => $this->draft($p, 1, MovementType::Receipt, ['reason' => $this->reason('FOUND')]),
        ];

        foreach ($cases as $label => $draft) {
            try {
                $this->ledger()->postOne($draft);
                $this->fail("Expected rejection: {$label}");
            } catch (InvalidMovement) {
                $this->addToAssertionCount(1);
            }
        }

        $this->ledger()->postOne($this->draft($p, -1, MovementType::Adjustment, ['reason' => $this->reason('LOST'), 'note' => 'Missing from shelf']));
        $this->assertSame(9, $this->balance($p));
    }

    // ---- Events --------------------------------------------------------

    public function test_stock_changed_fires_after_commit_only()
    {
        Event::fake([StockChanged::class]);
        $p = Product::factory()->create();

        DB::beginTransaction();
        $this->stockIn($p, 1);
        Event::assertNotDispatched(StockChanged::class);
        DB::rollBack();
        Event::assertNotDispatched(StockChanged::class);

        $this->stockIn($p, 1);
        Event::assertDispatched(StockChanged::class, fn ($e) => $e->productIds === [$p->id]);
    }
}
