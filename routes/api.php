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
    Route::post('/product-masters', [ProductController::class, 'storeMaster']);
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
        Route::post('/businesses', [BusinessController::class, 'store']);
        Route::get('/businesses/{id}', [BusinessController::class, 'show']);
        Route::get('/businesses/{id}/outlets', [BusinessController::class, 'outlets']);
        Route::post('/businesses/{id}/outlets', [BusinessController::class, 'storeOutlet']);
        Route::get('/businesses/{id}/settings', [BusinessController::class, 'settings']);
        Route::put('/businesses/{id}/settings', [BusinessController::class, 'saveSettings']);

        // Product Catalog & Master Data Routes
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::post('/products/status', [ProductController::class, 'updateStatus']);
        Route::post('/outlets/{outletId}/products/remove', [ProductController::class, 'removeFromOutlet']);
        Route::post('/outlets/{outletId}/products/assign', [ProductController::class, 'assignToOutlet']);
        Route::get('/products/{id}', [ProductController::class, 'show']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);

        // Customer Management & Khata (Credit Ledger) Routes
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{id}', [CustomerController::class, 'show']);
        Route::put('/customers/{id}', [CustomerController::class, 'update']);
        Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
        Route::get('/customers/{id}/ledger', [CustomerController::class, 'ledger']);
        Route::post('/customers/{id}/payments', [CustomerController::class, 'recordPayment']);

        // Supplier Management & Supplier Khata Routes
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
        Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);
        Route::get('/suppliers/{id}/ledger', [SupplierController::class, 'ledger']);
        Route::post('/suppliers/{id}/payments', [SupplierController::class, 'recordPayment']);

        // Purchase Bills & Procurement Routes
        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::get('/purchases/{id}', [PurchaseController::class, 'show']);
        Route::post('/purchases/{id}/approve', [PurchaseController::class, 'approve']);

        // Billing, Point of Sale (POS) & Invoicing Routes
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::post('/invoices/{id}/settle', [InvoiceController::class, 'settle']);
        Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel']);
        Route::patch('/invoices/{id}/due-date', [InvoiceController::class, 'updateDueDate']);

        // Storage Management (Godowns, Racks, Shelves) Routes
        Route::get('/storage/locations', [StorageController::class, 'index']);
        Route::post('/storage/locations', [StorageController::class, 'store']);
        Route::post('/storage/racks/batch', [StorageController::class, 'batchCreateRacks']);
        Route::get('/storage/stock-buckets', [StorageController::class, 'stockBuckets']);
        Route::get('/storage/locations/{id}', [StorageController::class, 'show']);
        Route::put('/storage/locations/{id}', [StorageController::class, 'update']);
        Route::patch('/storage/locations/{id}/status', [StorageController::class, 'updateStatus']);

        // Inventory, Stock Balances & Batch Movement Routes
        Route::get('/inventory/stock', [InventoryController::class, 'stock']);
        Route::post('/inventory/movements', [InventoryController::class, 'recordMovement']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::get('/inventory/batches', [InventoryController::class, 'batches']);
        Route::post('/inventory/batches', [InventoryController::class, 'storeBatch']);
        Route::get('/inventory/batch-movements', [InventoryController::class, 'batchMovements']);
        Route::post('/inventory/batch-movements', [InventoryController::class, 'recordBatchMovement']);

        // Employees, Roles & Permissions Routes
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::get('/employees/by-uid/{uid}', [EmployeeController::class, 'showByUid']);
        Route::post('/employees', [EmployeeController::class, 'store']);

        // Keep the fixed activity paths ahead of the /employees/{id} route.
        Route::get('/employees/activities', [EmployeeActivityController::class, 'index']);
        Route::post('/employees/activities', [EmployeeActivityController::class, 'store']);
        Route::get('/employees/activities/stats', [EmployeeActivityController::class, 'stats']);

        Route::get('/employees/{id}', [EmployeeController::class, 'show']);
        Route::put('/employees/{id}', [EmployeeController::class, 'update']);
        Route::patch('/employees/{id}/status', [EmployeeController::class, 'updateStatus']);
        Route::get('/roles', [EmployeeController::class, 'roles']);
        Route::get('/permissions', [EmployeeController::class, 'permissions']);

        // Staff Attendance & Biometric Face Verification Routes
        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::post('/attendance/punch', [AttendanceController::class, 'punch']);
        Route::post('/attendance/manual', [AttendanceController::class, 'manual']);
        Route::post('/attendance/{id}/approve', [AttendanceController::class, 'approve']);

        // Staff Salary, Payroll & Khata Routes
        Route::get('/payroll/profiles', [PayrollController::class, 'profiles']);
        Route::post('/payroll/profiles', [PayrollController::class, 'storeProfile']);
        Route::get('/payroll/periods', [PayrollController::class, 'periods']);
        Route::post('/payroll/disburse', [PayrollController::class, 'disburse']);
        Route::get('/payroll/khata', [PayrollController::class, 'khata']);
        Route::post('/payroll/advance', [PayrollController::class, 'recordAdvance']);
        Route::post('/payroll/salary-payment', [PayrollController::class, 'recordSalaryPayment']);
        Route::post('/payroll/advance-adjustment', [PayrollController::class, 'adjustAdvance']);

        // Expense Management Routes
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::get('/expenses/categories', [ExpenseController::class, 'categories']);
        Route::get('/expenses/{id}', [ExpenseController::class, 'show']);
        Route::put('/expenses/{id}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy']);

        // Financial Ledger & Cash Drawer Routes
        Route::get('/financial/transactions', [FinancialTransactionController::class, 'index']);
        Route::post('/financial/transactions', [FinancialTransactionController::class, 'store']);
        Route::get('/financial/summary', [FinancialTransactionController::class, 'summary']);

        // Dashboard Analytics Routes
        Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);
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
    Route::get('/reports/overview', [ReportController::class, 'overview']);
    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/purchases', [ReportController::class, 'purchases']);
    Route::get('/reports/inventory', [ReportController::class, 'inventory']);
    Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss']);
    Route::get('/reports/tax', [ReportController::class, 'tax']);
    Route::get('/reports/reconciliation', [ReportController::class, 'reconciliation']);

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
