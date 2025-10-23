<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'subscription_id',
        'payment_id',
        'invoice_number',
        'amount',
        'currency',
        'issued_at',
        'due_at',
        'paid_at',
        'status',
        'notes',
        'pdf_path',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'amount' => 'float',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    // Generate invoice number (INV-2024-0001)
    public static function generateInvoiceNumber()
    {
        $year = date('Y');
        $count = static::whereYear('created_at', $year)->count() + 1;
        return 'INV-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function markAsPaid()
    {
        $this->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $this;
    }

    public function markAsPending()
    {
        $this->update([
            'status' => 'pending',
            'paid_at' => null,
        ]);

        return $this;
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')
            ->where('due_at', '<', now());
    }
}