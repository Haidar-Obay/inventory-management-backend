<?php

namespace App\Http\Controllers;

use App\Models\{
    Customer,
    Address,
    PaymentTerm
};
use App\Http\Requests\Customer\{
    StoreCustomerRequest,
    UpdateCustomerRequest
};
use App\Models\CustomerAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class CustomerController extends Controller
{
    public function index()
    {
        $customers = Customer::with([
            'customerGroup',
            'salesman',
            'referBy',
            'paymentTerm',
            'primaryPaymentMethod',
            'openingCurrency',
            'billingAddress',
            'shippingAddress',
            'parentCustomer',
            'subCustomers',
            'attachments'
        ])->paginate(10);

        return response()->json([
            'status' => true,
            'message' => 'Customers fetched successfully.',
            'data' => $customers,
        ]);
    }

    public function store(StoreCustomerRequest $request)
    {
        $validated = $request->validated();

        $billingAddress = Address::create($request->input('billing_address'));
        $shippingAddress = Address::create($request->input('shipping_address'));

        $validated['billing_address_id'] = $billingAddress->id;
        $validated['shipping_address_id'] = $shippingAddress->id;

        if ($request->filled('primary_payment_method_id')) {
            $validated['primary_payment_method_id'] = $request->input('primary_payment_method_id');
        }

        if ($request->filled('payment_term')) {
            $paymentTerm = PaymentTerm::create($request->input('payment_term'));
            $validated['payment_term_id'] = $paymentTerm->id;
        }

        $customer = Customer::create($validated);

        $attachmentIds = [];

        if ($request->hasFile('attachments')) {
            $tenantId = tenant('id');
            $files = is_array($request->file('attachments'))
                ? $request->file('attachments')
                : [$request->file('attachments')];

            foreach ($files as $file) {
                $path = Storage::disk('public')->putFile(
                    "tenants/{$tenantId}/{$customer->id}/attachments",
                    $file
                );

                $attachment = CustomerAttachment::create([
                    'customer_id' => $customer->id,
                    'file_path' => url(Storage::url($path)),
                ]);

                $attachmentIds[] = $attachment->id;
            }

            // Save the attachment IDs as JSON
            $customer->update([
                'attachment_ids' => $attachmentIds
            ]);
        }

        return response()->json([
            'message' => 'Customer created successfully',
            'data' => $customer->load([
                'billingAddress',
                'shippingAddress',
                'primaryPaymentMethod',
                'paymentTerm',
            ])->toArray() + [
                'attachments' => $customer->attachments()->pluck('file_path'),
            ],
        ], 201);
    }

    public function show(Customer $customer)
    {
        $customer->load([
            'customerGroup',
            'salesman',
            'referBy',
            'paymentTerm',
            'primaryPaymentMethod',
            'openingCurrency',
            'billingAddress',
            'shippingAddress',
            'parentCustomer',
            'subCustomers',
            'attachments'
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Customer details fetched successfully.',
            'data' => $customer,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $validated = $request->validated();
        logger()->info('Validated data', $validated);
        if ($request->filled('billing_address')) {
            $customer->billingAddress()->update($request->input('billing_address'));
        }

        if ($request->filled('shipping_address')) {
            $customer->shippingAddress()->update($request->input('shipping_address'));
        }

        if ($request->filled('primary_payment_method_id')) {
            $validated['primary_payment_method_id'] = $request->input('primary_payment_method_id');
        }

        if ($request->filled('payment_term')) {
            if ($customer->paymentTerm) {
                $customer->paymentTerm()->update($request->input('payment_term'));
            } else {
                $paymentTerm = PaymentTerm::create($request->input('payment_term'));
                $validated['payment_term_id'] = $paymentTerm->id;
            }
        }

        $customer->update($validated);

        if ($request->hasFile('attachments')) {
            $tenantId = tenant('id');

            // Delete old files
            foreach ($customer->attachments as $attachment) {
                $relativePath = str_replace(url('/storage'), '', $attachment->file_path);
                Storage::disk('public')->delete($relativePath);
                $attachment->delete();
            }

            $newAttachmentIds = [];
            $files = is_array($request->file('attachments'))
                ? $request->file('attachments')
                : [$request->file('attachments')];

            foreach ($files as $file) {
                $path = Storage::disk('public')->putFile(
                    "tenants/{$tenantId}/{$customer->id}/attachments",
                    $file
                );

                $attachment = CustomerAttachment::create([
                    'customer_id' => $customer->id,
                    'file_path' => url(Storage::url($path)),
                ]);

                $newAttachmentIds[] = $attachment->id;
            }

            $customer->update([
                'attachment_ids' => $newAttachmentIds,
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer updated successfully.',
            'data' => $customer->load([
                'billingAddress',
                'shippingAddress',
                'primaryPaymentMethod',
                'paymentTerm',
                'attachments',
            ]),
        ]);
        
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return response()->json([
            'status' => true,
            'message' => 'Customer deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:customers,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += Customer::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $customers = Customer::with([
            'customerGroup',
            'salesman',
            'referBy',
            'paymentTerm',
            'primaryPaymentMethod',
            'openingCurrency',
            'billingAddress',
            'shippingAddress',
            'parentCustomer',
            'subCustomers',
        ])->select('id', 'first_name', 'last_name');
        $collection = $customers->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No customers found.'], 404);
        }
        $columns = [
            'id',
            'first_name',
            'last_name',
            'customer_group_id',
            'salesman_id',
            'refer_by_id',
            'payment_term_id',
            'primary_payment_method_id',
            'opening_currency_id',
            'billing_address_id',
            'shipping_address_id',
            'parent_customer_id'
        ];
        $headings = [
            'ID',
            'First_name',
            'Last_name',
            'Customer Group ID',
            'Salesman ID',
            'Refer By ID',
            'Payment Term ID',
            'Primary Payment Method ID',
            'Opening Currency ID',
            'Billing Address ID',
            'Shipping Address ID',
            'Parent Customer ID'
        ];
        return Excel::download(new Export($customers, $columns, $headings), 'customers.xlsx');
    }

    //export pdf
    public function exportPdf(ExportPDF $pdfService)
    {
        $customers = Customer::select(
            'id',
            'first_name',
            'last_name',
            'customer_group_id',
            'salesman_id',
            'refer_by_id',
            'payment_term_id',
            'primary_payment_method_id',
            'opening_currency_id',
            'billing_address_id',
            'shipping_address_id',
            'parent_customer_id'
        )->get();

        if ($customers->isEmpty()) {
            return response()->json(['message' => 'No customers found.'], 404);
        }

        $title = 'Customer Report';
        $headers = [
            'id' => 'Customer ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'customer_group_id' => 'Customer Group',
            'salesman_id' => 'Salesman',
            'refer_by_id' => 'Referred By',
            'payment_term_id' => 'Payment Term',
            'primary_payment_method_id' => 'Payment Method',
            'opening_currency_id' => 'Currency',
            'billing_address_id' => 'Billing Address',
            'shipping_address_id' => 'Shipping Address',
            'parent_customer_id' => 'Parent Customer'
        ];

        $data = $customers->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('customers.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Customer::class,
            [
                'first_name',
                'last_name',
                'billing_address_id',
                'shipping_address_id',
            ],
            function ($row) {
                $errors = [];

                // Required fields
                if (empty($row['first_name']))
                    $errors[] = 'Missing first_name';
                if (empty($row['last_name']))
                    $errors[] = 'Missing last_name';
                if (empty($row['billing_address_id']))
                    $errors[] = 'Missing billing_address_id';
                if (empty($row['shipping_address_id']))
                    $errors[] = 'Missing shipping_address_id';

                // Optional validations
                if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email format';
                }

                foreach (['phone1', 'phone2', 'phone3'] as $phoneField) {
                    if (!empty($row[$phoneField]) && !ctype_digit(strval($row[$phoneField]))) {
                        $errors[] = "$phoneField must be numeric";
                    }
                }

                if (isset($row['credit_limit']) && !is_numeric($row['credit_limit'])) {
                    $errors[] = 'credit_limit must be numeric';
                }

                if (isset($row['opening_balance']) && !is_numeric($row['opening_balance'])) {
                    $errors[] = 'opening_balance must be numeric';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'title' => $row['title'] ?? null,
                    'first_name' => $row['first_name'],
                    'middle_name' => $row['middle_name'] ?? null,
                    'last_name' => $row['last_name'],
                    'suffix' => $row['suffix'] ?? null,
                    'display_name' => $row['display_name'] ?? null,
                    'company_name' => $row['company_name'] ?? null,
                    'phone1' => $row['phone1'] ?? null,
                    'phone2' => $row['phone2'] ?? null,
                    'phone3' => $row['phone3'] ?? null,
                    'email' => $row['email'] ?? null,
                    'website' => $row['website'] ?? null,
                    'file_number' => $row['file_number'] ?? null,
                    'billing_address_id' => $row['billing_address_id'],
                    'shipping_address_id' => $row['shipping_address_id'],
                    'is_sub_customer' => boolval($row['is_sub_customer'] ?? false),
                    'parent_customer_id' => $row['parent_customer_id'] ?? null,
                    'customer_group_id' => $row['customer_group_id'] ?? null,
                    'salesman_id' => $row['salesman_id'] ?? null,
                    'refer_by_id' => $row['refer_by_id'] ?? null,
                    'primary_payment_method_id' => $row['primary_payment_method_id'] ?? null,
                    'payment_term_id' => $row['payment_term_id'] ?? null,
                    'credit_limit' => $row['credit_limit'] ?? null,
                    'taxable' => $row['taxable'] ?? null,
                    'tax_registration' => $row['tax_registration'] ?? null,
                    'opening_currency_id' => $row['opening_currency_id'] ?? null,
                    'opening_balance' => $row['opening_balance'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'attachments' => $row['attachments'] ?? null,
                    'is_inactive' => boolval($row['is_inactive'] ?? false),
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }

}

