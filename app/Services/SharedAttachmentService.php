<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SharedAttachmentService
{
    public function __construct(
        protected AttachmentService $attachmentService
    ) {}

    public function createAttachments(
        Model $owner,
        Request $request,
        string $entityFolder,
        string $attachmentModelClass,
        string $ownerForeignKey,
        ?int $ownerId = null
    ): void {
        $resolvedOwnerId = $ownerId ?? (int) $owner->getKey();
        if ($request->hasFile('attachments')) {
            $tenantId = tenant('id');
            $files = $this->attachmentService->collectUniqueAttachmentFiles($request);
            $attachmentMetadata = $this->attachmentService->extractAttachmentMetadata($request);

            $this->attachmentService->createFromUploadedFiles(
                $files,
                $attachmentMetadata,
                function ($file, array $metadata) use ($tenantId, $resolvedOwnerId, $entityFolder, $attachmentModelClass, $ownerForeignKey): void {
                    $path = Storage::disk('public')->putFile(
                        "tenants/{$tenantId}/{$entityFolder}/{$resolvedOwnerId}/attachments",
                        $file
                    );

                    $attachmentModelClass::create([
                        $ownerForeignKey => $resolvedOwnerId,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => url(Storage::url($path)),
                        'file_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'description' => $metadata['description'] ?? '',
                        'category' => 'document',
                    ]);
                }
            );

            return;
        }

        if (! $request->has('attachments')) {
            return;
        }

        $attachments = $request->input('attachments');
        if (! is_array($attachments)) {
            return;
        }

        $this->attachmentService->syncJsonAttachments(
            $attachments,
            $owner->attachments,
            function (array $attachmentData) use ($resolvedOwnerId, $attachmentModelClass, $ownerForeignKey): void {
                $filePath = $attachmentData['file_url'] ?? $attachmentData['file_path'] ?? null;
                if ($filePath && ! empty(trim((string) $filePath))) {
                    $attachmentModelClass::create([
                        $ownerForeignKey => $resolvedOwnerId,
                        'file_name' => $attachmentData['file_name'] ?? 'Unknown',
                        'file_path' => $filePath,
                        'file_type' => $attachmentData['file_type'] ?? null,
                        'file_size' => $attachmentData['file_size'] ?? null,
                        'description' => $attachmentData['description'] ?? '',
                        'category' => $attachmentData['category'] ?? 'document',
                    ]);
                }
            }
        );
    }

    public function syncAttachments(
        Model $owner,
        Request $request,
        string $entityFolder,
        string $attachmentModelClass,
        string $ownerForeignKey,
        ?int $ownerId = null,
        bool $includeFallbacks = true,
        bool $swallowUploadExceptions = false,
        ?string $uploadExceptionLogContext = null
    ): void {
        $resolvedOwnerId = $ownerId ?? (int) $owner->getKey();
        $tenantId = tenant('id');
        $attachmentDataFromJson = $this->attachmentService->extractAttachmentMetadata($request, $includeFallbacks);

        $partitioned = $this->attachmentService->partitionAttachmentData($attachmentDataFromJson);
        $this->attachmentService->syncExistingAttachments(
            $owner->attachments,
            $partitioned['keep_ids'],
            $partitioned['metadata_map']
        );

        $files = $this->attachmentService->collectUniqueAttachmentFiles($request);
        $this->attachmentService->createFromUploadedFiles(
            $files,
            $partitioned['new_file_metadata'],
            function ($file, array $metadata) use ($tenantId, $resolvedOwnerId, $entityFolder, $attachmentModelClass, $ownerForeignKey, $swallowUploadExceptions, $uploadExceptionLogContext): void {
                $create = function () use ($file, $metadata, $tenantId, $resolvedOwnerId, $entityFolder, $attachmentModelClass, $ownerForeignKey): void {
                    $path = Storage::disk('public')->putFile(
                        "tenants/{$tenantId}/{$entityFolder}/{$resolvedOwnerId}/attachments",
                        $file
                    );

                    $attachmentModelClass::create([
                        $ownerForeignKey => $resolvedOwnerId,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => url(Storage::url($path)),
                        'file_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'description' => $metadata['description'] ?? '',
                        'category' => $metadata['category'] ?? 'document',
                        'is_public' => $metadata['is_public'] ?? true,
                    ]);
                };

                if (! $swallowUploadExceptions) {
                    $create();

                    return;
                }

                try {
                    $create();
                } catch (\Exception $e) {
                    Log::error($uploadExceptionLogContext ?? 'Attachment sync: upload create error', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        );

        if (! $request->has('attachments') || $request->hasFile('attachments') || $request->hasFile('attachments.*')) {
            return;
        }

        $attachments = $request->input('attachments');
        if (! is_array($attachments)) {
            return;
        }

        $this->attachmentService->syncJsonAttachments(
            $attachments,
            $owner->attachments,
            function (array $attachmentData) use ($resolvedOwnerId, $attachmentModelClass, $ownerForeignKey): void {
                $filePath = $attachmentData['file_url'] ?? $attachmentData['file_path'] ?? null;
                if ($filePath && ! empty(trim((string) $filePath))) {
                    $attachmentModelClass::create([
                        $ownerForeignKey => $resolvedOwnerId,
                        'file_name' => $attachmentData['file_name'] ?? 'Unknown',
                        'file_path' => $filePath,
                        'file_type' => $attachmentData['file_type'] ?? null,
                        'file_size' => $attachmentData['file_size'] ?? null,
                        'description' => $attachmentData['description'] ?? '',
                        'category' => $attachmentData['category'] ?? 'document',
                    ]);
                }
            }
        );
    }
}

