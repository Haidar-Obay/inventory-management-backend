<?php

namespace App\Services;

use App\Models\AssociationServicePrice;
use App\Models\Referrer;
use App\Models\ReferrerServiceCommission;
use App\Models\Service;
use App\Models\ServiceAdvancedPricing;

class PricingService
{
    public function resolvePrice(array $context): array
    {
        $serviceId = $context['service_id'];
        $associationId = $context['association_id'] ?? null;
        $referrerId = $context['referrer_id'] ?? null;
        $specialistId = $context['specialist_id'] ?? null;
        $serviceType = $context['service_type'] ?? null; // 'on_site' or 'on_call'
        $hours = $context['hours'] ?? 1;
        $isEvent = $context['is_event'] ?? false;

        $service = Service::findOrFail($serviceId);
        $overridesApplied = [];
        $discountsApplied = [];

        // Base price calculation
        $basePrice = $this->calculateBasePrice($service, $hours);
        $overridesApplied[] = "Base price: {$basePrice}";

        // Service advanced pricing override (specialist + service type)
        if ($specialistId && $serviceType) {
            $advancedPricing = ServiceAdvancedPricing::where('service_id', $serviceId)
                ->where('specialist_id', $specialistId)
                ->first();

            if ($advancedPricing) {
                $advancedPrice = $serviceType === 'on_site'
                    ? $advancedPricing->price_on_site
                    : $advancedPricing->price_on_call;

                if ($advancedPrice > 0) {
                    $basePrice = $advancedPrice;
                    $overridesApplied[] = "Advanced pricing ({$serviceType}): {$basePrice}";
                }
            }
        }

        // Event pricing override
        if ($isEvent && $service->event_pricing) {
            $basePrice = $service->normal_price ?? $basePrice;
            $overridesApplied[] = 'Event pricing applied';
        }

        // Association-level price override
        if ($associationId) {
            $associationPrice = AssociationServicePrice::where('service_id', $serviceId)
                ->where('association_id', $associationId)
                ->first();

            if ($associationPrice && $associationPrice->price > 0) {
                $basePrice = $associationPrice->price;
                $overridesApplied[] = "Association price override: {$basePrice}";
            }
        }

        // Referrer-level price override
        if ($referrerId) {
            $referrerCommission = ReferrerServiceCommission::where('service_id', $serviceId)
                ->where('referrer_id', $referrerId)
                ->first();

            if ($referrerCommission && $referrerCommission->price_override > 0) {
                $basePrice = $referrerCommission->price_override;
                $overridesApplied[] = "Referrer price override: {$basePrice}";
            }
        }

        // Apply discounts: only association discount (referrer discount ignored)
        $discountTotal = 0;
        $finalPrice = $basePrice;

        // Association discount only
        if ($associationId) {
            $associationPrice = AssociationServicePrice::where('service_id', $serviceId)
                ->where('association_id', $associationId)
                ->first();

            if ($associationPrice && $associationPrice->discount > 0) {
                $discountTotal += $associationPrice->discount;
                $discountsApplied[] = "Association discount: {$associationPrice->discount}";
            }
        }

        // Note: Referrer discount is ignored when both association and referrer are present
        // Referrer still gets commission regardless

        $finalPrice = max(0, $basePrice - $discountTotal);

        // Calculate commission
        $commissionPercent = 0;
        $commissionAmount = 0;

        if ($referrerId) {
            $referrerCommission = ReferrerServiceCommission::where('service_id', $serviceId)
                ->where('referrer_id', $referrerId)
                ->first();

            if ($referrerCommission && $referrerCommission->commission_percent > 0) {
                // Use service-specific commission
                $commissionPercent = $referrerCommission->commission_percent;
            } else {
                // Fall back to global referrer commission
                $referrer = Referrer::find($referrerId);
                $commissionPercent = $referrer->commission_percent ?? 0;
            }

            $commissionAmount = ($finalPrice * $commissionPercent) / 100;
        }

        return [
            'base_price' => $basePrice,
            'overrides_applied' => $overridesApplied,
            'discounts_applied' => $discountsApplied,
            'discount_total' => $discountTotal,
            'final_price' => $finalPrice,
            'commission_percent' => $commissionPercent,
            'commission_amount' => $commissionAmount,
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'normal_price' => $service->normal_price,
                'hour_price' => $service->hour_price,
                'price_calculated_by_hour' => $service->price_calculated_by_hour,
            ],
        ];
    }

    private function calculateBasePrice(Service $service, int $hours): float
    {
        // Since we now have a simple one-to-one relationship with service categories,
        // we don't need category-specific pricing logic anymore.
        // The service category is just for classification, not pricing.

        // Use normal pricing logic
        if ($service->price_calculated_by_hour && $service->hour_price) {
            return $service->hour_price * $hours;
        }

        return $service->normal_price ?? 0;
    }
}
