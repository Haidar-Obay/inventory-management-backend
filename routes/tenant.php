<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\CustomerAttachmentController;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

// Controllers
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\SalesmanController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PaymentTermController;
use App\Http\Controllers\ReferByController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\CompanyCodeController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ProductLineController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SupplierGroupController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Events\Verified;
use App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\AdjustmentTypeController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\BusinessTypeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\TransactionSeriesController;
use App\Http\Controllers\CostCenterController;
use App\Http\Controllers\DepartmentController;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
| Routes loaded by TenantRouteServiceProvider, initialized per tenant.
| Customize freely.
|--------------------------------------------------------------------------
*/

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {

        // Public Routes
        Route::post('/login', [AuthController::class, 'login']);



        // Protected Routes
        Route::middleware(['auth:sanctum', 'verified'])->group(function () {

         //log audit
          Route::get('audits', [AuditController::class, 'index']);

            // Auth & User Management
            Route::post('/register', [UserManagementController::class, 'registerUser']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/get-all-users', action: [UserManagementController::class, 'getAllUsers']);
            Route::get('/get-user/{id}', action: [UserManagementController::class, 'getUser']);
            Route::delete('/delete-user/{id}', [UserManagementController::class, 'deleteUser']);
            Route::delete('/bulk-delete-users', [UserManagementController::class, 'bulkDeleteUsers']);

            // Resource APIs
            Route::apiResource('cities', CityController::class);
            Route::apiResource('countries', CountryController::class);
            Route::apiResource('provinces', ProvinceController::class);
            Route::apiResource('districts', DistrictController::class);
            Route::apiResource('trades', TradeController::class);
            Route::apiResource('company-codes', CompanyCodeController::class);
            Route::apiResource('brands', BrandController::class);
            Route::apiResource('product-lines', ProductLineController::class);
            Route::apiResource('categories', CategoryController::class);
            Route::apiResource('supplier-groups', SupplierGroupController::class);
            Route::apiResource('payment-terms', PaymentTermController::class);

            Route::apiResource('currencies', CurrencyController::class);
            Route::apiResource('salesmen', SalesmanController::class);
            Route::apiResource('customers', CustomerController::class);
            Route::apiResource('customer-groups', CustomerGroupController::class);
            // Route::apiResource('customer-attachments', CustomerAttachmentController::class);
            Route::apiResource('payment-methods', PaymentMethodController::class);
            Route::apiResource('refer-bies', ReferByController::class);
            Route::apiResource('branches', BranchController::class);
            Route::apiResource('adjustment-types', AdjustmentTypeController::class);
            Route::apiResource('warehouses', WarehouseController::class);
            Route::apiResource('business-types', BusinessTypeController::class);

            Route::get('adjustment-types/transaction-types', [AdjustmentTypeController::class, 'getTransactionTypes']);

            // Export to Excel Routes
            // Note: The export routes are prefixed with 'exportExcell' to avoid confusion with the import routes.
            Route::prefix('exportExcell')->group(function () {
                Route::get('customers', [CustomerController::class, 'exportExcell']);
                Route::get('cities', [CityController::class, 'exportExcell']);
                Route::get('countries', [CountryController::class, 'exportExcell']);
                Route::get('provinces', [ProvinceController::class, 'exportExcell']);
                Route::get('districts', [DistrictController::class, 'exportExcell']);
                Route::get('currencies', [CurrencyController::class, 'exportExcell']);
                Route::get('customer-groups', [CustomerGroupController::class, 'exportExcell']);
                Route::get('payment-methods', [PaymentMethodController::class, 'exportExcell']);
                Route::get('payment-terms', [PaymentTermController::class, 'exportExcell']);
                Route::get('salesmen', [SalesmanController::class, 'exportExcell']);
                Route::get('refer-bies', [ReferByController::class, 'exportExcell']);
                Route::get('trades', [TradeController::class, 'exportExcell']);
                Route::get('company-codes', [CompanyCodeController::class, 'exportExcell']);
                Route::get('brands', [BrandController::class, 'exportExcell']);
                Route::get('product-lines', [ProductLineController::class, 'exportExcell']);
                Route::get('categories', [CategoryController::class, 'exportExcell']);
                Route::get('supplier-groups', [SupplierGroupController::class, 'exportExcell']);
                Route::get('branches', [BranchController::class, 'exportExcell']);
                Route::get('adjustment-types', [AdjustmentTypeController::class, 'exportExcell']);
                Route::get('warehouses', [WarehouseController::class, 'exportExcell']);
                Route::get('business-types', [BusinessTypeController::class, 'exportExcell']);
            });

            // Export to PDF Routes
            // Note: The export routes are prefixed with 'exportPdf' to avoid confusion with the import routes.
            Route::prefix('exportPdf')->group(function () {
                Route::get('/customers', [CustomerController::class, 'exportPdf']);
                Route::get('/cities', [CityController::class, 'exportPdf']);
                Route::get('/countries', [CountryController::class, 'exportPdf']);
                Route::get('/districts', [DistrictController::class, 'exportPdf']);
                Route::get('/provinces', [ProvinceController::class, 'exportPdf']);
                Route::get('/currencies', [CurrencyController::class, 'exportPdf']);
                Route::get('/customer-groups', [CustomerGroupController::class, 'exportPdf']);
                Route::get('/payment-methods', [PaymentMethodController::class, 'exportPdf']);
                Route::get('/payment-terms', [PaymentTermController::class, 'exportPdf']);
                Route::get('/salesmen', [SalesmanController::class, 'exportPdf']);
                Route::get('/refer-bies', [ReferByController::class, 'exportPdf']);
                Route::get('/trades', [TradeController::class, 'exportPdf']);
                Route::get('/company-codes', [CompanyCodeController::class, 'exportPdf']);
                Route::get('/brands', [BrandController::class, 'exportPdf']);
                Route::get('/product-lines', [ProductLineController::class, 'exportPdf']);
                Route::get('/categories', [CategoryController::class, 'exportPdf']);
                Route::get('/supplier-groups', [SupplierGroupController::class, 'exportPdf']);
                Route::get('/branches', [BranchController::class, 'exportPdf']);
                Route::get('/adjustment-types', [AdjustmentTypeController::class, 'exportPdf']);
                Route::get('/warehouses', [WarehouseController::class, 'exportPdf']);
                Route::get('/business-types', [BusinessTypeController::class, 'exportPdf']);
            });

            // Import from Excel Routes
            // Note: The import routes are prefixed with 'importFromExcel' to avoid confusion with the export routes.
            Route::prefix('importFromExcel')->group(function () {
                Route::post('/customers', [CustomerController::class, 'importFromExcel']);
                Route::post('/cities', [CityController::class, 'importFromExcel']);
                Route::post('/countries', [CountryController::class, 'importFromExcel']);
                Route::post('/provinces', [ProvinceController::class, 'importFromExcel']);
                Route::post('/districts', [DistrictController::class, 'importFromExcel']);
                Route::post('/currencies', [CurrencyController::class, 'importFromExcel']);
                Route::post('/customer-groups', [CustomerGroupController::class, 'importFromExcel']);
                Route::post('/payment-methods', [PaymentMethodController::class, 'importFromExcel']);
                Route::post('/payment-terms', [PaymentTermController::class, 'importFromExcel']);
                Route::post('/salesmen', [SalesmanController::class, 'importFromExcel']);
                Route::post('/refer-bies', [ReferByController::class, 'importFromExcel']);
                Route::post('/trades', [TradeController::class, 'importFromExcel']);
                Route::post('/company-codes', [CompanyCodeController::class, 'importFromExcel']);
                Route::post('/brands', [BrandController::class, 'importFromExcel']);
                Route::post('/product-lines', [ProductLineController::class, 'importFromExcel']);
                Route::post('/categories', [CategoryController::class, 'importFromExcel']);
                Route::post('/supplier-groups', [SupplierGroupController::class, 'importFromExcel']);
                Route::post('/branches', [BranchController::class, 'importFromExcel']);
                Route::post('/adjustment-types', [AdjustmentTypeController::class, 'importFromExcel']);
                Route::post('/warehouses', [WarehouseController::class, 'importFromExcel']);
                Route::post('/business-types', [BusinessTypeController::class, 'importFromExcel']);
            });

            // Bulk Delete Routes
            // Note: The bulk delete routes are prefixed with 'bulk-delete' to avoid confusion with the import/export routes.
            Route::prefix('bulk-delete')->group(function () {
                Route::delete('/customers', [CustomerController::class, 'bulkDelete']);
                Route::delete('/cities', [CityController::class, 'bulkDelete']);
                Route::delete('/countries', [CountryController::class, 'bulkDelete']);
                Route::delete('/provinces', [ProvinceController::class, 'bulkDelete']);
                Route::delete('/currencies', [CurrencyController::class, 'bulkDelete']);
                Route::delete('/customer-groups', [CustomerGroupController::class, 'bulkDelete']);
                Route::delete('/payment-methods', [PaymentMethodController::class, 'bulkDelete']);
                Route::delete('/payment-terms', [PaymentTermController::class, 'bulkDelete']);
                Route::delete('/salesmen', [SalesmanController::class, 'bulkDelete']);
                Route::delete('/refer-bies', [ReferByController::class, 'bulkDelete']);
                Route::delete('/trades', [TradeController::class, 'bulkDelete']);
                Route::delete('/company-codes', [CompanyCodeController::class, 'bulkDelete']);
                Route::delete('/brands', [BrandController::class, 'bulkDelete']);
                Route::delete('/product-lines', [ProductLineController::class, 'bulkDelete']);
                Route::delete('/categories', [CategoryController::class, 'bulkDelete']);
                Route::delete('/supplier-groups', [SupplierGroupController::class, 'bulkDelete']);
                Route::delete('/branches', [BranchController::class, 'bulkDelete']);
                Route::delete('/adjustment-types', [AdjustmentTypeController::class, 'bulkDelete']);
                Route::delete('/warehouses', [WarehouseController::class, 'bulkDelete']);
                Route::delete('/business-types', [BusinessTypeController::class, 'bulkDelete']);
            });

            Route::apiResource('projects', ProjectController::class);
            Route::post('projects/export-excel', [ProjectController::class, 'exportExcell']);
            Route::post('projects/export-pdf', [ProjectController::class, 'exportPdf']);
            Route::post('projects/import-excel', [ProjectController::class, 'importFromExcel']);
            Route::get('customers/{customerId}/projects', [ProjectController::class, 'getCustomerProjects']);
            Route::apiResource('jobs', JobController::class);
            Route::post('jobs/export-excel', [JobController::class, 'exportExcell']);
            Route::post('jobs/export-pdf', [JobController::class, 'exportPdf']);
            Route::post('jobs/import-excel', [JobController::class, 'importFromExcel']);
            Route::get('projects/{projectId}/jobs', [JobController::class, 'getProjectJobs']);
            Route::apiResource('transaction-series', TransactionSeriesController::class);
            Route::post('transaction-series/export-excel', [TransactionSeriesController::class, 'exportExcell']);
            Route::post('transaction-series/export-pdf', [TransactionSeriesController::class, 'exportPdf']);
            Route::post('transaction-series/import-excel', [TransactionSeriesController::class, 'importFromExcel']);
            Route::get('company-codes/{companyCodeId}/transaction-series', [TransactionSeriesController::class, 'getByCompanyCode']);
            Route::get('trades/{tradeId}/transaction-series', [TransactionSeriesController::class, 'getByTrade']);
            Route::apiResource('cost-centers', CostCenterController::class);
            Route::post('cost-centers/export-excel', [CostCenterController::class, 'exportExcell']);
            Route::post('cost-centers/export-pdf', [CostCenterController::class, 'exportPdf']);
            Route::post('cost-centers/import-excel', [CostCenterController::class, 'importFromExcel']);
            Route::get('cost-centers/{costCenterId}/sub-cost-centers', [CostCenterController::class, 'getSubCostCenters']);
            Route::apiResource('departments', DepartmentController::class);
            Route::post('departments/export-excel', [DepartmentController::class, 'exportExcell']);
            Route::post('departments/export-pdf', [DepartmentController::class, 'exportPdf']);
            Route::post('departments/import-excel', [DepartmentController::class, 'importFromExcel']);
            Route::get('departments/{departmentId}/sub-departments', [DepartmentController::class, 'getSubDepartments']);
        });

          //  Email Verification Routes
          Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }
            Auth::login($user); // Log the user in manually in tenant context
            if (!hash_equals((string) $id, (string) $user->getKey())) {
                return response()->json(['message' => 'Invalid user ID.'], 403);
            }
            if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                return response()->json(['message' => 'Invalid email hash.'], 403);
            }
            if ($user->hasVerifiedEmail()) {
                return response()->json(['message' => 'Email already verified.']);
            }
            $user->markEmailAsVerified();
            event(new Verified($user));

            return response()->json(['message' => 'Email verified successfully!']);
        })->middleware(['signed'])->name('verification.verify');

        Route::post('/email/verification-notification', function (Request $request) {
            $request->user()->sendEmailVerificationNotification();
            return response()->json(['message' => 'Verification email resent']);
        })->middleware(['auth:sanctum', 'throttle:6,1'])->name('verification.send');



        // Password Reset Routes
        Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
        Route::post('/reset-password', [ResetPasswordController::class, 'reset']);
    });


    // Test cache route
    Route::get('/test-cache', function () {
        $key = 'tenant_' . tenant('id') . '_test_message';

        app('cache')->store('database')->put($key, 'Hello Tenant!', 600);

        return response()->json([
            'cached' => app('cache')->store('database')->get($key),
        ]);
});
