<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProductCategory;
use Illuminate\Support\Str;

class ProductCategorySeeder extends Seeder
{
    public function run()
    {
        $categories = [
            'Electronics',
            'Furniture',
            'Office Supplies',
            'Food & Beverage',
            'Clothing',
            'Books',
            'Toys & Games',
            'Sports & Outdoors',
            'Home & Garden',
            'Automotive',
        ];

        foreach ($categories as $category) {
            ProductCategory::create([
                'name' => $category,
                'slug' => Str::slug($category),
                'is_active' => true,
            ]);
        }
    }
}