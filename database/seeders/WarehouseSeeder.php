<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Warehouse;

class WarehouseSeeder extends Seeder
{
    public function run()
    {
        $warehouses = [
            [
                'name' => 'Main Warehouse',
                'code' => 'WH-001',
                'address' => '123 Warehouse St',
                'city' => 'Sydney',
                'state' => 'NSW',
                'postal_code' => '2000',
                'country' => 'Australia',
                'phone' => '02 9999 0001',
                'email' => 'main@warehouse.com',
                'manager' => 'John Smith',
            ],
            [
                'name' => 'Secondary Warehouse',
                'code' => 'WH-002',
                'address' => '456 Storage Ave',
                'city' => 'Melbourne',
                'state' => 'VIC',
                'postal_code' => '3000',
                'country' => 'Australia',
                'phone' => '03 9999 0002',
                'email' => 'secondary@warehouse.com',
                'manager' => 'Jane Doe',
            ],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::create($warehouse);
        }
    }
}