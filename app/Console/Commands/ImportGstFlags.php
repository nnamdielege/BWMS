<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * One-off: products.gst_free from a CSV of sku,gst_free (exported from Xero item tax codes).
 * A header row is optional. gst_free takes 1/0, true/false, yes/no, y/n. SKUs not in BWMS
 * are listed and skipped; rows with a bad value or a SKU given twice are listed and skipped.
 */
class ImportGstFlags extends Command
{
    protected $signature = 'products:import-gst-flags {csv : Path to a CSV of sku,gst_free} {--dry-run : Report what would change, change nothing}';

    protected $description = 'Set products.gst_free from a CSV of sku,gst_free';

    private const TRUE = ['1', 'true', 'yes', 'y'];

    private const FALSE = ['0', 'false', 'no', 'n'];

    public function handle(): int
    {
        $path = (string) $this->argument('csv');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Can't read {$path}.");

            return self::FAILURE;
        }

        [$flags, $invalid] = $this->read($path);
        $products = Product::whereIn('sku', array_keys($flags))->get(['id', 'sku', 'gst_free'])->keyBy(fn (Product $p) => strtoupper($p->sku));
        $unmatched = array_values(array_diff(array_keys($flags), $products->keys()->all()));

        $changes = [];
        $unchanged = 0;
        foreach ($products as $sku => $product) {
            if ($product->gst_free === $flags[$sku]) {
                $unchanged++;

                continue;
            }
            $changes[] = [$product->sku, $product->gst_free ? 'GST-free' : '10%', $flags[$sku] ? 'GST-free' : '10%'];
            if (! $this->option('dry-run')) {
                $product->update(['gst_free' => $flags[$sku]]);
            }
        }

        if ($changes !== []) {
            $this->table(['SKU', 'Was', 'Now'], $changes);
        }
        if ($unmatched !== []) {
            $this->warn(count($unmatched).' SKU(s) not in BWMS, skipped: '.implode(', ', $unmatched));
        }
        foreach ($invalid as $line) {
            $this->warn("Skipped {$line}");
        }

        $verb = $this->option('dry-run') ? 'would change' : 'changed';
        $this->info(count($changes)." product(s) {$verb}, {$unchanged} already right, ".count($unmatched).' unmatched, '.count($invalid).' invalid row(s).'
            .($this->option('dry-run') ? ' Nothing changed (dry run).' : ''));
        if (! $this->option('dry-run') && $changes !== []) {
            Audit::record('products_gst_flags_imported', meta: ['file' => basename($path), 'changed' => count($changes), 'unmatched' => $unmatched]);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string, bool>, 1: list<string>} SKU (upper case) => GST-free; and the rows skipped, described
     */
    private function read(string $path): array
    {
        $flags = [];
        $invalid = [];
        $seen = [];
        $handle = fopen($path, 'r');
        $n = 0;
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $n++;
            $sku = strtoupper(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) ($row[0] ?? '')))); // Excel's BOM
            $value = strtolower(trim((string) ($row[1] ?? '')));
            if ($sku === '' && $value === '') {
                continue; // blank line
            }
            if ($n === 1 && $sku === 'SKU') {
                continue; // header
            }
            if (isset($seen[$sku])) {
                $invalid[] = "line {$n}: {$sku} is also on line {$seen[$sku]}";
                unset($flags[$sku]);

                continue;
            }
            $seen[$sku] = $n;
            if ($sku === '' || ! in_array($value, [...self::TRUE, ...self::FALSE], true)) {
                $invalid[] = "line {$n}: \"".implode(',', $row).'" (expected sku,gst_free with gst_free 1/0, true/false or yes/no)';

                continue;
            }
            $flags[$sku] = in_array($value, self::TRUE, true);
        }
        fclose($handle);

        return [$flags, $invalid];
    }
}
