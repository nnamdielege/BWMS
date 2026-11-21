<?php

namespace App\Services\Finance;

use App\Enums\OrderChannel;
use App\Models\Order;

/**
 * Finds the order a bank receipt pays, for the cash GST basis, from any reference the customer
 * might quote: the BWMS number (SO-2026-00042), the portal's order ref (ORD-…), or Ordermentum's
 * invoice or order number (orders.channel_invoice_number / channel_order_number).
 *
 * Banks mangle references, so both sides are compared without spaces, hyphens, slashes, dots,
 * underscores or #, and case-insensitively: "ord 1234", "ORD-1234" and "Ord1234" are the same.
 * A reference split across two words ("INV 10042") is tried joined as well. Only exact matches
 * count, of at least 4 characters with a digit in them; a description that matches more than
 * one order matches none, so a receipt is never pinned on the wrong order.
 */
final class OrderMatcher
{
    private const STRIP = ['-', ' ', '/', '#', '.', '_'];

    /** The order a bank description refers to, or null (none, or more than one). */
    public function inDescription(string $description): ?int
    {
        $ids = $this->candidates($this->tokens($description));

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Orders a typed reference could mean (manual entry): normally one.
     *
     * @return list<int>
     */
    public function byReference(string $reference): array
    {
        $norm = self::norm($reference);

        return $norm === null ? [] : $this->candidates([$norm]);
    }

    public static function norm(string $value): ?string
    {
        $n = strtoupper(str_replace(self::STRIP, '', trim($value)));

        return preg_match('/^[A-Z0-9]{4,64}$/', $n) && preg_match('/\d/', $n) ? $n : null;
    }

    /** @return list<string> normalised tokens, and adjacent pairs joined */
    private function tokens(string $description): array
    {
        $words = array_values(array_filter(preg_split('/[\s,;:()\[\]\'"=+*|\\\\]+/', $description) ?: [], fn ($w) => $w !== ''));
        $out = [];
        foreach ($words as $i => $w) {
            $out[] = self::norm($w);
            if (isset($words[$i + 1])) {
                $out[] = self::norm($w.$words[$i + 1]);
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /** @param list<string> $norms @return list<int> distinct order ids */
    private function candidates(array $norms): array
    {
        if ($norms === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($norms), '?'));
        // Fixed column names only; the values are bound.
        $in = fn ($q, string $column) => $q->whereRaw(
            array_reduce(self::STRIP, fn ($e, $c) => "REPLACE({$e}, '{$c}', '')", "UPPER({$column})")." IN ({$marks})", $norms);

        return Order::query()
            ->where(function ($q) use ($in) {
                $q->where(fn ($q) => $in($q, 'number'))
                    ->orWhere(fn ($q) => $in($q->where('channel', OrderChannel::Portal->value), 'external_ref'))
                    ->orWhere(fn ($q) => $q->where('channel', OrderChannel::Ordermentum->value)
                        ->where(fn ($q) => $in($q, 'channel_invoice_number')->orWhere(fn ($q) => $in($q, 'channel_order_number'))));
            })
            ->limit(5)->pluck('id')->unique()->values()->all();
    }
}
