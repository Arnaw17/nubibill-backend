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
use App\Http\Controllers\Api\V1\EmployeeOutletAllocationController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FinancialTransactionController;
use App\Http\Controllers\Api\V1\HistoricalDataController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PayrollController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReturnController;
use App\Http\Controllers\Api\V1\ReturnReasonController;
use App\Http\Controllers\Api\V1\StorageController;
use App\Http\Controllers\Api\V1\SuperUserController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierRefundController;
use App\Http\Controllers\Api\V1\SyncController;
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

    // SQLite Outbox / Document Synchronization to Central MySQL
    Route::withoutMiddleware(['throttle:api'])->group(function () {
        Route::post('/sync/documents:commit', [SyncController::class, 'commit']);
        Route::post('/sync/commit', [SyncController::class, 'commit']);
        Route::get('/sync/documents/{collection}/{id}', [SyncController::class, 'getDocument']);
        Route::post('/sync/documents/{collection}/{id?}', [SyncController::class, 'createDocument']);
        Route::post('/sync/documents:runQuery', [SyncController::class, 'runQuery']);
        Route::post('/sync/runQuery', [SyncController::class, 'runQuery']);
    });
    // Public Authentication & Registration Routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/email-otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:6,1');
        Route::post('/email-otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:15,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
        Route::post('/forgot-password/send-otp', [AuthController::class, 'sendForgotPasswordOtp'])->middleware('throttle:10,1');
        Route::post('/forgot-password/reset', [AuthController::class, 'resetPasswordWithOtp'])->middleware('throttle:10,1');

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
            ->middleware('permission:product.read,products.view,inventory.view,sales,billing,barcode');
        Route::post('/products/barcodes/generate-missing', [ProductController::class, 'generateMissingBarcodes'])
            ->middleware('permission:barcode,products.view,product.read,products.update,product.update');
        Route::post('/products', [ProductController::class, 'store'])
            ->middleware('permission:product.create,products.create');
        Route::post('/products/status', [ProductController::class, 'updateStatus'])
            ->middleware('permission:product.update,products.update');
        Route::get('/outlets/{outletId}/products/assigned-ids', [ProductController::class, 'outletProductIds'])
            ->middleware('permission:product.read,product.update,products.view,products.update');
        Route::post('/outlets/{outletId}/products/remove', [ProductController::class, 'removeFromOutlet'])
            ->middleware('permission:product.update,products.update');
        Route::post('/outlets/{outletId}/products/assign', [ProductController::class, 'assignToOutlet'])
            ->middleware('permission:product.update,products.update');
        Route::get('/products/{id}', [ProductController::class, 'show'])
            ->middleware('permission:product.read,products.view,inventory.view,sales,billing,barcode');
        Route::put('/products/{id}', [ProductController::class, 'update'])
            ->middleware('permission:product.update,products.update');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])
            ->middleware('permission:product.delete,products.delete');

        // Customer Management & Khata (Credit Ledger) Routes
        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('permission:customer.read,CUSTOMER_VIEW,CUSTOMER_EDIT,customers,billing');
        Route::post('/customers', [CustomerController::class, 'store'])
            ->middleware('permission:customer.create,CUSTOMER_CREATE,customers.create,CUSTOMER_EDIT,customers');
        Route::get('/customers/{id}', [CustomerController::class, 'show'])
            ->middleware('permission:customer.read,CUSTOMER_VIEW,CUSTOMER_EDIT,customers,billing');
        Route::put('/customers/{id}', [CustomerController::class, 'update'])
            ->middleware('permission:customer.update,CUSTOMER_EDIT,customers');
        Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])
            ->middleware('permission:customer.delete,CUSTOMER_EDIT,customers');
        Route::get('/customers/{id}/ledger', [CustomerController::class, 'ledger'])
            ->middleware('permission:customer.read,CUSTOMER_LEDGER_VIEW,CUSTOMER_EDIT,customers');
        Route::post('/customers/{id}/payments', [CustomerController::class, 'recordPayment'])
            ->middleware('permission:payment.create,DUE_COLLECT,billing');

        // Supplier Management & Supplier Khata Routes
        Route::get('/suppliers', [SupplierController::class, 'index'])
            ->middleware('permission:supplier.read,suppliers.view,suppliers');
        Route::post('/suppliers', [SupplierController::class, 'store'])
            ->middleware('permission:supplier.create,suppliers.create,suppliers');
        Route::get('/suppliers/{id}', [SupplierController::class, 'show'])
            ->middleware('permission:supplier.read,suppliers.view,suppliers');
        Route::put('/suppliers/{id}', [SupplierController::class, 'update'])
            ->middleware('permission:supplier.update,suppliers.update,suppliers.edit,suppliers');
        Route::patch('/suppliers/{id}/status', [SupplierController::class, 'setStatus'])
            ->middleware('permission:supplier.update,suppliers.update,suppliers');
        Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy'])
            ->middleware('permission:supplier.delete,suppliers.delete,suppliers');
        Route::get('/suppliers/{id}/ledger', [SupplierController::class, 'ledger'])
            ->middleware('permission:supplier.read,supplier.ledger.view,suppliers.read,suppliers');
        Route::get('/suppliers/{id}/outstanding-bills', [SupplierController::class, 'outstandingBills'])
            ->middleware('permission:supplier.read,supplier.payment,suppliers,purchase.view,purchase');
        Route::post('/suppliers/{id}/payments', [SupplierController::class, 'recordPayment'])
            ->middleware('permission:supplier.payment,suppliers,purchase.pay,purchase.create,purchase.approve,purchase.manage,purchase');
        Route::get('/supplier-refunds', [SupplierRefundController::class, 'index'])
            ->middleware('permission:supplier.read,supplier.ledger.view,suppliers.read,suppliers');
        Route::post('/supplier-refunds', [SupplierRefundController::class, 'store'])
            ->middleware('permission:supplier.payment,suppliers,purchase.pay,purchase.create,purchase.approve,purchase.manage,purchase');

        // Purchase Bills & Procurement Routes
        Route::get('/purchases', [PurchaseController::class, 'index'])
            ->middleware('permission:purchase.read,purchase.view,purchase,reports,reports.read,reports.view');
        Route::post('/purchases', [PurchaseController::class, 'store'])
            ->middleware('permission:purchase.create,purchase');
        Route::get('/purchases/{id}', [PurchaseController::class, 'show'])
            ->middleware('permission:purchase.read,purchase.view,purchase,reports,reports.read,reports.view');
        Route::post('/purchases/{id}/approve', [PurchaseController::class, 'approve'])
            ->middleware('permission:purchase.approve');

        // Billing, Point of Sale (POS) & Invoicing Routes
        Route::get('/invoices', [InvoiceController::class, 'index'])
            ->middleware('permission:sales.read,sales.view,sales,billing');
        Route::post('/invoices', [InvoiceController::class, 'store'])
            ->middleware('permission:sales.create,sales,billing');
        Route::get('/invoices/{id}', [InvoiceController::class, 'show'])
            ->middleware('permission:sales.read,sales.view,sales,billing');
        Route::post('/invoices/{id}/settle', [InvoiceController::class, 'settle'])
            ->middleware('permission:payment.create,DUE_COLLECT,billing');
        Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel'])
            ->middleware('permission:sales.delete,sales.cancel,sales');
        Route::patch('/invoices/{id}/due-date', [InvoiceController::class, 'updateDueDate'])
            ->middleware('permission:sales.update,sales.edit,sales');

        // Storage Management (Godowns, Racks, Shelves) Routes
        Route::get('/storage/locations', [StorageController::class, 'index'])
            ->middleware('permission:godown.read,godown.view,storage,rack.read');
        Route::post('/storage/locations', [StorageController::class, 'store'])
            ->middleware('permission:godown.create,storage.create');
        Route::post('/storage/racks/batch', [StorageController::class, 'batchCreateRacks'])
            ->middleware('permission:rack.create,godown.create,storage.create');
        Route::get('/storage/stock-buckets', [StorageController::class, 'stockBuckets'])
            ->middleware('permission:godown.view,godown.read,storage,inventory.view');
        Route::get('/storage/locations/{id}', [StorageController::class, 'show'])
            ->middleware('permission:godown.view,godown.read,storage,rack.read');
        Route::put('/storage/locations/{id}', [StorageController::class, 'update'])
            ->middleware('permission:godown.update,rack.update,rack.manage,godown.edit,godown.manage,storage.edit');
        Route::patch('/storage/locations/{id}/status', [StorageController::class, 'updateStatus'])
            ->middleware('permission:godown.delete,rack.delete,godown.manage');

        // Inventory, Stock Balances & Batch Movement Routes
        Route::get('/inventory/stock', [InventoryController::class, 'stock'])
            ->middleware('permission:inventory.read,inventory.view,inventory,products,billing');
        Route::post('/inventory/movements', [InventoryController::class, 'recordMovement'])
            ->middleware('permission:inventory.adjust,inventory.transfer,stock_movement.create,inventory.opening_stock');
        Route::get('/inventory/movements', [InventoryController::class, 'movements'])
            ->middleware('permission:stock_movement.read,inventory.read,inventory.view,inventory');
        Route::get('/inventory/batches', [InventoryController::class, 'batches'])
            ->middleware('permission:inventory.read,inventory.view,inventory');
        Route::post('/inventory/batches', [InventoryController::class, 'storeBatch'])
            ->middleware('permission:inventory.update,inventory.adjust');
        Route::get('/inventory/batch-movements', [InventoryController::class, 'batchMovements'])
            ->middleware('permission:stock_movement.read,inventory.read,inventory.view,inventory');
        Route::post('/inventory/batch-movements', [InventoryController::class, 'recordBatchMovement'])
            ->middleware('permission:inventory.transfer,inventory.adjust,stock_movement.create');

        // Employees, Roles & Permissions Routes
        Route::get('/employees', [EmployeeController::class, 'index'])
            ->middleware('permission:employee.read,staff.view,staff');
        Route::get('/employees/by-uid/{uid}', [EmployeeController::class, 'showByUid']);
        Route::post('/employees', [EmployeeController::class, 'store'])
            ->middleware('permission:employee.create,staff.manage,staff');

        // Keep the fixed activity paths ahead of the /employees/{id} route.
        Route::get('/employees/activities', [EmployeeActivityController::class, 'index'])
            ->middleware('permission:employee_activity');
        Route::post('/employees/activities', [EmployeeActivityController::class, 'store'])
            ->middleware('permission:employee_activity');
        Route::get('/employees/activities/stats', [EmployeeActivityController::class, 'stats'])
            ->middleware('permission:employee_activity');

        Route::get('/employees/{id}', [EmployeeController::class, 'show'])
            ->middleware('permission:employee.read,staff.view,staff');
        Route::put('/employees/{id}', [EmployeeController::class, 'update'])
            ->middleware('permission:employee.update,staff.manage,staff');
        Route::patch('/employees/{id}/status', [EmployeeController::class, 'updateStatus'])
            ->middleware('permission:employee.update,staff.manage,staff');
        Route::get('/roles', [EmployeeController::class, 'roles']);
        Route::get('/permissions', [EmployeeController::class, 'permissions']);

        // Staff Attendance & Biometric Face Verification Routes
        Route::get('/attendance', [AttendanceController::class, 'index'])
            ->middleware('permission:attendance.view,attendance.manage,attendance.approve,ATTENDANCE_VIEW,ATTENDANCE_MANAGE,ATTENDANCE_APPROVE,attendance');
        Route::get('/attendance/events', [AttendanceController::class, 'events'])
            ->middleware('permission:attendance.view,attendance.manage,attendance.approve,ATTENDANCE_VIEW,ATTENDANCE_MANAGE,ATTENDANCE_APPROVE,attendance');
        Route::post('/attendance/punch', [AttendanceController::class, 'punch'])
            ->middleware('permission:attendance.manage,ATTENDANCE_MANAGE');
        Route::post('/attendance/manual', [AttendanceController::class, 'manual'])
            ->middleware('permission:attendance.manage,ATTENDANCE_MANAGE');
        Route::post('/attendance/{id}/approve', [AttendanceController::class, 'approve'])
            ->middleware('permission:attendance.approve,ATTENDANCE_APPROVE');

        // Staff Salary, Payroll & Khata Routes
        Route::get('/payroll/profiles', [PayrollController::class, 'profiles'])
            ->middleware('permission:salary.view,salary.manage,salary.approve,salary.pay,salary.report,SALARY_VIEW,SALARY_MANAGE,SALARY_APPROVE,SALARY_PAY,SALARY_REPORT,staff_salary');
        Route::post('/payroll/profiles', [PayrollController::class, 'storeProfile'])
            ->middleware('permission:salary.manage,SALARY_MANAGE,staff_salary.manage');
        Route::get('/payroll/outlet-allocations', [EmployeeOutletAllocationController::class, 'index'])
            ->middleware('permission:salary.view,salary.manage,salary.approve,salary.pay,salary.report,SALARY_VIEW,SALARY_MANAGE,SALARY_APPROVE,SALARY_PAY,SALARY_REPORT,staff_salary');
        Route::post('/payroll/outlet-allocations', [EmployeeOutletAllocationController::class, 'store'])
            ->middleware('permission:salary.manage,SALARY_MANAGE,staff_salary.manage');
        Route::get('/payroll/periods', [PayrollController::class, 'periods'])
            ->middleware('permission:salary.view,salary.manage,salary.approve,salary.pay,salary.report,SALARY_VIEW,SALARY_MANAGE,SALARY_APPROVE,SALARY_PAY,SALARY_REPORT,staff_salary');
        Route::post('/payroll/disburse', [PayrollController::class, 'disburse'])
            ->middleware('permission:salary.approve,salary.pay,SALARY_APPROVE,SALARY_PAY,staff_salary.approve,staff_salary.pay');
        Route::get('/payroll/khata', [PayrollController::class, 'khata'])
            ->middleware('permission:salary.view,salary.manage,salary.approve,salary.pay,salary.report,staff_khata_charge,SALARY_VIEW,SALARY_MANAGE,SALARY_APPROVE,SALARY_PAY,SALARY_REPORT,STAFF_KHATA_CHARGE,staff_salary');
        Route::post('/payroll/advance', [PayrollController::class, 'recordAdvance'])
            ->middleware('permission:salary.manage,SALARY_MANAGE,staff_salary.manage');
        Route::post('/payroll/salary-payment', [PayrollController::class, 'recordSalaryPayment'])
            ->middleware('permission:salary.pay,SALARY_PAY,staff_salary.pay');
        Route::post('/payroll/advance-adjustment', [PayrollController::class, 'adjustAdvance'])
            ->middleware('permission:salary.approve,SALARY_APPROVE,staff_salary.approve');
        Route::get('/payroll/employee-statement', [PayrollController::class, 'employeeStatement']);

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

        // Returns & Credit / Debit Notes Routes
        Route::post('/returns', [ReturnController::class, 'store'])
            ->middleware('permission:sales.return,sales,sales.view,billing,purchase,purchase.view,inventory');
        Route::get('/returns', [ReturnController::class, 'index'])
            ->middleware('permission:sales.read,sales,sales.view,billing,purchase,purchase.view,inventory');
        Route::get('/returns/{id}', [ReturnController::class, 'show'])
            ->middleware('permission:sales.read,sales,sales.view,billing,purchase,purchase.view,inventory');
        Route::put('/returns/{id}', [ReturnController::class, 'update'])
            ->middleware('permission:sales.return,sales,sales.view,billing,purchase,purchase.view,inventory');
        Route::post('/returns/{id}/cancel', [ReturnController::class, 'cancel'])
            ->middleware('permission:sales.return,sales,sales.view,billing,purchase,purchase.view,inventory');
        Route::get('/returns/{id}/pdf', [ReturnController::class, 'pdf'])
            ->middleware('permission:sales.read,sales,sales.view,billing,purchase,purchase.view,inventory');
        Route::get('/bills/{id}/returnable', [ReturnController::class, 'returnableBill'])
            ->middleware('permission:sales.read,sales,sales.view,billing,purchase,purchase.view,inventory');

        // Return Reasons
        Route::get('/return-reasons', [ReturnReasonController::class, 'index']);
        Route::post('/return-reasons', [ReturnReasonController::class, 'store']);

        // Stock History Timeline
        Route::get('/products/{id}/stock-history', [ProductController::class, 'stockHistory'])
            ->middleware('permission:inventory,inventory.view,products,products.view,product.read');

        // Historical Data Management & Previous Records System Routes
        Route::prefix('historical')->group(function () {
            Route::get('/cutoff-date', [HistoricalDataController::class, 'getCutoffDate'])
                ->middleware('permission:settings.view,settings');
            Route::post('/cutoff-date', [HistoricalDataController::class, 'setCutoffDate'])
                ->middleware('permission:settings.edit,settings');

            Route::get('/batches', [HistoricalDataController::class, 'listBatches'])
                ->middleware('permission:settings.view,reports,reports.read');
            Route::post('/batches', [HistoricalDataController::class, 'createBatch'])
                ->middleware('permission:settings.edit,settings');
            Route::post('/batches/{id}/approve', [HistoricalDataController::class, 'approveBatch'])
                ->middleware('permission:settings.edit,settings');
            Route::post('/batches/{id}/rollback', [HistoricalDataController::class, 'rollbackBatch'])
                ->middleware('permission:settings.edit,settings');

            Route::get('/stock-availability', [HistoricalDataController::class, 'stockAvailability'])
                ->middleware('permission:sales.create,sales,billing,inventory.view,inventory');
            Route::post('/sales', [HistoricalDataController::class, 'storeSale'])
                ->middleware('permission:sales.create,sales,billing');
            Route::post('/purchases', [HistoricalDataController::class, 'storePurchase'])
                ->middleware('permission:purchase.create,purchase');
            Route::post('/opening-stock', [HistoricalDataController::class, 'storeOpeningStock'])
                ->middleware('permission:inventory.adjust,inventory.opening_stock');

            Route::get('/reconciliation/stock', [HistoricalDataController::class, 'stockReconciliation'])
                ->middleware('permission:reports.read,reports.view,reports,inventory.view');

            Route::get('/reports/sales', [HistoricalDataController::class, 'salesReport'])
                ->middleware('permission:reports.read,reports.view,reports');
            Route::get('/reports/purchases', [HistoricalDataController::class, 'purchaseReport'])
                ->middleware('permission:reports.read,reports.view,reports');
            Route::get('/reports/customer-ledger', [HistoricalDataController::class, 'customerLedgerReport'])
                ->middleware('permission:customer.read,CUSTOMER_LEDGER_VIEW,reports');
            Route::get('/reports/supplier-ledger', [HistoricalDataController::class, 'supplierLedgerReport'])
                ->middleware('permission:supplier.read,supplier.ledger.view,reports');
            Route::get('/reports/stock-movements', [HistoricalDataController::class, 'stockMovementsReport'])
                ->middleware('permission:stock_movement.read,inventory.read,reports');
        });
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
            ->middleware('permission:reports.read,reports.view,reports');
        Route::get('/reports/sales', [ReportController::class, 'sales'])
            ->middleware('permission:reports.read,reports.view,reports');
        Route::get('/reports/purchases', [ReportController::class, 'purchases'])
            ->middleware('permission:reports.read,reports.view,reports');
        Route::get('/reports/inventory', [ReportController::class, 'inventory'])
            ->middleware('permission:reports.read,reports.view,reports');
        Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss'])
            ->middleware('permission:reports.read,reports.view,reports');
        Route::get('/reports/returns', [ReportController::class, 'returnsSummary'])
            ->middleware('permission:reports.read,reports.view,reports');
        Route::get('/reports/tax', [ReportController::class, 'tax'])
            ->middleware('permission:reports.read,reports.view,reports');
        Route::get('/reports/reconciliation', [ReportController::class, 'reconciliation'])
            ->middleware('permission:reports.read,reports.view,reports');
    });

    // Authenticated Media Access
    Route::get('/users/{id}/photo', [UserController::class, 'photo'])
        ->middleware(['auth:sanctum', 'active'])
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
