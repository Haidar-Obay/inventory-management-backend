<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRoute\StoreCustomerRouteRequest;
use App\Http\Requests\CustomerRoute\UpdateCustomerRouteRequest;
use App\Models\Customer;
use App\Models\CustomerRoute;
use App\Models\Salesman;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerRouteController extends Controller
{
    /**
     * Display a listing of customer routes.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CustomerRoute::with(['customer', 'salesman']);

        // Filter by customer if provided
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by salesman if provided
        if ($request->has('salesman_id')) {
            $query->where('salesman_id', $request->salesman_id);
        }

        // Filter by frequency
        if ($request->has('frequency')) {
            $query->where('frequency', $request->frequency);
        }

        // Filter by active status
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        // Filter by day value
        if ($request->has('day_value')) {
            $query->where('day_value', $request->day_value);
        }

        // Search by customer name or salesman name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', function ($customerQuery) use ($search) {
                    $customerQuery->where('name', 'like', "%{$search}%");
                })->orWhereHas('salesman', function ($salesmanQuery) use ($search) {
                    $salesmanQuery->where('name', 'like', "%{$search}%");
                });
            });
        }

        $routes = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $routes,
            'message' => 'Customer routes retrieved successfully.',
        ]);
    }

    /**
     * Store a newly created customer route.
     */
    public function store(StoreCustomerRouteRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Deactivate any existing active route for this customer
            CustomerRoute::where('customer_id', $request->customer_id)
                ->where('active', true)
                ->update(['active' => false]);

            $route = CustomerRoute::create($request->validated());

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $route->load(['customer', 'salesman']),
                'message' => 'Customer route created successfully.',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer route.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified customer route.
     */
    public function show(CustomerRoute $customerRoute): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $customerRoute->load(['customer', 'salesman']),
            'message' => 'Customer route retrieved successfully.',
        ]);
    }

    /**
     * Update the specified customer route.
     */
    public function update(UpdateCustomerRouteRequest $request, CustomerRoute $customerRoute): JsonResponse
    {
        try {
            DB::beginTransaction();

            $customerRoute->update($request->validated());

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $customerRoute->load(['customer', 'salesman']),
                'message' => 'Customer route updated successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer route.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified customer route.
     */
    public function destroy(CustomerRoute $customerRoute): JsonResponse
    {
        try {
            $customerRoute->delete();

            return response()->json([
                'success' => true,
                'message' => 'Customer route deleted successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer route.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all routes for a specific customer.
     */
    public function getCustomerRoutes(Customer $customer): JsonResponse
    {
        $routes = $customer->routes()->with('salesman')->get();
        $activeRoute = $customer->activeRoute;

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => $customer,
                'routes' => $routes,
                'active_route' => $activeRoute,
                'total_routes' => $routes->count(),
            ],
            'message' => 'Customer routes retrieved successfully.',
        ]);
    }

    /**
     * Get all routes for a specific salesman.
     */
    public function getSalesmanRoutes(Salesman $salesman): JsonResponse
    {
        $routes = $salesman->customerRoutes()->with('customer')->active()->get();

        return response()->json([
            'success' => true,
            'data' => [
                'salesman' => $salesman,
                'routes' => $routes,
                'total_routes' => $routes->count(),
            ],
            'message' => 'Salesman routes retrieved successfully.',
        ]);
    }

    /**
     * Get today's routes for a specific salesman.
     */
    public function getTodayRoutes(Salesman $salesman): JsonResponse
    {
        $today = Carbon::today();
        $routes = $salesman->customerRoutes()
            ->with('customer')
            ->active()
            ->get()
            ->filter(function ($route) {
                return $route->isVisitDayToday();
            });

        return response()->json([
            'success' => true,
            'data' => [
                'salesman' => $salesman,
                'date' => $today->toDateString(),
                'routes' => $routes,
                'total_routes' => $routes->count(),
            ],
            'message' => 'Today\'s routes retrieved successfully.',
        ]);
    }

    /**
     * Get routes for a specific date.
     */
    public function getDateRoutes(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date',
            'salesman_id' => 'nullable|exists:salesmen,id',
        ]);

        $date = Carbon::parse($request->date);
        $query = CustomerRoute::with(['customer', 'salesman'])->active();

        if ($request->has('salesman_id')) {
            $query->where('salesman_id', $request->salesman_id);
        }

        $routes = $query->get()->filter(function ($route) use ($date) {
            return $route->isVisitDate($date);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date->toDateString(),
                'routes' => $routes,
                'total_routes' => $routes->count(),
            ],
            'message' => 'Routes for the specified date retrieved successfully.',
        ]);
    }

    /**
     * Get upcoming routes for a salesman.
     */
    public function getUpcomingRoutes(Salesman $salesman, Request $request): JsonResponse
    {
        $request->validate([
            'days' => 'integer|min:1|max:90',
        ]);

        $days = $request->get('days', 7);
        $startDate = Carbon::today();
        $endDate = Carbon::today()->addDays($days);

        $routes = $salesman->customerRoutes()
            ->with('customer')
            ->active()
            ->get()
            ->map(function ($route) use ($startDate, $endDate) {
                $visitDates = $route->getVisitDates($startDate, $endDate);

                return [
                    'route' => $route,
                    'visit_dates' => $visitDates,
                    'next_visit' => $route->getNextVisitDate(),
                ];
            })
            ->filter(function ($item) {
                return ! empty($item['visit_dates']);
            });

        return response()->json([
            'success' => true,
            'data' => [
                'salesman' => $salesman,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'routes' => $routes,
                'total_routes' => $routes->count(),
            ],
            'message' => 'Upcoming routes retrieved successfully.',
        ]);
    }

    /**
     * Activate a customer route.
     */
    public function activate(CustomerRoute $customerRoute): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Deactivate other routes for the same customer
            CustomerRoute::where('customer_id', $customerRoute->customer_id)
                ->where('id', '!=', $customerRoute->id)
                ->update(['active' => false]);

            // Activate this route
            $customerRoute->update(['active' => true]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $customerRoute->load(['customer', 'salesman']),
                'message' => 'Customer route activated successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to activate customer route.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deactivate a customer route.
     */
    public function deactivate(CustomerRoute $customerRoute): JsonResponse
    {
        try {
            $customerRoute->update(['active' => false]);

            return response()->json([
                'success' => true,
                'data' => $customerRoute->load(['customer', 'salesman']),
                'message' => 'Customer route deactivated successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate customer route.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get route statistics.
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total_routes' => CustomerRoute::count(),
            'active_routes' => CustomerRoute::active()->count(),
            'weekly_routes' => CustomerRoute::frequency('weekly')->count(),
            'biweekly_routes' => CustomerRoute::frequency('biweekly')->count(),
            'monthly_routes' => CustomerRoute::frequency('monthly')->count(),
            'customers_with_routes' => Customer::whereHas('routes')->count(),
            'customers_without_routes' => Customer::whereDoesntHave('routes')->count(),
            'salesmen_with_routes' => Salesman::whereHas('customerRoutes')->count(),
            'today_routes' => CustomerRoute::active()->get()->filter(function ($route) {
                return $route->isVisitDayToday();
            })->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
            'message' => 'Route statistics retrieved successfully.',
        ]);
    }
}
