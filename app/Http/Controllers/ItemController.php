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
use App\Models\ItemBarcode;
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
            'taxGroup:id,code,name,value',
            'itemGroup:id,code,name',
            'baseUom:id,name,unit_group_id',
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
            if (isset($itemArray['taxGroup'])) {
                $itemArray['tax_group'] = $itemArray['taxGroup'];
                unset($itemArray['taxGroup']);
            }
            if (isset($itemArray['itemGroup'])) {
                $itemArray['item_group'] = $itemArray['itemGroup'];
                unset($itemArray['itemGroup']);
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

    /**
     * Get basic item details with relationships.
     * Simple endpoint for general item information.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Item $item)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_item_{$item->id}";

        $cachedItem = app('cache')->store('database')->get($key);

        if (! $cachedItem) {
            $cachedItem = $item->load([
                'trade:id,name',
                'companyCode:id,name',
                'productLine:id,name',
                'category:id,name',
                'brand:id,name',
                'taxGroup:id,code,name,value',
                'itemGroup:id,code,name',
                'baseUom:id,name,unit_group_id',
                'parent:id,code,name',
            ]);
            app('cache')->store('database')->forever($key, $cachedItem);
        }

        // Transform to array and convert camelCase to snake_case for frontend
        $itemArray = $cachedItem->toArray();

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
        if (isset($itemArray['taxGroup'])) {
            $itemArray['tax_group'] = $itemArray['taxGroup'];
            unset($itemArray['taxGroup']);
        }
        if (isset($itemArray['itemGroup'])) {
            $itemArray['item_group'] = $itemArray['itemGroup'];
            unset($itemArray['itemGroup']);
        }

        return response()->json([
            'status' => true,
            'message' => 'Item details fetched successfully.',
            'data' => $itemArray,
        ]);
    }

    /**
     * Get item with all relationships for preview/detail view.
     * Optimized with eager loading and efficient barcode queries.
     * Includes suppliers, UOMs with prices, barcodes, and all detailed information.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getItemForPreview(Item $item)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_item_preview_{$item->id}";

        $cachedItem = app('cache')->store('database')->get($key);

        if (! $cachedItem) {
            // Load all basic relationships with minimal columns
            $item->load([
                'trade:id,name',
                'companyCode:id,name',
                'productLine:id,name',
                'category:id,name',
                'brand:id,name',
                'taxGroup:id,code,name,value',
                'itemGroup:id,code,name',
                'baseUom:id,name',
                'purchaseUom:id,name',
                'salesUom:id,name',
                'parent:id,code,name',
            ]);

            // Load suppliers with pivot data
            $item->load([
                'suppliers' => function ($query) {
                    $query->select('suppliers.id', 'suppliers.company_name', 'suppliers.first_name', 'suppliers.last_name', 'suppliers.display_name')
                        ->orderBy('item_supplier.is_primary', 'desc');
                },
            ]);

            // Load unit of measurements with all pivot data
            $item->load([
                'unitOfMeasurements' => function ($query) {
                    $query->select('unit_of_measurements.id', 'unit_of_measurements.name')
                        ->orderBy('unit_of_measurements.name');
                },
            ]);

            // Get all item_unit_of_measurement IDs for efficient barcode loading
            $itemUomIds = $item->unitOfMeasurements->pluck('pivot.id')->filter()->toArray();

            // Load barcodes for all UOMs in a single query
            $barcodesByItemUomId = [];
            if (! empty($itemUomIds)) {
                $barcodes = ItemBarcode::whereIn('item_unit_of_measurement_id', $itemUomIds)
                    ->select('id', 'item_unit_of_measurement_id', 'barcode', 'is_primary')
                    ->orderBy('is_primary', 'desc')
                    ->orderBy('barcode')
                    ->get()
                    ->groupBy('item_unit_of_measurement_id');

                foreach ($barcodes as $itemUomId => $barcodeCollection) {
                    $barcodesByItemUomId[$itemUomId] = $barcodeCollection->pluck('barcode')->toArray();
                }
            }

            // Attach barcodes to each UOM pivot
            foreach ($item->unitOfMeasurements as $uom) {
                $pivotId = $uom->pivot->id ?? null;
                if ($pivotId && isset($barcodesByItemUomId[$pivotId])) {
                    $uom->pivot->setAttribute('barcodes', $barcodesByItemUomId[$pivotId]);
                } else {
                    $uom->pivot->setAttribute('barcodes', []);
                }
            }

            // Transform to array and convert camelCase to snake_case for frontend
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
            if (isset($itemArray['purchaseUom'])) {
                $itemArray['purchase_uom'] = $itemArray['purchaseUom'];
                unset($itemArray['purchaseUom']);
            }
            if (isset($itemArray['salesUom'])) {
                $itemArray['sales_uom'] = $itemArray['salesUom'];
                unset($itemArray['salesUom']);
            }
            if (isset($itemArray['taxGroup'])) {
                $itemArray['tax_group'] = $itemArray['taxGroup'];
                unset($itemArray['taxGroup']);
            }
            if (isset($itemArray['itemGroup'])) {
                $itemArray['item_group'] = $itemArray['itemGroup'];
                unset($itemArray['itemGroup']);
            }

            // Transform unit_of_measurements array
            if (isset($itemArray['unit_of_measurements'])) {
                foreach ($itemArray['unit_of_measurements'] as &$uom) {
                    if (isset($uom['pivot']['barcodes'])) {
                        // Barcodes already attached in pivot
                    }
                }
            }

            $cachedItem = $itemArray;

            // Cache for 1 hour (items don't change frequently, but we want fresh data)
            app('cache')->store('database')->put($key, $cachedItem, now()->addHour());
        }

        return response()->json([
            'status' => true,
            'message' => 'Item preview data fetched successfully.',
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

        // Handle attachments - check for actual file uploads first
        if ($request->hasFile('attachments')) {
            $tenantId = tenant('id');

            // Handle file uploads
            $files = is_array($request->file('attachments'))
                ? $request->file('attachments')
                : [$request->file('attachments')];

            // Get attachment metadata from the decoded data if available
            $attachmentMetadata = [];
            if ($request->has('data')) {
                $data = json_decode($request->input('data'), true);
                $attachmentMetadata = $data['attachments'] ?? [];
            }

            foreach ($files as $index => $file) {
                // Skip if file is null, not valid, or not an instance of UploadedFile
                if (! $file || ! $file->isValid()) {
                    continue;
                }

                $path = Storage::disk('public')->putFile(
                    "tenants/{$tenantId}/items/{$item->id}/attachments",
                    $file
                );

                // Find matching metadata for this file
                $metadata = $attachmentMetadata[$index] ?? [];
                $description = $metadata['description'] ?? '';
                $category = $metadata['category'] ?? 'document';
                $isPublic = $metadata['is_public'] ?? true;

                ItemAttachment::create([
                    'item_id' => $item->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => url(Storage::url($path)),
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'description' => $description,
                    'category' => $category,
                    'is_public' => $isPublic,
                ]);
            }
        } elseif ($request->has('attachments')) {
            // Handle JSON attachment data (fallback for frontend compatibility)
            // Only process if attachments is an array (not file uploads)
            $attachments = $request->input('attachments');
            if (is_array($attachments)) {
                foreach ($attachments as $attachmentData) {
                    // Only create attachment if we have a valid file path or file URL
                    $filePath = $attachmentData['file_url'] ?? $attachmentData['file_path'] ?? null;
                    if ($filePath && ! empty(trim($filePath))) {
                        ItemAttachment::create([
                            'item_id' => $item->id,
                            'file_name' => $attachmentData['file_name'] ?? 'Unknown',
                            'file_path' => $filePath,
                            'file_type' => $attachmentData['file_type'] ?? null,
                            'description' => $attachmentData['description'] ?? '',
                            'category' => $attachmentData['category'] ?? 'document',
                            'is_public' => $attachmentData['is_public'] ?? true,
                        ]);
                    }
                }
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_preview_{$item->id}");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_names");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");

        return response()->json([
            'status' => true,
            'message' => 'Item created successfully.',
            'data' => $item->load([
                'trade:id,name',
                'companyCode:id,name',
                'productLine:id,name',
                'category:id,name',
                'brand:id,name',
                'taxGroup:id,code,name,value',
                'itemGroup:id,code,name',
                'baseUom:id,name,unit_group_id',
                'parent:id,code,name',
                'attachments',
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

        // Handle attachments (multipart) - support both 'attachments' and 'attachments[]'
        if ($request->hasFile('attachments') || $request->hasFile('attachments.*')) {
            $tenantId = tenant('id');

            // Get attachment data from JSON (includes existing attachments with IDs + new file metadata)
            // Note: prepareForValidation stores attachments in '_attachment_metadata' before unsetting
            // to avoid validation conflict, so we can access it from there
            $attachmentDataFromJson = [];
            if ($request->has('_attachment_metadata')) {
                // Get from the stored metadata (set by prepareForValidation)
                $attachmentDataFromJson = $request->input('_attachment_metadata', []);
            } elseif ($request->has('data')) {
                // Fallback: try to get from raw data field (if prepareForValidation didn't run)
                $data = json_decode($request->input('data'), true);
                $attachmentDataFromJson = $data['attachments'] ?? [];
            }

            // Separate existing attachments (with IDs) from new file metadata (without IDs)
            $existingAttachmentIds = [];
            $attachmentMetadataMap = [];
            $newFileMetadata = [];

            foreach ($attachmentDataFromJson as $attData) {
                if (isset($attData['id']) && is_numeric($attData['id'])) {
                    // Existing attachment - keep it
                    $existingAttachmentIds[] = $attData['id'];
                    $attachmentMetadataMap[$attData['id']] = $attData;
                } else {
                    // New file metadata (will be matched with uploaded files)
                    $newFileMetadata[] = $attData;
                }
            }

            // Delete attachments that are not in the keep list
            $existingAttachments = $item->attachments;
            foreach ($existingAttachments as $existingAttachment) {
                if (! in_array($existingAttachment->id, $existingAttachmentIds)) {
                    // Delete file from storage
                    $relativePath = str_replace(url('/storage'), '', $existingAttachment->file_path);
                    Storage::disk('public')->delete($relativePath);
                    // Delete attachment record
                    $existingAttachment->delete();
                } else {
                    // Update existing attachment metadata if provided
                    $metadata = $attachmentMetadataMap[$existingAttachment->id] ?? null;
                    if ($metadata) {
                        if (isset($metadata['description'])) {
                            $existingAttachment->description = $metadata['description'];
                        }
                        if (isset($metadata['is_public'])) {
                            $existingAttachment->is_public = $metadata['is_public'];
                        }
                        if (isset($metadata['category'])) {
                            $existingAttachment->category = $metadata['category'];
                        }
                        $existingAttachment->save();
                    }
                }
            }

            // Create new attachments from uploaded files
            // When using attachments[] in FormData, Laravel receives it as attachments.*
            $files = [];
            $fileIdentifiers = []; // Track files by identifier to avoid duplicates

            // Check allFiles() first to get all files, then deduplicate
            $allFiles = $request->allFiles();

            // Collect all files from allFiles() (this is the most reliable source)
            foreach ($allFiles as $key => $file) {
                if (strpos($key, 'attachment') !== false) {
                    $fileArray = is_array($file) ? $file : [$file];
                    foreach ($fileArray as $f) {
                        if ($f && $f->isValid()) {
                            // Use a combination of name and size as identifier to avoid duplicates
                            $identifier = $f->getClientOriginalName().'|'.$f->getSize().'|'.$f->getMimeType();
                            if (! in_array($identifier, $fileIdentifiers)) {
                                $files[] = $f;
                                $fileIdentifiers[] = $identifier;
                            }
                        }
                    }
                }
            }

            // Fallback: If no files found in allFiles(), try direct methods
            if (count($files) === 0) {
                // Check for attachments.* first (array notation from FormData)
                $dot = $request->file('attachments.*');
                if ($dot) {
                    $dotFiles = is_array($dot) ? $dot : [$dot];
                    foreach ($dotFiles as $file) {
                        if ($file && $file->isValid()) {
                            $identifier = $file->getClientOriginalName().'|'.$file->getSize().'|'.$file->getMimeType();
                            if (! in_array($identifier, $fileIdentifiers)) {
                                $files[] = $file;
                                $fileIdentifiers[] = $identifier;
                            }
                        }
                    }
                }

                // Also check for direct 'attachments' (single file or already array)
                $direct = $request->file('attachments');
                if ($direct) {
                    $directFiles = is_array($direct) ? $direct : [$direct];
                    foreach ($directFiles as $file) {
                        if ($file && $file->isValid()) {
                            $identifier = $file->getClientOriginalName().'|'.$file->getSize().'|'.$file->getMimeType();
                            if (! in_array($identifier, $fileIdentifiers)) {
                                $files[] = $file;
                                $fileIdentifiers[] = $identifier;
                            }
                        }
                    }
                }
            }

            // Match uploaded files with metadata (new files come after existing attachments in the array)
            foreach ($files as $index => $file) {
                // Skip if file is null, not valid, or not an instance of UploadedFile
                if (! $file || ! $file->isValid()) {
                    continue;
                }

                $path = Storage::disk('public')->putFile(
                    "tenants/{$tenantId}/items/{$item->id}/attachments",
                    $file
                );

                // Find matching metadata for this file (new files start after existing attachments)
                $metadata = $newFileMetadata[$index] ?? [];
                $description = $metadata['description'] ?? '';
                $category = $metadata['category'] ?? 'document';
                $isPublic = $metadata['is_public'] ?? true;

                ItemAttachment::create([
                    'item_id' => $item->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => url(Storage::url($path)),
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'description' => $description,
                    'category' => $category,
                    'is_public' => $isPublic,
                ]);
            }
        }

        // Handle attachments with new structure (JSON data)
        // Only process if no files were uploaded (files are handled above)
        // and if attachments is an array (not file uploads)
        if ($request->has('attachments') && ! $request->hasFile('attachments') && ! $request->hasFile('attachments.*')) {
            $attachments = $request->input('attachments');
            if (is_array($attachments)) {
                // Get IDs of attachments that should be kept (existing attachments with IDs, not new file uploads)
                $attachmentIdsToKeep = [];
                $attachmentMetadataMap = []; // Map of ID => metadata for updates

                foreach ($attachments as $attachmentData) {
                    // If attachment has an ID, it's an existing attachment that should be kept
                    if (isset($attachmentData['id']) && is_numeric($attachmentData['id'])) {
                        $attachmentIdsToKeep[] = $attachmentData['id'];
                        $attachmentMetadataMap[$attachmentData['id']] = $attachmentData;
                    }
                }

                // Delete attachments that are not in the keep list
                $existingAttachments = $item->attachments;
                foreach ($existingAttachments as $existingAttachment) {
                    if (! in_array($existingAttachment->id, $attachmentIdsToKeep)) {
                        // Delete file from storage
                        $relativePath = str_replace(url('/storage'), '', $existingAttachment->file_path);
                        Storage::disk('public')->delete($relativePath);
                        // Delete attachment record
                        $existingAttachment->delete();
                    } else {
                        // Update existing attachment metadata if provided
                        $metadata = $attachmentMetadataMap[$existingAttachment->id] ?? null;
                        if ($metadata) {
                            if (isset($metadata['description'])) {
                                $existingAttachment->description = $metadata['description'];
                            }
                            if (isset($metadata['is_public'])) {
                                $existingAttachment->is_public = $metadata['is_public'];
                            }
                            if (isset($metadata['category'])) {
                                $existingAttachment->category = $metadata['category'];
                            }
                            $existingAttachment->save();
                        }
                    }
                }

                // Create new attachments from file URLs (if any)
                foreach ($attachments as $attachmentData) {
                    // Skip if this is an existing attachment (has ID)
                    if (isset($attachmentData['id']) && is_numeric($attachmentData['id'])) {
                        continue;
                    }

                    // Only create attachment if we have a valid file path or file URL
                    $filePath = $attachmentData['file_url'] ?? $attachmentData['file_path'] ?? null;
                    if ($filePath && ! empty(trim($filePath))) {
                        ItemAttachment::create([
                            'item_id' => $item->id,
                            'file_name' => $attachmentData['file_name'] ?? 'Unknown',
                            'file_path' => $filePath,
                            'file_type' => $attachmentData['file_type'] ?? null,
                            'description' => $attachmentData['description'] ?? '',
                            'category' => $attachmentData['category'] ?? 'document',
                            'is_public' => $attachmentData['is_public'] ?? true,
                        ]);
                    }
                }
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_preview_{$item->id}");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_names");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");

        return response()->json([
            'status' => true,
            'message' => 'Item updated successfully.',
            'data' => $item->load([
                'trade:id,name',
                'companyCode:id,name',
                'productLine:id,name',
                'category:id,name',
                'brand:id,name',
                'taxGroup:id,code,name,value',
                'itemGroup:id,code,name',
                'baseUom:id,name,unit_group_id',
                'parent:id,code,name',
                'attachments',
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
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_preview_{$item->id}");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_names");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");

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
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_names");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");

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
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_names");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");

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
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_preview_{$item->id}");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_names");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");

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
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_preview_{$item->id}");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_names");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");

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

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

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

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Supplier pivot updated.',
            'data' => $item->suppliers()->where('suppliers.id', $supplier->id)->first(),
        ]);
    }

    public function detachSuppliers(DetachSuppliersRequest $request, Item $item)
    {
        $item->suppliers()->detach($request->validated()['supplier_ids']);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

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

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Primary supplier set.',
        ]);
    }

    public function getUnitOfMeasurements(Item $item)
    {
        // Eager load barcodes for each UOM pivot
        $uoms = $item->unitOfMeasurements()
            ->with('unitGroup')
            ->orderBy('name')
            ->get();

        // Load barcodes for each UOM and attach to pivot
        $uoms->each(function ($uom) {
            $pivot = $uom->pivot;
            // Get barcodes from dedicated table
            $barcodes = \App\Models\ItemBarcode::where('item_unit_of_measurement_id', $pivot->id)
                ->pluck('barcode')
                ->toArray();
            // Attach barcodes to pivot as array (for frontend compatibility)
            $pivot->barcodes = $barcodes;
        });

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

            // Save UOM pivot data (without barcodes)
            $itemUom = ItemUnitOfMeasurement::updateOrCreate(
                [
                    'item_id' => $item->id,
                    'unit_of_measurement_id' => $row['unit_of_measurement_id'],
                ],
                [
                    'operation' => $row['operation'],
                    'conversion' => $row['conversion'],
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

            // Sync barcodes to item_barcodes (never to item_unit_of_measurement). Replace any existing for this pivot.
            $barcodes = is_array($row['barcodes'] ?? null) ? $row['barcodes'] : [];
            \App\Models\ItemBarcode::where('item_unit_of_measurement_id', $itemUom->id)->delete();
            $barcodesToInsert = [];
            foreach ($barcodes as $index => $barcodeValue) {
                $v = is_scalar($barcodeValue) ? trim((string) $barcodeValue) : '';
                if ($v !== '') {
                    $barcodesToInsert[] = [
                        'item_id' => $item->id,
                        'item_unit_of_measurement_id' => $itemUom->id,
                        'barcode' => $v,
                        'is_primary' => count($barcodesToInsert) === 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            if (! empty($barcodesToInsert)) {
                \App\Models\ItemBarcode::insert($barcodesToInsert);
            }
        }

        $result = $item->unitOfMeasurements()->with('unitGroup')->get();

        // Load barcodes for each UOM and attach to pivot
        $result->each(function ($uom) {
            $pivot = $uom->pivot;
            // Get barcodes from dedicated table
            $barcodes = \App\Models\ItemBarcode::where('item_unit_of_measurement_id', $pivot->id)
                ->pluck('barcode')
                ->toArray();
            // Attach barcodes to pivot as array (for frontend compatibility)
            $pivot->barcodes = $barcodes;
        });

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

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

        // Extract barcodes (saved to item_barcodes, not pivot) and remove from pivot data
        $barcodes = is_array($data['barcodes'] ?? null) ? $data['barcodes'] : [];
        unset($data['barcodes']);

        // Update or create the pivot (no barcode columns)
        $itemUom = ItemUnitOfMeasurement::updateOrCreate(
            [
                'item_id' => $item->id,
                'unit_of_measurement_id' => $unitOfMeasurement->id,
            ],
            $data
        );

        // Sync barcodes to item_barcodes. Replace any existing for this pivot.
        \App\Models\ItemBarcode::where('item_unit_of_measurement_id', $itemUom->id)->delete();
        $barcodesToInsert = [];
        foreach ($barcodes as $barcodeValue) {
            $v = is_scalar($barcodeValue) ? trim((string) $barcodeValue) : '';
            if ($v !== '') {
                $barcodesToInsert[] = [
                    'item_id' => $item->id,
                    'item_unit_of_measurement_id' => $itemUom->id,
                    'barcode' => $v,
                    'is_primary' => count($barcodesToInsert) === 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        if (! empty($barcodesToInsert)) {
            \App\Models\ItemBarcode::insert($barcodesToInsert);
        }

        $uom = $item->unitOfMeasurements()
            ->where('unit_of_measurements.id', $unitOfMeasurement->id)
            ->with('unitGroup')
            ->first();

        // Load barcodes and attach to pivot
        if ($uom && $uom->pivot) {
            $barcodes = \App\Models\ItemBarcode::where('item_unit_of_measurement_id', $uom->pivot->id)
                ->pluck('barcode')
                ->toArray();
            $uom->pivot->barcodes = $barcodes;
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Unit of measurement pivot updated successfully.',
            'data' => $uom,
        ]);
    }

    public function detachUOM(DetachUOMRequest $request, Item $item)
    {
        $item->unitOfMeasurements()->detach($request->validated()['unit_of_measurement_ids']);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

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
                'taxGroup:id,code,name,value',
                'itemGroup:id,code,name',
                'baseUom:id,name,unit_group_id',
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
            if (isset($itemArray['taxGroup'])) {
                $itemArray['tax_group'] = $itemArray['taxGroup'];
                unset($itemArray['taxGroup']);
            }
            if (isset($itemArray['itemGroup'])) {
                $itemArray['item_group'] = $itemArray['itemGroup'];
                unset($itemArray['itemGroup']);
            }

            return $itemArray;
        });

        return response()->json([
            'status' => true,
            'message' => 'All items fetched successfully.',
            'data' => $transformedItems,
        ]);
    }

    /**
     * Get item data optimized for invoice line entry.
     * Returns item details with UOMs, prices based on customer's price_choice, barcodes, and tax info.
     *
     * @param  int  $itemId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getItemForInvoice($itemId, Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
        ]);

        $item = Item::with([
            'taxGroup:id,code,name,value',
            'itemGroup:id,code,name',
            'baseUom:id,name,unit_group_id',
            'unitOfMeasurements:id,name',
        ])->find($itemId);

        if (! $item) {
            return response()->json([
                'status' => false,
                'message' => 'Item not found.',
            ], 404);
        }

        // Get customer's price choice
        $customer = \App\Models\Customer::find($request->customer_id);
        $priceChoice = $customer->price_choice ?? 'price1';

        // Map price choice to column name
        $priceColumn = match ($priceChoice) {
            'price1' => 'price_1',
            'price2' => 'price_2',
            'price3' => 'price_3',
            'price4' => 'price_4',
            'price5' => 'price_5',
            'price6' => 'price_6',
            'last_invoice_price' => 'price_1', // Default to price_1 for now
            default => 'price_1',
        };

        // Process UOMs with prices, barcodes, and calculations
        // Load unitOfMeasurements with pivot data
        $item->load('unitOfMeasurements');

        // Get all item_unit_of_measurement IDs to load barcodes efficiently
        // Try to get pivot IDs, if not available, query pivot table directly
        $itemUomIds = [];
        foreach ($item->unitOfMeasurements as $uom) {
            $pivotId = $uom->pivot->id ?? $uom->pivot->getKey();
            // If still not available, query pivot table directly
            if (! $pivotId) {
                $pivot = \App\Models\ItemUnitOfMeasurement::where('item_id', $item->id)
                    ->where('unit_of_measurement_id', $uom->id)
                    ->first();
                $pivotId = $pivot?->id;
            }
            if ($pivotId) {
                $itemUomIds[] = $pivotId;
            }
        }

        $barcodesByItemUomId = [];
        if (! empty($itemUomIds)) {
            $allBarcodes = \App\Models\ItemBarcode::whereIn('item_unit_of_measurement_id', $itemUomIds)->get();
            foreach ($allBarcodes as $barcode) {
                $barcodesByItemUomId[$barcode->item_unit_of_measurement_id][] = $barcode->barcode;
            }
        }

        $unitOfMeasurements = $item->unitOfMeasurements->map(function ($uom) use ($priceColumn, $item, $barcodesByItemUomId) {
            $pivot = $uom->pivot;
            $price = $pivot->{$priceColumn} ?? 0;
            $conversion = $pivot->conversion ?? 1;

            // Calculate unit price: price / conversion
            $unitPrice = $conversion > 0 ? $price / $conversion : $price;

            // Get pivot ID (try multiple methods)
            $pivotId = $pivot->id ?? $pivot->getKey();
            if (! $pivotId) {
                // Fallback: query pivot table directly
                $pivotModel = \App\Models\ItemUnitOfMeasurement::where('item_id', $item->id)
                    ->where('unit_of_measurement_id', $uom->id)
                    ->first();
                $pivotId = $pivotModel?->id;
            }

            // Get barcodes from dedicated table (using pre-loaded data)
            $barcodes = $pivotId ? ($barcodesByItemUomId[$pivotId] ?? []) : [];

            return [
                'id' => $uom->id,
                'name' => $uom->name,
                'conversion' => (float) $conversion,
                'operation' => $pivot->operation,
                'barcodes' => $barcodes,
                'price' => (float) $price,
                'unit_price' => round($unitPrice, 2),
                'is_base_uom' => $uom->id === $item->base_uom_id,
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Item data retrieved successfully.',
            'data' => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'sales_description' => $item->sales_description,
                'base_uom_id' => $item->base_uom_id,
                'tax' => $item->taxGroup ? [
                    'id' => $item->taxGroup->id,
                    'code' => $item->taxGroup->code,
                    'name' => $item->taxGroup->name,
                    'value' => (float) $item->taxGroup->value,
                ] : null,
                'unit_of_measurements' => $unitOfMeasurements,
            ],
        ]);
    }

    /**
     * Find item by barcode for invoice entry
     * Searches through all item_unit_of_measurement barcodes
     *
     * @return JsonResponse
     */
    public function getItemByBarcode(Request $request)
    {
        $request->validate([
            'barcode' => 'required|string|max:255',
            'customer_id' => 'required|exists:customers,id',
        ]);

        $barcode = trim($request->barcode);
        $customerId = $request->customer_id;

        // Search for barcode in dedicated table (fast indexed lookup)
        $itemBarcode = \App\Models\ItemBarcode::where('barcode', $barcode)->first();

        if (! $itemBarcode) {
            return response()->json([
                'status' => false,
                'message' => 'Item not found for this barcode.',
            ], 404);
        }

        // Get the item_unit_of_measurement from the barcode record
        $itemUom = \App\Models\ItemUnitOfMeasurement::where('id', $itemBarcode->item_unit_of_measurement_id)
            ->first();

        if (! $itemUom) {
            return response()->json([
                'status' => false,
                'message' => 'Item unit of measurement not found for this barcode.',
            ], 404);
        }

        // Load the item with all necessary relationships
        $item = Item::with([
            'taxGroup:id,code,name,value',
            'itemGroup:id,code,name',
            'baseUom:id,name,unit_group_id',
            'unitOfMeasurements:id,name',
        ])->find($itemUom->item_id);

        if (! $item) {
            return response()->json([
                'status' => false,
                'message' => 'Item not found.',
            ], 404);
        }

        // Get customer's price choice
        $customer = \App\Models\Customer::find($customerId);
        $priceChoice = $customer->price_choice ?? 'price1';

        // Map price choice to column name
        $priceColumn = match ($priceChoice) {
            'price1' => 'price_1',
            'price2' => 'price_2',
            'price3' => 'price_3',
            'price4' => 'price_4',
            'price5' => 'price_5',
            'price6' => 'price_6',
            'last_invoice_price' => 'price_1',
            default => 'price_1',
        };

        // Get the matched UOM
        $matchedUomId = $itemUom->unit_of_measurement_id;
        $matchedUom = $item->unitOfMeasurements->find($matchedUomId);

        if (! $matchedUom) {
            return response()->json([
                'status' => false,
                'message' => 'UOM not found for this barcode.',
            ], 404);
        }

        // Get price and conversion from the matched itemUom
        $price = $itemUom->{$priceColumn} ?? 0;
        $conversion = $itemUom->conversion ?? 1;
        $unitPrice = $conversion > 0 ? $price / $conversion : $price;

        // Get all UOMs for the item (for dropdown) - similar to getItemForInvoice
        // Reload item with unitOfMeasurements relationship to get pivot data
        $item->load('unitOfMeasurements');

        // Get all item_unit_of_measurement IDs to load barcodes efficiently
        // Try to get pivot IDs, if not available, query pivot table directly
        $itemUomIds = [];
        foreach ($item->unitOfMeasurements as $uom) {
            $pivotId = $uom->pivot->id ?? $uom->pivot->getKey();
            // If still not available, query pivot table directly
            if (! $pivotId) {
                $pivot = \App\Models\ItemUnitOfMeasurement::where('item_id', $item->id)
                    ->where('unit_of_measurement_id', $uom->id)
                    ->first();
                $pivotId = $pivot?->id;
            }
            if ($pivotId) {
                $itemUomIds[] = $pivotId;
            }
        }

        $barcodesByItemUomId = [];
        if (! empty($itemUomIds)) {
            $allBarcodes = \App\Models\ItemBarcode::whereIn('item_unit_of_measurement_id', $itemUomIds)->get();
            foreach ($allBarcodes as $barcode) {
                $barcodesByItemUomId[$barcode->item_unit_of_measurement_id][] = $barcode->barcode;
            }
        }

        $unitOfMeasurements = $item->unitOfMeasurements->map(function ($uom) use ($priceColumn, $item, $barcodesByItemUomId) {
            $pivot = $uom->pivot;
            $price = $pivot->{$priceColumn} ?? 0;
            $conversion = $pivot->conversion ?? 1;
            $unitPrice = $conversion > 0 ? $price / $conversion : $price;

            // Get pivot ID (try multiple methods)
            $pivotId = $pivot->id ?? $pivot->getKey();
            if (! $pivotId) {
                // Fallback: query pivot table directly
                $pivotModel = \App\Models\ItemUnitOfMeasurement::where('item_id', $item->id)
                    ->where('unit_of_measurement_id', $uom->id)
                    ->first();
                $pivotId = $pivotModel?->id;
            }

            // Get barcodes from dedicated table (using pre-loaded data)
            $barcodes = $pivotId ? ($barcodesByItemUomId[$pivotId] ?? []) : [];

            return [
                'id' => $uom->id,
                'name' => $uom->name,
                'conversion' => (float) $conversion,
                'operation' => $pivot->operation,
                'barcodes' => $barcodes,
                'price' => (float) $price,
                'unit_price' => round($unitPrice, 2),
                'is_base_uom' => $uom->id === $item->base_uom_id,
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Item found by barcode.',
            'data' => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'sales_description' => $item->sales_description,
                'base_uom_id' => $item->base_uom_id,
                'tax' => $item->taxGroup ? [
                    'id' => $item->taxGroup->id,
                    'code' => $item->taxGroup->code,
                    'name' => $item->taxGroup->name,
                    'value' => (float) $item->taxGroup->value,
                ] : null,
                'unit_of_measurements' => $unitOfMeasurements,
                // Include the matched UOM for auto-selection
                'matched_uom' => [
                    'id' => $matchedUom->id,
                    'name' => $matchedUom->name,
                    'barcode' => $barcode,
                    'price' => (float) $price,
                    'unit_price' => round($unitPrice, 2),
                    'conversion' => (float) $conversion,
                ],
            ],
        ]);
    }

    /**
     * Find item by code for invoice entry
     *
     * @return JsonResponse
     */
    public function getItemByCode(Request $request)
    {
        $request->validate([
            'item_code' => 'required|string|max:255',
            'customer_id' => 'required|exists:customers,id',
        ]);

        $itemCode = trim($request->item_code);
        $customerId = $request->customer_id;

        // First try exact match
        $item = Item::with([
            'taxGroup:id,code,name,value',
            'itemGroup:id,code,name',
            'baseUom:id,name,unit_group_id',
            'unitOfMeasurements:id,name',
        ])->whereRaw('LOWER(code) = LOWER(?)', [$itemCode])->first();

        // If exact match not found, try partial match and return multiple results
        if (! $item) {
            $items = Item::with([
                'taxGroup:id,code,name,value',
                'itemGroup:id,code,name',
                'baseUom:id,name,unit_group_id',
                'unitOfMeasurements:id,name',
            ])->whereRaw('LOWER(code) LIKE LOWER(?)', ['%'.$itemCode.'%'])
                ->limit(50) // Limit to prevent too many results
                ->get();

            // If multiple matches found, return them for user selection
            if ($items->count() > 1) {
                return response()->json([
                    'status' => true,
                    'message' => 'Multiple items found.',
                    'multiple' => true,
                    'data' => $items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'code' => $item->code,
                            'name' => $item->name,
                        ];
                    })->toArray(),
                ]);
            }

            // If single match found from partial search, use it
            if ($items->count() === 1) {
                $item = $items->first();
            } else {
                // No matches found
                return response()->json([
                    'status' => false,
                    'message' => 'Item not found for this code.',
                ], 404);
            }
        }

        // Get customer's price choice
        $customer = \App\Models\Customer::find($customerId);
        $priceChoice = $customer->price_choice ?? 'price1';

        // Map price choice to column name
        $priceColumn = match ($priceChoice) {
            'price1' => 'price_1',
            'price2' => 'price_2',
            'price3' => 'price_3',
            'price4' => 'price_4',
            'price5' => 'price_5',
            'price6' => 'price_6',
            'last_invoice_price' => 'price_1',
            default => 'price_1',
        };

        // Get all UOMs for the item (for dropdown)
        $item->load('unitOfMeasurements');

        $itemUomIds = [];
        foreach ($item->unitOfMeasurements as $uom) {
            $pivotId = $uom->pivot->id ?? $uom->pivot->getKey();
            if (! $pivotId) {
                $pivot = \App\Models\ItemUnitOfMeasurement::where('item_id', $item->id)
                    ->where('unit_of_measurement_id', $uom->id)
                    ->first();
                $pivotId = $pivot?->id;
            }
            if ($pivotId) {
                $itemUomIds[] = $pivotId;
            }
        }

        $barcodesByItemUomId = [];
        if (! empty($itemUomIds)) {
            $allBarcodes = \App\Models\ItemBarcode::whereIn('item_unit_of_measurement_id', $itemUomIds)->get();
            foreach ($allBarcodes as $barcode) {
                $barcodesByItemUomId[$barcode->item_unit_of_measurement_id][] = $barcode->barcode;
            }
        }

        $unitOfMeasurements = $item->unitOfMeasurements->map(function ($uom) use ($priceColumn, $item, $barcodesByItemUomId) {
            $pivot = $uom->pivot;
            $price = $pivot->{$priceColumn} ?? 0;
            $conversion = $pivot->conversion ?? 1;
            $unitPrice = $conversion > 0 ? $price / $conversion : $price;

            $pivotId = $pivot->id ?? $pivot->getKey();
            $barcodes = $barcodesByItemUomId[$pivotId] ?? [];

            return [
                'id' => $uom->id,
                'name' => $uom->name,
                'conversion' => (float) $conversion,
                'operation' => $pivot->operation,
                'barcodes' => $barcodes,
                'price' => (float) $price,
                'unit_price' => round($unitPrice, 2),
                'is_base_uom' => $uom->id === $item->base_uom_id,
            ];
        });

        // Auto-select the base UOM if available
        $defaultUom = $unitOfMeasurements->firstWhere('is_base_uom');
        if (! $defaultUom && $unitOfMeasurements->isNotEmpty()) {
            $defaultUom = $unitOfMeasurements->first(); // Fallback to first UOM
        }

        return response()->json([
            'status' => true,
            'message' => 'Item found by code.',
            'data' => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'sales_description' => $item->sales_description,
                'base_uom_id' => $item->base_uom_id,
                'tax' => $item->taxGroup ? [
                    'id' => $item->taxGroup->id,
                    'code' => $item->taxGroup->code,
                    'name' => $item->taxGroup->name,
                    'value' => (float) $item->taxGroup->value,
                ] : null,
                'unit_of_measurements' => $unitOfMeasurements,
                'matched_uom' => $defaultUom, // Include the matched UOM for auto-selection
            ],
        ]);
    }

    /**
     * Search items by barcode (partial match) for help grid
     * Returns list of items with their barcodes, UOMs, and item names
     *
     * @return JsonResponse
     */
    public function searchItemsByBarcode(Request $request)
    {
        $request->validate([
            'barcode' => 'nullable|string|max:255',
            'customer_id' => 'required|exists:customers,id',
        ]);

        $barcodeSearch = trim($request->barcode ?? '');
        $customerId = $request->customer_id;

        // Get customer's price choice for future use
        $customer = \App\Models\Customer::find($customerId);
        $priceChoice = $customer->price_choice ?? 'price1';

        // Map price choice to column name
        $priceColumn = match ($priceChoice) {
            'price1' => 'price_1',
            'price2' => 'price_2',
            'price3' => 'price_3',
            'price4' => 'price_4',
            'price5' => 'price_5',
            'price6' => 'price_6',
            'last_invoice_price' => 'price_1',
            default => 'price_1',
        };

        // Query barcodes with partial match
        $query = \App\Models\ItemBarcode::with([
            'itemUnitOfMeasurement.item:id,code,name',
            'itemUnitOfMeasurement.unitOfMeasurement:id,name',
        ]);

        if ($barcodeSearch) {
            $query->where('barcode', 'LIKE', '%'.$barcodeSearch.'%');
        }

        // Limit results to prevent too many
        $itemBarcodes = $query->limit(100)->get();

        // Transform data to include: id, item_name, uom_name, barcode
        $results = $itemBarcodes->map(function ($itemBarcode) {
            $itemUom = $itemBarcode->itemUnitOfMeasurement;
            if (! $itemUom) {
                return;
            }

            $item = $itemUom->item;
            $uom = $itemUom->unitOfMeasurement;

            if (! $item || ! $uom) {
                return;
            }

            return [
                'id' => $itemBarcode->id, // Use barcode ID as unique identifier (each barcode has unique ID)
                'item_id' => $item->id, // Item ID for reference
                'item_name' => $item->name,
                'uom_name' => $uom->name,
                'barcode' => $itemBarcode->barcode,
                'uom_id' => $uom->id, // UOM ID for selection
                'item_uom_id' => $itemUom->id, // ItemUnitOfMeasurement ID for fetching full data
            ];
        })->filter()->values(); // Remove nulls and reindex

        return response()->json([
            'status' => true,
            'message' => 'Items found by barcode.',
            'data' => $results,
        ]);
    }
}
