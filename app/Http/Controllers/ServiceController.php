<?php

namespace App\Http\Controllers;

use App\Enums\ItemType;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Requests\UploadServiceAttachmentsRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Item;
use App\Models\Service;
use App\Models\ServiceAttachment;
use App\Models\ServiceNeededItem;
use App\Services\AppointmentRestrictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Service::query()
            ->select(['id', 'name', 'service_category_id', 'normal_price', 'cost_price', 'service_color', 'active', 'duration_minutes', 'hour_capacity'])
            ->with(['serviceCategory:id,name']);

        if ($request->filled('category_id')) {
            $query->where('service_category_id', $request->integer('category_id'));
        }
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        $services = $query->orderBy('name')->paginate(10);

        // Check capacity if date/time is provided
        $startAt = $request->input('start_at');
        $endAt = $request->input('end_at');
        $excludeAppointmentId = $request->input('exclude_appointment_id');

        // Hide raw FK id but keep category
        $services->getCollection()->transform(function ($service) use ($startAt, $endAt, $excludeAppointmentId) {
            // Format needed items to show codes
            $service->needed_items = $service->neededItems->map(function ($neededItem) {
                return $neededItem->item ? $neededItem->item->code : null;
            })->filter()->values()->toArray();

            // Check capacity if date/time provided
            $service->capacity_reached = false;
            if ($startAt && $endAt && $service->hour_capacity && $service->hour_capacity > 0) {
                $service->capacity_reached = $this->checkServiceCapacityReached(
                    $service->id,
                    $service->hour_capacity,
                    $startAt,
                    $endAt,
                    $excludeAppointmentId
                );
            }

            return $service->makeHidden(['service_category_id', 'neededItems']);
        });

        return response()->json($services);
    }

    /**
     * Lightweight list for service pricing (e.g. specialist drawer): id and name only.
     */
    public function listNames(): JsonResponse
    {
        try {
            $services = Service::select('id', 'name')
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);

            return response()->json([
                'status' => 'success',
                'message' => 'Service names retrieved successfully',
                'data' => $services,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve service names',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if service has reached its hour capacity
     */
    protected function checkServiceCapacityReached(int $serviceId, int $capacity, string $startAt, string $endAt, ?int $excludeAppointmentId = null): bool
    {
        $restrictionService = app(AppointmentRestrictionService::class);
        $error = $restrictionService->checkServiceHourCapacity($serviceId, $startAt, $endAt, $excludeAppointmentId);

        return $error !== null;
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $specialistIds = $data['specialist_ids'] ?? [];
        $assetIds = $data['asset_ids'] ?? [];
        unset($data['specialist_ids'], $data['asset_ids']);

        $nextId = $this->computeNextAvailableId(Service::class, 'id');
        $service = new Service($data);
        $service->id = $nextId;
        $service->save();

        // Create linked Item for invoice purposes
        $itemCode = 'SVC-'.$service->id;
        // Ensure code is unique
        $counter = 1;
        while (Item::where('code', $itemCode)->exists()) {
            $itemCode = 'SVC-'.$service->id.'-'.$counter;
            $counter++;
        }

        $item = Item::create([
            'code' => $itemCode,
            'name' => $service->name,
            'type' => ItemType::SERVICE,
            'description' => null,
        ]);
        $service->item_id = $item->id;
        $service->save();

        // Clear item caches since we created a new item
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        if (! empty($specialistIds)) {
            $service->specialists()->sync($specialistIds);
        }
        if (! empty($assetIds)) {
            $service->assets()->sync($assetIds);
        }

        // Handle attachments - check for actual file uploads first
        if ($request->hasFile('attachments') || $request->hasFile('attachments.*')) {
            $tenantId = tenant('id');

            // Handle file uploads
            $files = [];
            if ($request->hasFile('attachments.*')) {
                $files = $request->file('attachments.*');
                if (! is_array($files)) {
                    $files = [$files];
                }
            } elseif ($request->hasFile('attachments')) {
                $file = $request->file('attachments');
                $files = is_array($file) ? $file : [$file];
            }

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
                    "tenants/{$tenantId}/services/{$service->id}/attachments",
                    $file
                );

                // Find matching metadata for this file
                $metadata = $attachmentMetadata[$index] ?? [];
                $description = $metadata['description'] ?? '';
                $category = $metadata['category'] ?? 'document';
                $isPublic = $metadata['is_public'] ?? true;

                ServiceAttachment::create([
                    'service_id' => $service->id,
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

        $service->load(['serviceCategory:id,name,description', 'specialists:id,name', 'assets:id,name', 'item:id,code,name', 'attachments']);

        return response()->json([
            'status' => true,
            'message' => 'Service created successfully.',
            'data' => $service,
        ], 201);
    }

    public function show(Service $service): JsonResponse
    {
        $loaded = $service->load([
            'serviceCategory:id,name,description,department_id',
            'serviceCategory.department:id,name,code,sub_department_of',
            'serviceCategory.department.parent:id,name,code',
            'specialists:id,name',
            'assets:id,name',
            'attachments',
        ]);

        // Attach needed items (with item and base UOM)
        $neededItems = ServiceNeededItem::with(['item:id,code,name,base_uom_id', 'item.baseUom:id,name'])
            ->where('service_id', $service->id)
            ->get()
            ->map(function ($neededItem) {
                return [
                    'id' => $neededItem->id,
                    'item_id' => $neededItem->item_id,
                    'item_code' => $neededItem->item->code ?? '',
                    'item_name' => $neededItem->item->name ?? '',
                    'description' => $neededItem->description ?? '',
                    'unit' => $neededItem->item->baseUom->name ?? '',
                    'quantity' => $neededItem->quantity,
                ];
            });
        $loaded->setRelation('needed_items', $neededItems);

        // Hide raw IDs but keep related objects
        $loaded->makeHidden(['service_category_id']);

        return response()->json([
            'status' => true,
            'message' => 'Service details fetched successfully.',
            'data' => $loaded,
        ]);
    }

    /**
     * Upload attachments for a service (dedicated endpoint for two-step save).
     */
    public function uploadAttachments(UploadServiceAttachmentsRequest $request, Service $service): JsonResponse
    {
        $tenantId = tenant('id');
        $files = $request->file('attachments');
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }
        $metadata = [];
        if ($request->has('data')) {
            $decoded = json_decode($request->input('data'), true);
            $metadata = $decoded['attachments'] ?? $decoded ?? [];
        }
        $created = [];
        foreach ($files as $index => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $path = Storage::disk('public')->putFile(
                "tenants/{$tenantId}/services/{$service->id}/attachments",
                $file
            );
            $meta = $metadata[$index] ?? [];
            $attachment = ServiceAttachment::create([
                'service_id' => $service->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => url(Storage::url($path)),
                'file_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'description' => $meta['description'] ?? '',
                'category' => $meta['category'] ?? 'document',
                'is_public' => $meta['is_public'] ?? true,
            ]);
            $created[] = $attachment;
        }

        return response()->json([
            'status' => true,
            'message' => 'Attachments uploaded successfully.',
            'data' => $created,
        ]);
    }

    /**
     * Get attachments for a service.
     */
    public function getAttachments(Service $service): JsonResponse
    {
        $attachments = $service->attachments()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Attachments fetched successfully.',
            'data' => $attachments,
        ]);
    }

    /**
     * Delete a service attachment.
     */
    public function deleteAttachment(Service $service, ServiceAttachment $attachment): JsonResponse
    {
        if ((int) $attachment->service_id !== (int) $service->id) {
            return response()->json([
                'status' => false,
                'message' => 'Attachment does not belong to this service.',
            ], 403);
        }
        $filePath = str_replace(url('storage/'), '', $attachment->file_path);
        $filePath = str_replace(url('/storage/'), '', $filePath);
        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
        $attachment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Attachment deleted successfully.',
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $data = $request->validated();
        $specialistIds = $data['specialist_ids'] ?? null;
        $assetIds = $data['asset_ids'] ?? null;
        unset($data['specialist_ids'], $data['asset_ids']);

        $service->update($data);

        // Update linked Item if it exists
        if ($service->item_id && $service->item) {
            $service->item->update([
                'name' => $service->name,
            ]);

            // Clear item caches since we updated the item
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_items");
            app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
            app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");
            app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$service->item->id}");
        }

        if (is_array($specialistIds)) {
            $service->specialists()->sync($specialistIds);
        }
        if (is_array($assetIds)) {
            $service->assets()->sync($assetIds);
        }

        // Handle attachments (multipart) - support both 'attachments' and 'attachments[]'
        if ($request->hasFile('attachments') || $request->hasFile('attachments.*')) {
            $tenantId = tenant('id');

            // Get existing attachments from request data (if provided)
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
            $existingAttachments = $service->attachments;
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
                        // Use array_key_exists to allow empty strings (isset returns true for empty strings, but this is more explicit)
                        if (array_key_exists('description', $metadata)) {
                            $existingAttachment->description = $metadata['description'] ?? '';
                        }
                        if (array_key_exists('is_public', $metadata)) {
                            $existingAttachment->is_public = $metadata['is_public'];
                        }
                        if (array_key_exists('category', $metadata)) {
                            $existingAttachment->category = $metadata['category'];
                        }
                        $existingAttachment->save();
                    }
                }
            }

            // Create new attachments from uploaded files
            $files = [];
            $fileIdentifiers = [];

            // Check allFiles() first to get all files, then deduplicate
            $allFiles = $request->allFiles();

            foreach ($allFiles as $key => $file) {
                if (strpos($key, 'attachment') !== false) {
                    $fileArray = is_array($file) ? $file : [$file];
                    foreach ($fileArray as $f) {
                        if ($f && $f->isValid()) {
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
                if ($request->hasFile('attachments.*')) {
                    $dotFiles = $request->file('attachments.*');
                    $dotFiles = is_array($dotFiles) ? $dotFiles : [$dotFiles];
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

                if ($request->hasFile('attachments')) {
                    $directFiles = $request->file('attachments');
                    $directFiles = is_array($directFiles) ? $directFiles : [$directFiles];
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
                if (! $file || ! $file->isValid()) {
                    continue;
                }

                $path = Storage::disk('public')->putFile(
                    "tenants/{$tenantId}/services/{$service->id}/attachments",
                    $file
                );

                // Find matching metadata for this file (new files start after existing attachments)
                $metadata = $newFileMetadata[$index] ?? [];
                $description = $metadata['description'] ?? '';
                $category = $metadata['category'] ?? 'document';
                $isPublic = $metadata['is_public'] ?? true;

                ServiceAttachment::create([
                    'service_id' => $service->id,
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
                $attachmentMetadataMap = [];

                foreach ($attachments as $attachmentData) {
                    if (isset($attachmentData['id']) && is_numeric($attachmentData['id'])) {
                        $attachmentIdsToKeep[] = $attachmentData['id'];
                        $attachmentMetadataMap[$attachmentData['id']] = $attachmentData;
                    }
                }

                // Delete attachments that are not in the keep list
                $existingAttachments = $service->attachments;
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
                            // Use array_key_exists to allow empty strings (isset returns true for empty strings, but this is more explicit)
                            if (array_key_exists('description', $metadata)) {
                                $existingAttachment->description = $metadata['description'] ?? '';
                            }
                            if (array_key_exists('is_public', $metadata)) {
                                $existingAttachment->is_public = $metadata['is_public'];
                            }
                            if (array_key_exists('category', $metadata)) {
                                $existingAttachment->category = $metadata['category'];
                            }
                            $existingAttachment->save();
                        }
                    }
                }
            }
        }

        $service->load(['serviceCategory:id,name,description', 'specialists:id,name', 'assets:id,name', 'item:id,code,name', 'attachments']);

        return response()->json([
            'status' => true,
            'message' => 'Service updated successfully.',
            'data' => $service,
        ]);
    }

    public function destroy(Service $service): JsonResponse
    {
        $identifier = $service->name ?? "ID: {$service->id}";
        $details = [];

        // Check if service has appointments through pivot table
        $appointmentsCount = DB::table('appointment_service')
            ->where('service_id', $service->id)
            ->distinct('appointment_id')
            ->count('appointment_id');

        if ($appointmentsCount > 0) {
            $sampleAppointmentId = DB::table('appointment_service')
                ->where('service_id', $service->id)
                ->select('appointment_id')
                ->first()?->appointment_id;

            $details['appointments'] = [
                'count' => $appointmentsCount,
                'sample_ids' => $sampleAppointmentId ? [$sampleAppointmentId] : [],
            ];
        }

        if (! empty($details)) {
            return response()->json([
                'status' => false,
                'message' => "Cannot delete service \"{$identifier}\" (ID: {$service->id}). It is referenced by existing appointments.",
                'details' => $details,
            ], 409);
        }

        // Delete linked Item if it exists
        $itemId = $service->item_id;
        if ($itemId && $service->item) {
            $service->item->delete();
        }

        $service->delete();

        // Clear item caches since we deleted the item
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");
        if ($itemId) {
            app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$itemId}");
        }

        return response()->json(['message' => 'Deleted']);
    }

    public function exportExcell()
    {
        $services = Service::query()->with([
            'serviceCategory:id,name',
            'specialists:id,name',
            'assets:id,name',
        ]);
        $collection = $services->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No services to export',
            ], 404);
        }

        $columns = [
            'id',
            'name',
            'serviceCategory.name',
            'result_after_days',
            'needs_specialist',
            'needs_asset',
            'duration_minutes',
            'hour_capacity',
            'normal_price',
            'vip_price',
            'price_in_group',
            'price_calculated_by_hour',
            'hour_price',
            'cost_price',
            'birthday_price',
            'wedding_price',
            'service_color',
            'service_sex',
            'active',
            'specialists.*.name',
            'assets.*.name',
            'created_at',
            'updated_at',
        ];

        $headings = [
            'ID', 'Name', 'Service Category',             'Result After Days', 'Needs Specialist', 'Needs Asset', 'Duration (min)', 'Hour Capacity',
            'Normal Price', 'VIP Price', 'Price In Group',
            'Price Calculated by Hour', 'Hour Price', 'Cost Price', 'Birthday Price', 'Wedding Price',
            'Service Color', 'Service Sex', 'Active', 'Specialists', 'Assets',
            'Created At', 'Updated At',
        ];

        $fileName = 'services_'.'.xlsx';

        return Excel::download(new Export($services, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $services = Service::query()
            ->with([
                'serviceCategory:id,name',
                'specialists:id,name',
                'assets:id,name',
            ])
            ->get([
                'id', 'name', 'service_category_id', 'result_after_days', 'needs_specialist', 'needs_asset',
                'duration_minutes', 'hour_capacity', 'normal_price', 'vip_price', 'price_in_group',
                'price_calculated_by_hour', 'hour_price', 'cost_price', 'birthday_price', 'wedding_price',
                'service_color', 'service_sex', 'active', 'created_at', 'updated_at',
            ]);

        if ($services->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No services to export',
            ], 404);
        }

        $title = 'Services Report';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'serviceCategory.name' => 'Service Category',
            'result_after_days' => 'Result After Days',
            'needs_specialist' => 'Needs Specialist',
            'needs_asset' => 'Needs Asset',
            'duration_minutes' => 'Duration (min)',
            'hour_capacity' => 'Hour Capacity',
            'normal_price' => 'Normal Price',
            'vip_price' => 'VIP Price',
            'price_in_group' => 'Price In Group',
            'price_calculated_by_hour' => 'Price Calculated by Hour',
            'hour_price' => 'Hour Price',
            'cost_price' => 'Cost Price',
            'birthday_price' => 'Birthday Price',
            'wedding_price' => 'Wedding Price',
            'service_color' => 'Service Color',
            'service_sex' => 'Service Sex',
            'active' => 'Active',
            'specialists' => 'Specialists',
            'assets' => 'Assets',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];

        // Normalize specialists and assets to array of names for PDF
        $data = $services->map(function ($s) {
            return [
                'id' => $s->id,
                'name' => $s->name,
                'serviceCategory.name' => optional($s->serviceCategory)->name,
                'result_after_days' => $s->result_after_days,
                'needs_specialist' => $s->needs_specialist,
                'needs_asset' => $s->needs_asset,
                'duration_minutes' => $s->duration_minutes,
                'hour_capacity' => $s->hour_capacity,
                'normal_price' => $s->normal_price,
                'vip_price' => $s->vip_price,
                'price_in_group' => $s->price_in_group,
                'price_calculated_by_hour' => $s->price_calculated_by_hour,
                'hour_price' => $s->hour_price,
                'cost_price' => $s->cost_price,
                'birthday_price' => $s->birthday_price,
                'wedding_price' => $s->wedding_price,
                'service_color' => $s->service_color,
                'service_sex' => $s->service_sex,
                'active' => $s->active,
                'specialists' => $s->specialists->pluck('name')->values()->all(),
                'assets' => $s->assets->pluck('name')->values()->all(),
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
            ];
        })->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('services_'.'.pdf');
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
            // If type is 'fresh', delete all records first so duplicate detection does not skip rows
            if ($request->input('type') === 'fresh') {
                Service::truncate();
            }

            $import = new DynamicExcelImport(
                Service::class,
                [
                    'name',
                    'service_category_id',
                    'result_after_days',
                    'needs_specialist',
                    'needs_asset',
                    'specialist_id',
                    'duration_minutes',
                    'hour_capacity',
                    'normal_price',
                    'vip_price',
                    'price_in_group',
                    'price_calculated_by_hour',
                    'hour_price',
                    'cost_price',
                    'birthday_price',
                    'wedding_price',
                    'service_color',
                    'service_sex',
                    'active',
                ],
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $errors = [];

                    if (empty($row['name'])) {
                        $errors[] = 'Missing name';
                    }

                    if (! empty($row['needs_specialist']) && empty($row['specialist_id'])) {
                        $errors[] = 'specialist_id required when needs_specialist is true';
                    }

                    if (! empty($row['price_calculated_by_hour']) && (empty($row['hour_price']) || ! is_numeric($row['hour_price']))) {
                        $errors[] = 'hour_price required and numeric when price_calculated_by_hour is true';
                    }

                    return $errors;
                },
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $toBool = function ($val) {
                        if (is_bool($val)) {
                            return $val;
                        }
                        $val = strtolower((string) $val);

                        return in_array($val, ['1', 'true', 'yes', 'y']);
                    };

                    return [
                        'name' => $row['name'] ?? null,
                        'service_category_id' => $row['service_category_id'] ?? null,
                        'result_after_days' => isset($row['result_after_days']) ? (int) $row['result_after_days'] : null,
                        'needs_specialist' => $toBool($row['needs_specialist'] ?? false),
                        'needs_asset' => $toBool($row['needs_asset'] ?? false),
                        'specialist_id' => $row['specialist_id'] ?? null,
                        'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null,
                        'hour_capacity' => isset($row['hour_capacity']) ? (int) $row['hour_capacity'] : null,
                        'normal_price' => isset($row['normal_price']) ? (float) $row['normal_price'] : null,
                        'vip_price' => isset($row['vip_price']) ? (float) $row['vip_price'] : null,
                        'price_in_group' => isset($row['price_in_group']) ? (float) $row['price_in_group'] : null,
                        'price_calculated_by_hour' => $toBool($row['price_calculated_by_hour'] ?? false),
                        'hour_price' => isset($row['hour_price']) ? (float) $row['hour_price'] : null,
                        'cost_price' => isset($row['cost_price']) ? (float) $row['cost_price'] : null,
                        'birthday_price' => isset($row['birthday_price']) ? (float) $row['birthday_price'] : null,
                        'wedding_price' => isset($row['wedding_price']) ? (float) $row['wedding_price'] : null,
                        'service_color' => $row['service_color'] ?? null,
                        'service_sex' => $row['service_sex'] ?? 'both',
                        'active' => $toBool($row['active'] ?? true),
                    ];
                },
                true, // Enable header validation
                $request->input('type') === 'fresh' // Skip duplicate check when fresh
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
                'header_validation' => $import->getHeaderValidationResult(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Import failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Import failed due to invalid data. Please check your file for invalid or missing references.',
                'error_type' => 'database',
            ], 422);
        }
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:services,id',
        ]);

        $skipped = [];
        $deleted = 0;
        $itemIds = [];

        foreach ($request->ids as $id) {
            try {
                $service = Service::find($id);
                if ($service) {
                    $identifier = $service->name ?? "ID: {$id}";
                    $details = [];

                    // Check if service has appointments through pivot table
                    $appointmentsCount = DB::table('appointment_service')
                        ->where('service_id', $service->id)
                        ->distinct('appointment_id')
                        ->count('appointment_id');

                    if ($appointmentsCount > 0) {
                        $sampleAppointmentId = DB::table('appointment_service')
                            ->where('service_id', $service->id)
                            ->select('appointment_id')
                            ->first()?->appointment_id;

                        $details['appointments'] = [
                            'count' => $appointmentsCount,
                            'sample_ids' => $sampleAppointmentId ? [$sampleAppointmentId] : [],
                        ];
                    }

                    if (! empty($details)) {
                        $skipped[] = [
                            'id' => $id,
                            'name' => $identifier,
                            'reason' => 'Cannot delete service. It is referenced by existing appointments.',
                            'details' => $details,
                        ];

                        continue;
                    }

                    // Collect item IDs before deletion
                    if ($service->item_id) {
                        $itemIds[] = $service->item_id;
                    }

                    // Delete linked item if it exists
                    if ($service->item_id && $service->item) {
                        $service->item->delete();
                    }

                    // Delete the service
                    $deleted += $service->delete();
                }
            } catch (\Illuminate\Database\QueryException $e) {
                $service = Service::find($id);
                $identifier = $service?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        // Clear item caches after bulk delete
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_service_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_all_items");
        // Clear individual item caches
        foreach ($itemIds as $itemId) {
            app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$itemId}");
        }

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }
}
