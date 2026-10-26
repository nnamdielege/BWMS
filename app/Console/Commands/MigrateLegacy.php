<?php

namespace App\Console\Commands;

use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Migration\Legacy\LegacyImporter;
use App\Migration\Legacy\LegacySchema;
use App\Migration\Legacy\MigrationReport;
use App\Migration\Legacy\OpeningBalanceFile;
use App\Migration\Legacy\OpeningBalanceRejected;
use App\Models\Location;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\StockLedger;
use App\Services\Stock\StockQuery;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * One-off import from a restored copy of the legacy prod dump (spec B9). Never
 * point --source at production. See docs/MIGRATION.md.
 */
class MigrateLegacy extends Command
{
    protected $signature = 'bwms:migrate-legacy
        {--source=legacy : DB connection holding the restored legacy dump (read-only user)}
        {--dry-run : Run everything, write the report, then roll back}
        {--commit : Run everything and keep it}
        {--site= : Site code for legacy stock and POs (default: the configured default site)}
        {--opening-balances= : Cutover stocktake CSV (sku,qty[,bin,site,lot,best_before]); without it no stock is imported}
        {--cutover= : Cutover date-time for opening balances, business time zone, e.g. "2026-11-30 18:00"}
        {--open-legacy-pos : Import legacy draft/pending POs as draft/sent (receivable) instead of closed history}
        {--with-ordermentum : Add current Ordermentum available per variant to the report (read-only GETs)}
        {--portal= : DB connection of a portal copy; adds portal stock_qty per SKU to the report}
        {--allow-writable-source : Accept a source user with write grants (test fixtures only; the session is still read-only)}';

    protected $description = 'Import legacy BWMS data from the restored dump, with a reconciliation report (dry-run or commit)';

