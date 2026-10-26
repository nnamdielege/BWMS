<?php

namespace Tests\Feature\Stock;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DDL (DROP/CREATE TRIGGER) commits implicitly, so this cannot run inside
 * RefreshDatabase's transaction; triggers are restored in tearDown.
 */
class LedgerTriggerCheckTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->artisan('migrate:refresh', [
            '--path' => 'database/migrations/2026_10_26_100100_add_stock_movement_immutability_triggers.php',
            '--force' => true,
        ]);
        parent::tearDown();
    }

    public function test_passes_when_triggers_exist()
    {
        $this->artisan('bwms:check-triggers')->assertSuccessful();
    }

    public function test_fails_loudly_when_a_trigger_is_missing()
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');

        $this->artisan('bwms:check-triggers')
            ->expectsOutputToContain('Missing trigger stock_movements_no_delete')
            ->expectsOutputToContain('log_bin_trust_function_creators')
            ->assertFailed();
    }

    public function test_fails_when_triggers_are_disabled_by_config()
    {
        config(['bwms.ledger_db_triggers' => false]);

        $this->artisan('bwms:check-triggers')->assertFailed();
    }
}
