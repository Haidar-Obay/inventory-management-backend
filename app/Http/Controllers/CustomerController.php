<?php

namespace App\Http\Controllers;

use App\Models\{
    Customer,
    Address,
    PaymentMethod,
    PaymentTerm,
    ReferBy
};
use App\Http\Requests\Customer\{
    StoreCustomerRequest,
    UpdateCustomerRequest
};
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;


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

        return response()->json([
            'message' => 'Customer created successfully',
            'data' => $customer->load([
                'billingAddress',
                'shippingAddress',
                'primaryPaymentMethod',
                'paymentTerm'
            ]),
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

        return response()->json([
            'status' => true,
            'message' => 'Customer updated successfully.',
            'data' => $customer->load([
                'billingAddress',
                'shippingAddress',
                'primaryPaymentMethod',
                'paymentTerm'
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

}

