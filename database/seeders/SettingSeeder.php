<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // General Settings
            [
                'key' => 'company_name',
                'value' => 'Business Warehouse Management System',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Company name displayed throughout the application',
            ],
            [
                'key' => 'company_email',
                'value' => 'info@bwms.com',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Primary company email address',
            ],
            [
                'key' => 'company_phone',
                'value' => '+1-555-0100',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Primary company phone number',
            ],
            [
                'key' => 'company_address',
                'value' => '123 Business Street, City, State 12345',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Company physical address',
            ],
            [
                'key' => 'currency',
                'value' => 'USD',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Default currency for transactions',
            ],
            [
                'key' => 'timezone',
                'value' => 'America/New_York',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Default timezone',
            ],

            // Inventory Settings
            [
                'key' => 'low_stock_threshold',
                'value' => '20',
                'type' => 'number',
                'group' => 'inventory',
                'description' => 'Stock level considered as low stock',
            ],
            [
                'key' => 'enable_negative_stock',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'inventory',
                'description' => 'Allow negative stock levels',
            ],
            [
                'key' => 'auto_allocate_stock',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'inventory',
                'description' => 'Automatically allocate stock on order creation',
            ],

            // Order Settings
            [
                'key' => 'order_prefix_sales',
                'value' => 'SO-',
                'type' => 'string',
                'group' => 'orders',
                'description' => 'Prefix for sales order numbers',
            ],
            [
                'key' => 'order_prefix_purchase',
                'value' => 'PO-',
                'type' => 'string',
                'group' => 'orders',
                'description' => 'Prefix for purchase order numbers',
            ],
            [
                'key' => 'default_payment_terms',
                'value' => 'Net 30',
                'type' => 'string',
                'group' => 'orders',
                'description' => 'Default payment terms for orders',
            ],
            [
                'key' => 'default_tax_rate',
                'value' => '10',
                'type' => 'number',
                'group' => 'orders',
                'description' => 'Default tax rate percentage',
            ],

            // Notification Settings
            [
                'key' => 'email_notifications',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Enable email notifications',
            ],
            [
                'key' => 'low_stock_notifications',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Send notifications for low stock items',
            ],
            [
                'key' => 'order_notifications',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Send notifications for new orders',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}