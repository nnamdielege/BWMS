<?php

namespace App\Exports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CustomersExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = Customer::query();

        if (!empty($this->filters['search'])) {
            $search = $this->filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'LIKE', "%{$search}%")
                    ->orWhere('customer_code', 'LIKE', "%{$search}%")
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
            'Customer Code',
            'Company Name',
            'Name',
            'Email',
            'Phone',
            'Mobile',
            'Address',
            'City',
            'State',
            'Postal Code',
            'Country',
            'Tax ID',
            'Payment Terms',
            'Credit Limit',
            'Status',
            'Created At',
        ];
    }

    public function map($customer): array
    {
        return [
            $customer->id,
            $customer->customer_code,
            $customer->company_name,
            $customer->name,
            $customer->email,
            $customer->phone,
            $customer->mobile,
            $customer->address,
            $customer->city,
            $customer->state,
            $customer->postal_code,
            $customer->country,
            $customer->tax_id,
            $customer->payment_terms,
            number_format($customer->credit_limit, 2),
            $customer->is_active ? 'Active' : 'Inactive',
            $customer->created_at->format('Y-m-d H:i:s'),
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
            'G' => 15,
            'H' => 30,
            'I' => 15,
            'J' => 15,
            'K' => 12,
            'L' => 15,
            'M' => 15,
            'N' => 15,
            'O' => 15,
            'P' => 12,
            'Q' => 20,
        ];
    }
}