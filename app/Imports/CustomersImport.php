<?php

namespace App\Imports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class CustomersImport implements
    ToModel,
    WithHeadingRow,
    WithValidation,
    SkipsOnError,
    SkipsOnFailure,
    WithBatchInserts,
    WithChunkReading
{
    use SkipsErrors, SkipsFailures;

    protected $rowCount = 0;
    protected $successCount = 0;

    public function model(array $row)
    {
        $this->rowCount++;

        // Skip empty rows
        if (empty($row['customer_code']) || empty($row['company_name'])) {
            return null;
        }

        // Check if customer exists by customer_code
        $customer = Customer::where('customer_code', $row['customer_code'])->first();

        $data = [
            'company_name' => $row['company_name'],
            'name' => $row['name'] ?? null,
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'mobile' => $row['mobile'] ?? null,
            'address' => $row['address'] ?? null,
            'city' => $row['city'] ?? null,
            'state' => $row['state'] ?? null,
            'postal_code' => $row['postal_code'] ?? null,
            'country' => $row['country'] ?? null,
            'tax_id' => $row['tax_id'] ?? null,
            'payment_terms' => $row['payment_terms'] ?? 'Net 30',
            'credit_limit' => !empty($row['credit_limit']) ? floatval($row['credit_limit']) : 0,
            'is_active' => $this->parseStatus($row['status'] ?? 'Active'),
        ];

        if ($customer) {
            // Update existing customer
            $customer->update($data);
            $this->successCount++;
            return null;
        }

        // Create new customer
        $this->successCount++;
        return new Customer(array_merge($data, [
            'customer_code' => $row['customer_code'],
        ]));
    }

    public function rules(): array
    {
        return [
            'customer_code' => 'required|string|max:255',
            'company_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
        ];
    }

    public function customValidationMessages()
    {
        return [
            'customer_code.required' => 'Customer code is required',
            'company_name.required' => 'Company name is required',
            'email.email' => 'Invalid email format',
        ];
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    private function parseStatus($status): bool
    {
        if (is_bool($status)) {
            return $status;
        }

        $status = strtolower(trim($status));
        return in_array($status, ['active', '1', 'yes', 'true']);
    }
}