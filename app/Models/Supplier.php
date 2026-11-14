<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['code', 'name', 'contact_name', 'email', 'cc_emails', 'phone', 'address', 'payment_terms', 'lead_time_days',
        'order_days', 'review_period_days', 'min_order_value', 'moq_packs', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'lead_time_days' => 'integer', 'review_period_days' => 'integer', 'min_order_value' => 'decimal:2', 'moq_packs' => 'integer'];
    }

    /** ISO weekdays it's ordered on (1 = Mon … 7 = Sun), sorted. @return list<int> */
    public function orderDays(): array
    {
        $days = array_map('intval', array_filter(explode(',', (string) $this->order_days), 'strlen'));
        $days = array_values(array_unique(array_filter($days, fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);

        return $days;
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** @return list<string> */
    public function ccList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->cc_emails))));
    }
}
