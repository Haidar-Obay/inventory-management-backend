<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Section is read from request: ?section=company_info|currency|full
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $section = $request->query('section', 'full');

        return match ($section) {
            'company_info' => $this->companyInfoAttributes(),
            'full' => $this->allAttributes(),
            default => $this->companyInfoAttributes(),
        };
    }

    /**
     * Attributes for company_info section (System Settings > Company info).
     *
     * @return array<string, mixed>
     */
    protected function companyInfoAttributes(): array
    {
        return [
            'company_name' => $this->company_name,
            'location' => $this->location,
            'main_language' => $this->main_language,
            'time_format' => $this->time_format,
            'working_time_from' => $this->working_time_from,
            'working_time_to' => $this->working_time_to,
            'days_off' => $this->days_off ?? [],
        ];
    }

    /**
     * All attributes (for full fetch when entering System Settings).
     *
     * @return array<string, mixed>
     */
    protected function allAttributes(): array
    {
        $this->loadMissing(['primaryCurrency', 'secondaryCurrency']);

        return [
            'company_name' => $this->company_name,
            'location' => $this->location,
            'main_language' => $this->main_language,
            'preferred_mode' => $this->preferred_mode,
            'time_format' => $this->time_format,
            'primary_currency_id' => $this->primary_currency_id,
            'secondary_currency_id' => $this->secondary_currency_id,
            'working_time_from' => $this->working_time_from,
            'working_time_to' => $this->working_time_to,
            'days_off' => $this->days_off ?? [],
            'setup_completed' => $this->setup_completed,
            'completed_at' => $this->completed_at?->toISOString(),
            'primary_currency' => $this->whenLoaded('primaryCurrency'),
            'secondary_currency' => $this->whenLoaded('secondaryCurrency'),
        ];
    }
}
