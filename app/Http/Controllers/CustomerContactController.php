<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerContact\StoreCustomerContactRequest;
use App\Http\Requests\CustomerContact\UpdateCustomerContactRequest;
use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerContactController extends Controller
{
    /**
     * Display a listing of customer contacts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CustomerContact::with('customer');

        // Filter by customer if provided
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Search by name, email, or position
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('position', 'like', "%{$search}%");
            });
        }

        $contacts = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $contacts,
            'message' => 'Customer contacts retrieved successfully.',
        ]);
    }

    /**
     * Store a newly created customer contact.
     */
    public function store(StoreCustomerContactRequest $request): JsonResponse
    {
        try {
            $contact = CustomerContact::create($request->validated());

            return response()->json([
                'success' => true,
                'data' => $contact->load('customer'),
                'message' => 'Customer contact created successfully.',
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer contact.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified customer contact.
     */
    public function show(CustomerContact $customerContact): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $customerContact->load('customer'),
            'message' => 'Customer contact retrieved successfully.',
        ]);
    }

    /**
     * Update the specified customer contact.
     */
    public function update(UpdateCustomerContactRequest $request, CustomerContact $customerContact): JsonResponse
    {
        try {
            $customerContact->update($request->validated());

            return response()->json([
                'success' => true,
                'data' => $customerContact->load('customer'),
                'message' => 'Customer contact updated successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer contact.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified customer contact.
     */
    public function destroy(CustomerContact $customerContact): JsonResponse
    {
        try {
            $customerContact->delete();

            return response()->json([
                'success' => true,
                'message' => 'Customer contact deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer contact.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all contacts for a specific customer.
     */
    public function getCustomerContacts(Customer $customer): JsonResponse
    {
        $contacts = $customer->contacts()->get();

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => $customer,
                'contacts' => $contacts,
                'total_contacts' => $contacts->count(),
            ],
            'message' => 'Customer contacts retrieved successfully.',
        ]);
    }

    /**
     * Get contact statistics.
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total_contacts' => CustomerContact::count(),
            'contacts_with_email' => CustomerContact::whereNotNull('email')->count(),
            'contacts_with_phone' => CustomerContact::where(function ($query) {
                $query->whereNotNull('mobile')->orWhereNotNull('work_phone');
            })->count(),
            'customers_with_contacts' => Customer::whereHas('contacts')->count(),
            'customers_without_contacts' => Customer::whereDoesntHave('contacts')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
            'message' => 'Contact statistics retrieved successfully.',
        ]);
    }

    /**
     * Set a contact as the primary contact for a customer.
     */
    public function setPrimaryContact(Request $request, Customer $customer): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|exists:customer_contacts,id'
        ]);

        try {
            // Verify the contact belongs to this customer
            $contact = CustomerContact::where('id', $request->contact_id)
                                    ->where('customer_id', $customer->id)
                                    ->firstOrFail();

            $customer->setPrimaryContact($contact->id);

            return response()->json([
                'success' => true,
                'data' => [
                    'customer' => $customer->load('primaryContact'),
                    'primary_contact' => $contact,
                ],
                'message' => 'Primary contact set successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set primary contact.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove primary contact from a customer.
     */
    public function removePrimaryContact(Customer $customer): JsonResponse
    {
        try {
            $customer->removePrimaryContact();

            return response()->json([
                'success' => true,
                'data' => $customer->load('primaryContact'),
                'message' => 'Primary contact removed successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove primary contact.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
