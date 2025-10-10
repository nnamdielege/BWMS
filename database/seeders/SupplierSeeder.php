<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Supplier;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            [
                'supplier_code' => 'SUP001',
                'company_name' => 'Tech Supply Co.',
                'contact_name' => 'Robert Johnson',
                'email' => 'robert@techsupply.com',
                'phone' => '+1-555-0201',
                'mobile' => '+1-555-0202',
                'address' => '789 Supply Street',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postal_code' => '94102',
                'country' => 'USA',
                'tax_id' => 'TAX-001',
                'payment_terms' => 'Net 30',
                'credit_limit' => 50000.00,
                'notes' => 'Primary electronics supplier',
                'is_active' => true,
            ],
            [
                'supplier_code' => 'SUP002',
                'company_name' => 'Global Parts Ltd.',
                'contact_name' => 'Maria Garcia',
                'email' => 'maria@globalparts.com',
                'phone' => '+1-555-0203',
                'mobile' => '+1-555-0204',
                'address' => '456 Commerce Ave',
                'city' => 'Miami',
                'state' => 'FL',
                'postal_code' => '33101',
                'country' => 'USA',
                'tax_id' => 'TAX-002',
                'payment_terms' => 'Net 45',
                'credit_limit' => 75000.00,
                'notes' => 'International parts supplier',
                'is_active' => true,
            ],
            [
                'supplier_code' => 'SUP003',
                'company_name' => 'Quality Components Inc.',
                'contact_name' => 'David Chen',
                'email' => 'david@qualitycomponents.com',
                'phone' => '+1-555-0205',
                'mobile' => '+1-555-0206',
                'address' => '123 Industrial Blvd',
                'city' => 'Seattle',
                'state' => 'WA',
                'postal_code' => '98101',
                'country' => 'USA',
                'tax_id' => 'TAX-003',
                'payment_terms' => 'Net 60',
                'credit_limit' => 100000.00,
                'notes' => 'High-quality component manufacturer',
                'is_active' => true,
            ],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::firstOrCreate(
                ['supplier_code' => $supplier['supplier_code']],
                $supplier
            );
        }
    }
}