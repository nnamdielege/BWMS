<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdermentumProduct extends Model
{
    protected $table = 'ordermentum_products';

    // Use default auto-increment id (no need to specify keyType or incrementing)

    protected $fillable = [
        'external_id',
        'name',
        'description',
        'sku',
        'universal_id',
        'base_price',
        'price',
        'display_price',
        'display_price_inc_tax',
        'cost',
        'unit_price',
        'tax_type',
        'add_tax',
        'weight',
        'uom',
        'unit',
        'unit_size',
        'min_quantity',
        'max_quantity',
        'packing_unit',
        'pack_size',
        'stock_tracking',
        'out_of_stock',
        'random_weight',
        'available_from',
        'available_until',
        'disabled',
        'deactivated_at',
        'featured',
        'batch_code',
        'sales_code',
        'badge_label',
        'sort_order',
        'image_id',
        'image_url',
        'images',
        'supplier_id',
        'has_variants',
        'quantity_type',
        'keywords',
        'category_names',
        'visibility_tags',
        'visibility_groups',
        'properties',
        'delivery_days',
        'raw_data',
    ];

    protected $casts = [
        'base_price' => 'decimal:4',
        'price' => 'decimal:4',
        'display_price' => 'decimal:4',
        'display_price_inc_tax' => 'decimal:4',
        'cost' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'weight' => 'decimal:4',
        'add_tax' => 'boolean',
        'stock_tracking' => 'boolean',
        'out_of_stock' => 'boolean',
        'random_weight' => 'boolean',
        'disabled' => 'boolean',
        'featured' => 'boolean',
        'has_variants' => 'boolean',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
        'deactivated_at' => 'datetime',
        'images' => 'array',
        'keywords' => 'array',
        'category_names' => 'array',
        'visibility_tags' => 'array',
        'visibility_groups' => 'array',
        'properties' => 'array',
        'delivery_days' => 'array',
        'raw_data' => 'array',
    ];

    /**
     * Check if product is available
     */
    public function isAvailable(): bool
    {
        return !$this->disabled && !$this->out_of_stock;
    }

    /**
     * Get formatted price
     */
    public function getFormattedPrice(): string
    {
        return '$' . number_format($this->display_price, 2);
    }

    /**
     * Get primary category name
     */
    public function getPrimaryCategory(): ?string
    {
        $categories = $this->category_names ?? [];
        return is_array($categories) && count($categories) > 0 ? $categories[0] : null;
    }

    /**
     * Scope: Get active (not disabled) products
     */
    public function scopeActive($query)
    {
        return $query->where('disabled', false);
    }

    /**
     * Scope: Get featured products
     */
    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }
}
