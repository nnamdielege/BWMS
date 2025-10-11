<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    /**
     * Create a notification for a user
     */
    public static function create(array $data)
    {
        return Notification::create($data);
    }

    /**
     * Create notification for all admin users
     */
    public static function notifyAdmins($type, $title, $message, $icon = 'info', $color = 'blue', $link = null)
    {
        $admins = User::where('role', 'admin')->where('is_active', true)->get();

        foreach ($admins as $admin) {
            self::create([
                'user_id' => $admin->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'icon' => $icon,
                'color' => $color,
                'link' => $link,
                'is_read' => false,
            ]);
        }
    }

    /**
     * Create notification for specific user
     */
    public static function notifyUser($userId, $type, $title, $message, $icon = 'info', $color = 'blue', $link = null)
    {
        return self::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'icon' => $icon,
            'color' => $color,
            'link' => $link,
            'is_read' => false,
        ]);
    }

    /**
     * Low stock notification
     */
    public static function lowStock($product, $warehouse, $quantity)
    {
        self::notifyAdmins(
            'low_stock',
            'Low Stock Alert',
            "Product \"{$product->name}\" is running low on stock ({$quantity} units remaining) in {$warehouse->name}",
            'exclamation',
            'yellow',
            '/inventory'
        );
    }

    /**
     * Out of stock notification
     */
    public static function outOfStock($product, $warehouse)
    {
        self::notifyAdmins(
            'out_of_stock',
            'Out of Stock Alert',
            "Product \"{$product->name}\" is out of stock in {$warehouse->name}",
            'alert',
            'red',
            '/inventory'
        );
    }

    /**
     * New sales order notification
     */
    public static function newSalesOrder($order)
    {
        self::notifyAdmins(
            'new_order',
            'New Sales Order',
            "New sales order {$order->order_number} has been created by {$order->customer->company_name}",
            'shopping-cart',
            'blue',
            "/sales-orders/{$order->id}"
        );
    }

    /**
     * Sales order status changed
     */
    public static function salesOrderStatusChanged($order, $oldStatus, $newStatus)
    {
        $color = match ($newStatus) {
            'fulfilled' => 'green',
            'cancelled' => 'red',
            'processing' => 'blue',
            default => 'yellow',
        };

        self::notifyAdmins(
            'order_status',
            'Order Status Updated',
            "Sales order {$order->order_number} status changed from {$oldStatus} to {$newStatus}",
            'check-circle',
            $color,
            "/sales-orders/{$order->id}"
        );
    }

    /**
     * New purchase order notification
     */
    public static function newPurchaseOrder($order)
    {
        self::notifyAdmins(
            'new_order',
            'New Purchase Order',
            "New purchase order {$order->order_number} has been created for {$order->supplier->company_name}",
            'shopping-cart',
            'blue',
            "/purchase-orders/{$order->id}"
        );
    }

    /**
     * Purchase order received
     */
    public static function purchaseOrderReceived($order)
    {
        self::notifyAdmins(
            'order_status',
            'Purchase Order Received',
            "Purchase order {$order->order_number} has been received from {$order->supplier->company_name}",
            'check-circle',
            'green',
            "/purchase-orders/{$order->id}"
        );
    }

    /**
     * Customer created notification
     */
    public static function newCustomer($customer)
    {
        self::notifyAdmins(
            'system',
            'New Customer Added',
            "New customer \"{$customer->company_name}\" has been added to the system",
            'info',
            'blue',
            "/customers/{$customer->id}"
        );
    }

    /**
     * Supplier created notification
     */
    public static function newSupplier($supplier)
    {
        self::notifyAdmins(
            'system',
            'New Supplier Added',
            "New supplier \"{$supplier->company_name}\" has been added to the system",
            'info',
            'blue',
            "/suppliers/{$supplier->id}"
        );
    }
}