<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        // Get all users or create one if none exists
        $users = User::all();

        if ($users->isEmpty()) {
            $this->command->warn('No users found. Creating a default user...');

            $user = User::create([
                'name' => 'Admin User',
                'email' => 'admin@bwms.com',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'is_active' => true,
            ]);

            $users = collect([$user]);
        }

        // Create notifications for each user
        foreach ($users as $user) {
            $notifications = [
                [
                    'user_id' => $user->id,
                    'type' => 'low_stock',
                    'title' => 'Low Stock Alert',
                    'message' => 'Product "Laptop Dell XPS 15" is running low on stock (5 units remaining)',
                    'icon' => 'exclamation',
                    'color' => 'yellow',
                    'link' => '/inventory',
                    'is_read' => false,
                    'created_at' => now()->subMinutes(5),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'new_order',
                    'title' => 'New Sales Order',
                    'message' => 'New sales order SO-0001 has been created by ABC Corporation',
                    'icon' => 'shopping-cart',
                    'color' => 'blue',
                    'link' => '/sales-orders/1',
                    'is_read' => false,
                    'created_at' => now()->subMinutes(15),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'order_status',
                    'title' => 'Order Fulfilled',
                    'message' => 'Purchase order PO-0001 has been received and processed',
                    'icon' => 'check-circle',
                    'color' => 'green',
                    'link' => '/purchase-orders/1',
                    'is_read' => false,
                    'created_at' => now()->subMinutes(30),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'out_of_stock',
                    'title' => 'Out of Stock Alert',
                    'message' => 'Product "Wireless Mouse Logitech" is out of stock in Main Warehouse',
                    'icon' => 'alert',
                    'color' => 'red',
                    'link' => '/inventory',
                    'is_read' => false,
                    'created_at' => now()->subHour(),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'system',
                    'title' => 'System Update',
                    'message' => 'System maintenance completed successfully. All services are operational.',
                    'icon' => 'info',
                    'color' => 'blue',
                    'link' => null,
                    'is_read' => true,
                    'read_at' => now()->subMinutes(10),
                    'created_at' => now()->subHours(2),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'low_stock',
                    'title' => 'Low Stock Warning',
                    'message' => 'Product "HP Printer LaserJet" has only 3 units left in Central Warehouse',
                    'icon' => 'exclamation',
                    'color' => 'yellow',
                    'link' => '/inventory',
                    'is_read' => false,
                    'created_at' => now()->subHours(3),
                ],
            ];

            foreach ($notifications as $notification) {
                Notification::create($notification);
            }

            $this->command->info("Created 6 notifications for user: {$user->name}");
        }

        $this->command->info('Notification seeding completed successfully!');
    }
}