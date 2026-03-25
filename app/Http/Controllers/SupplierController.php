<?php

namespace App\Http\Controllers;

use App\Actions\Supplier\BulkDeleteSuppliersAction;
use App\Actions\Supplier\GetSupplierAttachmentsAction;
use App\Actions\Supplier\GetSupplierBalanceAction;
use App\Actions\Supplier\GetSupplierBriefAction;
use App\Actions\Supplier\GetSupplierGridAction;
use App\Actions\Supplier\DeleteSupplierAction;
use App\Actions\Supplier\DeleteSupplierAttachmentAction;
use App\Actions\Supplier\ExportSuppliersExcelAction;
use App\Actions\Supplier\ExportSuppliersPdfAction;
use App\Actions\Supplier\GetSupplierForPurchaseInvoiceAction;
use App\Actions\Supplier\GetSupplierItemsAction;
use App\Actions\Supplier\ImportSuppliersFromExcelAction;
use App\Actions\Supplier\ListSuppliersForItemSupplierManagementAction;
use App\Actions\Supplier\GetSupplierNamesAction;
use App\Actions\Supplier\ShowSupplierFullAction;
use App\Actions\Supplier\StoreSupplierAction;
use App\Actions\Supplier\UpdateSupplierAction;
use App\Actions\Supplier\UploadSupplierAttachmentsAction;
use App\Exports\ExportPDF;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Responses\ApiResponse;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Requests\Supplier\UploadSupplierAttachmentsRequest;
use App\Models\Supplier;
use App\Models\SupplierAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    private const INDEX_SECTIONS = ['balance', 'names', 'brief'];

    private const SHOW_SECTIONS = ['full', 'attachments', 'for_purchase_invoice'];

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
            if ($section === 'balance') {
                return ApiResponse::success(
                    app(GetSupplierBalanceAction::class)->execute($request),
                    'Supplier balances fetched successfully.'
                );
            }
            if ($section === 'names') {
                return ApiResponse::success(
                    app(GetSupplierNamesAction::class)->execute(),
                    'Supplier names fetched successfully.'
                );
            }
            if ($section === 'brief') {
                return ApiResponse::success(
                    app(GetSupplierBriefAction::class)->execute(),
                    'Supplier brief list retrieved successfully.'
                );
            }
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'Failed to retrieve supplier section data.',
                500,
                null,
                [],
                null,
                $e->getMessage()
            );
        }

        $transformedData = app(GetSupplierGridAction::class)->execute();

        return ApiResponse::success(
            $transformedData,
            'Suppliers retrieved successfully.'
        );
    }

    public function store(StoreSupplierRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $nextId = $this->computeNextAvailableId(Supplier::class, 'id');
            $supplier = app(StoreSupplierAction::class)->execute($request, $nextId);

            return ApiResponse::created(
                $supplier,
                'Supplier created successfully.'
            );
        });
    }

    /**
     * Upload attachments for a supplier (dedicated endpoint; use after create/update without files).
     */
    public function uploadAttachments(UploadSupplierAttachmentsRequest $request, Supplier $supplier)
    {
        $created = app(UploadSupplierAttachmentsAction::class)->execute($request, $supplier);

        return ApiResponse::created(
            $created,
            'Attachments uploaded successfully.'
        );
    }

    /**
     * Delete a supplier attachment.
     */
    public function deleteAttachment(Supplier $supplier, SupplierAttachment $attachment)
    {
        $deleted = app(DeleteSupplierAttachmentAction::class)->execute($supplier, $attachment);
        if (! $deleted) {
            return ApiResponse::forbidden('Attachment does not belong to this supplier.');
        }

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    public function show(Request $request, Supplier $supplier)
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
                    app(GetSupplierAttachmentsAction::class)->execute($supplier),
                    'Attachments fetched successfully.'
                ),
                'for_purchase_invoice' => ApiResponse::success(
                    app(GetSupplierForPurchaseInvoiceAction::class)->execute($supplier),
                    'Supplier data for purchase invoice retrieved successfully.'
                ),
                default => ApiResponse::success(
                    app(ShowSupplierFullAction::class)->execute($supplier),
                    'Supplier retrieved successfully.'
                ),
            };
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'Failed to retrieve supplier details.',
                500,
                null,
                [],
                null,
                $e->getMessage()
            );
        }
    }

    /**
     * Get items related to a supplier with costs and purchase UOM
     * Optimized endpoint for loading supplier items in purchase invoice
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getItems(Supplier $supplier)
    {
        return ApiResponse::success(
            app(GetSupplierItemsAction::class)->execute($supplier),
            'Supplier items fetched successfully.'
        );
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        return DB::transaction(function () use ($request, $supplier) {
            $supplier = app(UpdateSupplierAction::class)->execute($request, $supplier);

            return ApiResponse::success(
                        $supplier,
                'Supplier updated successfully.'
            );
        });
    }

    public function destroy(Supplier $supplier)
    {
        app(DeleteSupplierAction::class)->execute($supplier);

        return ApiResponse::success(null, 'Supplier deleted successfully.');
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:suppliers,id',
        ]);

        $result = app(BulkDeleteSuppliersAction::class)->execute($request);

        return ApiResponse::success(
            $result,
            'Bulk delete completed.'
        );
    }

    public function exportExcell()
    {
        $result = app(ExportSuppliersExcelAction::class)->execute();
        if ($result['type'] === 'not_found') {
            return ApiResponse::notFound('No suppliers found.');
        }
        if ($result['type'] === 'exception') {
            return ApiResponse::error(
                'Failed to export suppliers.',
                500,
                null,
                [],
                null,
                $result['message']
            );
        }

        return $result['response'];
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $download = app(ExportSuppliersPdfAction::class)->execute($pdfService);
        if ($download === null) {
            return ApiResponse::notFound('No suppliers found.');
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
            $import = app(ImportSuppliersFromExcelAction::class)->execute($request);

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
                ],
                $message
            );

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Supplier import failed: '.$e->getMessage(), ['exception' => $e]);

            return ApiResponse::error(
                'Import failed due to invalid data. Please check your file for invalid or missing references (e.g., payment method, etc.).',
                422,
                [
                'success' => false,
                'error_type' => 'database',
                ]
            );
        }
    }

    /**
     * Lightweight list for Item Supplier Management section: id, name, and currency from first active opening balance.
     */
    public function listForItemSupplierManagement()
    {
        try {
            return ApiResponse::success(
                app(ListSuppliersForItemSupplierManagementAction::class)->execute(),
                'Suppliers for item supplier management retrieved successfully.'
            );
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'Failed to retrieve suppliers for item supplier management.',
                500,
                null,
                [],
                null,
                $e->getMessage()
            );
        }
    }

}
