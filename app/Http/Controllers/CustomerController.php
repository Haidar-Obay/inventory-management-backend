<?php

namespace App\Http\Controllers;

use App\Actions\Customer\BulkDeleteCustomersAction;
use App\Actions\Customer\DeleteCustomerAction;
use App\Actions\Customer\DeleteCustomerAttachmentAction;
use App\Actions\Customer\ExportCustomersExcelAction;
use App\Actions\Customer\ExportCustomersPdfAction;
use App\Actions\Customer\GetCustomerAppointmentHistoryAction;
use App\Actions\Customer\GetCustomerAttachmentsAction;
use App\Actions\Customer\GetCustomerBalanceAction;
use App\Actions\Customer\GetCustomerForInvoiceAction;
use App\Actions\Customer\GetCustomerGridAction;
use App\Actions\Customer\GetCustomerNamesAction;
use App\Actions\Customer\GetCustomerVisitHistoryAction;
use App\Actions\Customer\ImportCustomersFromExcelAction;
use App\Actions\Customer\SearchCustomerByPhoneAction;
use App\Actions\Customer\ShowCustomerFullAction;
use App\Actions\Customer\StoreCustomerAction;
use App\Actions\Customer\UpdateCustomerAction;
use App\Actions\Customer\UploadCustomerAttachmentsAction;
use App\Exports\ExportPDF;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Requests\Customer\UploadCustomerAttachmentsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\CustomerAttachment;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    private const INDEX_SECTIONS = ['names', 'balance'];

    private const SHOW_SECTIONS = ['full', 'attachments', 'appointments', 'visits', 'for_invoice'];

    /**
     * List customers. Use ?section=names for id/name/phone list; default is grid data.
     */
    public function index(Request $request)
    {
        $section = $request->query('section');
        if ($section !== null && ! in_array($section, self::INDEX_SECTIONS, true)) {
            return ApiResponse::validationFailed(
                ['section' => ['Allowed: '.implode(', ', self::INDEX_SECTIONS).'.']],
                'Invalid section. Allowed: '.implode(', ', self::INDEX_SECTIONS).'.'
            );
        }

        try {
            if ($section === 'names') {
                return ApiResponse::success(
                    app(GetCustomerNamesAction::class)->execute(),
                    'Customer names fetched successfully.'
                );
            }
            if ($section === 'balance') {
                return ApiResponse::success(
                    app(GetCustomerBalanceAction::class)->execute($request),
                    'Customer balances fetched successfully.'
                );
            }
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'Failed to retrieve customer section data.',
                500,
                null,
                [],
                null,
                $e->getMessage()
            );
        }

        $transformedData = app(GetCustomerGridAction::class)->execute();

        return ApiResponse::success(
            $transformedData,
            'Customers fetched successfully.'
        );
    }

    public function store(StoreCustomerRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $nextId = $this->computeNextAvailableId(Customer::class, 'id');
            $customer = app(StoreCustomerAction::class)->execute($request, $nextId);

            return ApiResponse::created($customer, 'Customer created successfully.');
        });
    }

    /**
     * Upload attachments for a customer (dedicated endpoint; use after create/update without files).
     */
    public function uploadAttachments(UploadCustomerAttachmentsRequest $request, Customer $customer)
    {
        $created = app(UploadCustomerAttachmentsAction::class)->execute($request, $customer);

        return ApiResponse::created(
            $created,
            'Attachments uploaded successfully.'
        );
    }

    /**
     * Show customer. Use ?section=full|attachments|appointments|visits|for_invoice (default: full).
     */
    public function show(Request $request, Customer $customer)
    {
        $section = $request->query('section', 'full');
        if (! in_array($section, self::SHOW_SECTIONS, true)) {
            return ApiResponse::validationFailed(
                ['section' => ['Allowed: '.implode(', ', self::SHOW_SECTIONS).'.']],
                'Invalid section. Allowed: '.implode(', ', self::SHOW_SECTIONS).'.'
            );
        }

        try {
            return match ($section) {
                'attachments' => ApiResponse::success(
                    app(GetCustomerAttachmentsAction::class)->execute($customer),
                    'Attachments fetched successfully.'
                ),
                'appointments' => ApiResponse::success(
                    app(GetCustomerAppointmentHistoryAction::class)->execute($customer),
                    'Appointment history fetched successfully.'
                ),
                'visits' => ApiResponse::success(
                    app(GetCustomerVisitHistoryAction::class)->execute($customer),
                    'Visit history fetched successfully.'
                ),
                'for_invoice' => ApiResponse::success(
                    app(GetCustomerForInvoiceAction::class)->execute($customer),
                    'Customer data retrieved successfully.'
                ),
                default => ApiResponse::success(
                    app(ShowCustomerFullAction::class)->execute($customer),
                    'Customer details fetched successfully.'
                ),
            };
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'Failed to retrieve customer details.',
                500,
                null,
                [],
                null,
                $e->getMessage()
            );
        }
    }

    /**
     * Delete a customer attachment.
     */
    public function deleteAttachment(Customer $customer, CustomerAttachment $attachment)
    {
        $deleted = app(DeleteCustomerAttachmentAction::class)->execute($customer, $attachment);
        if (! $deleted) {
            return ApiResponse::forbidden('Attachment does not belong to this customer.');
        }

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        return DB::transaction(function () use ($request, $customer) {
            $updatedCustomer = app(UpdateCustomerAction::class)->execute($request, $customer);

            return ApiResponse::success($updatedCustomer, 'Customer updated successfully.');
        });
    }

    public function destroy(Customer $customer)
    {
        // Block deletion if the customer has projects; include helpful details
        $projectsCount = Project::where('customer_id', $customer->id)->count();
        if ($projectsCount > 0) {
            $sampleProjectIds = Project::where('customer_id', $customer->id)
                ->select('projects.id')
                ->limit(1)
                ->pluck('id');

            return ApiResponse::error(
                'Cannot delete customer. It is referenced by existing projects.',
                409,
                null,
                [],
                'conflict',
                [
                    'projects' => [
                        'count' => $projectsCount,
                        'sample_ids' => $sampleProjectIds,
                    ],
                ]
            );
        }

        app(DeleteCustomerAction::class)->execute($customer);

        return ApiResponse::success(null, 'Customer deleted successfully.');
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:customers,id',
        ]);

        $result = app(BulkDeleteCustomersAction::class)->execute($request);

        return ApiResponse::success(
            $result,
            'Bulk delete completed.'
        );
    }

    public function exportExcell()
    {
        $download = app(ExportCustomersExcelAction::class)->execute();
        if ($download === null) {
            return ApiResponse::notFound('No customers found.');
        }

        return $download;
    }

    // export pdf
    public function exportPdf(ExportPDF $pdfService)
    {
        $download = app(ExportCustomersPdfAction::class)->execute(request(), $pdfService);
        if ($download === null) {
            return ApiResponse::notFound('No customers found.');
        }

        return $download;
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt,text/plain,text/csv,application/csv',
            ],
            'type' => 'nullable|string|in:fresh,mapping',
            'mapping' => 'nullable|array',
        ], [
            'file.mimes' => 'The file field must be a file of type: xlsx, xls, csv',
        ]);

        try {
            $import = app(ImportCustomersFromExcelAction::class)->execute($request);

            // Check if headers were valid
            if (! $import->areHeadersValid()) {
                $headerResult = $import->getHeaderValidationResult();

                return ApiResponse::error(
                    'Invalid Excel file headers.',
                    422,
                    [
                        'success' => false,
                        'header_validation' => $headerResult,
                        'errors' => [
                            'missing_headers' => $headerResult['missing'],
                            'extra_headers' => $headerResult['extra'],
                            'expected_headers' => $headerResult['expected_headers'],
                            'actual_headers' => $headerResult['excel_headers'],
                        ],
                    ],
                    [],
                    'header_validation'
                );
            }

            $imported = $import->getImportedCount();
            $skippedCount = $import->getSkippedCount();
            $skippedRows = $import->getSkippedRows();
            $totalProcessed = $imported + $skippedCount;

            $message = '';
            if ($imported > 0 && $skippedCount === 0) {
                $message = "Imported {$imported} row(s) successfully.";
            } elseif ($imported > 0 && $skippedCount > 0) {
                $message = "Partially imported: {$imported} row(s) added, {$skippedCount} row(s) skipped.";
            } elseif ($imported === 0 && $skippedCount > 0) {
                $message = 'No rows imported. All rows were skipped due to validation errors or duplicates.';
            } else {
                $message = 'No rows found to import.';
            }

            return ApiResponse::success(
                [
                    'success' => $imported > 0,
                    'rows_processed' => $totalProcessed,
                    'rows_imported' => $imported,
                    'rows_skipped_count' => $skippedCount,
                    'skipped_rows' => $skippedRows,
                    'header_validation' => $import->getHeaderValidationResult(),
                ],
                $message
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // Log the error for debugging
            Log::error('Import failed: '.$e->getMessage(), ['exception' => $e]);

            return ApiResponse::error(
                'Import failed due to invalid data. Please check your file for invalid or missing references (e.g., payment method, salesman, etc.).',
                422,
                [
                    'success' => false,
                    'error_type' => 'database',
                ]
            );
        }
    }

    /**
     * Search customer by phone number
     */
    public function searchByPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $result = app(SearchCustomerByPhoneAction::class)->execute((string) $request->input('phone'));
        if (! $result) {
            return ApiResponse::notFound('Customer not found.');
        }

        return ApiResponse::success(
            $result,
            'Customer found successfully.'
        );
    }
}
