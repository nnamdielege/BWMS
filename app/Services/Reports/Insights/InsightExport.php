<?php

namespace App\Services\Reports\Insights;

use App\Services\Documents\CsvWriter;
use App\Services\Documents\XlsxWriter;
use Illuminate\Support\Str;

/**
 * A stock insight report as one XLSX or CSV: a title, the filters, notes, then each
 * table stacked under its own heading. Money columns are given per table.
 */
final class InsightExport
{
    public const FORMATS = ['xlsx', 'csv'];

    /**
     * @param  list<string>  $notes
     * @param  list<array{title: string, columns: list<string>, rows: list<list<mixed>>, money?: list<int>}>  $sections
     * @return array{filename: string, type: string, body: string}
     */
    public static function make(string $title, InsightFilters $filters, array $notes, array $sections, string $format): array
    {
        $head = [[$title, $filters->describe()], ...array_map(fn ($n) => [$n], $notes)];

        if ($format === 'csv') {
            $lines = $head;
            foreach ($sections as $s) {
                $lines = [...$lines, [], [$s['title']], $s['columns'], ...$s['rows']];
            }
            $body = CsvWriter::toString($lines);
            $type = 'text/csv; charset=UTF-8';
        } else {
            $width = max(2, ...array_map(fn ($s) => count($s['columns']), $sections ?: [['columns' => []]]));
            $xlsx = (new XlsxWriter(Str::limit($title, 31, '')))->landscape()->widths(array_fill(0, $width, 14))
                ->footer('&L'.str_replace('&', '&&', $title).'&RPage &P of &N');
            foreach ($head as $h) {
                $xlsx->row($h, true);
            }
            foreach ($sections as $s) {
                $xlsx->row([])->row([$s['title']], true)->row($s['columns'], true, []);
                foreach ($s['rows'] as $r) {
                    $xlsx->row($r, is_string($r[0] ?? null) && $r[0] === 'Total', $s['money'] ?? []);
                }
            }
            $body = $xlsx->toString();
            $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        return ['filename' => Str::slug($title)."-{$filters->from}-to-{$filters->to}.{$format}", 'type' => $type, 'body' => $body];
    }
}
