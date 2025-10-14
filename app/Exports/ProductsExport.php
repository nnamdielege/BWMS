<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductsExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = Product::with('category');

        // Apply filters
        if (!empty($this->filters['search'])) {
            $search = $this->filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('sku', 'LIKE', "%{$search}%")
                    ->orWhere('barcode', 'LIKE', "%{$search}%");
            });
        }

        if (!empty($this->filters['category_id'])) {
            $query->where('category_id', $this->filters['category_id']);
        }

        if (isset($this->filters['is_active'])) {
            $query->where('is_active', $this->filters['is_active']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'SKU',
            'Barcode',
            'Name',
            'Slug',
            'Description',
            'Category',
            'Cost',
            'Price',
            'Unit of Measure',
            'Reorder Point',
            'Reorder Quantity',
            'Weight',
            'Dimensions',
            'Notes',
            'Status',
            'Created At',
            'Updated At',
        ];
    }

    public function map($product): array
    {
        return [
            $product->id,
            $product->sku,
            $product->barcode ?? '',
            $product->name,
            $product->slug,
            $product->description ?? '',
            $product->category ? $product->category->name : '',
            number_format($product->cost, 2),
            number_format($product->price, 2),
            $product->unit_of_measure ?? 'pcs',
            $product->reorder_point ?? 0,
            $product->reorder_quantity ?? 0,
            $product->weight ? number_format($product->weight, 2) : '',
            $product->dimensions ?? '',
            $product->notes ?? '',
            $product->is_active ? 'Active' : 'Inactive',
            $product->created_at->format('Y-m-d H:i:s'),
            $product->updated_at->format('Y-m-d H:i:s'),
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
            'A' => 10,  // ID
            'B' => 15,  // SKU
            'C' => 15,  // Barcode
            'D' => 30,  // Name
            'E' => 25,  // Slug
            'F' => 40,  // Description
            'G' => 20,  // Category
            'H' => 12,  // Cost
            'I' => 12,  // Price
            'J' => 15,  // Unit of Measure
            'K' => 15,  // Reorder Point
            'L' => 15,  // Reorder Quantity
            'M' => 10,  // Weight
            'N' => 15,  // Dimensions
            'O' => 30,  // Notes
            'P' => 12,  // Status
            'Q' => 20,  // Created At
            'R' => 20,  // Updated At
        ];
    }
}