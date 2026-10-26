<?php

namespace App\Migration\Legacy;

/**
 * Reconciliation report for bwms:migrate-legacy (spec B9): counts per entity,
 * issues to act on, and one row per legacy product.
 */
final class MigrationReport
{
    /** @var array<string, array{source: int, created: int, existing: int, skipped: int, target: int}> */
    public array $counts = [];

    /** @var array<string, list<string>> section => lines */
    public array $issues = [];

    /** @var list<array<string, mixed>> */
    public array $products = [];

    /** @var list<string> */
    public array $notes = [];

    public function __construct(public readonly string $mode, public readonly string $source) {}

    public function count(string $entity, string $field, int $by = 1): void
    {
        $this->counts[$entity] ??= ['source' => 0, 'created' => 0, 'existing' => 0, 'skipped' => 0, 'target' => 0];
        $this->counts[$entity][$field] += $by;
    }

    public function issue(string $section, string $line): void
    {
        $this->issues[$section][] = $line;
    }

    public function markdown(): string
    {
        $out = ["# Legacy migration report ({$this->mode})", '', '- Generated: '.now()->toIso8601String(), "- Source connection: `{$this->source}`", ''];
        foreach ($this->notes as $note) {
            $out[] = "- {$note}";
        }

        $out = [...$out, '', '## Row counts', '', '| Entity | Source | Created now | Already imported | Skipped | In target (legacy) |', '|---|---:|---:|---:|---:|---:|'];
        foreach ($this->counts as $entity => $c) {
            $out[] = "| {$entity} | {$c['source']} | {$c['created']} | {$c['existing']} | {$c['skipped']} | {$c['target']} |";
        }

        foreach ($this->issues as $section => $lines) {
            $out = [...$out, '', '## '.$section.' ('.count($lines).')', ''];
            foreach ($lines as $line) {
                $out[] = "- {$line}";
            }
        }

        $out = [...$out, '', '## Per product', '', 'See the CSV next to this file for every product.', ''];

        return implode("\n", $out)."\n";
    }

    public function csv(): string
    {
        $handle = fopen('php://temp', 'r+');
        $columns = ['sku', 'legacy_sku', 'name', 'legacy_id', 'product_id', 'ordermentum_variant_id', 'cost', 'legacy_unit',
            'legacy_on_hand', 'legacy_bin', 'opening_balance', 'ordermentum_available', 'portal_stock_qty', 'flags'];
        fputcsv($handle, $columns);
        foreach ($this->products as $row) {
            fputcsv($handle, array_map(fn ($c) => is_bool($row[$c] ?? null) ? (int) $row[$c] : ($row[$c] ?? ''), $columns));
        }
        rewind($handle);

        return stream_get_contents($handle);
    }
}
