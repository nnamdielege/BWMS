<?php

namespace App\Services\Documents;

use RuntimeException;
use ZipArchive;

/**
 * A one-sheet .xlsx, written directly (ext-zip, no spreadsheet library): text,
 * numbers, bold rows, money columns, boxed blank cells for writing in, column
 * widths, a frozen header, a repeated print header and a print footer.
 * Strings are inline, so nothing in a cell is ever read as a formula.
 */
class XlsxWriter
{
    private const STYLE_BOLD = 1;

    private const STYLE_MONEY = 2;

    private const STYLE_BOLD_MONEY = 3;

    private const STYLE_BOX = 4;

    private const STYLE_COST = 5;

    /** @var list<array{cells: list<mixed>, bold: bool}> */
    private array $rows = [];

    /** @var array<int, float> */
    private array $widths = [];

    /** @var array<int, int> column index => style for numbers */
    private array $numberStyles = [];

    /** @var list<int> */
    private array $boxColumns = [];

    private ?int $headerRow = null;

    private ?string $footer = null;

    private bool $landscape = false;

    public function __construct(private string $sheetName = 'Sheet1') {}

    /** @param  list<string|int|float|null>  $cells */
    public function row(array $cells, bool $bold = false): static
    {
        $this->rows[] = ['cells' => array_values($cells), 'bold' => $bold];

        return $this;
    }

    /** The table's header row, added now: frozen on screen and repeated on every printed page. */
    public function headerRow(array $cells): static
    {
        $this->row($cells, true);
        $this->headerRow = count($this->rows);

        return $this;
    }

    /** @param  list<float>  $widths  in characters */
    public function widths(array $widths): static
    {
        $this->widths = $widths;

        return $this;
    }

    /** @param  list<int>  $columns  0-based; numbers there show as 1,234.56 */
    public function moneyColumns(array $columns): static
    {
        foreach ($columns as $c) {
            $this->numberStyles[$c] = self::STYLE_MONEY;
        }

        return $this;
    }

    /** @param  list<int>  $columns  0-based; a unit cost, 2 to 4 decimals */
    public function costColumns(array $columns): static
    {
        foreach ($columns as $c) {
            $this->numberStyles[$c] = self::STYLE_COST;
        }

        return $this;
    }

    /** @param  list<int>  $columns  0-based; blank cells below the header get a box to write in */
    public function boxColumns(array $columns): static
    {
        $this->boxColumns = $columns;

        return $this;
    }

    /** Excel header/footer codes: &L &C &R sections, &P page, &N pages. */
    public function footer(string $footer): static
    {
        $this->footer = $footer;

        return $this;
    }

    public function landscape(): static
    {
        $this->landscape = true;

        return $this;
    }

    public function toString(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the spreadsheet.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function workbook(): string
    {
        $name = self::xml(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $this->sheetName), 0, 31));
        $titles = $this->headerRow
            ? '<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">\''.str_replace("'", "''", $name)."'!\${$this->headerRow}:\${$this->headerRow}</definedName></definedNames>"
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            ."<sheets><sheet name=\"{$name}\" sheetId=\"1\" r:id=\"rId1\"/></sheets>{$titles}</workbook>";
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.00##"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs></styleSheet>';
    }

    private function sheet(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        if ($this->headerRow) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$this->headerRow.'" topLeftCell="A'.($this->headerRow + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if ($this->widths) {
            $xml .= '<cols>';
            foreach ($this->widths as $i => $w) {
                $xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($this->rows as $r => $row) {
            $n = $r + 1;
            $boxed = $this->headerRow !== null && $n > $this->headerRow && $this->boxColumns !== [];
            $xml .= "<row r=\"{$n}\">";
            $count = $boxed ? max(count($row['cells']), max($this->boxColumns) + 1) : count($row['cells']);
            for ($c = 0; $c < $count; $c++) {
                $xml .= $this->cell(self::column($c).$n, $row['cells'][$c] ?? null, $c, $row['bold'], $boxed && in_array($c, $this->boxColumns, true));
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        $xml .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.7" header="0.3" footer="0.3"/>'
            .'<pageSetup paperSize="9" orientation="'.($this->landscape ? 'landscape' : 'portrait').'" fitToWidth="1" fitToHeight="0"/>';
        if ($this->footer !== null) {
            $xml .= '<headerFooter><oddFooter>'.self::xml($this->footer).'</oddFooter></headerFooter>';
        }

        return $xml.'</worksheet>';
    }

    private function cell(string $ref, mixed $value, int $column, bool $bold, bool $box): string
    {
        if ($value === null || $value === '') {
            return $box ? "<c r=\"{$ref}\" s=\"".self::STYLE_BOX.'"/>' : '';
        }
        if (is_int($value) || is_float($value)) {
            $style = match (true) {
                ($this->numberStyles[$column] ?? null) === self::STYLE_MONEY => $bold ? self::STYLE_BOLD_MONEY : self::STYLE_MONEY,
                isset($this->numberStyles[$column]) => $this->numberStyles[$column],
                default => $bold ? self::STYLE_BOLD : 0,
            };

            return "<c r=\"{$ref}\"".($style ? " s=\"{$style}\"" : '').'><v>'.(is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : $value).'</v></c>';
        }

        return "<c r=\"{$ref}\" t=\"inlineStr\"".($bold ? ' s="'.self::STYLE_BOLD.'"' : '').'><is><t xml:space="preserve">'.self::xml((string) $value).'</t></is></c>';
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + ($i - 1) % 26).$name;
        }

        return $name;
    }

    /** XML-escaped, without the control characters XML 1.0 can't hold. */
    private static function xml(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
