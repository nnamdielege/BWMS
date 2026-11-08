<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Support\Str;

/**
 * Quantities are typed as packs plus loose base units ("1 Case + 5 each") and
 * stored as base units. Every screen that takes a quantity sends `packs` and
 * `each`, never a bare number, so nobody has to know which one a box wants.
 */
final class PackQty
{
    /** Base units that read the same in the plural. */
    private const INVARIANT = ['each', 'ea', 'kg', 'g', 'l', 'ml', 'ltr', 'm'];

    /** Validation for a `packs` + `each` pair, optionally under a prefix such as "picked.*.". */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}packs" => ['nullable', 'integer', 'min:0', 'max:1000000'],
            "{$prefix}each" => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /** @param  array{packs?: int|string|null, each?: int|string|null}  $input */
    public static function toBase(array $input, int $perPack): int
    {
        return (int) ($input['packs'] ?? 0) * max(1, $perPack) + (int) ($input['each'] ?? 0);
    }

    /** Neither box filled in. */
    public static function isBlank(array $input): bool
    {
        return ($input['packs'] ?? null) === null && ($input['each'] ?? null) === null;
    }

    /** "1 Case", "1 Case + 5 each", "5 units"; "0 Case" for nothing. */
    public static function label(int $base, int $perPack, string $packName, string $baseUnit): string
    {
        if ($perPack <= 1) {
            return "{$base} ".self::unit($baseUnit, $base);
        }
        $packs = intdiv(abs($base), $perPack);
        $loose = abs($base) % $perPack;
        $text = match (true) {
            $loose === 0 => "{$packs} {$packName}",
            $packs === 0 => "{$loose} ".self::unit($baseUnit, $loose),
            default => "{$packs} {$packName} + {$loose} ".self::unit($baseUnit, $loose),
        };

        return $base < 0 ? "−{$text}" : $text;
    }

    /** "1 Case = 12 each", shown next to the boxes. */
    public static function hint(int $perPack, string $packName, string $baseUnit): ?string
    {
        return $perPack > 1 ? "1 {$packName} = {$perPack} ".self::unit($baseUnit, $perPack) : null;
    }

    public static function unit(string $baseUnit, int $n): string
    {
        return $n === 1 || in_array(strtolower($baseUnit), self::INVARIANT, true) ? $baseUnit : Str::plural($baseUnit);
    }

    /** The pack a stocktake or a stock move is counted in: the product's largest. */
    public static function largestPack(Product $product): ?ProductUnit
    {
        return $product->units->where('base_qty', '>', 1)->sortByDesc('base_qty')->first();
    }

    /** What the input component needs for a pack. */
    public static function forInput(int $perPack, string $packName, string $baseUnit): array
    {
        return ['per' => max(1, $perPack), 'name' => $packName, 'base_unit' => $baseUnit];
    }
}
