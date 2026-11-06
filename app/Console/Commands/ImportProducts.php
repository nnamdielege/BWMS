<?php

namespace App\Console\Commands;

use App\Integrations\Ordermentum\OrdermentumCatalogue;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Services\Catalogue\Import\ImportFileReader;
use App\Services\Catalogue\Import\ProductImportPlanner;
use App\Services\Catalogue\Import\ProductImportService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The product import without the screen. Same rules, with no edits: nothing is
 * overwritten and "Check pack" rows are left out. Use the preview for those.
 * Catalogue only; it never touches stock.
 */
class ImportProducts extends Command
{
    protected $signature = 'products:import
        {source : ordermentum, portal or file=path}
        {--dry-run : Print the summary; save nothing}
        {--yes : Apply. Required without --dry-run}
        {--all : With the summary, list every row and not only conflicts and checks}';

    protected $description = 'Import products, packs and channel mappings from Ordermentum or a CSV/XLSX file. Catalogue only.';

    public function handle(ProductImportPlanner $planner, ProductImportService $imports, OrdermentumCatalogue $catalogue, ImportFileReader $reader): int
    {
        if (! $this->option('dry-run') && ! $this->option('yes')) {
            $this->error('Nothing done. Add --dry-run to see the summary, or --yes to apply.');

            return self::FAILURE;
        }

        $source = (string) $this->argument('source');
        try {
            $items = match (true) {
                $source === 'ordermentum' => $catalogue->items(),
                $source === 'portal' => throw new RuntimeException('BWMS has no way to read the portal catalogue: PortalClient only pushes stock and dispatch events. Export it to the template (docs/PORTAL.md, "Importing portal products") and run products:import file=portal.csv.'),
                str_starts_with($source, 'file=') => $this->readFile($reader, substr($source, 5)),
                default => throw new RuntimeException('The source must be ordermentum, portal or file=path.'),
            };
        } catch (OrdermentumException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = $planner->plan($items);
        $this->printSummary($planner->summary($rows));
        $this->printRows($rows);

        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing was saved.');

            return self::SUCCESS;
        }

        $result = $imports->apply($items, [], null, $source === 'ordermentum' ? 'ordermentum' : 'file', skipUnresolved: true);
        $this->info("Created {$result['created']}, updated {$result['updated']}, mapped {$result['mapped']}, left alone {$result['skipped']}. Held order events queued again: {$result['replayed']}.");
        if ($result['failed']) {
            $this->error($result['failed']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function readFile(ImportFileReader $reader, string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return $reader->read($path, pathinfo($path, PATHINFO_EXTENSION));
    }

    /** @param  array<string, int>  $s */
    private function printSummary(array $s): void
    {
        $this->table(['New', 'Update', 'Mapping only', 'Conflict', 'Skipped', 'Total'], [[$s['new'], $s['update'], $s['mapping'], $s['conflict'], $s['skipped'], $s['total']]]);
        $this->line("Check pack (a value is needed): {$s['check_pack']}");
        $this->line("Differ from the WMS (kept unless overwrite is ticked in the preview): {$s['needs_tick']}");
        if ($s['barcodes_ignored'] > 0) {
            $this->line("Barcodes ignored, not GTINs: {$s['barcodes_ignored']}");
        }
    }

    private function printRows(array $rows): void
    {
        $shown = $this->option('all') ? $rows : array_filter($rows, fn ($r) => $r['group'] === 'conflict' || $r['check_pack']);
        if ($shown !== []) {
            $this->table(['Group', 'SKU', 'Name', 'Reason'], array_map(fn ($r) => [
                $r['check_pack'] ? 'check pack' : $r['group'], $r['sku'], mb_strimwidth($r['name'], 0, 48, '…'),
                trim(implode(' ', [$r['reason'], ...$r['changes'], ...$r['notes']])),
            ], $shown));
        }
    }
}
