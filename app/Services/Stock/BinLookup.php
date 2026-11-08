<?php

namespace App\Services\Stock;

use App\Models\Location;

/**
 * Finds a bin from what a spreadsheet says: its full path as the exports write
 * it ("MAIN / A / A-01"), or its code alone when that names one bin in scope.
 */
class BinLookup
{
    public const UNKNOWN = -1;

    public const OUTSIDE = -2;

    public const AMBIGUOUS = -3;

    /** @var array<string, int> */
    private array $byPath = [];

    /** @var array<string, list<int>> */
    private array $byCode = [];

    /** @param  list<int>  $inScope  bin ids the stocktake covers */
    public function __construct(private array $inScope)
    {
        foreach (Location::bins()->with('parent.parent.parent')->get() as $bin) {
            $this->byPath[self::normal($bin->path())] = $bin->id;
            $this->byCode[self::normal($bin->code)][] = $bin->id;
        }
    }

    /** A bin id, or UNKNOWN, OUTSIDE or AMBIGUOUS. */
    public function find(string $text): int
    {
        $text = self::normal($text);
        if (isset($this->byPath[$text])) {
            return $this->scoped($this->byPath[$text]);
        }
        $ids = $this->byCode[$text] ?? [];
        $inScope = array_values(array_intersect($ids, $this->inScope));

        return match (true) {
            count($inScope) === 1 => $inScope[0],
            count($inScope) > 1 => self::AMBIGUOUS,
            $ids !== [] => self::OUTSIDE,
            default => self::UNKNOWN,
        };
    }

    private function scoped(int $id): int
    {
        return in_array($id, $this->inScope, true) ? $id : self::OUTSIDE;
    }

    private static function normal(string $text): string
    {
        return mb_strtolower(preg_replace('#\s*/\s*#', ' / ', trim($text)));
    }
}
