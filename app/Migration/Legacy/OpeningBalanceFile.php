<?php

namespace App\Migration\Legacy;

use Illuminate\Support\Carbon;

/**
 * Cutover stocktake CSV (D-7). Header row required; columns:
 *   sku, qty            required; qty in base units, whole number >= 0
 *   bin, site           optional; default STOCK at the default site
 *   lot, best_before    optional; required for batch-tracked products (YYYY-MM-DD)
 */
final class OpeningBalanceFile
{
    /**
     * @return array{rows: list<array{line: int, sku: string, qty: int, bin: ?string, site: ?string, lot: ?string, best_before: ?string}>, errors: list<string>}
     */
    public static function parse(string $path): array
    {
        if (! is_readable($path)) {
            return ['rows' => [], 'errors' => ["Cannot read {$path}."]];
        }
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);
        if (! in_array('sku', $header, true) || ! in_array('qty', $header, true)) {
            fclose($handle);

            return ['rows' => [], 'errors' => ['The header must include sku and qty (optional: bin, site, lot, best_before).']];
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || trim(implode('', $cells)) === '') {
                continue;
            }
            $r = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $get = fn (string $k) => isset($r[$k]) && trim((string) $r[$k]) !== '' ? trim((string) $r[$k]) : null;

            $sku = $get('sku');
            $qty = $get('qty');
            $bestBefore = $get('best_before');
            if (! $sku) {
                $errors[] = "line {$line}: sku is empty.";

                continue;
            }
            if ($qty === null || ! preg_match('/^\d+$/', $qty)) {
                $errors[] = "line {$line}: qty must be a whole number >= 0 (got \"{$qty}\").";

                continue;
            }
            if ($bestBefore !== null) {
                $date = Carbon::createFromFormat('!Y-m-d', $bestBefore);
                if (! $date || $date->format('Y-m-d') !== $bestBefore) {
                    $errors[] = "line {$line}: best_before must be YYYY-MM-DD.";

                    continue;
                }
            }
            $key = strtoupper($sku).'|'.strtoupper((string) $get('site')).'|'.strtoupper($get('bin') ?? 'STOCK').'|'.$get('lot');
            if (isset($seen[$key])) {
                $errors[] = "line {$line}: duplicate of line {$seen[$key]} (same sku, site, bin and lot).";

                continue;
            }
            $seen[$key] = $line;

            $rows[] = ['line' => $line, 'sku' => $sku, 'qty' => (int) $qty, 'bin' => $get('bin'), 'site' => $get('site'),
                'lot' => $get('lot'), 'best_before' => $bestBefore];
        }
        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }
}
