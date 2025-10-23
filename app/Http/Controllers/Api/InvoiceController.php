<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    protected $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Get all invoices for authenticated user
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $status = $request->query('status');

        $invoices = $this->invoiceService->getUserInvoices($user->id, $status);

        return response()->json([
            'success' => true,
            'data' => $invoices,
        ]);
    }

    /**
     * Get specific invoice
     */
    public function show(Request $request, Invoice $invoice)
    {
        // Verify user owns this invoice
        if ($invoice->subscription->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $invoice,
        ]);
    }

    /**
     * Get invoice statistics
     */
    public function statistics(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $stats = $this->invoiceService->getStats($startDate, $endDate);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get pending invoices
     */
    public function pending(Request $request)
    {
        $user = $request->user();
        $invoices = $this->invoiceService->getPendingInvoices($user->id);

        return response()->json([
            'success' => true,
            'data' => $invoices,
            'count' => count($invoices),
        ]);
    }

    /**
     * Get overdue invoices
     */
    public function overdue(Request $request)
    {
        $user = $request->user();
        $invoices = $this->invoiceService->getOverdueInvoices($user->id);

        return response()->json([
            'success' => true,
            'data' => $invoices,
            'count' => count($invoices),
        ]);
    }
}