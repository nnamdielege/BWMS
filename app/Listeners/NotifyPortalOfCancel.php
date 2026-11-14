<?php

namespace App\Listeners;

use App\Enums\OrderChannel;
use App\Events\OrderCancelledByStaff;
use App\Jobs\SendPortalCancelEvent;
use App\Models\Order;

/** OrderCancelledByStaff (after commit) -> one queued portal notification, for a portal order. */
class NotifyPortalOfCancel
{
    public function handle(OrderCancelledByStaff $event): void
    {
        if (! config('bwms.portal.events_enabled') || $event->lines === []) {
            return;
        }
        if (Order::whereKey($event->orderId)->where('channel', OrderChannel::Portal)->exists()) {
            SendPortalCancelEvent::dispatch($event->orderId, $event->lines, $event->reason, $event->at, $event->scope);
        }
    }
}
