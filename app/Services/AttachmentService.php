<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AttachmentService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractAttachmentMetadata(Request $request, bool $includeSupplierFallbacks = false): array
    {
        if ($request->has('_attachment_metadata')) {
            return (array) $request->input('_attachment_metadata', []);
        }

        if ($request->has('data') || $request->input('data')) {
            $raw = $request->input('data');
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);

                return (array) ($decoded['attachments'] ?? []);
            }
            if (is_array($raw)) {
                return (array) ($raw['attachments'] ?? []);
            }
        }

        if ($includeSupplierFallbacks) {
            $all = $request->all();
            if (isset($all['attachments']) && is_array($all['attachments'])) {
                return $all['attachments'];
            }

            if ($request->getContent()) {
                $parsed = [];
                parse_str($request->getContent(), $parsed);
                if (isset($parsed['data'])) {
                    $decoded = json_decode((string) $parsed['data'], true);

                    return (array) ($decoded['attachments'] ?? []);
                }
            }
        }

        return [];
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function collectUniqueAttachmentFiles(Request $request): array
    {
        $files = [];
        $fileIdentifiers = [];

        foreach ($request->allFiles() as $key => $file) {
            if (strpos((string) $key, 'attachment') === false) {
                continue;
            }

            $fileArray = is_array($file) ? $file : [$file];
            foreach ($fileArray as $f) {
                if (! $f || ! $f->isValid()) {
                    continue;
                }
                $identifier = $f->getClientOriginalName().'|'.$f->getSize().'|'.$f->getMimeType();
                if (! in_array($identifier, $fileIdentifiers, true)) {
                    $files[] = $f;
                    $fileIdentifiers[] = $identifier;
                }
            }
        }

        if (count($files) > 0) {
            return $files;
        }

        $dot = $request->file('attachments.*');
        if ($dot) {
            $dotFiles = is_array($dot) ? $dot : [$dot];
            foreach ($dotFiles as $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }
                $identifier = $file->getClientOriginalName().'|'.$file->getSize().'|'.$file->getMimeType();
                if (! in_array($identifier, $fileIdentifiers, true)) {
                    $files[] = $file;
                    $fileIdentifiers[] = $identifier;
                }
            }
        }

        $direct = $request->file('attachments');
        if ($direct) {
            $directFiles = is_array($direct) ? $direct : [$direct];
            foreach ($directFiles as $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }
                $identifier = $file->getClientOriginalName().'|'.$file->getSize().'|'.$file->getMimeType();
                if (! in_array($identifier, $fileIdentifiers, true)) {
                    $files[] = $file;
                    $fileIdentifiers[] = $identifier;
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachmentDataFromJson
     * @return array{
     *   keep_ids: array<int, int|string>,
     *   metadata_map: array<int|string, array<string, mixed>>,
     *   new_file_metadata: array<int, array<string, mixed>>
     * }
     */
    public function partitionAttachmentData(array $attachmentDataFromJson): array
    {
        $keepIds = [];
        $metadataMap = [];
        $newFileMetadata = [];

        foreach ($attachmentDataFromJson as $attData) {
            if (isset($attData['id']) && is_numeric($attData['id'])) {
                $keepIds[] = $attData['id'];
                $metadataMap[$attData['id']] = $attData;
            } else {
                $newFileMetadata[] = $attData;
            }
        }

        return [
            'keep_ids' => $keepIds,
            'metadata_map' => $metadataMap,
            'new_file_metadata' => $newFileMetadata,
        ];
    }

    /**
     * @param  EloquentCollection<int, Model>  $existingAttachments
     * @param  array<int, int|string>  $attachmentIdsToKeep
     * @param  array<int|string, array<string, mixed>>  $attachmentMetadataMap
     */
    public function syncExistingAttachments(
        EloquentCollection $existingAttachments,
        array $attachmentIdsToKeep,
        array $attachmentMetadataMap
    ): void {
        foreach ($existingAttachments as $existingAttachment) {
            if (! in_array($existingAttachment->id, $attachmentIdsToKeep)) {
                $relativePath = str_replace(url('/storage'), '', (string) $existingAttachment->file_path);
                Storage::disk('public')->delete($relativePath);
                $existingAttachment->delete();

                continue;
            }

            $metadata = $attachmentMetadataMap[$existingAttachment->id] ?? null;
            if (! $metadata) {
                continue;
            }

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

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array<string, mixed>>  $newFileMetadata
     * @param  callable  $creator  fn(UploadedFile $file, array $metadata): void
     */
    public function createFromUploadedFiles(array $files, array $newFileMetadata, callable $creator): void
    {
        foreach ($files as $index => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $metadata = $newFileMetadata[$index] ?? [];
            $creator($file, $metadata);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @param  EloquentCollection<int, Model>  $existingAttachments
     * @param  callable  $createFromUrl  fn(array $attachmentData): void
     */
    public function syncJsonAttachments(
        array $attachments,
        EloquentCollection $existingAttachments,
        callable $createFromUrl
    ): void {
        $attachmentIdsToKeep = [];
        $attachmentMetadataMap = [];

        foreach ($attachments as $attachmentData) {
            if (isset($attachmentData['id']) && is_numeric($attachmentData['id'])) {
                $attachmentIdsToKeep[] = $attachmentData['id'];
                $attachmentMetadataMap[$attachmentData['id']] = $attachmentData;
            }
        }

        $this->syncExistingAttachments($existingAttachments, $attachmentIdsToKeep, $attachmentMetadataMap);

        foreach ($attachments as $attachmentData) {
            if (isset($attachmentData['id']) && is_numeric($attachmentData['id'])) {
                continue;
            }

            $filePath = $attachmentData['file_url'] ?? $attachmentData['file_path'] ?? null;
            if ($filePath && ! empty(trim((string) $filePath))) {
                $createFromUrl($attachmentData);
            }
        }
    }
}
