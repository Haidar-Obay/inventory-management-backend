<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Address;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressSyncService
{
    public function hasAnyBillingField(Request $request): bool
    {
        $billingFields = [
            'billing_address_line1',
            'billing_address_line2',
            'billing_country_id',
            'billing_city_id',
            'billing_district_id',
            'billing_zone_id',
            'billing_building',
            'billing_block',
            'billing_floor',
            'billing_side',
            'billing_apartment',
            'billing_zip_code',
            'billing_notes',
        ];

        foreach ($billingFields as $key) {
            if ($request->filled($key)) {
                return true;
            }
        }

        return false;
    }

    public function createBillingAddress(Model $owner, Request $request, bool $allowCustomName = false): void
    {
        $billingAddress = Address::create($this->billingAddressData($request));
        $owner->addresses()->attach($billingAddress->id, $this->billingPivotData($request, $allowCustomName));
    }

    public function syncBillingAddress(
        Model $owner,
        Request $request,
        bool $hasAnyBillingField,
        bool $allowCustomName = false
    ): void {
        if ($hasAnyBillingField) {
            $existingBillingPivot = $owner->primaryBillingAddress()->first();
            $billingAddressData = $this->billingAddressData($request);
            $billingPivotData = $this->billingPivotData($request, $allowCustomName);

            if ($existingBillingPivot) {
                $existingBillingPivot->update($billingAddressData);
                $owner->addresses()->updateExistingPivot($existingBillingPivot->id, $billingPivotData);
            } else {
                $billingAddress = Address::create($billingAddressData);
                $owner->addresses()->attach($billingAddress->id, $billingPivotData);
            }

            return;
        }

        $billingAddresses = $owner->billingAddresses()->get();
        foreach ($billingAddresses as $address) {
            $owner->addresses()->detach($address->id);
            $address->delete();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $shippingAddresses
     */
    public function createShippingAddresses(
        Model $owner,
        array $shippingAddresses,
        bool $respectIncomingPrimary = false,
        bool $useIncomingAddressName = false
    ): void {
        foreach ($shippingAddresses as $index => $shippingAddressData) {
            $shippingAddress = Address::create($this->shippingAddressData($shippingAddressData));
            $owner->addresses()->attach(
                $shippingAddress->id,
                $this->shippingPivotData($shippingAddressData, $index, $respectIncomingPrimary, $useIncomingAddressName)
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $shippingAddresses
     */
    public function syncShippingAddresses(
        Model $owner,
        ?array $shippingAddresses,
        bool $checkSharedUsageOnDelete = false
    ): void {
        if (is_array($shippingAddresses)) {
            $existingShippingPivots = $owner->shippingAddresses()->get()->keyBy('id');
            $newShippingIds = [];

            $existingPrimaryShipping = $owner->primaryShippingAddress()->first();
            if ($existingPrimaryShipping) {
                $owner->addresses()->updateExistingPivot($existingPrimaryShipping->id, ['is_primary' => false]);
            }

            foreach ($shippingAddresses as $index => $shippingAddressData) {
                $shippingAddressDataForTable = $this->shippingAddressData($shippingAddressData);
                $shippingPivotData = $this->shippingPivotData($shippingAddressData, $index, false, false);

                if (isset($shippingAddressData['id']) && $existingShippingPivots->has($shippingAddressData['id'])) {
                    $existingShipping = $existingShippingPivots->get($shippingAddressData['id']);
                    $existingShipping->update($shippingAddressDataForTable);
                    $owner->addresses()->updateExistingPivot($existingShipping->id, $shippingPivotData);
                    $newShippingIds[] = $existingShipping->id;
                } else {
                    $newAddress = Address::create($shippingAddressDataForTable);
                    $owner->addresses()->attach($newAddress->id, $shippingPivotData);
                    $newShippingIds[] = $newAddress->id;
                }
            }

            $addressesToDelete = array_diff($existingShippingPivots->keys()->toArray(), $newShippingIds);
            foreach ($addressesToDelete as $addressId) {
                $owner->addresses()->detach($addressId);
                $address = Address::find($addressId);
                if (! $address) {
                    continue;
                }

                if (! $checkSharedUsageOnDelete) {
                    $address->delete();
                    continue;
                }

                $usedByCustomers = DB::table('customer_addresses')->where('address_id', $addressId)->exists();
                $usedBySuppliers = DB::table('supplier_addresses')->where('address_id', $addressId)->exists();
                if (! $usedByCustomers && ! $usedBySuppliers) {
                    $address->delete();
                }
            }

            return;
        }

        $allShippingAddresses = $owner->shippingAddresses()->get();
        foreach ($allShippingAddresses as $address) {
            $owner->addresses()->detach($address->id);
            $address->delete();
        }
    }

    private function billingAddressData(Request $request): array
    {
        return [
            'address_line1' => $request->input('billing_address_line1'),
            'address_line2' => $request->input('billing_address_line2'),
            'country_id' => $request->input('billing_country_id'),
            'city_id' => $request->input('billing_city_id'),
            'district_id' => $request->input('billing_district_id'),
            'zone_id' => $request->input('billing_zone_id'),
            'building' => $request->input('billing_building'),
            'block' => $request->input('billing_block'),
            'floor' => $request->input('billing_floor'),
            'side' => $request->input('billing_side'),
            'appartment' => $request->input('billing_apartment'),
            'zip_code' => $request->input('billing_zip_code'),
        ];
    }

    private function billingPivotData(Request $request, bool $allowCustomName): array
    {
        return [
            'address_type' => 'billing',
            'is_primary' => true,
            'address_name' => $allowCustomName
                ? ($request->input('billing_address_name') ?? 'Primary Billing Address')
                : 'Primary Billing Address',
            'notes' => $request->input('billing_notes'),
        ];
    }

    /**
     * @param  array<string, mixed>  $shippingAddressData
     */
    private function shippingAddressData(array $shippingAddressData): array
    {
        return [
            'address_line1' => $shippingAddressData['address_line1'],
            'address_line2' => $shippingAddressData['address_line2'] ?? null,
            'country_id' => $shippingAddressData['country_id'] ?? null,
            'city_id' => $shippingAddressData['city_id'] ?? null,
            'district_id' => $shippingAddressData['district_id'] ?? null,
            'zone_id' => $shippingAddressData['zone_id'] ?? null,
            'building' => $shippingAddressData['building'] ?? null,
            'block' => $shippingAddressData['block'] ?? null,
            'floor' => $shippingAddressData['floor'] ?? null,
            'side' => $shippingAddressData['side'] ?? null,
            'appartment' => $shippingAddressData['apartment'] ?? null,
            'zip_code' => $shippingAddressData['zip_code'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $shippingAddressData
     */
    private function shippingPivotData(
        array $shippingAddressData,
        int $index,
        bool $respectIncomingPrimary,
        bool $useIncomingAddressName
    ): array {
        $isPrimary = $respectIncomingPrimary
            ? ($shippingAddressData['is_primary'] ?? ($index === 0))
            : ($index === 0);

        $addressName = $useIncomingAddressName
            ? ($shippingAddressData['address_name'] ?? ($index === 0 ? 'Primary Shipping Address' : 'Shipping Address '.($index + 1)))
            : ($index === 0 ? 'Primary Shipping Address' : 'Shipping Address '.($index + 1));

        return [
            'address_type' => 'shipping',
            'is_primary' => $isPrimary,
            'address_name' => $addressName,
            'notes' => $shippingAddressData['notes'] ?? null,
        ];
    }
}

