<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessTypeController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmployeeActivityController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FinancialTransactionController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\StorageController;
use App\Http\Controllers\Api\V1\SuperUserController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TelemetryController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public Application Configuration & Metadata Routes
    Route::get('/app-config', [AppConfigController::class, 'show']);
    Route::get('/business-types', [BusinessTypeController::class, 'types']);
    Route::get('/business-sub-types', [BusinessTypeController::class, 'subTypes']);
    Route::get('/product-masters', [ProductController::class, 'masters']);
        Route::post('/product-masters', [ProductController::class, 'storeMaster'])
            ->middleware(['auth:sanctum', 'active', 'permission:product.create,products.create']);
    Route::get('/products/central/search', [ProductController::class, 'searchCentral']);
    // Public Authentication & Registration Routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:30,1');
        Route::post('/email-otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:30,1');
        Route::post('/email-otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:60,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:60,1')->name('login');

        // Authenticated Session Routes
        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    // Authenticated Business & Workspace Routes
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        // Authenticated client telemetry and feature state.
        Route::get('/app/feature-flags', [TelemetryController::class, 'featureFlags']);
        Route::post('/telemetry/login', [TelemetryController::class, 'login']);
        Route::post('/telemetry/heartbeat', [TelemetryController::class, 'heartbeat']);
        Route::post('/telemetry/screen', [TelemetryController::class, 'recordScreen']);
        Route::post('/telemetry/logout', [TelemetryController::class, 'logout']);

        Route::get('/businesses', [BusinessController::class, 'index']);
        Route::post('/businesses', [BusinessController::class, 'store'])
            ->middleware('permission:businesses.create,businesses');
        Route::get('/businesses/{id}', [BusinessController::class, 'show']);
        Route::get('/businesses/{id}/outlets', [BusinessController::class, 'outlets']);
        Route::post('/businesses/{id}/outlets', [BusinessController::class, 'storeOutlet'])
            ->middleware('permission:outlet.create,businesses');
        Route::get('/businesses/{id}/settings', [BusinessController::class, 'settings'])
            ->middleware('permission:settings.view,settings');
        Route::put('/businesses/{id}/settings', [BusinessController::class, 'saveSettings'])
            ->middleware('permission:settings.edit,settings');

        // Product Catalog & Master Data Routes
        Route::get('/products', [ProductController::class, 'index'])
            ->middleware('permission:products.view,product.read,inventory.view,sales,billing,barcode');
        Route::post('/products/barcodes/generate-missing', [ProductController::class, 'generateMissingBarcodes'])
            ->middleware('permission:barcode,products.view,product.read,products.update,product.update');
        Route::post('/products', [ProductController::class, 'store'])
            ->middleware('permission:products.create,product.create');
        Route::post('/products/status', [ProductController::class, 'updateStatus'])
            ->middleware('permission:products.update,product.update');
        Route::post('/outlets/{outletId}/products/remove', [ProductController::class, 'removeFromOutlet'])
            ->middleware('permission:products.update,product.update');
        Route::post('/outlets/{outletId}/products/assign', [ProductController::class, 'assignToOutlet'])
            ->middleware('permission:products.update,product.update');
        Route::get('/products/{id}', [ProductController::class, 'show'])
            ->middleware('permission:products.view,product.read,inventory.view,sales,billing,barcode');
        Route::put('/products/{id}', [ProductController::class, 'update'])
            ->middleware('permission:products.update,product.update');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])
            ->middleware('permission:products.delete,product.delete');

        // Customer Management & Khata (Credit Ledger) Routes
        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('permission:CUSTOMER_VIEW,CUSTOMER_EDIT,customers,billing');
        Route::post('/customers', [CustomerController::class, 'store'])
            ->middleware('permission:CUSTOMER_CREATE,customer.create,customers.create,CUSTOMER_EDIT,customers');
        Route::get('/customers/{id}', [CustomerController::class, 'show'])
            ->middleware('permission:CUSTOMER_VIEW,CUSTOMER_EDIT,customers,billing');
        Route::put('/customers/{id}', [CustomerController::class, 'update'])
            ->middleware('permission:CUSTOMER_EDIT,customers');
        Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])
            ->middleware('permission:CUSTOMER_EDIT,customers');
        Route::get('/customers/{id}/ledger', [CustomerController::class, 'ledger'])
            ->middleware('permission:CUSTOMER_LEDGER_VIEW,CUSTOMER_EDIT,customers');
        Route::post('/customers/{id}/payments', [CustomerController::class, 'recordPayment'])
            ->middleware('permission:DUE_COLLECT,billing');

        // Supplier Management & Supplier Khata Routes
        Route::get('/suppliers', [SupplierController::class, 'index'])
            ->middleware('permission:suppliers.view,suppliers');
        Route::post('/suppliers', [SupplierController::class, 'store'])
            ->middleware('permission:supplier.create,suppliers.create,suppliers');
        Route::get('/suppliers/{id}', [SupplierController::class, 'show'])
            ->middleware('permission:suppliers.view,suppliers');
        Route::put('/suppliers/{id}', [SupplierController::class, 'update'])
            ->middleware('permission:supplier.update,suppliers.update,suppliers.edit,suppliers');
        Route::patch('/suppliers/{id}/status', [SupplierController::class, 'setStatus'])
            ->middleware('permission:supplier.update,suppliers.update,suppliers');
        Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy'])
            ->middleware('permission:suppliers.delete,suppliers');
        Route::get('/suppliers/{id}/ledger', [SupplierController::class, 'ledger'])
            ->middleware('permission:supplier.ledger.view,supplier.read,suppliers.read,suppliers');
        Route::get('/suppliers/{id}/outstanding-bills', [SupplierController::class, 'outstandingBills'])
            ->middleware('permission:supplier.payment,suppliers,purchase.view,purchase');
        Route::post('/suppliers/{id}/payments', [SupplierController::class, 'recordPayment'])
            ->middleware('permission:supplier.payment,suppliers,purchase.pay,purchase.create,purchase.approve,purchase.manage,purchase');

        // Purchase Bills & Procurement Routes
        Route::get('/purchases', [PurchaseController::class, 'index'])
            ->middleware('permission:purchase.view,purchase');
        Route::post('/purchases', [PurchaseController::class, 'store'])
            ->middleware('permission:purchase.create,purchase');
        Route::get('/purchases/{id}', [PurchaseController::class, 'show'])
            ->middleware('permission:purchase.view,purchase');
        Route::post('/purchases/{id}/approve', [PurchaseController::class, 'approve'])
            ->middleware('permission:purchase.approve');

        // Billing, Point of Sale (POS) & Invoicing Routes
        Route::get('/invoices', [InvoiceController::class, 'index'])
            ->middleware('permission:sales.view,sales,billing');
        Route::post('/invoices', [InvoiceController::class, 'store'])
            ->middleware('permission:sales,billing');
        Route::get('/invoices/{id}', [InvoiceController::class, 'show'])
            ->middleware('permission:sales.view,sales,billing');
        Route::post('/invoices/{id}/settle', [InvoiceController::class, 'settle'])
            ->middleware('permission:DUE_COLLECT,billing');
        Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel'])
            ->middleware('permission:sales.cancel,sales');
        Route::patch('/invoices/{id}/due-date', [InvoiceController::class, 'updateDueDate'])
            ->middleware('permission:sales.edit,sales');

        // Storage Management (Godowns, Racks, Shelves) Routes
        Route::get('/storage/locations', [StorageController::class, 'index'])
            ->middleware('permission:godown.view,godown.read,storage,rack.read');
        Route::post('/storage/locations', [StorageController::class, 'store'])
            ->middleware('permission:godown.create,storage.create');
        Route::post('/storage/racks/batch', [StorageController::class, 'batchCreateRacks'])
            ->middleware('permission:rack.create,godown.create,storage.create');
        Route::get('/storage/stock-buckets', [StorageController::class, 'stockBuckets'])
            ->middleware('permission:godown.view,godown.read,storage,inventory.view');
        Route::get('/storage/locations/{id}', [StorageController::class, 'show'])
            ->middleware('permission:godown.view,godown.read,storage,rack.read');
        Route::put('/storage/locations/{id}', [StorageController::class, 'update'])
            ->middleware('permission:rack.update,rack.manage,godown.edit,godown.update,godown.manage,storage.edit');
        Route::patch('/storage/locations/{id}/status', [StorageController::class, 'updateStatus'])
            ->middleware('permission:rack.delete,godown.delete,godown.manage');

        // Inventory, Stock Balances & Batch Movement Routes
        Route::get('/inventory/stock', [InventoryController::class, 'stock'])
            ->middleware('permission:inventory.view,inventory,products,billing');
        Route::post('/inventory/movements', [InventoryController::class, 'recordMovement'])
            ->middleware('permission:inventory.adjust,inventory.opening_stock');
        Route::get('/inventory/movements', [InventoryController::class, 'movements'])
            ->middleware('permission:inventory.view,inventory');
        Route::get('/inventory/batches', [InventoryController::class, 'batches'])
            ->middleware('permission:inventory.view,inventory');
        Route::post('/inventory/batches', [InventoryController::class, 'storeBatch'])
            ->middleware('permission:inventory.adjust');
        Route::get('/inventory/batch-movements', [InventoryController::class, 'batchMovements'])
            ->middleware('permission:inventory.view,inventory');
        Route::post('/inventory/batch-movements', [InventoryController::class, 'recordBatchMovement'])
            ->middleware('permission:inventory.adjust');

        // Employees, Roles & Permissions Routes
        Route::get('/employees', [EmployeeController::class, 'index'])
            ->middleware('permission:staff.view,staff');
        Route::get('/employees/by-uid/{uid}', [EmployeeController::class, 'showByUid']);
        Route::post('/employees', [EmployeeController::class, 'store'])
            ->middleware('permission:staff.manage,staff');

        // Keep the fixed activity paths ahead of the /employees/{id} route.
        Route::get('/employees/activities', [EmployeeActivityController::class, 'index'])
            ->middleware('permission:employee_activity');
        Route::post('/employees/activities', [EmployeeActivityController::class, 'store'])
            ->middleware('permission:employee_activity');
        Route::get('/employees/activities/stats', [EmployeeActivityController::class, 'stats'])
            ->middleware('permission:employee_activity');

        Route::get('/employees/{id}', [EmployeeController::class, 'show'])
            ->middleware('permission:staff.view,staff');
        Route::put('/employees/{id}', [EmployeeController::class, 'update'])
            ->middleware('permission:staff.manage,staff');
        Route::patch('/employees/{id}/status', [EmployeeController::class, 'updateStatus'])
            ->middleware('permission:staff.manage,staff');
        Route::get('/roles', [EmployeeController::class, 'roles']);
        Route::get('/permissions', [EmployeeController::class, 'permissions']);

        // Staff Attendance & Biometric Face Verification Routes
        Route::get('/attendance', [AttendanceController::class, 'index'])
            ->middleware('permission:ATTENDANCE_VIEW,ATTENDANCE_MANAGE,ATTENDANCE_APPROVE,attendance');
        Route::post('/attendance/punch', [AttendanceController::class, 'punch'])
            ->middleware('permission:ATTENDANCE_MANAGE');
        Route::post('/attendance/manual', [AttendanceController::class, 'manual'])
            ->middleware('permission:ATTENDANCE_MANAGE');
        Route::post('/attendance/{id}/approve', [AttendanceController::class, 'approve'])
            ->middleware('permission:ATTENDANCE_APPROVE');

        // Staff Salary, Payroll & Khata Routes
        Route::get('/payroll/profiles', [PayrollController::class, 'profiles'])
            ->middleware('permission:SALARY_VIEW,SALARY_MANAGE,SALARY_APPROVE,SALARY_PAY,SALARY_REPORT,staff_salary');
        Route::post('/payroll/profiles', [PayrollController::class, 'storeProfile'])
            ->middleware('permission:SALARY_MANAGE,staff_salary.manage');
        Route::get('/payroll/periods', [PayrollController::class, 'periods'])
            ->middleware('permission:SALARY_VIEW,SALARY_MANAGE,SALARY_APPROVE,SALARY_PAY,SALARY_REPORT,staff_salary');
        Route::post('/payroll/disburse', [PayrollController::class, 'disburse'])
            ->middleware('permission:SALARY_APPROVE,SALARY_PAY,staff_salary.approve,staff_salary.pay');
        Route::get('/payroll/khata', [PayrollController::class, 'khata'])
            ->middleware('permission:SALARY_VIEW,SALARY_MANAGE,SALARY_APPROVE,SALARY_PAY,SALARY_REPORT,STAFF_KHATA_CHARGE,staff_salary');
        Route::post('/payroll/advance', [PayrollController::class, 'recordAdvance'])
            ->middleware('permission:SALARY_MANAGE,staff_salary.manage');
        Route::post('/payroll/salary-payment', [PayrollController::class, 'recordSalaryPayment'])
            ->middleware('permission:SALARY_PAY,staff_salary.pay');
        Route::post('/payroll/advance-adjustment', [PayrollController::class, 'adjustAdvance'])
            ->middleware('permission:SALARY_APPROVE,staff_salary.approve');

        // Expense Management Routes
        Route::get('/expenses', [ExpenseController::class, 'index'])
            ->middleware('permission:expenses.view,expenses');
        Route::post('/expenses', [ExpenseController::class, 'store'])
            ->middleware('permission:expenses.create,expenses');
        Route::get('/expenses/categories', [ExpenseController::class, 'categories'])
            ->middleware('permission:expenses.view,expenses');
        Route::get('/expenses/{id}', [ExpenseController::class, 'show'])
            ->middleware('permission:expenses.view,expenses');
        Route::put('/expenses/{id}', [ExpenseController::class, 'update'])
            ->middleware('permission:expenses.edit,expenses');
        Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])
            ->middleware('permission:expenses.delete,expenses');

        // Financial Ledger & Cash Drawer Routes
        Route::get('/financial/transactions', [FinancialTransactionController::class, 'index'])
            ->middleware('permission:financial.view,expenses,reports');
        Route::post('/financial/transactions', [FinancialTransactionController::class, 'store'])
            ->middleware('permission:financial.create,expenses');
        Route::get('/financial/summary', [FinancialTransactionController::class, 'summary'])
            ->middleware('permission:financial.view,expenses,reports');

        // Dashboard Analytics Routes
        Route::get('/dashboard/metrics', [DashboardController::class, 'metrics'])
            ->middleware('permission:dashboard,sales,sales.view,billing,purchase,purchase.view,inventory,inventory.view,products,products.view,product.read,customers,customers.view,CUSTOMER_VIEW,expenses,expenses.view,reports,staff_salary,attendance');
    });

    // Super User / Developer Portal (Live Screening, approvals & remote controls).
    Route::middleware(['auth:sanctum', 'active', 'admin'])
        ->prefix('superuser')
        ->group(function () {
            Route::get('/overview', [SuperUserController::class, 'overview']);
            Route::get('/live-sessions', [SuperUserController::class, 'liveSessions']);
            Route::get('/screen-traces', [SuperUserController::class, 'screenTraces']);
            Route::get('/feature-flags', [SuperUserController::class, 'featureFlags']);
            Route::post('/feature-flags/toggle', [SuperUserController::class, 'toggleFeature']);
            Route::post('/feature-flags', [SuperUserController::class, 'saveFeature']);
            Route::post('/sessions/{sessionId}/terminate', [SuperUserController::class, 'terminateSession']);
            Route::get('/owners', [SuperUserController::class, 'owners']);
        });

    // Reports & Statutory Analytics Routes (Direct & Authenticated reporting)
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/reports/overview', [ReportController::class, 'overview'])
            ->middleware('permission:reports.view,reports,sales,purchase,inventory,expenses');
        Route::get('/reports/sales', [ReportController::class, 'sales'])
            ->middleware('permission:reports.view,reports,sales.view,sales,billing');
        Route::get('/reports/purchases', [ReportController::class, 'purchases'])
            ->middleware('permission:reports.view,reports,purchase.view,purchase');
        Route::get('/reports/inventory', [ReportController::class, 'inventory'])
            ->middleware('permission:reports.view,reports,inventory.view,inventory');
        Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss'])
            ->middleware('permission:reports.view,reports,expenses.view,expenses,sales.view,sales,purchase.view,purchase');
        Route::get('/reports/tax', [ReportController::class, 'tax'])
            ->middleware('permission:reports.view,reports,sales.view,sales,purchase.view,purchase');
        Route::get('/reports/reconciliation', [ReportController::class, 'reconciliation'])
            ->middleware('permission:reports.view,reports');
    });

    // Public Media Access (Profile photos & Business logos)
    Route::get('/users/{id}/photo', [UserController::class, 'photo'])
        ->name('api.v1.users.photo');
    Route::get('/businesses/{id}/logo', [BusinessController::class, 'logo'])
        ->name('api.v1.businesses.logo');

    // Admin Routes
    Route::middleware(['auth:sanctum', 'active', 'admin'])->prefix('admin')->group(function () {
        Route::put('/app-config/maintenance', [AppConfigController::class, 'updateMaintenance']);
        Route::patch('/users/{id}/status', [AdminController::class, 'updateUserStatus']);
        Route::patch('/users/{userId}/documents/{docId}', [AdminController::class, 'reviewDocument']);
        Route::get('/users/{userId}/documents/{docId}/file', [AdminController::class, 'streamDocumentFile']);
    });
});
