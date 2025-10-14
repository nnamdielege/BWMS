<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PurchaseOrdersExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = PurchaseOrder::with(['supplier', 'warehouse']);

        if (!empty($this->filters['start_date'])) {
            $query->whereDate('order_date', '>=', $this->filters['start_date']);
        }

        if (!empty($this->filters['end_date'])) {
            $query->whereDate('order_date', '<=', $this->filters['end_date']);
        }

        if (!empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        if (!empty($this->filters['supplier_id'])) {
            $query->where('supplier_id', $this->filters['supplier_id']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Order Number',
            'Supplier',
            'Warehouse',
            'Order Date',
            'Expected Date',
            'Status',
            'Subtotal',
            'Tax',
            'Shipping',
            'Discount',
            'Total',
            'Notes',
            'Created At',
        ];
    }

    public function map($order): array
    {
        return [
            $order->id,
            $order->order_number,
            $order->supplier ? $order->supplier->company_name : 'N/A',
            $order->warehouse ? $order->warehouse->name : 'N/A',
            $order->order_date,
            $order->expected_date,
            ucfirst($order->status),
            number_format($order->subtotal, 2),
            number_format($order->tax, 2),
            number_format($order->shipping, 2),
            number_format($order->discount, 2),
            number_format($order->total, 2),
            $order->notes,
            $order->created_at->format('Y-m-d H:i:s'),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 10,
            'B' => 20,
            'C' => 25,
            'D' => 20,
            'E' => 15,
            'F' => 15,
            'G' => 12,
            'H' => 12,
            'I' => 12,
            'J' => 12,
            'K' => 12,
            'L' => 12,
            'M' => 30,
            'N' => 20,
        ];
    }
}