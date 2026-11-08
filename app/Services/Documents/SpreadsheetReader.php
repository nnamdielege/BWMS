<?php

namespace App\Services\Documents;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * The rows of an uploaded CSV or the first sheet of an XLSX, as text. Row
 * numbers stay in the keys (zero-based) so a problem reads "line 12" like the
 * sheet does. No formulas or styles: what Excel last saved in each cell.
 */
class SpreadsheetReader
{
    /**
     * @return array<int, list<string>> at most $limit rows
     *
     * @throws RuntimeException when the file can't be read at all
     */
    public function rows(string $path, string $extension, int $limit): array
    {
        return strtolower($extension) === 'xlsx' ? $this->xlsxRows($path, $limit) : $this->csvRows($path, $limit);
    }

    /** @return array<int, list<string>> */
    private function csvRows(string $path, int $limit): array
    {
        $handle = @fopen($path, 'r');
        if (! $handle) {
            throw new RuntimeException('Cannot read the file.');
        }
        $rows = [];
        while (count($rows) < $limit && ($r = fgetcsv($handle)) !== false) {
            $rows[] = array_map(fn ($c) => (string) $c, $r);
        }
        fclose($handle);
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\x{FEFF}/u', '', $rows[0][0]);
        }

        return $rows;
    }

    /**
     * Cells in column order, so an empty cell Excel leaves out doesn't shift the
     * rest of the row.
     *
     * @return array<int, list<string>>
     */
    private function xlsxRows(string $path, int $limit): array
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

        return array_slice($rows, 0, $limit, true);
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
