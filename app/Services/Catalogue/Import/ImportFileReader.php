<?php

namespace App\Services\Catalogue\Import;

use App\Rules\Gtin;
use App\Services\Documents\SpreadsheetReader;
use Illuminate\Support\Str;
use RuntimeException;

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

    public function __construct(private SpreadsheetReader $sheets) {}

    /**
     * @return list<array<string, mixed>> import items, see ProductImportPlanner
     *
     * @throws RuntimeException when the file can't be read at all
     */
    public function read(string $path, string $extension): array
    {
        $rows = $this->sheets->rows($path, $extension, self::MAX_ROWS + 1);
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
}
