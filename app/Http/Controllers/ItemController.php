<?php

namespace App\Http\Controllers;

use App\Enums\ItemType;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Item\AttachSuppliersRequest;
use App\Http\Requests\Item\AttachUOMRequest;
use App\Http\Requests\Item\DetachSuppliersRequest;
use App\Http\Requests\Item\DetachUOMRequest;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Requests\Item\UpdateSupplierPivotRequest;
use App\Http\Requests\Item\UpdateUOMPivotRequest;
use App\Http\Requests\Item\UploadItemAttachmentRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Item;
use App\Models\ItemAttachment;
use App\Models\ItemUnitOfMeasurement;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ItemController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_items";

        // Always load relationships, even if cached
        // Exclude service items (they are mirrored from services table)
        $items = Item::with([
            'trade:id,name',
            'companyCode:id,name',
            'productLine:id,name',
            'category:id,name',
            'brand:id,name',
            'baseUom:id,name',
            'parent:id,code,name',
        ])
            ->where('type', '!=', ItemType::SERVICE)
            ->where('type', '!=', ItemType::MEDICAL_SERVICE)
            ->orderBy('name')
            ->get();

        // Transform relationships to snake_case for frontend compatibility
        $transformedItems = $items->map(function ($item) {
            $itemArray = $item->toArray();
            // Map camelCase relationships to snake_case
            if (isset($itemArray['companyCode'])) {
                $itemArray['company_code'] = $itemArray['companyCode'];
                unset($itemArray['companyCode']);
            }
            if (isset($itemArray['productLine'])) {
                $itemArray['product_line'] = $itemArray['productLine'];
                unset($itemArray['productLine']);
            }
            if (isset($itemArray['baseUom'])) {
                $itemArray['base_uom'] = $itemArray['baseUom'];
                unset($itemArray['baseUom']);
            }

            return $itemArray;
        });

        // Cache the items for future use
        app('cache')->store('database')->put($key, $items, now()->addHours(1));

        return response()->json([
            'status' => true,
            'message' => 'Items fetched successfully.',
            'data' => $transformedItems,
        ]);
    }

    public function show(Item $item)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_item_{$item->id}";

        $cachedItem = app('cache')->store('database')->get($key);

        if (! $cachedItem) {
            $cachedItem = $item;
            app('cache')->store('database')->forever($key, $cachedItem);
        }

        return response()->json([
            'status' => true,
            'message' => 'Item details fetched successfully.',
            'data' => $cachedItem,
        ]);
    }

    public function store(StoreItemRequest $request)
    {
        $data = $request->validated();
        $nextId = $this->computeNextAvailableId(Item::class, 'id');
        $item = new Item($data);
        $item->id = $nextId;
        $item->save();

        // Ensure base UOM is attached if provided
        if ($item->base_uom_id) {
            if (! $item->unitOfMeasurements()->where('unit_of_measurement_id', $item->base_uom_id)->exists()) {
                ItemUnitOfMeasurement::updateOrCreate([
                    'item_id' => $item->id,
                    'unit_of_measurement_id' => $item->base_uom_id,
                ], [
                    'operation' => 'multiply',
                    'conversion' => 1,
                ]);
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Item created successfully.',
            'data' => $item->load([
                'trade:id,name',
                'companyCode:id,name',
                'productLine:id,name',
                'category:id,name',
                'brand:id,name',
                'baseUom:id,name',
                'parent:id,code,name',
            ]),
        ], 201);
    }

    public function update(UpdateItemRequest $request, Item $item)
    {
        $item->update($request->validated());

        // Ensure base UOM is attached if provided
        if ($item->base_uom_id) {
            if (! $item->unitOfMeasurements()->where('unit_of_measurement_id', $item->base_uom_id)->exists()) {
                ItemUnitOfMeasurement::updateOrCreate([
                    'item_id' => $item->id,
                    'unit_of_measurement_id' => $item->base_uom_id,
                ], [
                    'operation' => 'multiply',
                    'conversion' => 1,
                ]);
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Item updated successfully.',
            'data' => $item->load([
                'trade:id,name',
                'companyCode:id,name',
                'productLine:id,name',
                'category:id,name',
                'brand:id,name',
                'baseUom:id,name',
                'parent:id,code,name',
            ]),
        ]);
    }

    public function destroy(Item $item)
    {
        $identifier = $item->name ?? $item->code ?? "ID: {$item->id}";
        $details = [];

        // Prevent deletion if item has child items (parent_id references)
        $childrenCount = $item->children()->count();
        if ($childrenCount > 0) {
            $details['child_items'] = [
                'count' => $childrenCount,
                'sample_ids' => $item->children()->select('items.id')->limit(1)->pluck('id'),
            ];
        }

        // Check if item is referenced by service needed items
        $serviceNeededItemsCount = \App\Models\ServiceNeededItem::where('item_id', $item->id)->count();
        if ($serviceNeededItemsCount > 0) {
            $details['service_needed_items'] = [
                'count' => $serviceNeededItemsCount,
                'sample_ids' => \App\Models\ServiceNeededItem::where('item_id', $item->id)
                    ->select('service_needed_items.id')
                    ->limit(1)
                    ->pluck('id'),
            ];
        }

        if (! empty($details)) {
            return response()->json([
                'status' => false,
                'message' => "Cannot delete item \"{$identifier}\" (ID: {$item->id}). It is referenced by existing records.",
                'details' => $details,
            ], 409);
        }

        $tenantId = tenant('id');
        $item->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Item deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:items,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $item = Item::find($id);

                if (! $item) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Item not found.',
                    ];

                    continue;
                }

                $identifier = $item->name ?? $item->code ?? "ID: {$id}";
                $details = [];

                // Check if item has child items and include details
                if ($item->children()->exists()) {
                    $childrenCount = $item->children()->count();
                    $details['child_items'] = [
                        'count' => $childrenCount,
                        'sample_ids' => $item->children()->select('items.id')->limit(1)->pluck('id'),
                    ];
                }

                // Check if item is referenced by service needed items
                $serviceNeededItemsCount = \App\Models\ServiceNeededItem::where('item_id', $id)->count();
                if ($serviceNeededItemsCount > 0) {
                    $details['service_needed_items'] = [
                        'count' => $serviceNeededItemsCount,
                        'sample_ids' => \App\Models\ServiceNeededItem::where('item_id', $id)
                            ->select('service_needed_items.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }

                if (! empty($details)) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete item. It is referenced by existing records.',
                        'details' => $details,
                    ];

                    continue;
                }

                $item->delete();
                $deleted++;
                app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$id}");

            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $item = Item::find($id);
                        $childrenCount = $item?->children()->count() ?? 0;
                        if ($childrenCount > 0) {
                            $details['child_items'] = [
                                'count' => $childrenCount,
                                'sample_ids' => $item->children()->select('items.id')->limit(1)->pluck('id'),
                            ];
                        }

                        $serviceNeededItemsCount = \App\Models\ServiceNeededItem::where('item_id', $id)->count();
                        if ($serviceNeededItemsCount > 0) {
                            $details['service_needed_items'] = [
                                'count' => $serviceNeededItemsCount,
                                'sample_ids' => \App\Models\ServiceNeededItem::where('item_id', $id)
                                    ->select('service_needed_items.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $item = Item::find($id);
                    $identifier = $item?->name ?? $item?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete item. It is referenced by existing records.',
                        'details' => $details,
                    ];
                } else {
                    Log::error('Error deleting item '.$id.': '.$e->getMessage());
                    $item = Item::find($id);
                    $identifier = $item?->name ?? $item?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => $e->getMessage(),
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Error deleting item '.$id.': '.$e->getMessage());
                $item = Item::find($id);
                $identifier = $item?->name ?? $item?->code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_items");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $items = Item::orderBy('name');
        $collection = $items->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No items to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'type', 'price', 'discount_percent', 'max_discount', 'unit', 'trade', 'company_code', 'product_line', 'category', 'brand', 'description', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Type', 'Price', 'Discount %', 'Max Discount', 'Unit', 'Trade', 'Company Code', 'Product Line', 'Category', 'Brand', 'Description', 'Created At', 'Updated At'];

        $fileName = 'items'.'.xlsx';

        return Excel::download(new Export($items, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $items = Item::select('id', 'code', 'name', 'type', 'price', 'discount_percent', 'max_discount', 'unit', 'trade', 'company_code', 'product_line', 'category', 'brand', 'description', 'created_at', 'updated_at')->get();

        if ($items->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No items to export',
            ], 404);
        }

        $title = 'Items Report';
        $headers = ['id' => 'ID', 'code' => 'Code', 'name' => 'Name', 'price' => 'Price', 'unit' => 'Unit', 'description' => 'Description', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $items->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Items.pdf');
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

        // If type is 'fresh', delete all records first
        if ($request->input('type') === 'fresh') {
            // Get model class from the import
            Item::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            Item::class,
            ['code', 'name', 'type', 'price', 'discount_percent', 'max_discount', 'unit', 'trade', 'company_code', 'product_line', 'category', 'brand', 'description'],
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $errors = [];

                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $typeKey = $mapping ? array_search('type', $mapping) : 'type';
                $priceKey = $mapping ? array_search('price', $mapping) : 'price';
                $discountPercentKey = $mapping ? array_search('discount_percent', $mapping) : 'discount_percent';
                $maxDiscountKey = $mapping ? array_search('max_discount', $mapping) : 'max_discount';
                $unitKey = $mapping ? array_search('unit', $mapping) : 'unit';
                $tradeKey = $mapping ? array_search('trade', $mapping) : 'trade';
                $companyCodeKey = $mapping ? array_search('company_code', $mapping) : 'company_code';
                $productLineKey = $mapping ? array_search('product_line', $mapping) : 'product_line';
                $categoryKey = $mapping ? array_search('category', $mapping) : 'category';
                $brandKey = $mapping ? array_search('brand', $mapping) : 'brand';
                $descriptionKey = $mapping ? array_search('description', $mapping) : 'description';
                $typeVal = strtolower(trim((string) ($row[$typeKey] ?? '')));
                $validTypes = array_map(fn ($c) => $c->value, ItemType::cases());
                if ($typeVal === '' || ! in_array($typeVal, $validTypes, true)) {
                    $errors[] = 'Invalid type';
                }

                if ((($row[$codeKey] ?? '') === '')) {
                    $errors[] = 'Missing code';
                }
                if ((($row[$nameKey] ?? '') === '')) {
                    $errors[] = 'Missing name';
                }
                $priceVal = $row[$priceKey] ?? null;
                if ($priceVal === '' || $priceVal === null || ! is_numeric($priceVal)) {
                    $errors[] = 'Invalid price';
                }
                $discountPercentVal = $row[$discountPercentKey] ?? null;
                if ($discountPercentVal !== null && $discountPercentVal !== '' && (! is_numeric($discountPercentVal) || $discountPercentVal < 0 || $discountPercentVal > 100)) {
                    $errors[] = 'Invalid discount_percent';
                }
                $maxDiscountVal = $row[$maxDiscountKey] ?? null;
                if ($maxDiscountVal !== null && $maxDiscountVal !== '' && (! is_numeric($maxDiscountVal) || $maxDiscountVal < 0)) {
                    $errors[] = 'Invalid max_discount';
                }
                // Optional string fields: no strict validation needed

                return $errors;
            },
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $typeKey = $mapping ? array_search('type', $mapping) : 'type';
                $priceKey = $mapping ? array_search('price', $mapping) : 'price';
                $discountPercentKey = $mapping ? array_search('discount_percent', $mapping) : 'discount_percent';
                $maxDiscountKey = $mapping ? array_search('max_discount', $mapping) : 'max_discount';
                $unitKey = $mapping ? array_search('unit', $mapping) : 'unit';
                $tradeKey = $mapping ? array_search('trade', $mapping) : 'trade';
                $companyCodeKey = $mapping ? array_search('company_code', $mapping) : 'company_code';
                $productLineKey = $mapping ? array_search('product_line', $mapping) : 'product_line';
                $categoryKey = $mapping ? array_search('category', $mapping) : 'category';
                $brandKey = $mapping ? array_search('brand', $mapping) : 'brand';
                $descriptionKey = $mapping ? array_search('description', $mapping) : 'description';

                return [
                    'code' => $row[$codeKey] ?? null,
                    'name' => $row[$nameKey] ?? null,
                    'type' => isset($row[$typeKey]) ? strtolower(trim((string) $row[$typeKey])) : null,
                    'price' => isset($row[$priceKey]) ? floatval($row[$priceKey]) : null,
                    'discount_percent' => isset($row[$discountPercentKey]) && $row[$discountPercentKey] !== '' ? floatval($row[$discountPercentKey]) : 0,
                    'max_discount' => isset($row[$maxDiscountKey]) && $row[$maxDiscountKey] !== '' ? floatval($row[$maxDiscountKey]) : null,
                    'unit' => $row[$unitKey] ?? null,
                    'trade' => $row[$tradeKey] ?? null,
                    'company_code' => $row[$companyCodeKey] ?? null,
                    'product_line' => $row[$productLineKey] ?? null,
                    'category' => $row[$categoryKey] ?? null,
                    'brand' => $row[$brandKey] ?? null,
                    'description' => $row[$descriptionKey] ?? null,
                ];
            },
            $mapping ? false : true // Disable header validation when mapping provided
        );

        Excel::import($import, $request->file('file'));

        // Check if headers were valid
        if (! $import->areHeadersValid()) {
            $headerResult = $import->getHeaderValidationResult();

            return response()->json([
                'success' => false,
                'message' => 'Invalid Excel file headers',
                'header_validation' => $headerResult,
                'errors' => [
                    'missing_headers' => $headerResult['missing'],
                    'extra_headers' => $headerResult['extra'],
                    'expected_headers' => $headerResult['expected_headers'],
                    'actual_headers' => $headerResult['excel_headers'],
                ],
            ], 422);
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");

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

        return response()->json([
            'success' => $imported > 0,
            'message' => $message,
            'rows_processed' => $totalProcessed,
            'rows_imported' => $imported,
            'rows_skipped_count' => $skippedCount,
            'skipped_rows' => $skippedRows,
        ]);
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_item_names";

        $items = app('cache')->store('database')->get($key);

        if (! $items) {
            $items = Item::select('id', 'code', 'name')
                ->orderBy('name')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'code' => $item->code,
                        'name' => $item->name,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ];
                });

            app('cache')->store('database')->forever($key, $items);
        }

        return response()->json([
            'status' => true,
            'message' => 'Item names fetched successfully.',
            'data' => $items,
        ]);
    }

    public function uploadAttachment(UploadItemAttachmentRequest $request, Item $item)
    {
        $tenantId = tenant('id');
        $validated = $request->validated();
        /** @var \Illuminate\Http\UploadedFile|null $file */
        $file = request()->file('attachment');

        if (! $file || ! $file->isValid()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid file.',
            ], 422);
        }

        $path = Storage::disk('public')->putFile(
            "tenants/{$tenantId}/items/{$item->id}/attachments",
            $file
        );

        $category = $validated['category'] ?? 'other';
        if ($category === 'other') {
            $mimeType = $file->getMimeType();
            $category = str_starts_with($mimeType, 'image/') ? 'photo' : 'document';
        }

        $attachment = ItemAttachment::create([
            'item_id' => $item->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => url(Storage::url($path)),
            'file_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'description' => $validated['description'] ?? null,
            'category' => $category,
        ]);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Attachment uploaded successfully.',
            'data' => $attachment,
        ], 201);
    }

    public function getAttachments(Item $item)
    {
        $attachments = $item->attachments()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Attachments fetched successfully.',
            'data' => $attachments,
        ]);
    }

    public function deleteAttachment(Item $item, ItemAttachment $attachment)
    {
        if ($attachment->item_id !== $item->id) {
            return response()->json([
                'status' => false,
                'message' => 'Attachment does not belong to this item.',
            ], 403);
        }

        // Extract the storage path from the URL
        $filePath = str_replace(url('storage/'), '', $attachment->file_path);
        $filePath = str_replace(url('/storage/'), '', $filePath);

        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }

        $attachment->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Attachment deleted successfully.',
        ]);
    }

    public function getSuppliers(Item $item)
    {
        $suppliers = $item->suppliers()
            ->select('suppliers.id', 'suppliers.company_name', 'suppliers.first_name', 'suppliers.last_name')
            ->withPivot(['original_code', 'currency', 'cost', 'is_primary'])
            ->orderByDesc('pivot_is_primary')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Item suppliers fetched successfully.',
            'data' => $suppliers,
        ]);
    }

    public function attachSuppliers(AttachSuppliersRequest $request, Item $item)
    {
        $validated = $request->validated();

        $payload = [];
        $hasPrimary = false;
        foreach ($validated['suppliers'] as $row) {
            $payload[$row['supplier_id']] = [
                'original_code' => $row['original_code'] ?? null,
                'currency' => $row['currency'] ?? null,
                'cost' => isset($row['cost']) ? $row['cost'] : null,
                'is_primary' => ! empty($row['is_primary']) ? (bool) $row['is_primary'] : false,
            ];
            if (! $hasPrimary && ! empty($row['is_primary'])) {
                $hasPrimary = true;
            }
        }

        // If a primary is provided, ensure only one primary
        if ($hasPrimary) {
            // Reset all to non-primary first
            $item->suppliers()->updateExistingPivot(
                $item->suppliers->pluck('id')->all(),
                ['is_primary' => false]
            );
        }

        $item->suppliers()->syncWithoutDetaching($payload);

        return response()->json([
            'status' => true,
            'message' => 'Suppliers attached successfully.',
            'data' => $item->suppliers()->withPivot(['original_code', 'currency', 'cost', 'is_primary'])->get(),
        ]);
    }

    public function updateSupplier(Item $item, Supplier $supplier, UpdateSupplierPivotRequest $request)
    {
        $data = $request->validated();
        if (! $item->suppliers()->where('supplier_id', $supplier->id)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Supplier is not attached to this item.',
            ], 404);
        }

        if (array_key_exists('is_primary', $data) && $data['is_primary']) {
            // Clear previous primary
            $item->suppliers()->updateExistingPivot(
                $item->suppliers->pluck('id')->all(),
                ['is_primary' => false]
            );
        }

        $item->suppliers()->updateExistingPivot($supplier->id, [
            'original_code' => $data['original_code'] ?? null,
            'currency' => $data['currency'] ?? null,
            'cost' => $data['cost'] ?? null,
            'is_primary' => isset($data['is_primary']) ? (bool) $data['is_primary'] : false,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Supplier pivot updated.',
            'data' => $item->suppliers()->where('suppliers.id', $supplier->id)->first(),
        ]);
    }

    public function detachSuppliers(DetachSuppliersRequest $request, Item $item)
    {
        $item->suppliers()->detach($request->validated()['supplier_ids']);

        return response()->json([
            'status' => true,
            'message' => 'Suppliers detached successfully.',
        ]);
    }

    public function setPrimarySupplier(Item $item, Supplier $supplier)
    {
        if (! $item->suppliers()->where('supplier_id', $supplier->id)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Supplier is not attached to this item.',
            ], 404);
        }

        // Reset all to non-primary
        $item->suppliers()->updateExistingPivot(
            $item->suppliers->pluck('id')->all(),
            ['is_primary' => false]
        );

        // Set selected as primary
        $item->suppliers()->updateExistingPivot($supplier->id, ['is_primary' => true]);

        return response()->json([
            'status' => true,
            'message' => 'Primary supplier set.',
        ]);
    }

    public function getUnitOfMeasurements(Item $item)
    {
        $uoms = $item->unitOfMeasurements()
            ->with('unitGroup')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Item unit of measurements fetched successfully.',
            'data' => $uoms,
        ]);
    }

    public function attachUOM(AttachUOMRequest $request, Item $item)
    {
        $validated = $request->validated();
        $payload = [];

        // Require base UOM before attaching any UOMs
        if (! $item->base_uom_id) {
            return response()->json([
                'status' => false,
                'message' => 'Set base_uom_id on the item before attaching unit of measurements.',
            ], 422);
        }

        // Ensure base UOM pivot exists with multiply/1
        if (! $item->unitOfMeasurements()->where('unit_of_measurement_id', $item->base_uom_id)->exists()) {
            ItemUnitOfMeasurement::updateOrCreate([
                'item_id' => $item->id,
                'unit_of_measurement_id' => $item->base_uom_id,
            ], [
                'operation' => 'multiply',
                'conversion' => 1,
            ]);
        }

        foreach ($validated['unit_of_measurements'] as $row) {
            // Enforce base UOM rule: multiply + 1
            if ($item->base_uom_id && intval($row['unit_of_measurement_id']) === intval($item->base_uom_id)) {
                $row['operation'] = 'multiply';
                $row['conversion'] = 1;
            }
            ItemUnitOfMeasurement::updateOrCreate(
                [
                    'item_id' => $item->id,
                    'unit_of_measurement_id' => $row['unit_of_measurement_id'],
                ],
                [
                    'operation' => $row['operation'],
                    'conversion' => $row['conversion'],
                    'barcodes' => $row['barcodes'] ?? null,
                    'price_1' => $row['price_1'] ?? null,
                    'price_2' => $row['price_2'] ?? null,
                    'price_3' => $row['price_3'] ?? null,
                    'price_4' => $row['price_4'] ?? null,
                    'price_5' => $row['price_5'] ?? null,
                    'price_6' => $row['price_6'] ?? null,
                    'gross_volume' => $row['gross_volume'] ?? null,
                    'gross_weight' => $row['gross_weight'] ?? null,
                    'net_volume' => $row['net_volume'] ?? null,
                    'net_weight' => $row['net_weight'] ?? null,
                ]
            );
        }

        $result = $item->unitOfMeasurements()->with('unitGroup')->get();

        return response()->json([
            'status' => true,
            'message' => 'Unit of measurements attached successfully.',
            'data' => $result,
        ]);
    }

    public function updateUOM(Item $item, UnitOfMeasurement $unitOfMeasurement, UpdateUOMPivotRequest $request)
    {
        if (! $item->unitOfMeasurements()->where('unit_of_measurement_id', $unitOfMeasurement->id)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Unit of measurement is not attached to this item.',
            ], 404);
        }

        $data = $request->validated();
        // Enforce base UOM rule: multiply + 1 regardless of client input
        if ($item->base_uom_id && $unitOfMeasurement->id === intval($item->base_uom_id)) {
            $data['operation'] = 'multiply';
            $data['conversion'] = 1;
        }

        ItemUnitOfMeasurement::updateOrCreate(
            [
                'item_id' => $item->id,
                'unit_of_measurement_id' => $unitOfMeasurement->id,
            ],
            $data
        );

        $uom = $item->unitOfMeasurements()
            ->where('unit_of_measurements.id', $unitOfMeasurement->id)
            ->with('unitGroup')
            ->first();

        return response()->json([
            'status' => true,
            'message' => 'Unit of measurement pivot updated successfully.',
            'data' => $uom,
        ]);
    }

    public function detachUOM(DetachUOMRequest $request, Item $item)
    {
        $item->unitOfMeasurements()->detach($request->validated()['unit_of_measurement_ids']);

        return response()->json([
            'status' => true,
            'message' => 'Unit of measurements detached successfully.',
        ]);
    }

    /**
     * Fetch service items (items mirrored from services table)
     * These are items with type='service' or type='medical_service'
     */
    public function getServiceItems()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_service_items";

        $items = app('cache')->store('database')->get($key);

        if (! $items) {
            $items = Item::with(['service:id,name'])
                ->whereIn('type', [ItemType::SERVICE, ItemType::MEDICAL_SERVICE])
                ->orderBy('name')
                ->get();

            app('cache')->store('database')->put($key, $items, now()->addHours(1));
        }

        return response()->json([
            'status' => true,
            'message' => 'Service items fetched successfully.',
            'data' => $items,
        ]);
    }

    /**
     * Fetch all items including both regular items and service items
     * This combines regular items with service-mirrored items
     */
    public function getAllItems()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_all_items";

        $items = app('cache')->store('database')->get($key);

        if (! $items) {
            // Get regular items (excluding services)
            $regularItems = Item::with([
                'trade:id,name',
                'companyCode:id,name',
                'productLine:id,name',
                'category:id,name',
                'brand:id,name',
                'baseUom:id,name',
                'parent:id,code,name',
            ])
                ->where('type', '!=', ItemType::SERVICE)
                ->where('type', '!=', ItemType::MEDICAL_SERVICE)
                ->orderBy('name')
                ->get();

            // Get service items (with service relationship)
            $serviceItems = Item::with(['service:id,name'])
                ->whereIn('type', [ItemType::SERVICE, ItemType::MEDICAL_SERVICE])
                ->orderBy('name')
                ->get();

            // Combine both collections
            $items = $regularItems->concat($serviceItems)->sortBy('name')->values();

            app('cache')->store('database')->put($key, $items, now()->addHours(1));
        }

        // Transform relationships to snake_case for frontend compatibility
        $transformedItems = $items->map(function ($item) {
            $itemArray = $item->toArray();
            // Map camelCase relationships to snake_case
            if (isset($itemArray['companyCode'])) {
                $itemArray['company_code'] = $itemArray['companyCode'];
                unset($itemArray['companyCode']);
            }
            if (isset($itemArray['productLine'])) {
                $itemArray['product_line'] = $itemArray['productLine'];
                unset($itemArray['productLine']);
            }
            if (isset($itemArray['baseUom'])) {
                $itemArray['base_uom'] = $itemArray['baseUom'];
                unset($itemArray['baseUom']);
            }

            return $itemArray;
        });

        return response()->json([
            'status' => true,
            'message' => 'All items fetched successfully.',
            'data' => $transformedItems,
        ]);
    }
}
