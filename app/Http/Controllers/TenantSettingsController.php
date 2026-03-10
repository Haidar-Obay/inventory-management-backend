<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TenantSettings\UpdateCompanyInfoRequest;
use App\Http\Resources\TenantSettingResource;
use App\Models\TenantSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantSettingsController extends Controller
{
    /**
     * Get tenant settings. Section via query: ?section=company_info|full
     */
    public function index(Request $request): TenantSettingResource|JsonResponse
    {
        $settings = TenantSetting::getSettings();

        return new TenantSettingResource($settings);
    }

    /**
     * Update tenant settings. Section in body: { "section": "company_info", ... }
     */
    public function update(Request $request): TenantSettingResource|JsonResponse
    {
        $section = $request->input('section');

        if ($section === 'company_info') {
            $validated = $request->validate(
                (new UpdateCompanyInfoRequest)->rules(),
                (new UpdateCompanyInfoRequest)->messages()
            );
             $settings = TenantSetting::getSettings();
            $settings->update([
                'company_name' => $validated['company_name'],
                'location' => $validated['location'],
                'main_language' => $validated['main_language'],
                'time_format' => $validated['time_format'],
                'working_time_from' => $validated['working_time_from'],
                'working_time_to' => $validated['working_time_to'],
                'days_off' => $validated['days_off'] ?? [],
            ]);

            return new TenantSettingResource($settings);
        }

        return response()->json(['message' => 'Invalid or missing section.'], 422);
    }
}
