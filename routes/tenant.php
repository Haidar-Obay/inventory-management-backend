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
use App\Http\Controllers\ZoneController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\SalesmanController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerCreditLimitController;
use App\Http\Controllers\CustomerChequeLimitController;
use App\Http\Controllers\CustomerTaxController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PaymentTermController;
use App\Http\Controllers\ReferByController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\SubscriptionPlanController;
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
use App\Http\Controllers\SalesChannelController;
use App\Http\Controllers\DistributionChannelController;
use App\Http\Controllers\TransportationChannelController;
use App\Http\Controllers\MediaChannelController;
use App\Http\Controllers\CustomerOpeningBalanceController;
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\CustomerRouteController;
use App\Http\Controllers\TableTemplateController;
use App\Http\Controllers\ItemController;

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
            Route::post('/register', [UserManagementController::class, 'registerUser'])->middleware('subscription.limits:user');
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/get-all-users', action: [UserManagementController::class, 'getAllUsers']);
            Route::get('/get-user/{id}', action: [UserManagementController::class, 'getUser']);
            Route::delete('/delete-user/{id}', [UserManagementController::class, 'deleteUser']);
            Route::delete('/bulk-delete-users', [UserManagementController::class, 'bulkDeleteUsers']);

            // Subscription Status Check
            Route::get('/subscription/status', [SubscriptionPlanController::class, 'checkCurrentUserSubscription']);

            // Resource APIs
            Route::apiResource('cities', CityController::class);
            Route::apiResource('countries', CountryController::class);
            Route::apiResource('zones', ZoneController::class);
            Route::apiResource('districts', DistrictController::class);
            Route::apiResource('trades', TradeController::class);
            Route::apiResource('company-codes', CompanyCodeController::class);
            Route::apiResource('brands', BrandController::class);
            Route::apiResource('product-lines', ProductLineController::class);
            Route::apiResource('categories', CategoryController::class);
            Route::apiResource('supplier-groups', SupplierGroupController::class);
            Route::apiResource('payment-terms', PaymentTermController::class);

            Route::apiResource('currencies', CurrencyController::class)->middleware('subscription.limits:currency');
            Route::apiResource('salesmen', SalesmanController::class);
            Route::apiResource('customers', CustomerController::class)->middleware('subscription.limits:customer');
            Route::apiResource('customer-groups', CustomerGroupController::class);
            // Route::apiResource('customer-attachments', CustomerAttachmentController::class);
            Route::apiResource('payment-methods', PaymentMethodController::class);
            Route::apiResource('refer-bies', ReferByController::class);
            Route::apiResource('branches', BranchController::class);
            Route::apiResource('adjustment-types', AdjustmentTypeController::class);
            Route::apiResource('warehouses', WarehouseController::class);
            Route::apiResource('business-types', BusinessTypeController::class);
            Route::apiResource('projects', ProjectController::class);
            Route::apiResource('jobs', JobController::class);
            Route::apiResource('transaction-series', TransactionSeriesController::class);
            Route::apiResource('cost-centers', CostCenterController::class);
            Route::apiResource('departments', DepartmentController::class);
            Route::apiResource('sales-channels', SalesChannelController::class);
            Route::apiResource('distribution-channels', DistributionChannelController::class);
            Route::apiResource('transportation-channels', TransportationChannelController::class);
            Route::apiResource('media-channels', MediaChannelController::class);
            Route::apiResource('items', ItemController::class);

            // Table Templates Routes
            Route::prefix('table-templates')->group(function () {
                Route::get('{tableName}', [TableTemplateController::class, 'index']);
                Route::post('{tableName}', [TableTemplateController::class, 'store']);
                Route::get('{tableName}/{templateId}', [TableTemplateController::class, 'show']);
                Route::put('{tableName}/{templateId}', [TableTemplateController::class, 'update']);
                Route::delete('{tableName}/{templateId}', [TableTemplateController::class, 'destroy']);
            });

            Route::get('adjustment-types/transaction-types', [AdjustmentTypeController::class, 'getTransactionTypes']);

            // Export to Excel Routes
            // Note: The export routes are prefixed with 'exportExcell' to avoid confusion with the import routes.
            Route::prefix('exportExcell')->group(function () {
                Route::get('customers', [CustomerController::class, 'exportExcell']);
                Route::get('cities', [CityController::class, 'exportExcell']);
                Route::get('countries', [CountryController::class, 'exportExcell']);
                Route::get('zones', [ZoneController::class, 'exportExcell']);
                Route::get('districts', [DistrictController::class, 'exportExcell']);
                Route::get('currencies', [CurrencyController::class, 'exportExcell']);
                Route::get('customer-groups', [CustomerGroupController::class, 'exportExcel']);
                Route::get('payment-methods', [PaymentMethodController::class, 'exportExcell']);
                Route::get('payment-terms', [PaymentTermController::class, 'exportExcell']);
                Route::get('salesmen', [SalesmanController::class, 'exportExcell']);
                Route::get('refer-bies', [ReferByController::class, 'exportExcell']);
                Route::get('trades', [TradeController::class, 'exportExcell']);
                Route::get('company-codes', [CompanyCodeController::class, 'exportExcell']);
                Route::get('brands', [BrandController::class, 'exportExcel']);
                Route::get('product-lines', [ProductLineController::class, 'exportExcel']);
                Route::get('categories', [CategoryController::class, 'exportExcell']);
                Route::get('supplier-groups', [SupplierGroupController::class, 'exportExcell']);
                Route::get('branches', [BranchController::class, 'exportExcell']);
                Route::get('adjustment-types', [AdjustmentTypeController::class, 'exportExcell']);
                Route::get('warehouses', [WarehouseController::class, 'exportExcell']);
                Route::get('business-types', [BusinessTypeController::class, 'exportExcell']);
                Route::get('projects', [ProjectController::class, 'exportExcel']);
                Route::get('jobs', [JobController::class, 'exportExcell']);
                Route::get('transaction-series', [TransactionSeriesController::class, 'exportExcell']);
                Route::get('cost-centers', [CostCenterController::class, 'exportExcell']);
                Route::get('departments', [DepartmentController::class, 'exportExcell']);
                Route::get('sales-channels', [SalesChannelController::class, 'exportExcell']);
                Route::get('distribution-channels', [DistributionChannelController::class, 'exportExcell']);
                Route::get('transportation-channels', [TransportationChannelController::class, 'exportExcell']);
                Route::get('media-channels', [MediaChannelController::class, 'exportExcell']);
                Route::get('items', [ItemController::class, 'exportExcell']);
            });

            // Export to PDF Routes
            // Note: The export routes are prefixed with 'exportPdf' to avoid confusion with the import routes.
            Route::prefix('exportPdf')->group(function () {
                Route::get('/customers', [CustomerController::class, 'exportPdf']);
                Route::get('/cities', [CityController::class, 'exportPdf']);
                Route::get('/countries', [CountryController::class, 'exportPdf']);
                Route::get('/districts', [DistrictController::class, 'exportPdf']);
                Route::get('/zones', [ZoneController::class, 'exportPdf']);
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
                Route::get('/projects', [ProjectController::class, 'exportPdf']);
                Route::get('/jobs', [JobController::class, 'exportPdf']);
                Route::get('/transaction-series', [TransactionSeriesController::class, 'exportPdf']);
                Route::get('/cost-centers', [CostCenterController::class, 'exportPdf']);
                Route::get('/departments', [DepartmentController::class, 'exportPdf']);
                Route::get('/sales-channels', [SalesChannelController::class, 'exportPdf']);
                Route::get('/distribution-channels', [DistributionChannelController::class, 'exportPdf']);
                Route::get('/transportation-channels', [TransportationChannelController::class, 'exportPdf']);
                Route::get('/media-channels', [MediaChannelController::class, 'exportPdf']);
                Route::get('/items', [ItemController::class, 'exportPdf']);
            });

            // Import from Excel Routes
            // Note: The import routes are prefixed with 'importFromExcel' to avoid confusion with the export routes.
            Route::prefix('importFromExcel')->group(function () {
                Route::post('/customers', [CustomerController::class, 'importFromExcel']);
                Route::post('/cities', [CityController::class, 'importFromExcel']);
                Route::post('/countries', [CountryController::class, 'importFromExcel']);
                Route::post('/zones', [ZoneController::class, 'importFromExcel']);
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
                Route::post('/projects', [ProjectController::class, 'importFromExcel']);
                Route::post('/jobs', [JobController::class, 'importFromExcel']);
                Route::post('/transaction-series', [TransactionSeriesController::class, 'importFromExcel']);
                Route::post('/cost-centers', [CostCenterController::class, 'importFromExcel']);
                Route::post('/departments', [DepartmentController::class, 'importFromExcel']);
                Route::post('/sales-channels', [SalesChannelController::class, 'import']);
                Route::post('/distribution-channels', [DistributionChannelController::class, 'import']);
                Route::post('/transportation-channels', [TransportationChannelController::class, 'import']);
                Route::post('/media-channels', [MediaChannelController::class, 'import']);
                Route::post('/items', [ItemController::class, 'importFromExcel']);
            });

            // Bulk Delete Routes
            // Note: The bulk delete routes are prefixed with 'bulk-delete' to avoid confusion with the import/export routes.
            Route::prefix('bulk-delete')->group(function () {
                Route::delete('/customers', [CustomerController::class, 'bulkDelete']);
                Route::delete('/cities', [CityController::class, 'bulkDelete']);
                Route::delete('/countries', [CountryController::class, 'bulkDelete']);
                Route::delete('/zones', [ZoneController::class, 'bulkDelete']);
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
                Route::delete('/projects', [ProjectController::class, 'bulkDelete']);
                Route::delete('/jobs', [JobController::class, 'bulkDelete']);
                Route::delete('/transaction-series', [TransactionSeriesController::class, 'bulkDelete']);
                Route::delete('/cost-centers', [CostCenterController::class, 'bulkDelete']);
                Route::delete('/departments', [DepartmentController::class, 'bulkDelete']);
                Route::delete('/sales-channels', [SalesChannelController::class, 'bulkDelete']);
                Route::delete('/distribution-channels', [DistributionChannelController::class, 'bulkDelete']);
                Route::delete('/transportation-channels', [TransportationChannelController::class, 'bulkDelete']);
                Route::delete('/media-channels', [MediaChannelController::class, 'bulkDelete']);
                Route::delete('/items', [ItemController::class, 'bulkDelete']);
            });

            // Get Customer Projects & Jobs
            Route::get('customers/{customerId}/projects', [ProjectController::class, 'getCustomerProjects']);
            Route::get('projects/{projectId}/jobs', [JobController::class, 'getProjectJobs']);
            Route::get('company-codes/{companyCodeId}/transaction-series', [TransactionSeriesController::class, 'getByCompanyCode']);
            Route::get('trades/{tradeId}/transaction-series', [TransactionSeriesController::class, 'getByTrade']);
            Route::get('cost-centers/{costCenterId}/sub-cost-centers', [CostCenterController::class, 'getSubCostCenters']);
            Route::get('departments/{departmentId}/sub-departments', [DepartmentController::class, 'getSubDepartments']);
            Route::get('sales-channels/{salesChannelId}/sub-sales-channels', [SalesChannelController::class, 'getSubSalesChannels']);
            Route::get('distribution-channels/{distributionChannelId}/sub-distribution-channels', [DistributionChannelController::class, 'getSubDistributionChannels']);
            Route::get('transportation-channels/{transportationChannelId}/sub-transportation-channels', [TransportationChannelController::class, 'getSubTransportationChannels']);
            Route::get('media-channels/{mediaChannelId}/sub-media-channels', [MediaChannelController::class, 'getSubMediaChannels']);



            // Customer Routes
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
            Route::put('/customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);


            // Customer Credit Limits Routes
            Route::prefix('customers/{customer}/credit-limits')->group(function () {
                Route::get('/', [CustomerCreditLimitController::class, 'index']);
                Route::post('/', [CustomerCreditLimitController::class, 'store']);
                Route::post('/bulk', [CustomerCreditLimitController::class, 'bulkStore']);
                Route::get('/available-currencies', [CustomerCreditLimitController::class, 'getAvailableCurrencies']);
                Route::get('/summary', [CustomerCreditLimitController::class, 'getCreditSummary']);
                Route::get('/{creditLimit}', [CustomerCreditLimitController::class, 'show']);
                Route::put('/{creditLimit}', [CustomerCreditLimitController::class, 'update']);
                Route::delete('/{creditLimit}', [CustomerCreditLimitController::class, 'destroy']);
            });

            // Customer Cheque Limits Routes
            Route::prefix('customers/{customer}/cheque-limits')->group(function () {
                Route::get('/', [CustomerChequeLimitController::class, 'index']);
                Route::post('/', [CustomerChequeLimitController::class, 'store']);
                Route::post('/bulk', [CustomerChequeLimitController::class, 'bulkStore']);
                Route::get('/available-currencies', [CustomerChequeLimitController::class, 'getAvailableCurrencies']);
                Route::get('/summary', [CustomerChequeLimitController::class, 'getChequeSummary']);
                Route::post('/check-availability', [CustomerChequeLimitController::class, 'checkChequeAvailability']);
                Route::get('/{chequeLimit}', [CustomerChequeLimitController::class, 'show']);
                Route::put('/{chequeLimit}', [CustomerChequeLimitController::class, 'update']);
                Route::delete('/{chequeLimit}', [CustomerChequeLimitController::class, 'destroy']);
            });

            // Customer Tax Routes
            Route::prefix('customers/{customer}/tax')->group(function () {
                Route::get('/', [CustomerTaxController::class, 'show']);
                Route::put('/', [CustomerTaxController::class, 'update']);
                Route::get('/exemption-status', [CustomerTaxController::class, 'exemptionStatus']);
                Route::get('/tax-info', [CustomerTaxController::class, 'taxInfo']);
                Route::post('/set-exemption', [CustomerTaxController::class, 'setExemption']);
                Route::delete('/remove-exemption', [CustomerTaxController::class, 'removeExemption']);
                Route::get('/report', [CustomerTaxController::class, 'report']);
            });

            // Customer Opening Balance Management Routes
            Route::prefix('customers/{customer}/opening-balances')->group(function () {
                Route::get('/', [CustomerOpeningBalanceController::class, 'index']);
                Route::post('/', [CustomerOpeningBalanceController::class, 'store']);
                Route::get('/summary', [CustomerOpeningBalanceController::class, 'summary']);
                Route::get('/available-currencies', [CustomerOpeningBalanceController::class, 'availableCurrencies']);
                Route::post('/bulk-update', [CustomerOpeningBalanceController::class, 'bulkUpdate']);
                Route::get('/statistics', [CustomerOpeningBalanceController::class, 'statistics']);
                Route::get('/{openingBalance}', [CustomerOpeningBalanceController::class, 'show']);
                Route::put('/{openingBalance}', [CustomerOpeningBalanceController::class, 'update']);
                Route::delete('/{openingBalance}', [CustomerOpeningBalanceController::class, 'destroy']);
            });

            // Customer Contact Management Routes
            Route::prefix('customers/{customer}/contacts')->group(function () {
                Route::get('/', [CustomerContactController::class, 'getCustomerContacts']);
                Route::post('/', [CustomerContactController::class, 'store']);
                Route::get('/{contact}', [CustomerContactController::class, 'show']);
                Route::put('/{contact}', [CustomerContactController::class, 'update']);
                Route::delete('/{contact}', [CustomerContactController::class, 'destroy']);
            });

            // Global Customer Contact Routes
            Route::prefix('customer-contacts')->group(function () {
                Route::get('/', [CustomerContactController::class, 'index']);
                Route::post('/', [CustomerContactController::class, 'store']);
                Route::get('/statistics', [CustomerContactController::class, 'statistics']);
                Route::get('/{contact}', [CustomerContactController::class, 'show']);
                Route::put('/{contact}', [CustomerContactController::class, 'update']);
                Route::delete('/{contact}', [CustomerContactController::class, 'destroy']);
            });

            // Customer Route Management Routes
            Route::prefix('customers/{customer}/routes')->group(function () {
                Route::get('/', [CustomerRouteController::class, 'getCustomerRoutes']);
                Route::post('/', [CustomerRouteController::class, 'store']);
                Route::get('/{route}', [CustomerRouteController::class, 'show']);
                Route::put('/{route}', [CustomerRouteController::class, 'update']);
                Route::delete('/{route}', [CustomerRouteController::class, 'destroy']);
                Route::get('/{route}/activate', [CustomerRouteController::class, 'activate']);
                Route::get('/{route}/deactivate', [CustomerRouteController::class, 'deactivate']);
            });

            // Salesman Route Management Routes
            Route::prefix('salesmen/{salesman}/routes')->group(function () {
                Route::get('/', [CustomerRouteController::class, 'getSalesmanRoutes']);
                Route::get('/today', [CustomerRouteController::class, 'getTodayRoutes']);
                Route::get('/upcoming', [CustomerRouteController::class, 'getUpcomingRoutes']);
            });

            // Global Customer Route Routes
            Route::prefix('customer-routes')->group(function () {
                Route::get('/', [CustomerRouteController::class, 'index']);
                Route::post('/', [CustomerRouteController::class, 'store']);
                Route::get('/statistics', [CustomerRouteController::class, 'statistics']);
                Route::get('/date-routes', [CustomerRouteController::class, 'getDateRoutes']);
                Route::get('/{route}', [CustomerRouteController::class, 'show']);
                Route::put('/{route}', [CustomerRouteController::class, 'update']);
                Route::delete('/{route}', [CustomerRouteController::class, 'destroy']);
                Route::get('/{route}/activate', [CustomerRouteController::class, 'activate']);
                Route::get('/{route}/deactivate', [CustomerRouteController::class, 'deactivate']);
            });

            // Global Tax Routes
            Route::prefix('tax')->group(function () {
                Route::get('/expiring-exemptions', [CustomerTaxController::class, 'getExpiringExemptions']);
                Route::get('/expired-exemptions', [CustomerTaxController::class, 'getExpiredExemptions']);
                Route::get('/currently-exempted', [CustomerTaxController::class, 'getCurrentlyExemptedCustomers']);
                Route::get('/customers-with-tax-numbers', [CustomerTaxController::class, 'getCustomersWithTaxNumbers']);
                Route::get('/summary', [CustomerTaxController::class, 'getTaxSummary']);
                Route::post('/bulk-exemptions', [CustomerTaxController::class, 'bulkUpdateTaxExemptions']);
            });
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

    Route::middleware([
        'web',
        InitializeTenancyByDomain::class,
        PreventAccessFromCentralDomains::class,
    ])->group(function () {
        Route::get('/names/customers', [CustomerController::class, 'getNames']);
        Route::get('/names/cost-centers', [CostCenterController::class, 'getNames']);
        Route::get('/names/departments', [DepartmentController::class, 'getNames']);
        Route::get('/names/projects', [ProjectController::class, 'getNames']);
        Route::get('/names/categories', [CategoryController::class, 'getNames']);
        Route::get('/names/brands', [BrandController::class, 'getNames']);
        Route::get('/names/sales-channels', [SalesChannelController::class, 'getNames']);
        Route::get('/names/distribution-channels', [DistributionChannelController::class, 'getNames']);
        Route::get('/names/transportation-channels', [TransportationChannelController::class, 'getNames']);
        Route::get('/names/media-channels', [MediaChannelController::class, 'getNames']);
        Route::get('/names/customer-groups', [CustomerGroupController::class, 'getNames']);
        Route::get('/names/salesmen', [SalesmanController::class, 'getNames']);
        Route::get('/names/trades', [TradeController::class, 'getNames']);
        Route::get('/names/items', [ItemController::class, 'getNames']);
    });



