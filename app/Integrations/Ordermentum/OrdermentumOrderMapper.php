<?php

namespace App\Integrations\Ordermentum;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * The one place that knows Ordermentum's order JSON shape
 * (config bwms.ordermentum.order_mapping).
 *
 * Confirmed on a live order (2026-10-27): lineItems[].quantity and
 * lineItems[].variantId (= the stock API item ID). If Ordermentum's shape ever
 * changes, the order fails with the keys it found, and the paths can be
 * re-pointed via config without a code change.
 */
class OrdermentumOrderMapper
{
    /** @var array{lines: list<string>, quantity: string, variant: list<string>, line_ref: list<string>, unit_price: string, sku: list<string>, name: list<string>} */
    private array $map;

    public function __construct(?array $map = null)
    {
        $this->map = $map ?? config('bwms.ordermentum.order_mapping');
    }

    /**
     * @return array{external_ref: string, version: int, cancelled: bool, retailer_id: ?string, retailer_name: ?string,
     *               delivery_date: ?string, delivery: array<string, mixed>, lines: list<array{line_ref: string, variant_id: string, quantity: int, unit_price: ?string, sku: ?string, name: ?string}>}
     *
     * @throws UnmappableOrder when the shape does not match the configured mapping
     */
    public function map(array $order): array
    {
        $id = (string) ($order['id'] ?? '');
        if ($id === '') {
            throw new UnmappableOrder('Order has no id.');
        }

        $rawLines = $this->first($order, $this->map['lines']);
        $cancelled = filled($order['cancelledAt'] ?? null) || strcasecmp((string) ($order['status'] ?? ''), 'cancelled') === 0;
        if (! is_array($rawLines) || ! array_is_list($rawLines)) {
            if ($cancelled) {
                $rawLines = []; // a cancellation does not need its lines
            } else {
                throw new UnmappableOrder('No line items found at '.implode(' / ', $this->map['lines']).'. Top-level keys: '.implode(', ', array_keys($order)).'.');
            }
        }

        $lines = [];
        foreach ($rawLines as $i => $line) {
            $line = (array) $line;
            $variant = $this->first($line, $this->map['variant']);
            if (blank($variant) || ! is_scalar($variant)) {
                throw new UnmappableOrder("Line {$i}: no variant ID at ".implode(' / ', $this->map['variant'])
                    .'. Line keys: '.implode(', ', array_keys($line)).'. Set ORDERMENTUM_LINE_VARIANT_FIELDS once om:probe shows the field.');
            }
            $qty = data_get($line, $this->map['quantity']);
            if (! is_numeric($qty) || (float) $qty != (int) $qty || (int) $qty < 0) {
                throw new UnmappableOrder("Line {$i}: quantity must be a whole number (got ".json_encode($qty).').');
            }
            $ref = $this->first($line, $this->map['line_ref']);
            $price = data_get($line, $this->map['unit_price']);

            $lines[] = [
                'line_ref' => (string) ($ref ?? $i + 1),
                'variant_id' => (string) $variant,
                'quantity' => (int) $qty,
                'unit_price' => is_numeric($price) ? (string) $price : null,
                'sku' => $this->stringOrNull($this->first($line, $this->map['sku'])),
                'name' => $this->stringOrNull($this->first($line, $this->map['name'])),
            ];
        }

        return [
            'external_ref' => $id,
            'version' => $this->version($order),
            'cancelled' => $cancelled,
            'retailer_id' => $this->stringOrNull($order['retailerId'] ?? data_get($order, 'retailer.id')),
            'retailer_name' => $this->stringOrNull(data_get($order, 'retailer.name') ?? data_get($order, 'retailerName')),
            'delivery_date' => $this->date($order['deliveryDate'] ?? null),
            'delivery' => array_filter([
                'street' => trim(implode(' ', array_filter([data_get($order, 'address.number'), data_get($order, 'address.street1'), data_get($order, 'address.street2')]))) ?: null,
                'suburb' => $this->stringOrNull(data_get($order, 'address.suburb')),
                'postcode' => $this->stringOrNull(data_get($order, 'address.postcode')),
                'lat' => is_numeric(data_get($order, 'address.latitude')) ? (float) data_get($order, 'address.latitude') : null,
                'lng' => is_numeric(data_get($order, 'address.longitude')) ? (float) data_get($order, 'address.longitude') : null,
                'instructions' => $this->stringOrNull($order['deliveryInstructions'] ?? null),
            ], fn ($v) => $v !== null),
            'lines' => $lines,
        ];
    }

    /** updatedAt in milliseconds (Ordermentum has no version number). Missing -> now, so the fetched state wins. */
    private function version(array $order): int
    {
        $updated = $order['updatedAt'] ?? null;
        try {
            return $updated ? (int) Carbon::parse($updated)->getPreciseTimestamp(3) : (int) Date::now()->getPreciseTimestamp(3);
        } catch (\Throwable) {
            return (int) Date::now()->getPreciseTimestamp(3);
        }
    }

    private function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('bwms.business_timezone'))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  list<string>  $paths */
    private function first(array $data, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }
}
