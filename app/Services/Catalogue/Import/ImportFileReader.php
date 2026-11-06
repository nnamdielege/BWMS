<?php

namespace App\Services\Catalogue\Import;

use App\Rules\Gtin;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * The CSV/XLSX route of the product import. Every row becomes an import item.
 * Bad cells become row errors, shown as conflicts in the preview, so one typo
 * doesn't refuse the whole file.
 */
class ImportFileReader
{
    public const COLUMNS = [
        'sku', 'name', 'range', 'base_unit', 'pack_name', 'pack_size', 'barcode', 'sell_on_ordermentum', 'sell_on_portal',
        'ordermentum_variant_id', 'portal_item_id', 'batch_tracked', 'shelf_life_days', 'active',
    ];

    public const MAX_ROWS = 5000;

    private const YES = ['yes', 'y', '1', 'true'];

    private const NO = ['no', 'n', '0', 'false'];

    /**
     * @return list<array<string, mixed>> import items, see ProductImportPlanner
     *
     * @throws RuntimeException when the file can't be read at all
     */
    public function read(string $path, string $extension): array
    {
        $rows = strtolower($extension) === 'xlsx' ? $this->xlsxRows($path) : $this->csvRows($path);
        $first = array_key_first($rows);
        $header = array_map(fn ($h) => Str::lower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), $first === null ? [] : $rows[$first]);
        if (! in_array('sku', $header, true) || ! in_array('name', $header, true)) {
            throw new RuntimeException('The header must include sku and name. Download the template for all the columns.');
        }
        unset($rows[$first]);

        $items = [];
        foreach ($rows as $i => $cells) {
            $cells = array_map(fn ($c) => trim((string) $c), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            $items[] = $this->item($i + 1, array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), '')));
        }

        return $items;
    }

    /** @param  array<string, string>  $r */
    private function item(int $line, array $r): array
    {
        $errors = [];
        $bool = function (string $column) use ($r, &$errors): ?bool {
            $value = Str::lower($r[$column] ?? '');
            if ($value === '') {
                return null;
            }
            if (! in_array($value, [...self::YES, ...self::NO], true)) {
                $errors[] = "{$column} must be yes or no.";

                return null;
            }

            return in_array($value, self::YES, true);
        };
        $whole = function (string $column, int $max) use ($r, &$errors): ?int {
            $value = $r[$column] ?? '';
            if ($value === '') {
                return null;
            }
            if (! ctype_digit($value) || (int) $value < 1 || (int) $value > $max) {
                $errors[] = "{$column} must be a whole number from 1 to {$max}.";

                return null;
            }

            return (int) $value;
        };

        $barcode = preg_replace('/\s+/', '', $r['barcode'] ?? '');
        $gtinLength = ctype_digit($barcode) && in_array(strlen($barcode), [8, 12, 13, 14], true);
        if ($barcode !== '' && (! preg_match('/^[A-Za-z0-9\-]{1,64}$/', $barcode) || ($gtinLength && ! Gtin::isValid($barcode)))) {
            $errors[] = "Barcode {$barcode} is not valid.";
            $barcode = '';
        }
        $variant = $r['ordermentum_variant_id'] ?? '';
        $portal = $r['portal_item_id'] ?? '';
        if ($portal !== '' && ! ctype_digit($portal)) {
            $errors[] = 'portal_item_id must be a number.';
            $portal = '';
        }
        $external = [];
        if ($variant !== '') {
            $external['ordermentum'] = ['variant_id' => $variant, 'product_id' => null, 'sku' => $r['sku'] ?? null];
        }
        if ($portal !== '') {
            // Portal listings are keyed by the portal product ID.
            $external['portal'] = ['variant_id' => $portal, 'product_id' => $portal, 'sku' => $r['sku'] ?? null];
        }

        return [
            'key' => "L{$line}",
            'source' => 'file',
            'line' => $line,
            'sku' => $r['sku'] ?? '',
            'name' => $r['name'] ?? '',
            'range' => ($r['range'] ?? '') ?: null,
            'categories' => [],
            'pack_text' => $r['name'] ?? '',
            'base_unit' => ($r['base_unit'] ?? '') ? mb_strtolower($r['base_unit']) : null,
            'pack_name' => ($r['pack_name'] ?? '') ?: null,
            'pack_size' => $whole('pack_size', 100000),
            'barcode' => $barcode !== '' ? $barcode : null,
            'barcode_ignored' => false,
            'price' => null,
            'sell_ordermentum' => $bool('sell_on_ordermentum'),
            'sell_portal' => $bool('sell_on_portal'),
            'external' => $external,
            'batch_tracked' => $bool('batch_tracked'),
            'shelf_life_days' => $whole('shelf_life_days', 3650),
            'active' => $bool('active'),
            'errors' => $errors,
        ];
    }

    /** @return array<int, list<string|null>> rows keyed by zero-based row number */
    private function csvRows(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (! $handle) {
            throw new RuntimeException('Cannot read the file.');
        }
        $rows = [];
        while (($r = fgetcsv($handle)) !== false && count($rows) <= self::MAX_ROWS) {
            $rows[] = $r;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * The first worksheet of a workbook, cells in column order, so an empty cell
     * Excel leaves out doesn't shift the rest of the row. Row numbers stay in the
     * keys so a problem reads "line 12" like the sheet does.
     *
     * @return array<int, list<string|null>>
     */
    private function xlsxRows(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('This server has no PHP zip extension. Save the sheet as CSV and upload that.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('That is not a readable .xlsx file.');
        }
        $sheet = $zip->getFromName($this->firstSheet($zip));
        if ($sheet === false) {
            throw new RuntimeException('That is not a readable .xlsx file.');
        }
        $strings = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach ($this->xml($xml)->si as $si) {
                $strings[] = $this->text($si);
            }
        }
        $zip->close();

        $rows = [];
        foreach ($this->xml($sheet)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                preg_match('/^[A-Z]+/', (string) $c['r'], $ref);
                $cells[$this->columnIndex($ref[0] ?? 'A')] = match ((string) $c['t']) {
                    's' => $strings[(int) $c->v] ?? '',
                    'inlineStr' => $this->text($c->is),
                    default => $this->number((string) $c->v),
                };
            }
            if ($cells !== []) {
                $rows[(int) $row['r'] - 1] = array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
            }
        }
        ksort($rows);

        return array_slice($rows, 0, self::MAX_ROWS + 1, true);
    }

    private function firstSheet(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $rels !== false) {
            $sheet = $this->xml($workbook)->sheets->sheet[0] ?? null;
            $id = $sheet ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
            foreach ($this->xml($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $id) {
                    return 'xl/'.preg_replace('#^/?(xl/)?#', '', (string) $rel['Target']);
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function xml(string $xml): SimpleXMLElement
    {
        $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        if ($parsed === false) {
            throw new RuntimeException('That is not a readable .xlsx file.');
        }

        return $parsed;
    }

    /** Plain and rich text cells. */
    private function text(SimpleXMLElement $node): string
    {
        $text = (string) $node->t;
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /** Excel stores long numbers as 9.3E+12; a barcode must come back whole. */
    private function number(string $value): string
    {
        return preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $value) ? number_format((float) $value, 0, '', '') : $value;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }
}
