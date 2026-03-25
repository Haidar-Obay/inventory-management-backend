<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Currency;

class CurrencyResolverService
{
    public function resolveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Currency::find((int) $value)?->id;
        }

        return Currency::where('code', (string) $value)->first()?->id;
    }

    public function resolveIdFromPayload(array $payload): ?int
    {
        $currencyId = $payload['currency_id'] ?? null;
        if ($currencyId !== null && $currencyId !== '') {
            return (int) $currencyId;
        }

        return $this->resolveId($payload['currency'] ?? null);
    }
}

