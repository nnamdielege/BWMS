<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Subscription;
use Carbon\Carbon;

class InvoiceService
{
    /**
     * Create invoice for subscription
     */
    public function createInvoice(Subscription $subscription, array $data = [])
    {
        $invoice = Invoice::create([
            'subscription_id' => $subscription->id,
            'payment_id' => $data['payment_id'] ?? null,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'amount' => $data['amount'] ?? $subscription->plan->price_monthly,
            'currency' => $data['currency'] ?? 'AUD',
            'status' => $data['status'] ?? 'pending',
            'issued_at' => $data['issued_at'] ?? now(),
            'due_at' => $data['due_at'] ?? now()->addDays(30),
            'paid_at' => $data['paid_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'pdf_path' => $data['pdf_path'] ?? null,
        ]);

        return $invoice;
    }

    /**
     * Get invoices for user
     */
    public function getUserInvoices($userId, $status = null)
    {
        $query = Invoice::whereHas('subscription', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->orderBy('issued_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /**
     * Get invoices for subscription
     */
    public function getSubscriptionInvoices(Subscription $subscription, $status = null)
    {
        $query = $subscription->invoices()->orderBy('issued_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /**
     * Get total revenue
     */
    public function getTotalRevenue($startDate = null, $endDate = null)
    {
        $query = Invoice::paid();

        if ($startDate) {
            $query->where('paid_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('paid_at', '<=', $endDate);
        }

        return $query->sum('amount');
    }

    /**
     * Get pending invoices
     */
    public function getPendingInvoices($userId)
    {
        return $this->getUserInvoices($userId, 'pending');
    }

    /**
     * Get overdue invoices
     */
    public function getOverdueInvoices($userId)
    {
        return Invoice::whereHas('subscription', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
            ->where('status', 'pending')
            ->where('due_at', '<', now())
            ->orderBy('due_at', 'asc')
            ->get();
    }

    /**
     * Mark invoice as paid
     */
    public function markAsPaid(Invoice $invoice)
    {
        return $invoice->markAsPaid();
    }

    /**
     * Get invoice statistics
     */
    public function getStats($startDate = null, $endDate = null)
    {
        $baseQuery = Invoice::query();

        if ($startDate) {
            $baseQuery->where('issued_at', '>=', $startDate);
        }

        if ($endDate) {
            $baseQuery->where('issued_at', '<=', $endDate);
        }

        return [
            'total_invoices' => (clone $baseQuery)->count(),
            'total_amount' => (clone $baseQuery)->sum('amount'),
            'paid_amount' => (clone $baseQuery)->paid()->sum('amount'),
            'pending_amount' => (clone $baseQuery)->pending()->sum('amount'),
            'paid_count' => (clone $baseQuery)->paid()->count(),
            'pending_count' => (clone $baseQuery)->pending()->count(),
        ];
    }
}