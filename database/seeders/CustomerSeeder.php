<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            [
                'customer_code' => 'CUST001',
                'company_name' => 'ABC Corporation',
                'name' => 'John Doe',
                'email' => 'john@abc.com',
                'phone' => '+1-555-0101',
                'mobile' => '+1-555-0102',
                'address' => '100 Business Plaza',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'country' => 'USA',
                'tax_id' => 'TAX-001',
                'payment_terms' => 'Net 30',
                'credit_limit' => 10000.00,
                'notes' => 'Premium customer',
                'is_active' => true,
            ],
            [
                'customer_code' => 'CUST002',
                'company_name' => 'XYZ Industries',
                'name' => 'Jane Smith',
                'email' => 'jane@xyz.com',
                'phone' => '+1-555-0201',
                'mobile' => '+1-555-0202',
                'address' => '200 Commerce Street',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90001',
                'country' => 'USA',
                'tax_id' => 'TAX-002',
                'payment_terms' => 'Net 45',
                'credit_limit' => 15000.00,
                'notes' => 'Long-term customer',
                'is_active' => true,
            ],
            [
                'customer_code' => 'CUST003',
                'company_name' => 'Global Solutions Ltd',
                'name' => 'Michael Johnson',
                'email' => 'michael@globalsolutions.com',
                'phone' => '+1-555-0301',
                'mobile' => '+1-555-0302',
                'address' => '300 Tech Drive',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postal_code' => '94102',
                'country' => 'USA',
                'tax_id' => 'TAX-003',
                'payment_terms' => 'Net 60',
                'credit_limit' => 20000.00,
                'notes' => 'Enterprise customer',
                'is_active' => true,
            ],
        ];

        foreach ($customers as $customer) {
            Customer::firstOrCreate(
                ['customer_code' => $customer['customer_code']],
                $customer
            );
        }
    }
}