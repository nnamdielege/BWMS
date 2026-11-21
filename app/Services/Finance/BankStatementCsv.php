<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;

/**
 * Reads a bank-statement CSV into credits: date, description, amount.
 *
 * With a header row, the columns are found by name (Date; Description / Narrative / Details /
 * Memo / Particulars / Transaction; Amount, or Credit and Debit). Without one (CommBank's
 * export, for example: date, amount, description, balance) each row is read as the first date,
 * the first amount after it, and the longest text. Debits (negative, or in a Debit column) are
 * skipped. Dates are Australian: 5/11/2026 is 5 November.
 *
 * Each credit gets a fingerprint of date, description and amount, plus how many times that same
 * row has already appeared in this file, so importing the same statement (or an overlapping one)
 * twice adds nothing, while two identical payments on one day both count.
 */
final class BankStatementCsv
{
    private const DESCRIPTION = ['description', 'narrative', 'details', 'transaction details', 'memo', 'particulars', 'transaction', 'reference', 'payee'];

    /**
     * @param  list<list<string>>  $rows
     * @return array{credits: list<array{received_on: string, description: string, cents: int, fingerprint: string}>, debits: int, invalid: int}
     */
    public static function parse(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => implode('', array_map('trim', $r)) !== ''));
        $map = $rows ? self::header($rows[0]) : null;
        if ($map !== null) {
            array_shift($rows);
        }

        $credits = [];
        $debits = 0;
        $invalid = 0;
        $seen = [];
        foreach ($rows as $row) {
            $row = array_map(fn ($c) => trim((string) $c), $row);
            [$date, $description, $cents] = $map !== null ? self::byHeader($row, $map) : self::guess($row);
            if ($date === null || $cents === null) {
                $invalid++;

                continue;
            }
            if ($cents <= 0) {
                $debits++;

                continue;
            }
            $description = mb_substr(preg_replace('/\s+/', ' ', $description), 0, 255) ?: 'Bank credit';
            $key = $date.'|'.mb_strtolower($description).'|'.$cents;
            $n = $seen[$key] = ($seen[$key] ?? 0) + 1;
            $credits[] = ['received_on' => $date, 'description' => $description, 'cents' => $cents, 'fingerprint' => sha1($key.'|'.$n)];
        }

        return ['credits' => $credits, 'debits' => $debits, 'invalid' => $invalid];
    }

    /** Dollars to cents: $1,234.50, -12.00, (12.00), 12.00 CR / DR, +5. Null if it isn't a number. */
    public static function amount(string $raw): ?int
    {
        $s = strtoupper(trim($raw));
        if ($s === '') {
            return null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            [$s, $negative] = [$m[1], true];
        }
        if (preg_match('/^(.*?)\s*(CR|DR)$/', $s, $m)) {
            [$s, $negative] = [$m[1], $m[2] === 'DR'];
        }
        $s = str_replace(['$', ',', ' ', 'AUD'], '', $s);
        if (! preg_match('/^([+-]?)(\d+(?:\.\d{1,2})?|\.\d{1,2})$/', $s, $m)) {
            return null;
        }
        $cents = (int) round((float) $m[2] * 100);

        return ($negative || $m[1] === '-') ? -$cents : $cents;
    }

    /** d/m/Y, d/m/y, Y-m-d, d-m-Y, d.m.Y, "5 Nov 2026", "05-Nov-26" → Y-m-d; null otherwise. */
    public static function date(string $raw): ?string
    {
        $s = trim(preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\s*[AP]M)?$/i', '', trim($raw)));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2}|\d{4})$#', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\s\-]([A-Za-z]{3,9})[\s\-,]+(\d{2}|\d{4})$/', $s, $m)) {
            $mo = (int) date('n', strtotime('1 '.substr($m[2], 0, 3).' 2000') ?: 0);
            [$d, $y] = [(int) $m[1], (int) $m[3]];
            if ($mo === 0 || ! preg_match('/^(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i', $m[2])) {
                return null;
            }
        } else {
            return null;
        }
        $y = $y < 100 ? 2000 + $y : $y;

        return checkdate($mo, $d, $y) ? Carbon::create($y, $mo, $d)->toDateString() : null;
    }

    /** @return array{date: int, description: ?int, amount: ?int, credit: ?int, debit: ?int}|null */
    private static function header(array $row): ?array
    {
        $cells = array_map(fn ($c) => strtolower(trim(preg_replace('/^\x{FEFF}/u', '', (string) $c))), $row);
        $find = function (array $names) use ($cells): ?int {
            foreach ($names as $name) {
                foreach ($cells as $i => $c) {
                    if ($c === $name) {
                        return $i;
                    }
                }
            }
            foreach ($names as $name) {
                foreach ($cells as $i => $c) {
                    if (str_contains($c, $name)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $date = $find(['date', 'transaction date', 'posted', 'value date']);
        $amount = $find(['amount']);
        $credit = $find(['credit', 'deposit', 'paid in']);
        if ($date === null || ($amount === null && $credit === null) || self::date($row[$date] ?? '') !== null) {
            return null;
        }

        return ['date' => $date, 'description' => $find(self::DESCRIPTION), 'amount' => $amount, 'credit' => $credit, 'debit' => $find(['debit', 'withdrawal', 'paid out'])];
    }

    /** @return array{0: ?string, 1: string, 2: ?int} */
    private static function byHeader(array $row, array $map): array
    {
        $date = self::date($row[$map['date']] ?? '');
        $description = $map['description'] !== null ? ($row[$map['description']] ?? '') : '';
        if ($map['amount'] !== null) {
            $cents = self::amount($row[$map['amount']] ?? '');
        } else {
            $credit = self::amount($row[$map['credit']] ?? '');
            $debit = $map['debit'] !== null ? self::amount($row[$map['debit']] ?? '') : null;
            $cents = $credit !== null && $credit !== 0 ? abs($credit) : ($debit !== null ? -abs($debit) : null);
        }

        return [$date, $description, $cents];
    }

    /** @return array{0: ?string, 1: string, 2: ?int} */
    private static function guess(array $row): array
    {
        $date = null;
        $cents = null;
        $text = '';
        foreach ($row as $cell) {
            if ($date === null && ($d = self::date($cell)) !== null) {
                $date = $d;
            } elseif ($date !== null && $cents === null && ($a = self::amount($cell)) !== null) {
                $cents = $a;
            } elseif (self::amount($cell) === null && mb_strlen($cell) > mb_strlen($text)) {
                $text = $cell;
            }
        }

        return [$date, $text, $cents];
    }
}
