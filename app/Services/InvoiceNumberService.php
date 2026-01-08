<?php

namespace App\Services;

use App\Models\Invoice;

class InvoiceNumberService
{
    /**
     * Generate the next invoice number for a given invoice type and year.
     * Format: "{year_last_2_digits}-{sequence_number}"
     * Example: "25-0", "25-1", "25-2" for year 2025
     *
     * @param  string  $invoiceType  'purchase' or 'sale'
     * @param  int|null  $year  Year (e.g., 2025). If null, uses current year.
     * @return string The generated invoice number
     */
    public function generateNextInvoiceNumber(string $invoiceType, ?int $year = null): string
    {
        $year = $year ?? (int) date('Y');
        $yearLastTwoDigits = substr((string) $year, -2);

        // Get the last sequence number for this invoice type and year
        $lastInvoice = Invoice::where('invoice_type', $invoiceType)
            ->where('year', $year)
            ->orderBy('sequence_number', 'desc')
            ->lockForUpdate() // Prevent race conditions
            ->first();

        $nextSequence = $lastInvoice ? ($lastInvoice->sequence_number + 1) : 0;

        $invoiceNumber = "{$yearLastTwoDigits}-{$nextSequence}";

        // Ensure uniqueness (shouldn't happen, but safety check)
        $exists = Invoice::where('invoice_number', $invoiceNumber)->exists();
        if ($exists) {
            // If somehow it exists, increment until we find a unique one
            do {
                $nextSequence++;
                $invoiceNumber = "{$yearLastTwoDigits}-{$nextSequence}";
            } while (Invoice::where('invoice_number', $invoiceNumber)->exists());
        }

        return $invoiceNumber;
    }

    /**
     * Generate invoice number and return both the number and sequence.
     *
     * @return array ['invoice_number' => string, 'sequence_number' => int, 'year' => int]
     */
    public function generateInvoiceNumberWithSequence(string $invoiceType, ?int $year = null): array
    {
        $year = $year ?? (int) date('Y');
        $yearLastTwoDigits = substr((string) $year, -2);

        // Get the last sequence number for this invoice type and year
        $lastInvoice = Invoice::where('invoice_type', $invoiceType)
            ->where('year', $year)
            ->orderBy('sequence_number', 'desc')
            ->lockForUpdate()
            ->first();

        $nextSequence = $lastInvoice ? ($lastInvoice->sequence_number + 1) : 0;

        $invoiceNumber = "{$yearLastTwoDigits}-{$nextSequence}";

        // Ensure uniqueness
        $exists = Invoice::where('invoice_number', $invoiceNumber)->exists();
        if ($exists) {
            do {
                $nextSequence++;
                $invoiceNumber = "{$yearLastTwoDigits}-{$nextSequence}";
            } while (Invoice::where('invoice_number', $invoiceNumber)->exists());
        }

        return [
            'invoice_number' => $invoiceNumber,
            'sequence_number' => $nextSequence,
            'year' => $year,
        ];
    }
}
