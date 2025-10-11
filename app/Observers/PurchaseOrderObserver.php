<?php

namespace App\Observers;

use App\Models\PurchaseOrder;
use App\Services\NotificationService;

class PurchaseOrderObserver
{
    public function created(PurchaseOrder $order)
    {
        NotificationService::newPurchaseOrder($order);
    }

    public function updated(PurchaseOrder $order)
    {
        if ($order->isDirty('status') && $order->status === 'received') {
            NotificationService::purchaseOrderReceived($order);
        }
    }
}