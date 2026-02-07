<?php

declare(strict_types=1);

use App\Http\Controllers\AdjustmentTypeController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssociationContactController;
use App\Http\Controllers\AssociationController;
use App\Http\Controllers\AssociationServicePriceController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\ForgotPasswordController as AuthForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController as AuthResetPasswordController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BusinessTypeController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CompanyCodeController;
use App\Http\Controllers\CostCenterController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerAttachmentController;
use App\Http\Controllers\CustomerChequeLimitController;
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerCreditLimitController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\CustomerMasterListController;
use App\Http\Controllers\CustomerOpeningBalanceController;
use App\Http\Controllers\CustomerRouteController;
use App\Http\Controllers\CustomerTaxController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DistributionChannelController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventStatusController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ItemGroupController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\MediaChannelController;
use App\Http\Controllers\MediaTypeController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PaymentTermController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ProductLineController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReferByController;
use App\Http\Controllers\ReferrerController;
use App\Http\Controllers\ReferrerServiceCommissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SalesChannelController;
use App\Http\Controllers\SalesmanController;
use App\Http\Controllers\SchedulerUnitController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\ServiceAdvancedPricingController;
use App\Http\Controllers\ServiceCategoryController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceNeededItemController;
use App\Http\Controllers\SetupWizardController;
use App\Http\Controllers\SpecialistController;
use App\Http\Controllers\SpecialityController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierGroupController;
use App\Http\Controllers\TableTemplateController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskStatusController;
use App\Http\Controllers\TaxGroupController;
use App\Http\Controllers\TenantModuleController;
use App\Http\Controllers\TenantPurgeController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\TransactionSeriesController;
use App\Http\Controllers\TransportationChannelController;
use App\Http\Controllers\UnitGroupController;
use App\Http\Controllers\UnitOfMeasurementController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\VisitController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\ZoneController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

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

    // add verified 'auth:sanctum', 'verified'
    // Protected Routes
    Route::middleware(['auth:sanctum'])->group(function () {

        // Tenant modules pages and assigned modules
        Route::get('tenant/allowed-pages', [TenantModuleController::class, 'getAllowedPages']);
        Route::get('tenant/assigned-modules', [TenantModuleController::class, 'getAssignedModules']);

        // log audit
        Route::get('audits', [AuditController::class, 'index']);

        // Auth & User Management
        Route::post('/user/register', [UserManagementController::class, 'registerUser'])->middleware(['subscription.limits:user', 'check.permission:users,add']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [UserManagementController::class, 'me']);
        Route::get('/get-all-users', [UserManagementController::class, 'getAllUsers'])->middleware('check.permission:users,view');
        Route::get('/get-assignable-users', [UserManagementController::class, 'getAssignableUsers'])->middleware('check.permission:users,view');
        Route::get('/get-user/{id}', [UserManagementController::class, 'getUser'])->middleware('check.permission:users,view');
        Route::put('/update-user/{id}', [UserManagementController::class, 'updateUser'])->middleware('check.permission:users,edit');
        Route::delete('/delete-user/{id}', [UserManagementController::class, 'deleteUser'])->middleware('check.permission:users,delete');
        Route::delete('/bulk-delete-users', [UserManagementController::class, 'bulkDeleteUsers'])->middleware('check.permission:users,delete');
        Route::patch('/toggle-user-status/{id}', [UserManagementController::class, 'toggleUserStatus'])->middleware('check.permission:users,edit');

        // Subscription Status Check
        Route::get('/subscription/status', [SubscriptionPlanController::class, 'checkCurrentUserSubscription']);
        Route::get('/subscription/scheduler-mode', [SubscriptionPlanController::class, 'getSchedulerMode']);

        // Setup Wizard Routes
        Route::prefix('setup-wizard')->group(function () {
            Route::get('/', [SetupWizardController::class, 'index']);
            Route::get('/status', [SetupWizardController::class, 'checkStatus']);
            Route::get('/available-currencies', [SetupWizardController::class, 'getAvailableCurrencies']);
            Route::get('/currencies', [SetupWizardController::class, 'getCurrencies']); // Legacy endpoint
            Route::get('/subscription-info', [SetupWizardController::class, 'getSubscriptionInfo']);
            Route::post('/fetch-exchange-rates', [SetupWizardController::class, 'fetchExchangeRates']);
            Route::post('/', [SetupWizardController::class, 'store']);
            Route::post('/reset', [SetupWizardController::class, 'reset']);
        });

        // Resource APIs
        Route::apiResource('cities', CityController::class);
        Route::apiResource('countries', CountryController::class);
        Route::apiResource('zones', ZoneController::class);
        Route::apiResource('districts', DistrictController::class);
        Route::apiResource('trades', TradeController::class);
        Route::apiResource('company-codes', CompanyCodeController::class);
        Route::apiResource('brands', BrandController::class)->middleware('check.permission:brands,view');
        Route::apiResource('product-lines', ProductLineController::class)->middleware('check.permission:product_lines,view');
        Route::apiResource('categories', CategoryController::class)->middleware('check.permission:categories,view');
        Route::apiResource('sub-categories', SubCategoryController::class)->middleware('check.permission:sub_categories,view');
        Route::apiResource('item-groups', ItemGroupController::class)->middleware('check.permission:item_groups,view');
        Route::apiResource('supplier-groups', SupplierGroupController::class)->middleware('check.permission:supplier_groups,view');
        Route::get('suppliers/{supplier}/for-purchase-invoice', [SupplierController::class, 'getForPurchaseInvoice'])->middleware('check.permission:suppliers,view'); // Optimized endpoint for purchase invoice
        Route::get('suppliers/{supplier}/items', [SupplierController::class, 'getItems'])->middleware('check.permission:suppliers,view'); // Get supplier items with costs and purchase UOM
        Route::get('suppliers/for-item-management', [SupplierController::class, 'listForItemSupplierManagement'])->middleware(['subscription.limits:opening_balance', 'check.permission:suppliers,view']);
        Route::apiResource('suppliers', SupplierController::class)->middleware(['subscription.limits:opening_balance', 'check.permission:suppliers,view']);

        // Supplier Opening Balances
        Route::prefix('suppliers/{supplier}/opening-balances')->middleware('subscription.limits:opening_balance')->group(function () {
            Route::get('/', [App\Http\Controllers\SupplierOpeningBalanceController::class, 'index']);
            Route::post('/', [App\Http\Controllers\SupplierOpeningBalanceController::class, 'store']);
            Route::post('/bulk', [App\Http\Controllers\SupplierOpeningBalanceController::class, 'bulkStore']);
            Route::get('/available-currencies', [App\Http\Controllers\SupplierOpeningBalanceController::class, 'getAvailableCurrencies']);
            Route::get('/{openingBalance}', [App\Http\Controllers\SupplierOpeningBalanceController::class, 'show']);
            Route::put('/{openingBalance}', [App\Http\Controllers\SupplierOpeningBalanceController::class, 'update']);
            Route::delete('/{openingBalance}', [App\Http\Controllers\SupplierOpeningBalanceController::class, 'destroy']);
            Route::get('/check-currency/{currencyId}', [App\Http\Controllers\SupplierOpeningBalanceController::class, 'checkCurrencyExists']);
        });
        Route::apiResource('payment-terms', PaymentTermController::class)->middleware('check.permission:payment_terms,view');

        // Define specific currency routes BEFORE apiResource to avoid route conflicts
        Route::get('currencies/exchange-rate', [CurrencyController::class, 'getExchangeRate'])->middleware('check.permission:currencies,view');
        Route::post('currencies/convert', [CurrencyController::class, 'convert'])->middleware('check.permission:currencies,view');
        Route::put('currencies/{id}/rate', [CurrencyController::class, 'updateRate'])->middleware('check.permission:currencies,update');
        Route::get('currencies/{id}/rate-history', [CurrencyController::class, 'getRateHistory'])->middleware('check.permission:currencies,view');
        Route::apiResource('currencies', CurrencyController::class)->middleware(['subscription.limits:currency', 'check.permission:currencies,view']);
        Route::apiResource('salesmen', SalesmanController::class)->middleware('check.permission:salesmen,view');
        // Customer attachments (dedicated endpoints; must be before apiResource)
        Route::post('customers/{customer}/attachments', [CustomerController::class, 'uploadAttachments'])->middleware(['subscription.limits:customer', 'check.permission:customers,view']);
        Route::get('customers/{customer}/attachments', [CustomerController::class, 'getAttachments'])->middleware(['check.permission:customers,view']);
        Route::delete('customers/{customer}/attachments/{attachment}', [CustomerController::class, 'deleteAttachment'])->middleware(['check.permission:customers,view']);
        Route::apiResource('customers', CustomerController::class)->middleware(['subscription.limits:customer', 'check.permission:customers,view']);
        Route::apiResource('customer-groups', CustomerGroupController::class)->middleware('check.permission:customer_groups,view');
        // Route::apiResource('customer-attachments', CustomerAttachmentController::class);
        Route::apiResource('payment-methods', PaymentMethodController::class)->middleware('check.permission:payment_methods,view');
        Route::apiResource('refer-bies', ReferByController::class);
        Route::apiResource('branches', BranchController::class)->middleware('check.permission:branches,view');
        Route::apiResource('adjustment-types', AdjustmentTypeController::class);
        Route::apiResource('warehouses', WarehouseController::class)->middleware('check.permission:warehouses,view');
        Route::apiResource('tax-groups', TaxGroupController::class)->middleware('check.permission:tax_groups,view');
        Route::apiResource('rooms', RoomController::class);
        Route::apiResource('sections', SectionController::class);

        // Custom routes for assets (must be before resource route)
        Route::get('/assets/available', [AssetController::class, 'available'])->middleware('check.permission:assets,view');

        Route::apiResource('assets', AssetController::class)->middleware('check.permission:assets,view');
        Route::apiResource('appointments', AppointmentController::class);
        Route::apiResource('visits', VisitController::class);
        Route::post('appointments/find-available-slots', [AppointmentController::class, 'findAvailableSlots']);
        Route::apiResource('tasks', TaskController::class);
        Route::patch('tasks/{task}/status', [TaskStatusController::class, 'update']);
        Route::patch('events/{event}/status', [EventStatusController::class, 'toggleStatus']);
        Route::apiResource('events', EventController::class);
        Route::apiResource('business-types', BusinessTypeController::class);
        Route::apiResource('projects', ProjectController::class)->middleware('check.permission:projects,view');
        Route::apiResource('jobs', JobController::class)->middleware('check.permission:jobs,view');
        Route::apiResource('transaction-series', TransactionSeriesController::class);
        Route::apiResource('cost-centers', CostCenterController::class);
        Route::apiResource('departments', DepartmentController::class);
        Route::apiResource('sales-channels', SalesChannelController::class);
        Route::apiResource('distribution-channels', DistributionChannelController::class);
        Route::apiResource('transportation-channels', TransportationChannelController::class);
        Route::apiResource('media-channels', MediaChannelController::class);
        Route::get('items/services/list', [ItemController::class, 'getServiceItems'])->middleware('check.permission:items,view');
        Route::get('items/all', [ItemController::class, 'getAllItems'])->middleware('check.permission:items,view');
        Route::get('items/for-needed-items', [ItemController::class, 'listForNeededItems'])->middleware('check.permission:items,view');
        Route::get('items/by-barcode', [ItemController::class, 'getItemByBarcode'])->middleware('check.permission:items,view'); // Must come before apiResource
        Route::get('items/search-by-barcode', [ItemController::class, 'searchItemsByBarcode'])->middleware('check.permission:items,view'); // Search items by barcode (partial match) for help grid
        Route::get('items/by-code', [ItemController::class, 'getItemByCode'])->middleware('check.permission:items,view'); // Must come before apiResource
        Route::get('items/{item}/preview', [ItemController::class, 'getItemForPreview'])->middleware('check.permission:items,view'); // Preview endpoint - must come before apiResource
        Route::get('items/{item}/supplier-cost', [ItemController::class, 'getSupplierCost'])->middleware('check.permission:items,view'); // Get supplier cost for item
        Route::get('items/last-invoice-price', [ItemController::class, 'getLastInvoicePrice'])->middleware('check.permission:items,view'); // Get last invoice price
        Route::apiResource('items', ItemController::class)->middleware('check.permission:items,view');
        // Explicit POST route for items update with FormData (method spoofing support)
        Route::post('items/{item}', [ItemController::class, 'update'])->middleware('check.permission:items,view');
        Route::post('items/{item}/attachments', [ItemController::class, 'uploadAttachment'])->middleware('check.permission:items,view');
        Route::get('items/{item}/attachments', [ItemController::class, 'getAttachments'])->middleware('check.permission:items,view');
        Route::delete('items/{item}/attachments/{attachment}', [ItemController::class, 'deleteAttachment'])->middleware('check.permission:items,view');
        Route::get('items/{item}/suppliers', [ItemController::class, 'getSuppliers'])->middleware('check.permission:items,view');
        Route::post('items/{item}/suppliers/attach', [ItemController::class, 'attachSuppliers'])->middleware('check.permission:items,view');
        Route::post('items/{item}/suppliers/{supplier}/update', [ItemController::class, 'updateSupplier'])->middleware('check.permission:items,view');
        Route::post('items/{item}/suppliers/detach', [ItemController::class, 'detachSuppliers'])->middleware('check.permission:items,view');
        Route::post('items/{item}/suppliers/{supplier}/set-primary', [ItemController::class, 'setPrimarySupplier'])->middleware('check.permission:items,view');
        Route::get('items/{item}/unit-of-measurements', [ItemController::class, 'getUnitOfMeasurements'])->middleware('check.permission:items,view');
        Route::post('items/{item}/unit-of-measurements/attach', [ItemController::class, 'attachUOM'])->middleware('check.permission:items,view');
        Route::post('items/{item}/unit-of-measurements/{unitOfMeasurement}/update', [ItemController::class, 'updateUOM'])->middleware('check.permission:items,view');
        Route::post('items/{item}/unit-of-measurements/detach', [ItemController::class, 'detachUOM'])->middleware('check.permission:items,view');
        Route::apiResource('unit-groups', UnitGroupController::class)->middleware('check.permission:unit_groups,view');
        Route::get('unit-groups/{unitGroup}/units', [\App\Http\Controllers\UnitGroupController::class, 'units'])->middleware('check.permission:unit_groups,view');
        Route::get('units/{unitOfMeasurement}/operations', [\App\Http\Controllers\UnitOfMeasurementController::class, 'operations'])->middleware('check.permission:unit_of_measurements,view');
        Route::get('units/{unitOfMeasurement}/conversions', [\App\Http\Controllers\UnitOfMeasurementController::class, 'conversions'])->middleware('check.permission:unit_of_measurements,view');
        Route::apiResource('unit-of-measurements', UnitOfMeasurementController::class)->middleware('check.permission:unit_of_measurements,view');
        Route::get('invoices/next-number', [InvoiceController::class, 'getNextInvoiceNumber'])->middleware('check.permission:invoices,view');
        Route::get('invoices/last-invoice', [InvoiceController::class, 'getLastInvoice'])->middleware('check.permission:invoices,view');
        Route::apiResource('invoices', InvoiceController::class)->middleware('check.permission:invoices,view');
        Route::apiResource('customer-master-lists', CustomerMasterListController::class);
        Route::apiResource('specialities', SpecialityController::class)->middleware('check.permission:specialities,view');
        Route::apiResource('specialists', SpecialistController::class)->middleware('check.permission:specialists,view');
        Route::get('services/names', [ServiceController::class, 'listNames'])->middleware('check.permission:services,view');
        Route::apiResource('services', ServiceController::class)->middleware('check.permission:services,view');
        // Explicit POST route for services update with FormData (method spoofing support)
        Route::post('services/{service}', [ServiceController::class, 'update'])->middleware('check.permission:services,view');
        Route::apiResource('service-needed-items', ServiceNeededItemController::class);
        Route::apiResource('service-advanced-pricings', ServiceAdvancedPricingController::class);
        Route::apiResource('associations', AssociationController::class)->middleware('check.permission:associations,view');
        Route::get('associations-names', [AssociationController::class, 'getNames']);
        Route::apiResource('association-contacts', AssociationContactController::class);
        Route::apiResource('association-service-prices', AssociationServicePriceController::class);
        // Custom routes for media-types (must be before resource route)
        Route::get('media-types/parents', [MediaTypeController::class, 'getParentMediaTypes']);
        Route::get('media-types/hierarchy', [MediaTypeController::class, 'getHierarchy']);
        Route::get('media-types/{media_type}/sub-types', [MediaTypeController::class, 'getSubMediaTypes']);
        Route::apiResource('media-types', MediaTypeController::class);
        Route::apiResource('service-categories', ServiceCategoryController::class)->middleware('check.permission:service_categories,view');
        Route::post('service-categories/bulk-delete', [ServiceCategoryController::class, 'bulkDelete']);
        Route::apiResource('referrers', ReferrerController::class)->middleware('check.permission:referrers,view');
        Route::get('referrers-names', [ReferrerController::class, 'getNames']);
        Route::apiResource('referrer-service-commissions', ReferrerServiceCommissionController::class);

        // Customer Master List additional routes
        Route::prefix('customer-master-lists')->group(function () {
            Route::get('active/list', [CustomerMasterListController::class, 'active']);
            Route::post('valid-on', [CustomerMasterListController::class, 'validOn']);
            Route::post('{customerMasterList}/attach-items', [CustomerMasterListController::class, 'attachItems']);
            Route::post('{customerMasterList}/detach-items', [CustomerMasterListController::class, 'detachItems']);
        });

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
            Route::get('customers', [CustomerController::class, 'exportExcell'])->middleware('check.permission:customers,export');
            Route::get('cities', [CityController::class, 'exportExcell'])->middleware('check.permission:cities,export');
            Route::get('countries', [CountryController::class, 'exportExcell'])->middleware('check.permission:countries,export');
            Route::get('zones', [ZoneController::class, 'exportExcell'])->middleware('check.permission:zones,export');
            Route::get('districts', [DistrictController::class, 'exportExcell'])->middleware('check.permission:districts,export');
            Route::get('currencies', [CurrencyController::class, 'exportExcell'])->middleware('check.permission:currencies,export');
            Route::get('customer-groups', [CustomerGroupController::class, 'exportExcel'])->middleware('check.permission:customer_groups,export');
            Route::get('item-groups', [ItemGroupController::class, 'exportExcel'])->middleware('check.permission:item_groups,export');
            Route::get('payment-methods', [PaymentMethodController::class, 'exportExcell'])->middleware('check.permission:payment_methods,export');
            Route::get('payment-terms', [PaymentTermController::class, 'exportExcell'])->middleware('check.permission:payment_terms,export');
            Route::get('salesmen', [SalesmanController::class, 'exportExcell'])->middleware('check.permission:salesmen,export');
            Route::get('refer-bies', [ReferByController::class, 'exportExcell'])->middleware('check.permission:refer_bies,export');
            Route::get('trades', [TradeController::class, 'exportExcell'])->middleware('check.permission:trades,export');
            Route::get('company-codes', [CompanyCodeController::class, 'exportExcell'])->middleware('check.permission:company_codes,export');
            Route::get('brands', [BrandController::class, 'exportExcel'])->middleware('check.permission:brands,export');
            Route::get('product-lines', [ProductLineController::class, 'exportExcel'])->middleware('check.permission:product_lines,export');
            Route::get('categories', [CategoryController::class, 'exportExcell'])->middleware('check.permission:categories,export');
            Route::get('sub-categories', [SubCategoryController::class, 'exportExcell'])->middleware('check.permission:sub_categories,export');
            Route::get('supplier-groups', [SupplierGroupController::class, 'exportExcell'])->middleware('check.permission:supplier_groups,export');
            Route::get('suppliers', [SupplierController::class, 'exportExcell'])->middleware('check.permission:suppliers,export');
            Route::get('branches', [BranchController::class, 'exportExcell'])->middleware('check.permission:branches,export');
            Route::get('adjustment-types', [AdjustmentTypeController::class, 'exportExcell'])->middleware('check.permission:adjustment_types,export');
            Route::get('warehouses', [WarehouseController::class, 'exportExcell'])->middleware('check.permission:warehouses,export');
            Route::get('tax-groups', [TaxGroupController::class, 'exportExcell'])->middleware('check.permission:tax_groups,export');
            Route::get('rooms', [RoomController::class, 'exportExcell'])->middleware('check.permission:rooms,export');
            Route::get('sections', [SectionController::class, 'exportExcell'])->middleware('check.permission:sections,export');
            Route::get('assets', [AssetController::class, 'exportExcell'])->middleware('check.permission:assets,export');
            Route::get('appointments', [AppointmentController::class, 'exportExcell'])->middleware('check.permission:appointments,export');
            Route::get('business-types', [BusinessTypeController::class, 'exportExcell'])->middleware('check.permission:business_types,export');
            Route::get('projects', [ProjectController::class, 'exportExcel'])->middleware('check.permission:projects,export');
            Route::get('jobs', [JobController::class, 'exportExcell'])->middleware('check.permission:jobs,export');
            Route::get('transaction-series', [TransactionSeriesController::class, 'exportExcell'])->middleware('check.permission:transaction_series,export');
            Route::get('cost-centers', [CostCenterController::class, 'exportExcell'])->middleware('check.permission:cost_centers,export');
            Route::get('departments', [DepartmentController::class, 'exportExcell'])->middleware('check.permission:departments,export');
            Route::get('sales-channels', [SalesChannelController::class, 'exportExcell'])->middleware('check.permission:sales_channels,export');
            Route::get('distribution-channels', [DistributionChannelController::class, 'exportExcell'])->middleware('check.permission:distribution_channels,export');
            Route::get('transportation-channels', [TransportationChannelController::class, 'exportExcell'])->middleware('check.permission:transportation_channels,export');
            Route::get('media-channels', [MediaChannelController::class, 'exportExcell'])->middleware('check.permission:media_channels,export');
            Route::get('items', [ItemController::class, 'exportExcell'])->middleware('check.permission:items,export');
            Route::get('customer-master-lists', [CustomerMasterListController::class, 'exportExcell'])->middleware('check.permission:customer_master_lists,export');
            Route::get('specialities', [SpecialityController::class, 'exportExcell'])->middleware('check.permission:specialities,export');
            Route::get('specialists', [SpecialistController::class, 'exportExcell'])->middleware('check.permission:specialists,export');
            Route::get('services', [ServiceController::class, 'exportExcell'])->middleware('check.permission:services,export');
            Route::get('service-categories', [ServiceCategoryController::class, 'exportExcell'])->middleware('check.permission:service_categories,export');
            Route::get('service-needed-items', [ServiceNeededItemController::class, 'exportExcell'])->middleware('check.permission:service_needed_items,export');
            Route::get('service-advanced-pricings', [ServiceAdvancedPricingController::class, 'exportExcell'])->middleware('check.permission:service_advanced_pricings,export');
            Route::get('associations', [AssociationController::class, 'exportExcell'])->middleware('check.permission:associations,export');
            Route::get('association-contacts', [AssociationContactController::class, 'exportExcell'])->middleware('check.permission:association_contacts,export');
            Route::get('association-service-prices', [AssociationServicePriceController::class, 'exportExcell'])->middleware('check.permission:association_service_prices,export');
            Route::get('media-types', [MediaTypeController::class, 'exportExcell'])->middleware('check.permission:media_types,export');
            Route::get('referrers', [ReferrerController::class, 'exportExcell'])->middleware('check.permission:referrers,export');
            Route::get('referrer-service-commissions', [ReferrerServiceCommissionController::class, 'exportExcell'])->middleware('check.permission:referrer_service_commissions,export');
            Route::get('roles', [RoleController::class, 'exportExcell'])->middleware('check.permission:roles,export');
            Route::get('permissions', [PermissionController::class, 'exportExcell'])->middleware('check.permission:permissions,export');
        });

        // Export to PDF Routes
        // Note: The export routes are prefixed with 'exportPdf' to avoid confusion with the import routes.
        Route::prefix('exportPdf')->group(function () {
            Route::get('/customers', [CustomerController::class, 'exportPdf'])->middleware('check.permission:customers,export');
            Route::get('/cities', [CityController::class, 'exportPdf'])->middleware('check.permission:cities,export');
            Route::get('/countries', [CountryController::class, 'exportPdf'])->middleware('check.permission:countries,export');
            Route::get('/districts', [DistrictController::class, 'exportPdf'])->middleware('check.permission:districts,export');
            Route::get('/zones', [ZoneController::class, 'exportPdf'])->middleware('check.permission:zones,export');
            Route::get('/currencies', [CurrencyController::class, 'exportPdf'])->middleware('check.permission:currencies,export');
            Route::get('/customer-groups', [CustomerGroupController::class, 'exportPdf'])->middleware('check.permission:customer_groups,export');
            Route::get('/item-groups', [ItemGroupController::class, 'exportPdf'])->middleware('check.permission:item_groups,export');
            Route::get('/payment-methods', [PaymentMethodController::class, 'exportPdf'])->middleware('check.permission:payment_methods,export');
            Route::get('/payment-terms', [PaymentTermController::class, 'exportPdf'])->middleware('check.permission:payment_terms,export');
            Route::get('/salesmen', [SalesmanController::class, 'exportPdf'])->middleware('check.permission:salesmen,export');
            Route::get('/refer-bies', [ReferByController::class, 'exportPdf'])->middleware('check.permission:refer_bies,export');
            Route::get('/trades', [TradeController::class, 'exportPdf'])->middleware('check.permission:trades,export');
            Route::get('/company-codes', [CompanyCodeController::class, 'exportPdf'])->middleware('check.permission:company_codes,export');
            Route::get('/brands', [BrandController::class, 'exportPdf'])->middleware('check.permission:brands,export');
            Route::get('/product-lines', [ProductLineController::class, 'exportPdf'])->middleware('check.permission:product_lines,export');
            Route::get('/categories', [CategoryController::class, 'exportPdf'])->middleware('check.permission:categories,export');
            Route::get('/sub-categories', [SubCategoryController::class, 'exportPdf'])->middleware('check.permission:sub_categories,export');
            Route::get('/supplier-groups', [SupplierGroupController::class, 'exportPdf'])->middleware('check.permission:supplier_groups,export');
            Route::get('/suppliers', [SupplierController::class, 'exportPdf'])->middleware('check.permission:suppliers,export');
            Route::get('/branches', [BranchController::class, 'exportPdf'])->middleware('check.permission:branches,export');
            Route::get('/adjustment-types', [AdjustmentTypeController::class, 'exportPdf'])->middleware('check.permission:adjustment_types,export');
            Route::get('/warehouses', [WarehouseController::class, 'exportPdf'])->middleware('check.permission:warehouses,export');
            Route::get('/tax-groups', [TaxGroupController::class, 'exportPdf'])->middleware('check.permission:tax_groups,export');
            Route::get('/rooms', [RoomController::class, 'exportPdf'])->middleware('check.permission:rooms,export');
            Route::get('/sections', [SectionController::class, 'exportPdf'])->middleware('check.permission:sections,export');
            Route::get('/assets', [AssetController::class, 'exportPdf'])->middleware('check.permission:assets,export');
            Route::get('/appointments', [AppointmentController::class, 'exportPdf'])->middleware('check.permission:appointments,export');
            Route::get('/business-types', [BusinessTypeController::class, 'exportPdf'])->middleware('check.permission:business_types,export');
            Route::get('/projects', [ProjectController::class, 'exportPdf'])->middleware('check.permission:projects,export');
            Route::get('/jobs', [JobController::class, 'exportPdf'])->middleware('check.permission:jobs,export');
            Route::get('/transaction-series', [TransactionSeriesController::class, 'exportPdf'])->middleware('check.permission:transaction_series,export');
            Route::get('/cost-centers', [CostCenterController::class, 'exportPdf'])->middleware('check.permission:cost_centers,export');
            Route::get('/departments', [DepartmentController::class, 'exportPdf'])->middleware('check.permission:departments,export');
            Route::get('/sales-channels', [SalesChannelController::class, 'exportPdf'])->middleware('check.permission:sales_channels,export');
            Route::get('/distribution-channels', [DistributionChannelController::class, 'exportPdf'])->middleware('check.permission:distribution_channels,export');
            Route::get('/transportation-channels', [TransportationChannelController::class, 'exportPdf'])->middleware('check.permission:transportation_channels,export');
            Route::get('/media-channels', [MediaChannelController::class, 'exportPdf'])->middleware('check.permission:media_channels,export');
            Route::get('/items', [ItemController::class, 'exportPdf'])->middleware('check.permission:items,export');
            Route::get('/customer-master-lists', [CustomerMasterListController::class, 'exportPdf'])->middleware('check.permission:customer_master_lists,export');
            Route::get('/specialities', [SpecialityController::class, 'exportPdf'])->middleware('check.permission:specialities,export');
            Route::get('/specialists', [SpecialistController::class, 'exportPdf'])->middleware('check.permission:specialists,export');
            Route::get('/services', [ServiceController::class, 'exportPdf'])->middleware('check.permission:services,export');
            Route::get('/service-categories', [ServiceCategoryController::class, 'exportPdf'])->middleware('check.permission:service_categories,export');
            Route::get('/service-needed-items', [ServiceNeededItemController::class, 'exportPdf'])->middleware('check.permission:service_needed_items,export');
            Route::get('/service-advanced-pricings', [ServiceAdvancedPricingController::class, 'exportPdf'])->middleware('check.permission:service_advanced_pricings,export');
            Route::get('/associations', [AssociationController::class, 'exportPdf'])->middleware('check.permission:associations,export');
            Route::get('/association-contacts', [AssociationContactController::class, 'exportPdf'])->middleware('check.permission:association_contacts,export');
            Route::get('/association-service-prices', [AssociationServicePriceController::class, 'exportPdf'])->middleware('check.permission:association_service_prices,export');
            Route::get('/media-types', [MediaTypeController::class, 'exportPdf'])->middleware('check.permission:media_types,export');
            Route::get('/referrers', [ReferrerController::class, 'exportPdf'])->middleware('check.permission:referrers,export');
            Route::get('/referrer-service-commissions', [ReferrerServiceCommissionController::class, 'exportPdf'])->middleware('check.permission:referrer_service_commissions,export');
            Route::get('/roles', [RoleController::class, 'exportPdf'])->middleware('check.permission:roles,export');
            Route::get('/permissions', [PermissionController::class, 'exportPdf'])->middleware('check.permission:permissions,export');
        });

        // Import from Excel Routes
        // Note: The import routes are prefixed with 'importFromExcel' to avoid confusion with the export routes.
        Route::prefix('importFromExcel')->group(function () {
            Route::post('/customers', [CustomerController::class, 'importFromExcel'])->middleware('check.permission:customers,import');
            Route::post('/cities', [CityController::class, 'importFromExcel'])->middleware('check.permission:cities,import');
            Route::post('/countries', [CountryController::class, 'importFromExcel'])->middleware('check.permission:countries,import');
            Route::post('/countries/headers', [CountryController::class, 'importHeaders'])->middleware('check.permission:countries,import');
            Route::post('/countries/dry-run', [CountryController::class, 'importDryRun'])->middleware('check.permission:countries,import');
            Route::get('/countries/schema', [CountryController::class, 'importSchema'])->middleware('check.permission:countries,import');
            Route::post('/zones', [ZoneController::class, 'importFromExcel'])->middleware('check.permission:zones,import');
            Route::post('/districts', [DistrictController::class, 'importFromExcel'])->middleware('check.permission:districts,import');
            Route::post('/currencies', [CurrencyController::class, 'importFromExcel'])->middleware('check.permission:currencies,import');
            Route::post('/customer-groups', [CustomerGroupController::class, 'importFromExcel'])->middleware('check.permission:customer_groups,import');
            Route::post('/item-groups', [ItemGroupController::class, 'importFromExcel'])->middleware('check.permission:item_groups,import');
            Route::post('/payment-methods', [PaymentMethodController::class, 'importFromExcel'])->middleware('check.permission:payment_methods,import');
            Route::post('/payment-terms', [PaymentTermController::class, 'importFromExcel'])->middleware('check.permission:payment_terms,import');
            Route::post('/salesmen', [SalesmanController::class, 'importFromExcel'])->middleware('check.permission:salesmen,import');
            Route::post('/refer-bies', [ReferByController::class, 'importFromExcel'])->middleware('check.permission:refer_bies,import');
            Route::post('/trades', [TradeController::class, 'importFromExcel'])->middleware('check.permission:trades,import');
            Route::post('/company-codes', [CompanyCodeController::class, 'importFromExcel'])->middleware('check.permission:company_codes,import');
            Route::post('/brands', [BrandController::class, 'importFromExcel'])->middleware('check.permission:brands,import');
            Route::post('/product-lines', [ProductLineController::class, 'importFromExcel'])->middleware('check.permission:product_lines,import');
            Route::post('/categories', [CategoryController::class, 'importFromExcel'])->middleware('check.permission:categories,import');
            Route::post('/sub-categories', [SubCategoryController::class, 'importFromExcel'])->middleware('check.permission:sub_categories,import');
            Route::post('/supplier-groups', [SupplierGroupController::class, 'importFromExcel'])->middleware('check.permission:supplier_groups,import');
            Route::post('/suppliers', [SupplierController::class, 'importFromExcel'])->middleware('check.permission:suppliers,import');
            Route::post('/branches', [BranchController::class, 'importFromExcel'])->middleware('check.permission:branches,import');
            Route::post('/adjustment-types', [AdjustmentTypeController::class, 'importFromExcel'])->middleware('check.permission:adjustment_types,import');
            Route::post('/warehouses', [WarehouseController::class, 'importFromExcel'])->middleware('check.permission:warehouses,import');
            Route::post('/tax-groups', [TaxGroupController::class, 'importFromExcel'])->middleware('check.permission:tax_groups,import');
            Route::post('/rooms', [RoomController::class, 'importFromExcel'])->middleware('check.permission:rooms,import');
            Route::post('/sections', [SectionController::class, 'importFromExcel'])->middleware('check.permission:sections,import');
            Route::post('/assets', [AssetController::class, 'importFromExcel'])->middleware('check.permission:assets,import');
            Route::post('/appointments', [AppointmentController::class, 'importFromExcel'])->middleware('check.permission:appointments,import');
            Route::post('/business-types', [BusinessTypeController::class, 'importFromExcel'])->middleware('check.permission:business_types,import');
            Route::post('/projects', [ProjectController::class, 'importFromExcel'])->middleware('check.permission:projects,import');
            Route::post('/jobs', [JobController::class, 'importFromExcel'])->middleware('check.permission:jobs,import');
            Route::post('/transaction-series', [TransactionSeriesController::class, 'importFromExcel'])->middleware('check.permission:transaction_series,import');
            Route::post('/cost-centers', [CostCenterController::class, 'importFromExcel'])->middleware('check.permission:cost_centers,import');
            Route::post('/departments', [DepartmentController::class, 'importFromExcel'])->middleware('check.permission:departments,import');
            Route::post('/sales-channels', [SalesChannelController::class, 'importFromExcel'])->middleware('check.permission:sales_channels,import');
            Route::post('/distribution-channels', [DistributionChannelController::class, 'importFromExcel'])->middleware('check.permission:distribution_channels,import');
            Route::post('/transportation-channels', [TransportationChannelController::class, 'importFromExcel'])->middleware('check.permission:transportation_channels,import');
            Route::post('/media-channels', [MediaChannelController::class, 'importFromExcel'])->middleware('check.permission:media_channels,import');
            Route::post('/items', [ItemController::class, 'importFromExcel'])->middleware('check.permission:items,import');
            Route::post('/customer-master-lists', [CustomerMasterListController::class, 'importFromExcel'])->middleware('check.permission:customer_master_lists,import');
            Route::post('/specialities', [SpecialityController::class, 'importFromExcel'])->middleware('check.permission:specialities,import');
            Route::post('/specialists', [SpecialistController::class, 'importFromExcel'])->middleware('check.permission:specialists,import');
            Route::post('/services', [ServiceController::class, 'importFromExcel'])->middleware('check.permission:services,import');
            Route::post('/service-categories', [ServiceCategoryController::class, 'importFromExcel'])->middleware('check.permission:service_categories,import');
            Route::post('/service-needed-items', [ServiceNeededItemController::class, 'importFromExcel'])->middleware('check.permission:service_needed_items,import');
            Route::post('/service-advanced-pricings', [ServiceAdvancedPricingController::class, 'importFromExcel'])->middleware('check.permission:service_advanced_pricings,import');
            Route::post('/associations', [AssociationController::class, 'importFromExcel'])->middleware('check.permission:associations,import');
            Route::post('/association-contacts', [AssociationContactController::class, 'importFromExcel'])->middleware('check.permission:association_contacts,import');
            Route::post('/association-service-prices', [AssociationServicePriceController::class, 'importFromExcel'])->middleware('check.permission:association_service_prices,import');
            Route::post('/media-types', [MediaTypeController::class, 'importFromExcel'])->middleware('check.permission:media_types,import');
            Route::post('/referrers', [ReferrerController::class, 'importFromExcel'])->middleware('check.permission:referrers,import');
            Route::post('/referrer-service-commissions', [ReferrerServiceCommissionController::class, 'importFromExcel'])->middleware('check.permission:referrer_service_commissions,import');
            Route::post('/roles', [RoleController::class, 'importFromExcel'])->middleware('check.permission:roles,import');
            Route::post('/permissions', [PermissionController::class, 'importFromExcel'])->middleware('check.permission:permissions,import');
        });

        // Bulk Delete Routes
        // Note: The bulk delete routes are prefixed with 'bulk-delete' to avoid confusion with the import/export routes.
        Route::prefix('bulk-delete')->group(function () {
            Route::delete('/customers', [CustomerController::class, 'bulkDelete']);
            Route::delete('/cities', [CityController::class, 'bulkDelete']);
            Route::delete('/countries', [CountryController::class, 'bulkDelete']);
            Route::delete('/districts', [DistrictController::class, 'bulkDelete']);
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
            Route::delete('/sub-categories', [SubCategoryController::class, 'bulkDelete']);
            Route::delete('/item-groups', [ItemGroupController::class, 'bulkDelete']);
            Route::delete('/unit-groups', [UnitGroupController::class, 'bulkDelete']);
            Route::delete('/unit-of-measurements', [UnitOfMeasurementController::class, 'bulkDelete']);
            Route::delete('/supplier-groups', [SupplierGroupController::class, 'bulkDelete']);
            Route::delete('/suppliers', [SupplierController::class, 'bulkDelete']);
            Route::delete('/branches', [BranchController::class, 'bulkDelete']);
            Route::delete('/adjustment-types', [AdjustmentTypeController::class, 'bulkDelete']);
            Route::delete('/warehouses', [WarehouseController::class, 'bulkDelete'])->middleware('check.permission:warehouses,delete');
            Route::delete('/tax-groups', [TaxGroupController::class, 'bulkDelete'])->middleware('check.permission:tax_groups,delete');
            Route::delete('/rooms', [RoomController::class, 'bulkDelete']);
            Route::delete('/sections', [SectionController::class, 'bulkDelete']);
            Route::delete('/assets', [AssetController::class, 'bulkDelete'])->middleware('check.permission:assets,delete');
            Route::delete('/appointments', [AppointmentController::class, 'bulkDelete']);

            // Custom routes for sections
            Route::get('/rooms/{room}/sections', [SectionController::class, 'byRoom']);

            // Custom routes for assets
            Route::get('/sections/{section}/assets', [AssetController::class, 'bySection']);

            // Custom routes for appointments
            Route::get('/assets/{asset}/appointments', [AppointmentController::class, 'byAsset']);
            Route::get('/specialists/{specialist}/appointments', [AppointmentController::class, 'bySpecialist']);
            Route::get('/appointments/active', [AppointmentController::class, 'active']);

            // Custom routes for tasks
            Route::get('/tasks/for/{schedulableType}/{schedulableId}', [TaskController::class, 'forSchedulable']);

            // Custom routes for events
            Route::get('/events/for/{schedulableType}/{schedulableId}', [EventController::class, 'forSchedulable']);

            // Scheduler Unit routes (aggregated data)
            Route::get('/scheduler-units/{schedulableType}/{schedulableId}', [SchedulerUnitController::class, 'show']);
            Route::get('/scheduler-units/{schedulableType}/{schedulableId}/timeline', [SchedulerUnitController::class, 'timeline']);

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
            Route::delete('/invoices', [InvoiceController::class, 'bulkDelete'])->middleware('check.permission:invoices,delete');
            Route::delete('/specialities', [SpecialityController::class, 'bulkDelete']);
            Route::delete('/specialists', [SpecialistController::class, 'bulkDelete']);
            Route::delete('/services', [ServiceController::class, 'bulkDelete']);
            Route::delete('/service-needed-items', [ServiceNeededItemController::class, 'bulkDelete']);
            Route::delete('/service-advanced-pricings', [ServiceAdvancedPricingController::class, 'bulkDelete']);
            Route::delete('/associations', [AssociationController::class, 'bulkDelete']);
            Route::delete('/association-contacts', [AssociationContactController::class, 'bulkDelete']);
            Route::delete('/association-service-prices', [AssociationServicePriceController::class, 'bulkDelete']);
            Route::delete('/media-types', [MediaTypeController::class, 'bulkDelete']);
            Route::delete('/referrers', [ReferrerController::class, 'bulkDelete']);
            Route::delete('/referrer-service-commissions', [ReferrerServiceCommissionController::class, 'bulkDelete']);
            Route::delete('/roles', [RoleController::class, 'bulkDelete']);
            Route::delete('/permissions', [PermissionController::class, 'bulkDelete']);
        });

        // Specialist association routes
        Route::prefix('specialists/{specialist}')->group(function () {
            Route::post('/attach-specialities', [SpecialistController::class, 'attachSpecialities']);
            Route::post('/detach-specialities', [SpecialistController::class, 'detachSpecialities']);
            Route::post('/attach-assets', [SpecialistController::class, 'attachAssets']);
            Route::post('/detach-assets', [SpecialistController::class, 'detachAssets']);
        });

        // Service nested needed items
        Route::get('services/{service}/needed-items', [ServiceNeededItemController::class, 'indexByService']);
        // Service nested advanced pricing
        Route::get('services/{service}/advanced-pricing', [ServiceAdvancedPricingController::class, 'indexByService']);
        // Association nested contacts
        Route::get('associations/{association}/contacts', [AssociationContactController::class, 'byAssociation']);
        // Nested association service prices
        Route::get('associations/{association}/service-prices', [AssociationServicePriceController::class, 'byAssociation']);
        Route::get('services/{service}/association-prices', [AssociationServicePriceController::class, 'byService']);
        // Nested referrer service commissions
        Route::get('referrers/{referrer}/service-commissions', [ReferrerServiceCommissionController::class, 'byReferrer']);
        Route::get('services/{service}/referrer-commissions', [ReferrerServiceCommissionController::class, 'byService']);

        // Pricing engine
        Route::post('pricing/resolve', [PricingController::class, 'resolvePrice']);

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

        // Hard purge selected tables for current tenant (force delete)
        Route::post('purge', [TenantPurgeController::class, 'purge']);

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

        // Role Management
        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->middleware('check.permission:roles,view');
            Route::post('/', [RoleController::class, 'store'])->middleware('check.permission:roles,add');
            Route::get('/active', [RoleController::class, 'active'])->middleware('check.permission:roles,view');
            Route::get('/{role}', [RoleController::class, 'show'])->middleware('check.permission:roles,view');
            Route::put('/{role}', [RoleController::class, 'update'])->middleware('check.permission:roles,edit');
            Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('check.permission:roles,delete');
            Route::patch('/{role}/toggle-status', [RoleController::class, 'toggleStatus'])->middleware('check.permission:roles,edit');
        });

        // User-Role Management
        Route::prefix('user-roles')->group(function () {
            Route::get('/user/{user}/roles', [UserRoleController::class, 'getUserRoles'])->middleware('check.permission:roles,view');
            Route::get('/role/{role}/users', [UserRoleController::class, 'getRoleUsers'])->middleware('check.permission:roles,view');
            Route::post('/user/{user}/assign-roles', [UserRoleController::class, 'assignRoles'])->middleware('check.permission:roles,edit');
            Route::delete('/user/{user}/remove-roles', [UserRoleController::class, 'removeRoles'])->middleware('check.permission:roles,edit');
            Route::post('/user/{user}/check-role', [UserRoleController::class, 'checkUserRole'])->middleware('check.permission:roles,view');
        });

        // Permission Management
        Route::prefix('permissions')->group(function () {
            Route::get('/', [PermissionController::class, 'index'])->middleware('check.permission:permissions,view');
            Route::post('/', [PermissionController::class, 'store'])->middleware('check.permission:permissions,add');
            Route::get('/{permission}', [PermissionController::class, 'show'])->middleware('check.permission:permissions,view');
            Route::put('/{permission}', [PermissionController::class, 'update'])->middleware('check.permission:permissions,edit');
            Route::delete('/{permission}', [PermissionController::class, 'destroy'])->middleware('check.permission:permissions,delete');
        });

        // Role-Permission Management
        Route::prefix('role-permissions')->group(function () {
            Route::get('/role/{role}/permissions', [RolePermissionController::class, 'getRolePermissions'])->middleware('check.permission:roles,view');
            Route::get('/permission/{permission}/roles', [RolePermissionController::class, 'getPermissionRoles'])->middleware('check.permission:permissions,view');
            Route::post('/role/{role}/assign-permissions', [RolePermissionController::class, 'assignPermissions'])->middleware('check.permission:roles,edit');
            Route::put('/role/{role}/permission/{permission}', [RolePermissionController::class, 'updatePermission'])->middleware('check.permission:roles,edit');
            Route::delete('/role/{role}/permission/{permission}', [RolePermissionController::class, 'removePermission'])->middleware('check.permission:roles,edit');
            Route::post('/role/{role}/check-permission', [RolePermissionController::class, 'checkPermission'])->middleware('check.permission:roles,view');
        });
    });

    //  Email Verification Routes
    Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
        $user = User::find($id);
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        Auth::login($user); // Log the user in manually in tenant context
        if (! hash_equals((string) $id, (string) $user->getKey())) {
            return response()->json(['message' => 'Invalid user ID.'], 403);
        }
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
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
    Route::post('/forgot-password', [AuthForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password', [AuthResetPasswordController::class, 'reset']);
});

// These routes use 'api' middleware instead of 'web' to avoid session/CSRF issues
// when called from frontend API requests, especially concurrent requests
Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/names/customers', [CustomerController::class, 'getNames']);
    Route::post('/customers/search-by-phone', [CustomerController::class, 'searchByPhone']);
    Route::get('/customers/{customerId}/appointments', [CustomerController::class, 'getAppointmentHistory']);
    Route::get('/customers/{customerId}/visits', [CustomerController::class, 'getVisitHistory']);
    Route::get('/customers/{customerId}/for-invoice', [CustomerController::class, 'getForInvoice']);
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
    Route::get('/names/item-groups', [ItemGroupController::class, 'getNames']);
    Route::get('/names/salesmen', [SalesmanController::class, 'getNames']);
    Route::get('/names/trades', [TradeController::class, 'getNames']);
    Route::get('/names/items', [ItemController::class, 'getNames']);
    Route::get('/items/{itemId}/for-invoice', [ItemController::class, 'getItemForInvoice']);
    Route::get('/names/suppliers', [SupplierController::class, 'getNames']);
    Route::get('/names/suppliers-brief', [SupplierController::class, 'getBrief']);
});
