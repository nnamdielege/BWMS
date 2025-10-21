<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionAudit extends Model
{
    protected $table = 'subscription_audits';

    protected $fillable = [
        'subscription_id',
        'action',
        'from_plan_id',
        'to_plan_id',
        'amount_charged',
        'amount_credited',
        'reason',
        'provider',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'amount_charged' => 'decimal:2',
        'amount_credited' => 'decimal:2',
    ];

    /**
     * Get the subscription that this audit belongs to
     */
    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the "from" plan
     */
    public function fromPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'from_plan_id');
    }

    /**
     * Get the "to" plan
     */
    public function toPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'to_plan_id');
    }
}