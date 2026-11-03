<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingStaffRoleAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $billingStaffUser;
    private User $otherStaffUser;
    private string $businessId = 'bus_audit_100';
    private string $outletId = 'out_audit_100';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@example.com',
            'phone' => '9876543210',
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);

        $this->billingStaffUser = User::factory()->create([
            'email' => 'billing-staff@example.com',
            'phone' => '9876543211',
            'role' => 'STAFF',
            'status' => 'ACTIVE',
        ]);

        $this->otherStaffUser = User::factory()->create([
            'email' => 'other-staff@example.com',
            'phone' => '9876543212',
            'role' => 'STAFF',
            'status' => 'ACTIVE',
        ]);

        // Setup Business & Outlet
        DB::table('businesses')->insert([
            'id' => $this->businessId,
            'name' => 'Audit Business',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['businessId' => $this->businessId, 'ownerUid' => (string) $this->admin->id, 'name' => 'Audit Business']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outlets')->insert([
            'id' => $this->outletId,
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['outletId' => $this->outletId, 'businessId' => $this->businessId, 'name' => 'Audit Outlet']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed ROLE_BILLING_STAFF role
        $billingPermissions = [
            'billing',
            'sales.create',
            'sales.read',
            'sales.print',
            'sales.share',
            'sales.return',
            'payment.create',
            'payment.read',
            'customer.create',
            'customer.read',
            'customer.update',
            'product.read',
            'inventory.read',
        ];

        DB::table('roles')->updateOrInsert(
            ['id' => 'ROLE_BILLING_STAFF'],
            [
                'status' => 'active',
                'is_deleted' => 0,
                'payload' => json_encode([
                    'fields' => [
                        'roleId' => 'ROLE_BILLING_STAFF',
                        'name' => 'Billing Staff',
                        'roleName' => 'Billing Staff',
                        'isActive' => true,
                        'permissions' => $billingPermissions,
                        'permissionIds' => $billingPermissions,
                    ],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        foreach ($billingPermissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['id' => $perm],
                [
                    'status' => 'active',
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => ['permissionId' => $perm, 'status' => 'active']]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Additional permissions for testing restrictions
        foreach (['purchase.read', 'purchase.create', 'supplier.read', 'supplier.create', 'reports.read', 'financial.view', 'salary.view'] as $p) {
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

        // Create employee records
        DB::table('employees')->insert([
            'id' => 'emp_billing_1',
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode([
                'fields' => [
                    'uid' => (string) $this->billingStaffUser->id,
                    'ownerUid' => (string) $this->admin->id,
                    'roleId' => 'ROLE_BILLING_STAFF',
                    'roleIds' => ['ROLE_BILLING_STAFF'],
                    'businessIds' => [$this->businessId],
                    'outletIds' => [$this->outletId],
                    'permissionIds' => [],
                    'status' => 'active',
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'id' => 'emp_other_1',
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode([
                'fields' => [
                    'uid' => (string) $this->otherStaffUser->id,
                    'ownerUid' => (string) $this->admin->id,
                    'roleId' => 'ROLE_BILLING_STAFF',
                    'roleIds' => ['ROLE_BILLING_STAFF'],
                    'businessIds' => [$this->businessId],
                    'outletIds' => [$this->outletId],
                    'permissionIds' => [],
                    'status' => 'active',
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_billing_staff_can_access_allowed_billing_customer_and_inventory_routes(): void
    {
        // 1. Inventory stock
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}")
            ->assertOk();

        // 2. Products list
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/products?business_id={$this->businessId}&outlet_id={$this->outletId}")
            ->assertOk();

        // 3. Customers create
        $customerRes = $this->actingAs($this->billingStaffUser)
            ->postJson('/api/v1/customers', [
                'business_id' => $this->businessId,
                'name' => 'Counter Customer',
                'phone' => '9123456789',
            ]);
        $customerRes->assertSuccessful();
        $customerId = $customerRes->json('data.id') ?? $customerRes->json('data.customer_id');

        // 4. Customers read
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/customers?business_id={$this->businessId}")
            ->assertOk();

        // 5. Customer ledger
        if ($customerId) {
            $this->actingAs($this->billingStaffUser)
                ->getJson("/api/v1/customers/{$customerId}/ledger?business_id={$this->businessId}")
                ->assertOk();
        }

        // 6. Create sale / invoice
        $invoiceRes = $this->actingAs($this->billingStaffUser)
            ->postJson('/api/v1/invoices', [
                'business_id' => $this->businessId,
                'outlet_id' => $this->outletId,
                'customer_name' => 'Walk In',
                'lines' => [
                    [
                        'product_id' => 'prod_1',
                        'product_name' => 'Item 1',
                        'quantity' => 1,
                        'unit_price_paise' => 10000,
                    ],
                ],
                'cash_paise' => 10000,
                'payment_mode' => 'CASH',
            ]);
        $invoiceRes->assertSuccessful();
        $ownInvoiceId = $invoiceRes->json('data.id') ?? $invoiceRes->json('data.invoiceId');
        $this->assertNotNull($ownInvoiceId);

        // 7. View own sales
        $salesListRes = $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/invoices?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $salesListRes->assertOk();
        $ids = collect($salesListRes->json('data'))->pluck('id')->all();
        $this->assertContains($ownInvoiceId, $ids);

        // 8. View own invoice by ID
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/invoices/{$ownInvoiceId}?business_id={$this->businessId}")
            ->assertOk();
    }

    public function test_billing_staff_cannot_view_sales_created_by_other_staff(): void
    {
        // Create an invoice as other staff
        $otherInvoiceRes = $this->actingAs($this->otherStaffUser)
            ->postJson('/api/v1/invoices', [
                'business_id' => $this->businessId,
                'outlet_id' => $this->outletId,
                'customer_name' => 'Other Customer',
                'lines' => [
                    [
                        'product_id' => 'prod_2',
                        'product_name' => 'Item 2',
                        'quantity' => 1,
                        'unit_price_paise' => 20000,
                    ],
                ],
                'cash_paise' => 20000,
                'payment_mode' => 'CASH',
            ]);
        $otherInvoiceRes->assertSuccessful();
        $otherInvoiceId = $otherInvoiceRes->json('data.id') ?? $otherInvoiceRes->json('data.invoiceId');
        $this->assertNotNull($otherInvoiceId);

        // Billing staff listing should NOT contain other staff's invoice
        $salesListRes = $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/invoices?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $salesListRes->assertOk();
        $ids = collect($salesListRes->json('data'))->pluck('id')->all();
        $this->assertNotContains($otherInvoiceId, $ids);

        // Billing staff viewing other staff's invoice directly should return 403 Forbidden
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/invoices/{$otherInvoiceId}?business_id={$this->businessId}")
            ->assertForbidden();
    }

    public function test_billing_staff_is_strictly_forbidden_from_restricted_domains(): void
    {
        // 1. Purchases
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/purchases?business_id={$this->businessId}")
            ->assertForbidden();

        $this->actingAs($this->billingStaffUser)
            ->postJson('/api/v1/purchases', ['business_id' => $this->businessId])
            ->assertForbidden();

        // 2. Suppliers
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/suppliers?business_id={$this->businessId}")
            ->assertForbidden();

        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/suppliers/sup_1/outstanding-bills?business_id={$this->businessId}")
            ->assertForbidden();

        $this->actingAs($this->billingStaffUser)
            ->postJson("/api/v1/suppliers/sup_1/payments", ['business_id' => $this->businessId])
            ->assertForbidden();

        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/supplier-refunds?business_id={$this->businessId}")
            ->assertForbidden();

        // 3. Payroll
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/payroll/profiles?business_id={$this->businessId}")
            ->assertForbidden();

        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/payroll/khata?business_id={$this->businessId}")
            ->assertForbidden();

        // 4. Financial ledger
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/financial/transactions?business_id={$this->businessId}")
            ->assertForbidden();

        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/financial/summary?business_id={$this->businessId}")
            ->assertForbidden();

        // 5. Reports
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/reports/overview?business_id={$this->businessId}")
            ->assertForbidden();

        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/reports/sales?business_id={$this->businessId}")
            ->assertForbidden();

        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/reports/profit-loss?business_id={$this->businessId}")
            ->assertForbidden();

        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/reports/purchases?business_id={$this->businessId}")
            ->assertForbidden();

        // 6. Expenses
        $this->actingAs($this->billingStaffUser)
            ->getJson("/api/v1/expenses?business_id={$this->businessId}")
            ->assertForbidden();
    }
}
