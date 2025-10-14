<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ProductsImport;
use App\Imports\CustomersImport;
use App\Exports\TemplateExport;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ImportController extends Controller
{
    /**
     * Import Products
     */
    public function importProducts(Request $request)
    {
        try {
            Log::info('Product import started');

            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // 10MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('file');

            Log::info('File received: ' . $file->getClientOriginalName());

            $import = new ProductsImport();

            Excel::import($import, $file);

            // Get import results
            $failures = $import->failures();
            $errors = collect($import->errors());
            $successCount = $import->getSuccessCount();
            $rowCount = $import->getRowCount();

            Log::info('Import completed', [
                'total_rows' => $rowCount,
                'success_count' => $successCount,
                'failures_count' => count($failures),
                'errors_count' => $errors->count(),
            ]);

            if (count($failures) > 0 || $errors->count() > 0) {
                $formattedFailures = [];

                foreach ($failures as $failure) {
                    $formattedFailures[] = [
                        'row' => $failure->row(),
                        'attribute' => $failure->attribute(),
                        'errors' => $failure->errors(),
                        'values' => $failure->values(),
                    ];
                }

                return response()->json([
                    'message' => 'Import completed with errors',
                    'success_count' => $successCount,
                    'total_rows' => $rowCount,
                    'failures' => $formattedFailures,
                    'errors' => $errors->toArray(),
                ], 422);
            }

            return response()->json([
                'message' => 'Products imported successfully',
                'success_count' => $successCount,
                'total_rows' => $rowCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Import products error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to import products',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Import Customers
     */
    public function importCustomers(Request $request)
    {
        try {
            Log::info('Customer import started');

            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('file');

            Log::info('File received: ' . $file->getClientOriginalName());

            $import = new CustomersImport();

            Excel::import($import, $file);

            $failures = $import->failures();
            $errors = collect($import->errors());
            $successCount = $import->getSuccessCount();
            $rowCount = $import->getRowCount();

            Log::info('Import completed', [
                'total_rows' => $rowCount,
                'success_count' => $successCount,
                'failures_count' => count($failures),
                'errors_count' => $errors->count(),
            ]);

            if (count($failures) > 0 || $errors->count() > 0) {
                $formattedFailures = [];

                foreach ($failures as $failure) {
                    $formattedFailures[] = [
                        'row' => $failure->row(),
                        'attribute' => $failure->attribute(),
                        'errors' => $failure->errors(),
                        'values' => $failure->values(),
                    ];
                }

                return response()->json([
                    'message' => 'Import completed with errors',
                    'success_count' => $successCount,
                    'total_rows' => $rowCount,
                    'failures' => $formattedFailures,
                    'errors' => $errors->toArray(),
                ], 422);
            }

            return response()->json([
                'message' => 'Customers imported successfully',
                'success_count' => $successCount,
                'total_rows' => $rowCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Import customers error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to import customers',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Download sample import template
     */
    public function downloadTemplate($type)
    {
        try {
            $templates = [
                'products' => [
                    'filename' => 'products_import_template.xlsx',
                    'headers' => [
                        'SKU',
                        'Barcode',
                        'Name',
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
                        'Status'
                    ],
                    'sample' => [
                        [
                            'PROD-001',
                            '1234567890123',
                            'Sample Product 1',
                            'This is a sample product description',
                            'Electronics',
                            '50.00',
                            '99.99',
                            'pcs',
                            '10',
                            '50',
                            '1.5',
                            '10x10x10 cm',
                            'Sample notes here',
                            'Active'
                        ],
                        [
                            'PROD-002',
                            '9876543210987',
                            'Sample Product 2',
                            'Another sample product',
                            'Accessories',
                            '15.00',
                            '29.99',
                            'pcs',
                            '5',
                            '25',
                            '0.5',
                            '5x5x5 cm',
                            'More notes',
                            'Active'
                        ],
                    ]
                ],
                'customers' => [
                    'filename' => 'customers_import_template.xlsx',
                    'headers' => [
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
                        'Status'
                    ],
                    'sample' => [
                        [
                            'CUST-001',
                            'ABC Corporation',
                            'John Doe',
                            'john@abc.com',
                            '555-0001',
                            '555-1001',
                            '123 Main Street',
                            'New York',
                            'NY',
                            '10001',
                            'USA',
                            'TAX-123456',
                            'Net 30',
                            '10000.00',
                            'Active'
                        ],
                        [
                            'CUST-002',
                            'XYZ Industries Inc',
                            'Jane Smith',
                            'jane@xyz.com',
                            '555-0002',
                            '555-1002',
                            '456 Oak Avenue',
                            'Los Angeles',
                            'CA',
                            '90001',
                            'USA',
                            'TAX-789012',
                            'Net 60',
                            '15000.00',
                            'Active'
                        ],
                    ]
                ],
            ];

            if (!isset($templates[$type])) {
                return response()->json([
                    'message' => 'Invalid template type'
                ], 404);
            }

            $template = $templates[$type];

            return Excel::download(
                new TemplateExport($template['headers'], $template['sample']),
                $template['filename']
            );
        } catch (\Exception $e) {
            Log::error('Download template error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to download template',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}