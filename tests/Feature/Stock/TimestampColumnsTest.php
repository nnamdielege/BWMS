<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Models\UserInvitation;
use App\Services\Stock\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/**
 * MariaDB with explicit_defaults_for_timestamp=0 (production, and this test
 * database) gave five columns ON UPDATE CURRENT_TIMESTAMP. The migration takes
 * it off; bwms:check-timestamps finds rows it already overwrote.
 */
class TimestampColumnsTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private const COLUMNS = [
        'stocktakes' => 'snapshot_at', 'stock_movements' => 'occurred_at', 'receipts' => 'received_at',
        'order_packs' => 'packed_at', 'user_invitations' => 'expires_at',
    ];

    public function test_none_of_the_five_columns_updates_itself()
    {
        foreach (self::COLUMNS as $table => $column) {
            $col = DB::selectOne('SELECT IS_NULLABLE n, COLUMN_DEFAULT d, EXTRA e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
            $this->assertStringNotContainsStringIgnoringCase('on update', $col->e, "{$table}.{$column}");
            $this->assertSame('NO', $col->n, "{$table}.{$column} stays NOT NULL");
            $this->assertMatchesRegularExpression('/current_timestamp/i', (string) $col->d, "{$table}.{$column} has an explicit default");
        }
    }

    public function test_posting_a_stocktake_leaves_its_snapshot_time_alone()
    {
        $this->seedStockReferenceData();
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $st = app(StocktakeService::class)->create($manager, $this->bin());
        DB::table('stocktakes')->where('id', $st->id)->update(['snapshot_at' => '2026-11-01 08:00:00', 'created_at' => '2026-11-01 08:00:00']);

        app(StocktakeService::class)->post($st->fresh(), $manager, zeroUncounted: true);

        $this->assertSame('2026-11-01 08:00:00', DB::table('stocktakes')->where('id', $st->id)->value('snapshot_at'));
    }

    public function test_the_check_lists_overwritten_rows_with_repairs_and_changes_nothing()
    {
        $this->seedStockReferenceData();
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $hit = app(StocktakeService::class)->create($manager, $this->bin());
        $fine = app(StocktakeService::class)->create($manager, $this->bin());
        // What ON UPDATE did: the timestamp became updated_at.
        DB::table('stocktakes')->where('id', $hit->id)->update(['created_at' => '2026-11-01 08:00:00', 'updated_at' => '2026-11-05 10:30:00', 'snapshot_at' => '2026-11-05 10:30:00']);
        DB::table('stocktakes')->where('id', $fine->id)->update(['created_at' => '2026-11-01 08:00:00', 'updated_at' => '2026-11-05 10:30:00', 'snapshot_at' => '2026-11-01 08:00:00']);
        [$invitation] = UserInvitation::issue('new@example.com', 'New', Permissions::ROLE_WAREHOUSE_STAFF, $manager);
        DB::table('user_invitations')->where('id', $invitation->id)->update(['created_at' => '2026-11-01 08:00:00', 'updated_at' => '2026-11-03 09:00:00', 'expires_at' => '2026-11-03 09:00:00']);

        $this->assertSame(0, Artisan::call('bwms:check-timestamps'));
        $out = Artisan::output();

        $this->assertStringContainsString('stocktakes.snapshot_at: 1 likely overwritten', $out);
        $this->assertStringContainsString("UPDATE `stocktakes` SET `snapshot_at` = '2026-11-01 08:00:00' WHERE id = {$hit->id} AND `snapshot_at` = '2026-11-05 10:30:00';", $out);
        $this->assertStringNotContainsString("WHERE id = {$fine->id} AND `snapshot_at`", $out);
        $this->assertStringContainsString("UPDATE `user_invitations` SET `expires_at` = '2026-11-08 08:00:00' WHERE id = {$invitation->id}", $out);
        $this->assertStringContainsString('receipts.received_at: 0 likely overwritten', $out);
        $this->assertStringContainsString('stock_movements_no_update trigger is installed', $out);
        $this->assertSame('2026-11-05 10:30:00', DB::table('stocktakes')->where('id', $hit->id)->value('snapshot_at'), 'dry run only');
    }
}
