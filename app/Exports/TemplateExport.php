<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class TemplateExport implements FromArray, WithHeadings, WithStyles, WithColumnFormatting
{
    protected $headers;
    protected $sample;

    public function __construct($headers, $sample)
    {
        $this->headers = $headers;
        $this->sample = $sample;
    }

    public function array(): array
    {
        return $this->sample;
    }

    public function headings(): array
    {
        return $this->headers;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 12,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5'], // Indigo color
                ],
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_NUMBER_00, // Cost
            'G' => NumberFormat::FORMAT_NUMBER_00, // Price
            'K' => NumberFormat::FORMAT_NUMBER_00, // Weight
            'N' => NumberFormat::FORMAT_NUMBER_00, // Credit Limit (for customers)
        ];
    }
}