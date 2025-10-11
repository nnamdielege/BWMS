<?php

namespace App\Observers;

use App\Models\SalesOrder;
use App\Services\NotificationService;

class SalesOrderObserver
{
    public function created(SalesOrder $order)
    {
        // Notify on new order
        NotificationService::newSalesOrder($order);
    }

    public function updated(SalesOrder $order)
    {
        // Check if status changed
        if ($order->isDirty('status')) {
            $oldStatus = $order->getOriginal('status');
            $newStatus = $order->status;

            NotificationService::salesOrderStatusChanged($order, $oldStatus, $newStatus);
        }
    }
}