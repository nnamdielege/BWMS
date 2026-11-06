<?php

namespace App\Integrations\Ordermentum;

use App\Rules\Gtin;

/**
 * Reads the supplier's catalogue for the product import, GET only.
 *
 * GET /v1/variants has the SKU, name, price, barcode and active state, and
 * GET /v1/products adds the categories. They are joined on productId. Every
 * item is one variant today, but nothing here relies on that.
 *
 * What the live data looks like (2026-11-06): `unit`, `uom`, `unitSize` and
 * `packingUnit` are "unit" / 1 on all 145 items, so the pack is read from the
 * name; `barcode` is an internal "x" + 8 hex value, never a GTIN, so it is dropped
 * rather than stored against a pack.
 */
class OrdermentumCatalogue
{
    private const PAGE_SIZE = 100;

    public function __construct(private OrdermentumClient $client) {}

    /** @return list<array<string, mixed>> import items, see ProductImportPlanner */
    public function items(): array
    {
        $categories = [];
        foreach ($this->all('/v1/products') as $product) {
            $categories[$product['id']] = array_values(array_filter($product['categoryNames'] ?? []));
        }
        $variants = collect($this->all('/v1/variants'))->reject(fn ($v) => filled($v['deletedAt'] ?? null))->unique('id')->values();

        // The broadest category names the range: "Juices" rather than "Noah's Juices".
        $sizes = array_count_values(array_merge([], ...array_values($categories)));

        return $variants->map(function (array $v) use ($categories, $sizes) {
            $names = $categories[$v['productId'] ?? ''] ?? [];
            $name = trim((string) ($v['name'] ?? ''));
            $unit = trim((string) ($v['unit'] ?? ''));
            $barcode = trim((string) ($v['barcode'] ?? ''));
            $gtin = Gtin::isValid($barcode);

            return [
                'key' => 'V'.$v['id'],
                'source' => 'ordermentum',
                'line' => null,
                'sku' => trim((string) ($v['SKU'] ?? '')),
                'name' => $name,
                'range' => collect($names)->sortByDesc(fn ($n) => $sizes[$n])->first(),
                'categories' => $names,
                'pack_text' => preg_match('/\d/', $unit) ? $unit : $name,
                'base_unit' => null,
                'pack_name' => null,
                'pack_size' => null,
                'barcode' => $gtin ? $barcode : null,
                'barcode_ignored' => $barcode !== '' && ! $gtin,
                'price' => is_numeric($v['price'] ?? null) && (float) $v['price'] > 0 ? (string) $v['price'] : null,
                'sell_ordermentum' => true,
                'sell_portal' => null,
                'external' => ['ordermentum' => ['variant_id' => $v['id'], 'product_id' => $v['productId'] ?? null, 'sku' => $v['SKU'] ?? null]],
                'batch_tracked' => null,
                'shelf_life_days' => null,
                'active' => blank($v['deactivatedAt'] ?? null) && ($v['visible'] ?? true) !== false,
                'errors' => [],
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function all(string $path): array
    {
        $rows = [];
        $page = 1;
        do {
            $response = $this->client->get($path, [
                'supplierId' => config('services.ordermentum.supplier_id'), 'pageSize' => self::PAGE_SIZE, 'pageNo' => $page,
            ]);
            if (! $response->successful()) {
                throw new OrdermentumException("Ordermentum returned {$response->status()} for {$path}.");
            }
            $rows = array_merge($rows, $response->json('data') ?? []);
            $pages = (int) $response->json('meta.totalPages');
        } while (++$page <= $pages);

        // A partial list would make every missing item look like nothing to do.
        $total = (int) $response->json('meta.totalResults');
        if (count($rows) < $total) {
            throw new OrdermentumException('Ordermentum returned '.count($rows)." of {$total} rows for {$path}.");
        }

        return $rows;
    }
}
