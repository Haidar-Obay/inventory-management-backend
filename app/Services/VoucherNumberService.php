<?php

namespace App\Services;

use App\Models\Voucher;

class VoucherNumberService
{
    /**
     * Generate the next voucher number for a given type and year.
     * Format: "{year_last_2_digits}-{sequence_number}" (e.g. "26-1", "26-2")
     *
     * @param  string  $type  'receipt' or 'payment'
     * @param  int|null  $year  Year (e.g. 2026). If null, uses current year.
     * @return string The generated voucher number
     */
    public function generateNextVoucherNumber(string $type, ?int $year = null): string
    {
        $data = $this->generateVoucherNumberWithSequence($type, $year);

        return $data['voucher_number'];
    }

    /**
     * Generate voucher number and return number + sequence + year.
     *
     * @return array ['voucher_number' => string, 'sequence_number' => int, 'year' => int]
     */
    public function generateVoucherNumberWithSequence(string $type, ?int $year = null): array
    {
        $year = $year ?? (int) date('Y');
        $yearLastTwoDigits = substr((string) $year, -2);

        $lastVoucher = Voucher::where('type', $type)
            ->where('year', $year)
            ->orderBy('sequence_number', 'desc')
            ->lockForUpdate()
            ->first();

        $nextSequence = $lastVoucher ? ($lastVoucher->sequence_number + 1) : 1;
        $voucherNumber = "{$yearLastTwoDigits}-{$nextSequence}";

        $exists = Voucher::where('voucher_number', $voucherNumber)->exists();
        if ($exists) {
            do {
                $nextSequence++;
                $voucherNumber = "{$yearLastTwoDigits}-{$nextSequence}";
            } while (Voucher::where('voucher_number', $voucherNumber)->exists());
        }

        return [
            'voucher_number' => $voucherNumber,
            'sequence_number' => $nextSequence,
            'year' => $year,
        ];
    }
}
