<?php

namespace App\Migration\Legacy;

use Illuminate\Database\Connection;

/**
 * The legacy columns the importer reads, checked against the restored dump's
 * information_schema before anything is imported (NFR-8: live schema, never the
 * legacy migration files).
 */
final class LegacySchema
{
    public const EXPECTED = [
        'product_categories' => ['id', 'name', 'parent_id', 'is_active', 'deleted_at'],
        'products' => ['id', 'ordermentum_variant_id', 'ordermentum_product_id', 'sku', 'ordermentum_sku', 'barcode', 'name', 'description',
            'category_id', 'cost', 'price', 'unit_of_measure', 'reorder_point', 'reorder_quantity', 'is_active', 'deleted_at'],
        'suppliers' => ['id', 'supplier_code', 'company_name', 'contact_name', 'email', 'phone', 'mobile', 'address', 'city', 'state',
            'postal_code', 'country', 'tax_id', 'payment_terms', 'credit_limit', 'notes', 'is_active', 'deleted_at'],
        'customers' => ['id', 'customer_code', 'company_name', 'name', 'email', 'phone', 'mobile', 'address', 'city', 'state',
            'postal_code', 'country', 'tax_id', 'payment_terms', 'credit_limit', 'notes', 'is_active', 'deleted_at'],
        'warehouses' => ['id', 'name', 'deleted_at'],
        'purchase_orders' => ['id', 'order_number', 'supplier_id', 'warehouse_id', 'order_date', 'expected_date', 'received_date', 'status',
            'subtotal', 'tax_rate', 'tax', 'shipping', 'discount', 'total', 'notes', 'created_at', 'updated_at', 'deleted_at'],
        'purchase_order_items' => ['id', 'purchase_order_id', 'product_id', 'quantity', 'received_quantity', 'unit_price', 'discount', 'subtotal'],
        'inventory' => ['product_id', 'warehouse_id', 'quantity_on_hand', 'bin_location'],
        'users' => ['id', 'name', 'email', 'role', 'is_active'],
    ];

    /** @return array{missing: array<string, list<string>>, extra: array<string, list<string>>} */
    public static function check(Connection $source): array
    {
        $actual = [];
        foreach ($source->select('SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = ?', [$source->getDatabaseName()]) as $row) {
            $actual[$row->t][] = $row->c;
        }

        $missing = [];
        $extra = [];
        foreach (self::EXPECTED as $table => $columns) {
            if (! isset($actual[$table])) {
                $missing[$table] = ['(table missing)'];

                continue;
            }
            if ($diff = array_values(array_diff($columns, $actual[$table]))) {
                $missing[$table] = $diff;
            }
            if ($diff = array_values(array_diff($actual[$table], $columns, ['created_at', 'updated_at']))) {
                $extra[$table] = $diff;
            }
        }

        return ['missing' => $missing, 'extra' => $extra];
    }
}
