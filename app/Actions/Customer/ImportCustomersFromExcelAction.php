<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Imports\DynamicExcelImport;
use App\Models\Customer;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportCustomersFromExcelAction
{
    public function execute(Request $request): DynamicExcelImport
    {
        // If type is 'fresh', delete all records first so duplicate detection does not skip rows
        if ($request->input('type') === 'fresh') {
            Customer::truncate();
        }

        $import = new DynamicExcelImport(
            Customer::class,
            [
                'first_name',
                'last_name',
                'title',
                'middle_name',
                'company_name',
                'phone1',
                'phone2',
                'phone3',
                'file_number',
                'bar_code',
                'search_terms',
                'trade_id',
                'company_code_id',
                'customer_group_id',
                'business_type_id',
                'sales_channel_id',
                'distribution_channel_id',
                'media_channel_id',
                'indicator',
                'risk_category',
                'salesman_id',
                'collector_id',
                'supervisor_id',
                'manager_id',
                'payment_term_id',
                'payment_method_id',
                'allow_credit',
                'accept_cheques',
                'price_choice',
                'global_discount',
                'discount_class',
                'markup_percentage',
                'markdown_percentage',
                'taxable',
                'taxed_from_date',
                'taxed_till_date',
                'subjected_to_tax',
                'added_tax',
                'exempted',
                'exempted_from',
                'exemption_reference',
                'exempted_from_date',
                'exempted_till_date',
                'active',
                'black_listed',
                'one_time_account',
                'special_account',
                'pos_customer',
                'free_delivery_charge',
                'print_invoice_language',
                'send_invoice',
                'showMessageField',
                'message',
                'contacts_id',
                'notes',
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

                foreach (['phone1', 'phone2', 'phone3'] as $phoneField) {
                    if (! empty($row[$phoneField]) && ! is_string($row[$phoneField])) {
                        $errors[] = "$phoneField must be a string";
                    }
                }

                if (isset($row['global_discount']) && ! is_numeric($row['global_discount'])) {
                    $errors[] = 'global_discount must be numeric';
                }

                if (isset($row['markup_percentage']) && ! is_numeric($row['markup_percentage'])) {
                    $errors[] = 'markup_percentage must be numeric';
                }

                if (isset($row['markdown_percentage']) && ! is_numeric($row['markdown_percentage'])) {
                    $errors[] = 'markdown_percentage must be numeric';
                }

                // Date validation
                $isValidDate = function ($value) {
                    if ($value === null || $value === '') {
                        return true;
                    }
                    if (is_numeric($value)) {
                        return true;
                    }

                    try {
                        \Carbon\Carbon::createFromFormat('n/j/Y', (string) $value);

                        return true;
                    } catch (\Throwable $e) {
                    }

                    try {
                        \Carbon\Carbon::createFromFormat('m/d/Y', (string) $value);

                        return true;
                    } catch (\Throwable $e) {
                    }

                    try {
                        \Carbon\Carbon::parse((string) $value);

                        return true;
                    } catch (\Throwable $e) {
                    }

                    return false;
                };
                foreach (['taxed_from_date', 'taxed_till_date', 'exempted_from_date', 'exempted_till_date', 'exempted_from'] as $df) {
                    if (isset($row[$df]) && $row[$df] !== '' && ! $isValidDate($row[$df])) {
                        $errors[] = "$df has invalid date";
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
                    'company_code_id' => $row['company_code_id'] ?? null,
                    'customer_group_id' => $row['customer_group_id'] ?? null,
                    'business_type_id' => $row['business_type_id'] ?? null,
                    'sales_channel_id' => $row['sales_channel_id'] ?? null,
                    'distribution_channel_id' => $row['distribution_channel_id'] ?? null,
                    'media_channel_id' => $row['media_channel_id'] ?? null,
                    'indicator' => $row['indicator'] ?? null,
                    'risk_category' => $row['risk_category'] ?? null,
                    'salesman_id' => $row['salesman_id'] ?? null,
                    'collector_id' => $row['collector_id'] ?? null,
                    'supervisor_id' => $row['supervisor_id'] ?? null,
                    'manager_id' => $row['manager_id'] ?? null,
                    'payment_term_id' => $row['payment_term_id'] ?? null,
                    'payment_method_id' => $row['payment_method_id'] ?? null,
                    'allow_credit' => boolval($row['allow_credit'] ?? false),
                    'price_choice' => $row['price_choice'] ?? null,
                    'global_discount' => $row['global_discount'] ?? null,
                    'discount_class' => $row['discount_class'] ?? null,
                    'markup_percentage' => $row['markup_percentage'] ?? null,
                    'markdown_percentage' => $row['markdown_percentage'] ?? null,
                    'taxable' => boolval($row['taxable'] ?? false),
                    'taxed_from_date' => $parseDate($row['taxed_from_date'] ?? null),
                    'taxed_till_date' => $parseDate($row['taxed_till_date'] ?? null),
                    'subjected_to_tax' => boolval($row['subjected_to_tax'] ?? false),
                    'added_tax' => $row['added_tax'] ?? null,
                    'exempted' => boolval($row['exempted'] ?? false),
                    'exempted_from' => $parseDate($row['exempted_from'] ?? null),
                    'exemption_reference' => $row['exemption_reference'] ?? null,
                    'exempted_from_date' => $parseDate($row['exempted_from_date'] ?? null),
                    'exempted_till_date' => $parseDate($row['exempted_till_date'] ?? null),
                    'active' => boolval($row['active'] ?? true),
                    'black_listed' => boolval($row['black_listed'] ?? false),
                    'one_time_account' => boolval($row['one_time_account'] ?? true),
                    'special_account' => boolval($row['special_account'] ?? false),
                    'pos_customer' => boolval($row['pos_customer'] ?? false),
                    'free_delivery_charge' => boolval($row['free_delivery_charge'] ?? false),
                    'print_invoice_language' => $row['print_invoice_language'] ?? 'English',
                    'send_invoice' => $row['send_invoice'] ?? 'email',
                    'message' => $row['message'] ?? null,
                    'contacts_id' => $row['contacts_id'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ];
            },
            true, // Enable header validation
            $request->input('type') === 'fresh' // Skip duplicate check when fresh
        );

        Excel::import($import, $request->file('file'));

        return $import;
    }
}
