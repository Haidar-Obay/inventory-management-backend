<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class SharedContactService
{
    /**
     * @param  array<int, array<string, mixed>>  $contacts
     * @param  array<int, string>  $fieldMap
     */
    public function createContacts(
        Model $owner,
        array $contacts,
        string $contactModelClass,
        string $ownerForeignKey,
        ?int $ownerId = null,
        array $fieldMap = []
    ): void {
        $resolvedOwnerId = $ownerId ?? (int) $owner->getKey();
        foreach ($contacts as $contactData) {
            $isPrimary = isset($contactData['is_primary']) && (bool) $contactData['is_primary'];
            $payload = $this->buildContactPayload($contactData, $resolvedOwnerId, $ownerForeignKey, $fieldMap, $isPrimary);

            $contact = $contactModelClass::create($payload);
            if ($isPrimary && method_exists($owner, 'setPrimaryContact')) {
                $owner->setPrimaryContact($contact->id);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     * @param  array<int, string>  $fieldMap
     */
    public function syncContacts(
        Model $owner,
        array $contacts,
        string $contactModelClass,
        string $ownerForeignKey,
        ?int $contactsId = null,
        ?int $ownerId = null,
        array $fieldMap = []
    ): void {
        $resolvedOwnerId = $ownerId ?? (int) $owner->getKey();
        $existingContacts = $owner->contacts()->get()->keyBy('id');
        $existingContactIds = $existingContacts->keys()->toArray();
        $incomingContactIds = [];
        $existingPrimaryContact = $owner->contacts()->where('is_primary', true)->first();

        foreach ($contacts as $contactData) {
            $isPrimary = isset($contactData['is_primary']) && (bool) $contactData['is_primary'];
            $contactId = isset($contactData['id']) ? (int) $contactData['id'] : null;

            if ($isPrimary && ! $contactId && $existingPrimaryContact) {
                $contactId = $existingPrimaryContact->id;
            }

            $payload = $this->buildContactPayload($contactData, $resolvedOwnerId, $ownerForeignKey, $fieldMap, $isPrimary);

            if ($contactId && isset($existingContacts[$contactId])) {
                $contact = $existingContacts[$contactId];
                $contact->update($payload);
                if ($isPrimary && method_exists($owner, 'setPrimaryContact')) {
                    $owner->setPrimaryContact($contact->id);
                }
                $incomingContactIds[] = $contactId;
                continue;
            }

            $contact = $contactModelClass::create($payload);
            if ($isPrimary && method_exists($owner, 'setPrimaryContact')) {
                $owner->setPrimaryContact($contact->id);
            }
            $incomingContactIds[] = $contact->id;
        }

        $contactsToDelete = array_diff($existingContactIds, $incomingContactIds);
        if (! empty($contactsToDelete)) {
            $contactModelClass::whereIn('id', $contactsToDelete)
                ->where($ownerForeignKey, $resolvedOwnerId)
                ->delete();
        }

        if ($contactsId !== null && method_exists($owner, 'setPrimaryContact')) {
            $owner->setPrimaryContact($contactsId);
        }
    }

    /**
     * @param  array<string, mixed>  $contactData
     * @param  array<int, string>  $fieldMap
     * @return array<string, mixed>
     */
    private function buildContactPayload(
        array $contactData,
        mixed $ownerId,
        string $ownerForeignKey,
        array $fieldMap,
        bool $isPrimary
    ): array {
        $payload = [
            $ownerForeignKey => $ownerId,
            'title' => $contactData['title'] ?? null,
            'name' => $contactData['name'] ?? null,
            'work_phone' => $contactData['work_phone'] ?? null,
            'mobile' => $contactData['mobile'] ?? null,
            'position' => $contactData['position'] ?? null,
            'extension' => $contactData['extension'] ?? null,
            'is_primary' => $isPrimary,
        ];

        foreach ($fieldMap as $inputKey => $modelKey) {
            $payload[$modelKey] = $contactData[$inputKey] ?? null;
        }

        return $payload;
    }
}

