<?php

namespace App\Exports;

use App\Models\Supplier;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SuppliersExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = Supplier::query();

        if (!empty($this->filters['search'])) {
            $search = $this->filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'LIKE', "%{$search}%")
                    ->orWhere('supplier_code', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        if (isset($this->filters['is_active'])) {
            $query->where('is_active', $this->filters['is_active']);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Supplier Code',
            'Company Name',
            'Contact Name',
            'Email',
            'Phone',
            'Address',
            'City',
            'State',
            'Postal Code',
            'Country',
            'Tax ID',
            'Payment Terms',
            'Status',
            'Created At',
        ];
    }

    public function map($supplier): array
    {
        return [
            $supplier->id,
            $supplier->supplier_code,
            $supplier->company_name,
            $supplier->contact_name,
            $supplier->email,
            $supplier->phone,
            $supplier->address,
            $supplier->city,
            $supplier->state,
            $supplier->postal_code,
            $supplier->country,
            $supplier->tax_id,
            $supplier->payment_terms,
            $supplier->is_active ? 'Active' : 'Inactive',
            $supplier->created_at->format('Y-m-d H:i:s'),
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
            'B' => 15,
            'C' => 25,
            'D' => 20,
            'E' => 25,
            'F' => 15,
            'G' => 30,
            'H' => 15,
            'I' => 15,
            'J' => 12,
            'K' => 15,
            'L' => 15,
            'M' => 15,
            'N' => 12,
            'O' => 20,
        ];
    }
}