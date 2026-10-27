<?php

namespace Tests\Feature;

use App\Support\DatabaseSafety;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Destructive database operations only run against a *_test database, so a
 * mistaken command can never wipe the dev or production database.
 * No database trait here: this test must not refresh anything itself.
 */
class DatabaseSafetyTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = config('database.connections.'.config('database.default').'.database');
    }

    protected function tearDown(): void
    {
        config(['database.connections.'.config('database.default').'.database' => $this->original]);
        DB::prohibitDestructiveCommands(! DatabaseSafety::destructiveAllowed());
        parent::tearDown();
    }

    public function test_the_suite_runs_on_a_dedicated_test_database()
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->assertTrue(DatabaseSafety::destructiveAllowed());
    }

    public function test_destructive_commands_refuse_on_any_other_database_and_touch_nothing()
    {
        $tablesBefore = count(DB::select('SHOW TABLES'));

        // Pretend the app is pointed at a dev database (a name that does not exist, so nothing real is ever at risk).
        config(['database.connections.'.config('database.default').'.database' => 'bwms_dev_example']);
        $this->assertFalse(DatabaseSafety::destructiveAllowed());
        DB::prohibitDestructiveCommands(! DatabaseSafety::destructiveAllowed()); // what AppServiceProvider does at boot

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'] as $command) {
            $this->artisan($command, ['--force' => true])->assertFailed();
        }

        $this->assertSame($tablesBefore, count(DB::select('SHOW TABLES')), 'Nothing was dropped');
    }

    public function test_refresh_database_refuses_outside_a_test_database()
    {
        config(['database.connections.'.config('database.default').'.database' => 'bwms_next']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing RefreshDatabase: database "bwms_next" does not end in "_test"');
        DatabaseSafety::assertDestructiveAllowed('RefreshDatabase');
    }

    public function test_name_rule()
    {
        config(['database.connections.mysql.database' => 'bwms_next_testing']);
        $this->assertFalse(DatabaseSafety::destructiveAllowed('mysql'), '"_testing" is not "_test"');
        config(['database.connections.mysql.database' => 'bwms_next_test']);
        $this->assertTrue(DatabaseSafety::destructiveAllowed('mysql'));
    }
}
