<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['code', 'name', 'contact_name', 'email', 'cc_emails', 'phone', 'address', 'payment_terms', 'lead_time_days', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'lead_time_days' => 'integer'];
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