    public function handle(StockLedger $ledger, StockQuery $stock): int
    {
        if ($this->option('dry-run') === $this->option('commit')) {
            $this->error('Pass exactly one of --dry-run or --commit.');

            return self::FAILURE;
        }
        $mode = $this->option('commit') ? 'commit' : 'dry-run';

        try {
            $source = $this->openReadOnly((string) $this->option('source'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $schema = LegacySchema::check($source);
        if ($schema['missing'] !== []) {
            $this->error('The source schema is missing columns the importer reads. Nothing was imported.');
            foreach ($schema['missing'] as $table => $cols) {
                $this->line("  {$table}: ".implode(', ', $cols));
            }

            return self::FAILURE;
        }

        $siteCode = $this->option('site') ?: config('bwms.default_site.code');
        $site = Location::whereNull('parent_id')->where('type', Location::TYPE_SITE)->where('code', $siteCode)->first();
        if (! $site) {
            $this->error("Site {$siteCode} does not exist. Run the seeders first (php artisan db:seed).");

            return self::FAILURE;
        }

        $openingRows = null;
        $cutover = null;
        if ($path = $this->option('opening-balances')) {
            $parsed = OpeningBalanceFile::parse($path);
            if ($parsed['errors'] !== []) {
                $this->error('The opening balance file has errors. Nothing was imported.');
                foreach ($parsed['errors'] as $e) {
                    $this->line("  {$e}");
                }

                return self::FAILURE;
            }
            if (! $this->option('cutover')) {
                $this->error('--cutover is required with --opening-balances.');

                return self::FAILURE;
            }
            $openingRows = $parsed['rows'];
            $cutover = Carbon::parse($this->option('cutover'), config('bwms.business_timezone'));
        }

        // External reads happen before the transaction opens (NFR-2).
        $omAvailable = $this->option('with-ordermentum') ? $this->fetchOrdermentum($source) : null;
        $portalStock = $this->option('portal') ? $this->fetchPortal((string) $this->option('portal')) : null;

        $report = new MigrationReport($mode, (string) $this->option('source'));
        $report->notes[] = "Site: {$site->code} ({$site->name}).";
        $report->notes[] = $this->option('open-legacy-pos')
            ? 'Legacy draft/pending POs imported as draft/sent (--open-legacy-pos).'
            : 'All legacy POs imported as history (closed/cancelled). Use --open-legacy-pos to keep draft/pending ones open.';
        foreach ($schema['extra'] as $table => $cols) {
            $report->notes[] = "Source columns not imported: {$table}.".implode(", {$table}.", $cols);
        }

        $importer = new LegacyImporter($source, $site, $ledger, $stock, [
            'open_legacy_pos' => (bool) $this->option('open-legacy-pos'),
            'cutover' => $cutover,
            'opening_rows' => $openingRows,
            'om_available' => $omAvailable,
            'portal_stock' => $portalStock,
        ]);

        DB::beginTransaction();
        try {
            $importer->run($report);
            if ($mode === 'commit') {
                Audit::record('legacy_migration_committed', meta: ['counts' => $report->counts]);
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (OpeningBalanceRejected|StockException $e) {
            DB::rollBack();
            $report->notes[] = 'FAILED, rolled back: '.$e->getMessage();
            $this->writeReport($report);
            $this->error($e->getMessage().' Nothing was imported.');

            return self::FAILURE;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->writeReport($report);
        $this->table(['Entity', 'Source', 'Created', 'Already imported', 'Skipped', 'In target'], collect($report->counts)
            ->map(fn ($c, $entity) => [$entity, $c['source'], $c['created'], $c['existing'], $c['skipped'], $c['target']])->values()->all());
        foreach ($report->issues as $section => $lines) {
            $this->line(sprintf('  %-70s %d', $section, count($lines)));
        }
        $mode === 'commit'
            ? $this->info('Committed.')
            : $this->warn('Dry run: everything above was rolled back.');

        return self::SUCCESS;
    }

    private function openReadOnly(string $name): Connection
    {
        if (! config("database.connections.{$name}")) {
            throw new RuntimeException("Unknown DB connection [{$name}].");
        }
        $source = DB::connection($name);
        $target = DB::connection();
        if ($name === $target->getName() || ($source->getConfig('host') === $target->getConfig('host')
            && (string) $source->getConfig('port') === (string) $target->getConfig('port') && $source->getDatabaseName() === $target->getDatabaseName())) {
            throw new RuntimeException('The source must be a different database from the BWMS database.');
        }

        $grants = collect($source->select('SHOW GRANTS'))->map(fn ($g) => (string) array_values((array) $g)[0]);
        $writable = $grants->contains(fn ($g) => preg_match('/^GRANT (ALL|.*\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE)\b)/i', $g)
            && ! preg_match('/^GRANT USAGE/i', $g));
        if ($writable && ! $this->option('allow-writable-source')) {
            throw new RuntimeException("The [{$name}] user can write. Use a SELECT-only user (see docs/MIGRATION.md).");
        }
        $source->statement('SET SESSION TRANSACTION READ ONLY');

        return $source;
    }

    /** @return array<string, int|string> variant id => available, or an error label */
    private function fetchOrdermentum(Connection $source): array
    {
        $client = app(OrdermentumClient::class);
        $ids = $source->table('products')->whereNotNull('ordermentum_variant_id')->pluck('ordermentum_variant_id')->all();
        $location = config('services.ordermentum.location_id');
        $out = [];
        $this->info('Reading Ordermentum stock for '.count($ids).' variants (read-only)...');
        $bar = $this->output->createProgressBar(count($ids));
        foreach ($ids as $id) {
            try {
                $response = $client->getStock('/v1/items/'.rawurlencode($id), $location ? ['locationId' => $location] : []);
                $out[$id] = $response->successful() ? (int) $response->json('available') : "HTTP {$response->status()}";
            } catch (OrdermentumException $e) {
                $out[$id] = 'error';
                if (in_array($e->status, [401, 403], true) || $e->status === null) {
                    $this->newLine();
                    $this->warn('Stopping Ordermentum reads: '.$e->getMessage());
                    break;
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        return $out;
    }

    /** @return array<string, int> SKU (upper) => stock_qty */
    private function fetchPortal(string $name): array
    {
        $portal = DB::connection($name);
        $portal->statement('SET SESSION TRANSACTION READ ONLY');

        return $portal->table('products')->whereNotNull('sku')->get(['sku', 'stock_qty'])
            ->mapWithKeys(fn ($p) => [strtoupper(trim($p->sku)) => (int) $p->stock_qty])->all();
    }

    private function writeReport(MigrationReport $report): void
    {
        $base = storage_path('app/migration/legacy-'.$report->mode.'-'.now()->format('Ymd-His'));
        File::ensureDirectoryExists(dirname($base));
        File::put("{$base}.md", $report->markdown());
        File::put("{$base}.csv", $report->csv());
        $this->line("Report: {$base}.md (+ .csv)");
    }
}
