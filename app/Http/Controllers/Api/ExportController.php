<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ProductsExport;
use App\Exports\CustomersExport;
use App\Exports\SalesOrdersExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\InventoryExport;
use App\Exports\SuppliersExport;
use Illuminate\Support\Facades\Log;

class ExportController extends Controller
{
    /**
     * Export Products
     */
    public function exportProducts(Request $request)
    {
        try {
            $filters = $request->only(['search', 'category_id']);
            $format = $request->get('format', 'xlsx'); // xlsx or csv

            $fileName = 'products_' . now()->format('Y-m-d_His') . '.' . $format;

            return Excel::download(
                new ProductsExport($filters),
                $fileName,
                $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
            );
        } catch (\Exception $e) {
            Log::error('Export products error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to export products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export Customers
     */
    public function exportCustomers(Request $request)
    {
        try {
            $filters = $request->only(['search', 'is_active']);
            $format = $request->get('format', 'xlsx');

            $fileName = 'customers_' . now()->format('Y-m-d_His') . '.' . $format;

            return Excel::download(
                new CustomersExport($filters),
                $fileName,
                $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
            );
        } catch (\Exception $e) {
            Log::error('Export customers error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to export customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export Suppliers
     */
    public function exportSuppliers(Request $request)
    {
        try {
            $filters = $request->only(['search', 'is_active']);
            $format = $request->get('format', 'xlsx');

            $fileName = 'suppliers_' . now()->format('Y-m-d_His') . '.' . $format;

            return Excel::download(
                new SuppliersExport($filters),
                $fileName,
                $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
            );
        } catch (\Exception $e) {
            Log::error('Export suppliers error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to export suppliers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export Sales Orders
     */
    public function exportSalesOrders(Request $request)
    {
        try {
            $filters = $request->only(['start_date', 'end_date', 'status', 'customer_id']);
            $format = $request->get('format', 'xlsx');

            $fileName = 'sales_orders_' . now()->format('Y-m-d_His') . '.' . $format;

            return Excel::download(
                new SalesOrdersExport($filters),
                $fileName,
                $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
            );
        } catch (\Exception $e) {
            Log::error('Export sales orders error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to export sales orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export Purchase Orders
     */
    public function exportPurchaseOrders(Request $request)
    {
        try {
            $filters = $request->only(['start_date', 'end_date', 'status', 'supplier_id']);
            $format = $request->get('format', 'xlsx');

            $fileName = 'purchase_orders_' . now()->format('Y-m-d_His') . '.' . $format;

            return Excel::download(
                new PurchaseOrdersExport($filters),
                $fileName,
                $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
            );
        } catch (\Exception $e) {
            Log::error('Export purchase orders error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to export purchase orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export Inventory
     */
    public function exportInventory(Request $request)
    {
        try {
            $filters = $request->only(['warehouse_id', 'product_id']);
            $format = $request->get('format', 'xlsx');

            $fileName = 'inventory_' . now()->format('Y-m-d_His') . '.' . $format;

            return Excel::download(
                new InventoryExport($filters),
                $fileName,
                $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
            );
        } catch (\Exception $e) {
            Log::error('Export inventory error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to export inventory',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}