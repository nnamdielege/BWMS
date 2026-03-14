<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Mail\PurchaseOrderMail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of purchase orders.
     */
    public function index(Request $request)
    {
        $query = PurchaseOrder::with('supplier', 'warehouse', 'items.product');

        if ($request->search) {
            $query->where('order_number', 'like', "%{$request->search}%");
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->date_from) {
            $query->whereDate('order_date', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('order_date', '<=', $request->date_to);
        }

        $orders = $query->paginate($request->per_page ?? 15);

        return response()->json($orders);
    }

    /**
     * Store a newly created purchase order.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'status' => 'sometimes|in:draft,pending,received,cancelled',
            'subtotal' => 'sometimes|numeric',
            'tax_rate' => 'sometimes|numeric',
            'tax' => 'sometimes|numeric',
            'shipping' => 'sometimes|numeric',
            'discount' => 'sometimes|numeric',
            'total' => 'sometimes|numeric',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'sometimes|numeric|min:0',
            'items.*.tax' => 'sometimes|numeric|min:0',
        ]);

        $order = PurchaseOrder::create([
            'supplier_id' => $validated['supplier_id'],
            'warehouse_id' => $validated['warehouse_id'],
            'order_number' => $this->generateOrderNumber(),
            'order_date' => $validated['order_date'],
            'expected_date' => $validated['expected_date'] ?? null,
            'status' => $validated['status'] ?? 'pending',
            'subtotal' => $validated['subtotal'] ?? 0,
            'tax_rate' => $validated['tax_rate'] ?? 0,
            'tax' => $validated['tax'] ?? 0,
            'shipping' => $validated['shipping'] ?? 0,
            'discount' => $validated['discount'] ?? 0,
            'total' => $validated['total'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);

        // Create order items
        foreach ($validated['items'] as $item) {
            $order->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'received_quantity' => 0,
                'unit_price' => $item['unit_price'],
                'discount' => $item['discount'] ?? 0,
                'tax' => $item['tax'] ?? 0,
                'subtotal' => ($item['quantity'] * $item['unit_price']) - ($item['discount'] ?? 0),
            ]);
        }

        return response()->json([
            'message' => 'Purchase order created successfully',
            'data' => $order->load('supplier', 'warehouse', 'items.product'),
        ], 201);
    }

    /**
     * Display the specified purchase order.
     */
    public function show($id)
    {
        $order = PurchaseOrder::with('supplier', 'warehouse', 'items.product')
            ->findOrFail($id);

        return response()->json($order);
    }

    /**
     * Update the specified purchase order.
     */
    public function update(Request $request, $id)
    {
        $order = PurchaseOrder::findOrFail($id);

        $validated = $request->validate([
            'supplier_id' => 'sometimes|exists:suppliers,id',
            'warehouse_id' => 'sometimes|exists:warehouses,id',
            'order_date' => 'sometimes|date',
            'expected_date' => 'nullable|date',
            'status' => 'sometimes|in:draft,pending,received,cancelled',
            'tax_rate' => 'sometimes|numeric',
            'shipping' => 'sometimes|numeric',
            'discount' => 'sometimes|numeric',
            'notes' => 'nullable|string',
            'items' => 'sometimes|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:1',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.discount' => 'sometimes|numeric|min:0',
        ]);

        $order->update([
            'supplier_id' => $validated['supplier_id'] ?? $order->supplier_id,
            'warehouse_id' => $validated['warehouse_id'] ?? $order->warehouse_id,
            'order_date' => $validated['order_date'] ?? $order->order_date,
            'expected_date' => $validated['expected_date'] ?? $order->expected_date,
            'status' => $validated['status'] ?? $order->status,
            'tax_rate' => $validated['tax_rate'] ?? $order->tax_rate,
            'shipping' => $validated['shipping'] ?? $order->shipping,
            'discount' => $validated['discount'] ?? $order->discount,
            'notes' => $validated['notes'] ?? $order->notes,
        ]);

        // Update items if provided
        if (isset($validated['items'])) {
            $order->items()->delete();
            foreach ($validated['items'] as $item) {
                $subtotal = ($item['quantity'] * $item['unit_price']) - ($item['discount'] ?? 0);
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'received_quantity' => 0,
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                    'subtotal' => $subtotal,
                ]);
            }
        }

        return response()->json([
            'message' => 'Purchase order updated successfully',
            'data' => $order->load('supplier', 'warehouse', 'items.product'),
        ]);
    }

    /**
     * Receive items from purchase order.
     */
    public function receive(Request $request, $id)
    {
        $order = PurchaseOrder::findOrFail($id);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:purchase_order_items,id',
            'items.*.received_quantity' => 'required|numeric|min:0',
        ]);

        foreach ($validated['items'] as $item) {
            $orderItem = $order->items()->findOrFail($item['id']);
            $orderItem->received_quantity += $item['received_quantity'];
            $orderItem->save();
        }

        // Check if all items are received
        $allReceived = $order->items()->where('received_quantity', '<', function ($query) {
            $query->selectRaw('quantity')->from('purchase_order_items as poi')
                ->whereColumn('poi.id', 'purchase_order_items.id');
        })->count() === 0;

        if ($allReceived) {
            $order->status = 'received';
            $order->received_date = now();
            $order->save();
        }

        return response()->json([
            'message' => 'Items received successfully',
            'data' => $order->load('items'),
        ]);
    }

    /**
     * Cancel purchase order.
     */
    public function cancel($id)
    {
        $order = PurchaseOrder::findOrFail($id);

        if ($order->status === 'received' || $order->status === 'cancelled') {
            return response()->json(
                ['message' => 'Cannot cancel a received or already cancelled order'],
                422
            );
        }

        $order->status = 'cancelled';
        $order->save();

        return response()->json(['message' => 'Purchase order cancelled successfully']);
    }

    /**
     * Download purchase order as PDF.
     */
    public function download($id)
    {
        try {
            $order = PurchaseOrder::with('items.product', 'supplier', 'warehouse')
                ->findOrFail($id);

            $pdf = Pdf::loadView('purchase-orders.pdf', ['order' => $order]);

            return response($pdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"PO-{$order->order_number}.pdf\"",
            ]);
        } catch (\Exception $e) {
            Log::error('PDF Download Error', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json(
                ['message' => 'Failed to download PDF: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * Send purchase order via email to custom recipient.
     * 
     * Expected request body:
     * {
     *   "recipient_email": "supplier@example.com",
     *   "subject": "Your Purchase Order",
     *   "message": "Please process this order"
     * }
     */
    public function sendEmail(Request $request, $id)
    {
        $request->validate([
            'recipient_email' => 'required|email',
            'subject' => 'nullable|string|max:255',
            'message' => 'nullable|string',
        ]);

        $order = PurchaseOrder::with('items.product', 'supplier', 'warehouse')
            ->findOrFail($id);

        try {
            Log::info('Attempting to send email', [
                'order_id' => $id,
                'recipient' => $request->recipient_email,
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
            ]);

            Mail::to($request->recipient_email)
                ->send(new PurchaseOrderMail(
                    recipient: $request->recipient_email,
                    emailSubject: $request->subject ?? "Purchase Order {$order->order_number}",
                    emailMessage: $request->message ?? 'Please see the attached purchase order.',
                    orderNumber: $order->order_number,
                    orderId: $order->id,
                ));

            Log::info('Email sent successfully', ['recipient' => $request->recipient_email]);

            return response()->json([
                'message' => 'Purchase order sent successfully',
                'recipient' => $request->recipient_email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send purchase order email', [
                'order_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(
                ['message' => 'Failed to send email: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * Send purchase order directly to supplier's email.
     */
    public function sendToSupplier($id)
    {
        $order = PurchaseOrder::with('items.product', 'supplier', 'warehouse')
            ->findOrFail($id);

        if (!$order->supplier || !$order->supplier->email) {
            return response()->json(
                ['message' => 'Supplier has no email address on file'],
                422
            );
        }

        try {
            // Send email (PDF will be generated in Mailable)
            Mail::send(new PurchaseOrderMail(
                recipient: $order->supplier->email,
                emailSubject: "Purchase Order {$order->order_number}",
                emailMessage: "Please process this purchase order as detailed in the attached document.",
                orderNumber: $order->order_number,
                orderId: $order->id,
            ));

            return response()->json([
                'message' => 'Purchase order sent to supplier successfully',
                'supplier_email' => $order->supplier->email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send purchase order to supplier', [
                'order_id' => $id,
                'supplier_id' => $order->supplier_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(
                ['message' => 'Failed to send email to supplier: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * Generate unique purchase order number.
     */
    private function generateOrderNumber()
    {
        $count = PurchaseOrder::count() + 1;
        $date = now()->format('Ymd');
        return "PO-{$date}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}