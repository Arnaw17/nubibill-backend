<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountantRoleAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $accountantUser;
    private string $businessId = 'bus_acc_audit_100';
    private string $outletId = 'out_acc_audit_100';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin-acc@example.com',
            'phone' => '9876543220',
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);

        $this->accountantUser = User::factory()->create([
            'email' => 'accountant@example.com',
            'phone' => '9876543221',
            'role' => 'STAFF',
            'status' => 'ACTIVE',
        ]);

        // Setup Business & Outlet
        DB::table('businesses')->insert([
            'id' => $this->businessId,
            'name' => 'Accounting Business',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['businessId' => $this->businessId, 'ownerUid' => (string) $this->admin->id, 'name' => 'Accounting Business']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outlets')->insert([
            'id' => $this->outletId,
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['outletId' => $this->outletId, 'businessId' => $this->businessId, 'name' => 'HQ Outlet']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('suppliers')->insert([
            'id' => 'SUP_TEST_01',
            'business_id' => $this->businessId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['supplierId' => 'SUP_TEST_01', 'businessId' => $this->businessId, 'name' => 'Primary Vendor']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountantPermissions = [
            'reports',
            'reports.read',
            'reports.export',
            'reports.print',
            'purchase',
            'purchase.create',
            'purchase.read',
            'purchase.update',
            'purchase.delete',
            'purchase.approve',
            'purchase.cancel',
            'suppliers',
            'supplier.create',
            'supplier.read',
            'supplier.update',
            'supplier.delete',
            'supplier.reports',
            'supplier.payment',
            'payment.create',
            'payment.read',
            'payment.update',
            'payment.delete',
            'payment.refund',
            'expenses',
            'expenses.view',
            'expenses.create',
            'expenses.edit',
            'expenses.delete',
            'staff_salary',
            'salary.view',
            'salary.approve',
            'salary.report',
            'SALARY_VIEW',
            'SALARY_APPROVE',
            'SALARY_REPORT',
            'sales.read',
            'sales.print',
            'sales.share',
            'customer.read',
            'inventory.read',
            'product.read',
            'stock_movement.read',
        ];

        foreach ($accountantPermissions as $p) {
            DB::table('permissions')->updateOrInsert(
                ['id' => $p],
                [
                    'status' => 'active',
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => ['permissionId' => $p, 'status' => 'active']]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        DB::table('roles')->updateOrInsert(
            ['id' => 'ROLE_ACCOUNTANT'],
            [
                'status' => 'active',
                'is_deleted' => 0,
                'payload' => json_encode([
                    'fields' => [
                        'roleId' => 'ROLE_ACCOUNTANT',
                        'name' => 'Accountant',
                        'roleName' => 'Accountant',
                        'isActive' => true,
                        'permissions' => $accountantPermissions,
                        'permissionIds' => $accountantPermissions,
                    ],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Assign Employee Record for Accountant
        DB::table('employees')->insert([
            'id' => 'emp_accountant_1',
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode([
                'fields' => [
                    'uid' => (string) $this->accountantUser->id,
                    'ownerUid' => (string) $this->admin->id,
                    'businessId' => $this->businessId,
                    'businessIds' => [$this->businessId],
                    'outletId' => $this->outletId,
                    'outletIds' => [$this->outletId],
                    'roleId' => 'ROLE_ACCOUNTANT',
                    'roleIds' => ['ROLE_ACCOUNTANT'],
                    'permissionIds' => [],
                    'status' => 'active',
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_accountant_can_access_financial_reports_and_purchases(): void
    {
        $headers = [
            'X-Business-Id' => $this->businessId,
            'X-Outlet-Id' => $this->outletId,
        ];

        // 1. Profit & Loss Report
        $res = $this->actingAs($this->accountantUser)->getJson('/api/v1/reports/profit-loss', $headers);
        $res->assertOk();

        // 2. Sales Report
        $res = $this->actingAs($this->accountantUser)->getJson('/api/v1/reports/sales', $headers);
        $res->assertOk();

        // 3. Tax Report
        $res = $this->actingAs($this->accountantUser)->getJson('/api/v1/reports/tax', $headers);
        $res->assertOk();

        // 4. Purchases List
        $res = $this->actingAs($this->accountantUser)->getJson('/api/v1/purchases', $headers);
        $res->assertOk();

        // 5. Create Purchase Order/Bill
        DB::table('suppliers')->updateOrInsert(
            ['id' => 'SUP_TEST_01'],
            [
                'business_id' => $this->businessId,
                'outlet_id' => $this->outletId,
                'status' => 'active',
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => ['name' => 'Test Supplier', 'supplierId' => 'SUP_TEST_01']]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $purchasePayload = [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'SUP_TEST_01',
            'supplier_invoice_number' => 'PB-ACC-001',
            'status' => 'APPROVED',
            'items' => [
                [
                    'product_id' => 'PROD_001',
                    'variant_id' => 'VAR_001',
                    'product_name' => 'Wholesale Raw Goods',
                    'quantity' => 10,
                    'rate' => 100.0,
                    'gst_rate_bps' => 1800,
                ],
            ],
        ];
        $res = $this->actingAs($this->accountantUser)->postJson('/api/v1/purchases', $purchasePayload, $headers);
        $this->assertContains($res->status(), [200, 201]);

        // 6. View Suppliers List
        $res = $this->actingAs($this->accountantUser)->getJson('/api/v1/suppliers', $headers);
        $res->assertOk();

        // 7. View Expenses & Financial Transactions
        $res = $this->actingAs($this->accountantUser)->getJson('/api/v1/expenses', $headers);
        $res->assertOk();

        $res = $this->actingAs($this->accountantUser)->getJson('/api/v1/financial/transactions', $headers);
        $res->assertOk();

        // 8. Read-only historical sales invoices
        $res = $this->actingAs($this->accountantUser)->getJson('/api/v1/invoices', $headers);
        $res->assertOk();
    }

    public function test_accountant_is_strictly_blocked_from_restricted_operational_areas(): void
    {
        $headers = [
            'X-Business-Id' => $this->businessId,
            'X-Outlet-Id' => $this->outletId,
        ];

        // 1. BLOCKED: Creating POS Counter Invoices
        $invoicePayload = [
            'invoice_number' => 'INV-ACC-999',
            'customer_name' => 'Walk-in Customer',
            'items' => [
                [
                    'productId' => 'PROD_001',
                    'productName' => 'Counter Item',
                    'quantity' => 1,
                    'sellingPricePaise' => 10000,
                ],
            ],
        ];
        $res = $this->actingAs($this->accountantUser)->postJson('/api/v1/invoices', $invoicePayload, $headers);
        $res->assertStatus(403);

        // 2. BLOCKED: Cancelling / Modifying Sales Invoices
        $res = $this->actingAs($this->accountantUser)->postJson('/api/v1/invoices/INV-123/cancel', ['reason' => 'Audit cancel'], $headers);
        $res->assertStatus(403);

        // 3. BLOCKED: Physical inventory adjustments
        $stockMovementPayload = [
            'productId' => 'PROD_001',
            'qtyChange' => 5,
            'type' => 'adjustment',
            'reason' => 'Warehouse stock recount',
        ];
        $res = $this->actingAs($this->accountantUser)->postJson('/api/v1/inventory/movements', $stockMovementPayload, $headers);
        $res->assertStatus(403);

        // 4. BLOCKED: Creating Storage Locations / Godowns
        $storagePayload = [
            'name' => 'Godown North 1',
            'type' => 'godown',
        ];
        $res = $this->actingAs($this->accountantUser)->postJson('/api/v1/storage/locations', $storagePayload, $headers);
        $res->assertStatus(403);

        // 5. BLOCKED: Employee / Staff Management
        $empPayload = [
            'name' => 'New Billing Staff',
            'phone' => '9876543230',
            'role_id' => 'ROLE_BILLING_STAFF',
        ];
        $res = $this->actingAs($this->accountantUser)->postJson('/api/v1/employees', $empPayload, $headers);
        $res->assertStatus(403);

        // 6. BLOCKED: Core Business Settings Modification
        $settingsPayload = [
            'gstin' => '27ABCDE1234F1Z5',
            'business_name' => 'Modified Profile',
        ];
        $res = $this->actingAs($this->accountantUser)->putJson("/api/v1/businesses/{$this->businessId}/settings", $settingsPayload, $headers);
        $res->assertStatus(403);
    }
}
