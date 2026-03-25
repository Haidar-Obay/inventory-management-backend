<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Imports\DynamicExcelImport;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportSuppliersFromExcelAction
{
    public function execute(Request $request): DynamicExcelImport
    {
        // If type is 'fresh', delete all records first so duplicate detection does not skip rows
        if ($request->input('type') === 'fresh') {
            Supplier::truncate();
        }
        $import = new DynamicExcelImport(
            Supplier::class,
            [
                'title',
                'first_name',
                'middle_name',
                'last_name',
                'display_name',
                'company_name',
                'phone1',
                'phone2',
                'phone3',
                'file_number',
                'bar_code',
                'search_terms',
                'trade_id',
                'supplier_group_id',
                'business_type_id',
                'indicator',
                'invoicing_mode',
                'currency_id',
                'opening_amount',
                'opening_date',
                'credit_limit',
                'max_cheques',
                'notes',
                'taxable',
                'taxed_from_date',
                'taxed_till_date',
                'subjected_to_tax',
                'added_tax',
                'catalog',
                'is_foreign',
                'active',
                'message',
                'contacts_id',
            ],
            function ($row) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $errors = [];
                if (($row['first_name'] ?? '') === '') {
                    $errors[] = 'Missing first_name';
                }
                if (($row['last_name'] ?? '') === '') {
                    $errors[] = 'Missing last_name';
                }
                if (($row['phone1'] ?? '') === '') {
                    $errors[] = 'Missing phone1';
                }
                // Validate foreign keys as numeric if present
                foreach (['trade_id', 'supplier_group_id', 'business_type_id', 'currency_id', 'contacts_id'] as $fk) {
                    if (isset($row[$fk]) && $row[$fk] !== '' && ! is_numeric($row[$fk])) {
                        $errors[] = "Invalid $fk: must be numeric ID";
                    }
                }

                return $errors;
            },
            function ($row) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $parseDate = function ($value) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (is_numeric($value)) {
                        try {
                            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);

                            return \Carbon\Carbon::instance($dt)->format('Y-m-d');
                        } catch (\Throwable $e) {
                        }
                    }
                    foreach (['n/j/Y', 'm/d/Y', 'Y-m-d'] as $fmt) {
                        try {
                            return \Carbon\Carbon::createFromFormat($fmt, (string) $value)->format('Y-m-d');
                        } catch (\Throwable $e) {
                        }
                    }

                    try {
                        return \Carbon\Carbon::parse((string) $value)->format('Y-m-d');
                    } catch (\Throwable $e) {
                        return;
                    }
                };

                return [
                    'title' => $row['title'] ?? null,
                    'first_name' => $row['first_name'] ?? null,
                    'middle_name' => $row['middle_name'] ?? null,
                    'last_name' => $row['last_name'] ?? null,
                    'display_name' => $row['display_name'] ?? null,
                    'company_name' => $row['company_name'] ?? null,
                    'phone1' => $row['phone1'] ?? null,
                    'phone2' => $row['phone2'] ?? null,
                    'phone3' => $row['phone3'] ?? null,
                    'file_number' => $row['file_number'] ?? null,
                    'bar_code' => $row['bar_code'] ?? null,
                    'search_terms' => $row['search_terms'] ?? null,
                    'trade_id' => $row['trade_id'] ?? null,
                    'supplier_group_id' => $row['supplier_group_id'] ?? null,
                    'business_type_id' => $row['business_type_id'] ?? null,
                    'indicator' => $row['indicator'] ?? null,
                    'invoicing_mode' => $row['invoicing_mode'] ?? null,
                    'currency_id' => $row['currency_id'] ?? null,
                    'opening_amount' => $row['opening_amount'] ?? null,
                    'opening_date' => $row['opening_date'] ?? null,
                    'credit_limit' => $row['credit_limit'] ?? null,
                    'max_cheques' => $row['max_cheques'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'taxable' => $row['taxable'] ?? null,
                    'taxed_from_date' => $parseDate($row['taxed_from_date'] ?? null),
                    'taxed_till_date' => $parseDate($row['taxed_till_date'] ?? null),
                    'subjected_to_tax' => $row['subjected_to_tax'] ?? null,
                    'added_tax' => $row['added_tax'] ?? null,
                    'catalog' => $row['catalog'] ?? null,
                    'is_foreign' => $row['is_foreign'] ?? null,
                    'active' => isset($row['active']) ? (bool) $row['active'] : true,
                    'message' => $row['message'] ?? null,
                    'contacts_id' => $row['contacts_id'] ?? null,
                ];
            },
            true, // Enable header validation
            $request->input('type') === 'fresh' // Skip duplicate check when fresh
        );

        Excel::import($import, $request->file('file'));

        return $import;
    }
}

