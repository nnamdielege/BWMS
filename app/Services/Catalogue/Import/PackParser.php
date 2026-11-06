<?php

namespace App\Services\Catalogue\Import;

/**
 * Reads a pack out of free text such as "Carton 12 x 1L", "Giddy Fizz Soda 330ml x 12"
 * or "Milk Lab Soy (12x1lt)". Ordermentum's own unit fields are "unit" / 1 on every
 * item, so for the real catalogue this text is the item name.
 *
 * Only an explicit quantity counts as sure. Anything else comes back unsure with a
 * reason, and the person decides: a lone size such as "Syrup 750ml" carries a
 * suggestion they can accept, it is never applied on its own.
 *
 * @phpstan-type Parsed array{sure: bool, pack_name: ?string, pack_size: ?int, base_unit: ?string, why: ?string, suggested: ?array{pack_name: string, pack_size: int, base_unit: string}}
 */
final class PackParser
{
    private const MAX_PACK = 500;

    private const SIZE = '(\d+(?:\.\d+)?)\s*(ml|cl|lt|ltr|litres?|liters?|l|kg|gm|g)\b';

    private const TIMES = '\s*[x×]\s*';

    /** Text that sells a deal or a bundle, where any number in it is not a pack size. */
    private const DEAL = '/\b\d+\s*\+\s*\d+\b|\b(promo|bonus|offer|buy\s+\d|misc)\b/i';

    private const PACK_WORDS = ['carton' => 'Carton', 'ctn' => 'Carton', 'case' => 'Case', 'tray' => 'Tray', 'box' => 'Box'];

    /** The container the text names, if it names one. Otherwise liquids are bottles and the rest packs. */
    private const CONTAINERS = ['bottle', 'can', 'bar', 'bag', 'tub', 'tin', 'jar', 'pouch', 'sachet'];

    /** No pack word in the text: the WMS's usual name for an outer. */
    private const DEFAULT_OUTER = 'Case';

    /** @return Parsed */
    public function parse(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return $this->unsure('No pack text.');
        }
        if (preg_match(self::DEAL, $text)) {
            return $this->unsure('Looks like a deal or bundle, not a pack.');
        }

        $times = self::TIMES;
        $size = self::SIZE;
        $qtyFirst = preg_match_all("/(?<![\d.])(\d{1,4}){$times}{$size}/i", $text, $before, PREG_SET_ORDER);
        $sizeFirst = preg_match_all("/{$size}{$times}(\d{1,4})\b/i", $text, $after, PREG_SET_ORDER);
        $multipliers = preg_match_all('/[x×]\s*\d/i', $text);

        if ($qtyFirst + $sizeFirst > 1 || $multipliers > 1) {
            return $this->unsure('More than one quantity in the text.');
        }
        if ($qtyFirst === 1) {
            return $this->pack($text, (int) $before[0][1], $before[0][2], $before[0][3]);
        }
        if ($sizeFirst === 1) {
            return $this->pack($text, (int) $after[0][3], $after[0][1], $after[0][2]);
        }
        if ($multipliers === 1) {
            return $this->unsure('Has a quantity, but no size with a unit.');
        }

        $words = implode('|', array_keys(self::PACK_WORDS));
        if (preg_match("/\b({$words})\s+(?:of\s+)?(\d{1,4})\b/i", $text, $m)) {
            return $this->known(self::PACK_WORDS[strtolower($m[1])], (int) $m[2], 'each');
        }

        $sizes = preg_match_all("/(?<![\d.]){$size}/i", $text, $found, PREG_SET_ORDER);
        if ($sizes === 1) {
            return $this->unsure('No pack quantity in the text. It may be a single.', [
                'pack_name' => 'Each', 'pack_size' => 1, 'base_unit' => $this->baseUnit($text, $found[0][1], $found[0][2]),
            ]);
        }

        return $this->unsure($sizes > 1 ? 'More than one size in the text.' : 'No pack size in the text.');
    }

    /** @return Parsed */
    private function pack(string $text, int $qty, string $amount, string $unit): array
    {
        if ($qty < 1 || $qty > self::MAX_PACK) {
            return $this->unsure("A pack of {$qty} looks wrong.");
        }
        $baseUnit = $this->baseUnit($text, $amount, $unit);
        if ($qty === 1) {
            return $this->known('Each', 1, $baseUnit);
        }
        $words = implode('|', array_keys(self::PACK_WORDS));

        return $this->known(preg_match("/\b({$words})\b/i", $text, $m) ? self::PACK_WORDS[strtolower($m[1])] : self::DEFAULT_OUTER, $qty, $baseUnit);
    }

    private function baseUnit(string $text, string $amount, string $unit): string
    {
        $unit = strtolower($unit);
        $normal = match (true) {
            $unit === 'ml', $unit === 'cl', $unit === 'kg' => $unit,
            $unit === 'g', $unit === 'gm' => 'g',
            default => 'L',
        };
        $names = implode('|', self::CONTAINERS);
        $container = preg_match("/\b({$names})s?\b/i", $text, $m)
            ? strtolower($m[1])
            : (in_array($normal, ['ml', 'cl', 'L'], true) ? 'bottle' : 'pack');

        return "{$amount}{$normal} {$container}";
    }

    /** @return Parsed */
    private function known(string $name, int $size, string $baseUnit): array
    {
        return ['sure' => true, 'pack_name' => $name, 'pack_size' => $size, 'base_unit' => $baseUnit, 'why' => null, 'suggested' => null];
    }

    /** @return Parsed */
    private function unsure(string $why, ?array $suggested = null): array
    {
        return ['sure' => false, 'pack_name' => null, 'pack_size' => null, 'base_unit' => null, 'why' => $why, 'suggested' => $suggested];
    }
}
