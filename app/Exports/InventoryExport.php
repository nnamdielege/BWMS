<?php

namespace App\Exports;

use App\Models\Inventory;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = Inventory::with(['product', 'warehouse']);

        if (!empty($this->filters['warehouse_id'])) {
            $query->where('warehouse_id', $this->filters['warehouse_id']);
        }

        if (!empty($this->filters['product_id'])) {
            $query->where('product_id', $this->filters['product_id']);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Product',
            'SKU',
            'Warehouse',
            'Quantity On Hand',
            'Quantity Allocated',
            'Quantity Available',
            'Reorder Point',
            'Reorder Quantity',
            'Last Restocked',
        ];
    }

    public function map($inventory): array
    {
        return [
            $inventory->id,
            $inventory->product ? $inventory->product->name : 'N/A',
            $inventory->product ? $inventory->product->sku : 'N/A',
            $inventory->warehouse ? $inventory->warehouse->name : 'N/A',
            $inventory->quantity_on_hand,
            $inventory->quantity_allocated,
            $inventory->quantity_available,
            $inventory->reorder_point ?? 0,
            $inventory->reorder_quantity ?? 0,
            $inventory->last_restocked_at ? $inventory->last_restocked_at->format('Y-m-d H:i:s') : 'Never',
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
            'B' => 30,
            'C' => 15,
            'D' => 20,
            'E' => 15,
            'F' => 15,
            'G' => 15,
            'H' => 15,
            'I' => 15,
            'J' => 20,
        ];
    }
}