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
            'Coffee Beans',
            'Syrups & Flavours',
            'Drinking Chocolate',
            'Chai Latte Powders',
            'Tea & Infusions',
            'Equipment & Accessories',
            'Cafe Essentials',
            'Bulk Beverages',
            'Merchandise',
            'Wholesale Supplies',
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