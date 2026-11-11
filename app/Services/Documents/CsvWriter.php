<?php

namespace App\Services\Documents;

/** CSV for Excel: UTF-8 with a BOM so accents read right; text that could run as a formula is quoted. */
final class CsvWriter
{
    /** @param  list<list<string|int|float|null>>  $rows */
    public static function toString(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}");
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
