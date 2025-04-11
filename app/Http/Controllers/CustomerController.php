<?php

namespace App\Http\Controllers;

use App\Models\{
    Customer,
    Address,
    PaymentMethod,
    PaymentTerm
};
use App\Http\Requests\Customer\{
    StoreCustomerRequest,
    UpdateCustomerRequest
};

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

        // Create related resources if needed
        $billingAddress = Address::create($request->input('billing_address'));
        $shippingAddress = Address::create($request->input('shipping_address'));

        $validated['billing_address_id'] = $billingAddress->id;
        $validated['shipping_address_id'] = $shippingAddress->id;

        if ($request->filled('payment_method')) {
            $paymentMethod = PaymentMethod::create($request->input('payment_method'));
            $validated['primary_payment_method_id'] = $paymentMethod->id;
        }

        if ($request->filled('payment_term')) {
            $paymentTerm = PaymentTerm::create($request->input('payment_term'));
            $validated['payment_term_id'] = $paymentTerm->id;
        }

        $customer = Customer::create($validated);

        return response()->json([
            'message' => 'Customer created successfully',
            'data' => $customer->load([
                'billingAddress', 'shippingAddress', 'primaryPaymentMethod', 'paymentTerm'
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

        // Update or create related resources
        if ($request->filled('billing_address')) {
            $customer->billingAddress()->update($request->input('billing_address'));
        }

        if ($request->filled('shipping_address')) {
            $customer->shippingAddress()->update($request->input('shipping_address'));
        }

        if ($request->filled('payment_method')) {
            if ($customer->primaryPaymentMethod) {
                $customer->primaryPaymentMethod()->update($request->input('payment_method'));
            } else {
                $paymentMethod = PaymentMethod::create($request->input('payment_method'));
                $validated['primary_payment_method_id'] = $paymentMethod->id;
            }
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
                'billingAddress', 'shippingAddress', 'primaryPaymentMethod', 'paymentTerm'
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
}
