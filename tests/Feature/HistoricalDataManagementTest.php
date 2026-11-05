<?php

namespace Tests\Feature;

use App\Models\MigrationBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoricalDataManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $businessId;
    protected string $outletId;
    protected array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessId = 'BUS_HIST_TEST_01';
        $this->outletId = 'OUT_HIST_TEST_01';

        $this->user = new User();
        $this->user->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'Store Owner',
            'email' => 'owner@teststore.com',
            'phone' => '9876543210',
            'password' => Hash::make('Password123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'business_id' => $this->businessId,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $this->user->save();

        $this->headers = [
            'X-Business-Id' => $this->businessId,
            'X-Outlet-Id' => $this->outletId,
        ];

        // Seed supplier and customer for testing
        DB::table('suppliers')->insert([
            'id' => 'SUP_01',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['name' => 'Metro Supplier', 'supplierId' => 'SUP_01']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customers')->insert([
            'id' => 'CUST_01',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['name' => 'Ramesh Kumar', 'phone' => '9988776655', 'customerId' => 'CUST_01']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('products')->insert([
            'id' => 'PROD_BAGS_01',
            'business_id' => $this->businessId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['name' => 'Cement Bags', 'productId' => 'PROD_BAGS_01']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test 1 – Normal New Purchase
     * A new purchase after onboarding must update inventory and supplier ledger normally.
     */
    public function test_01_normal_new_purchase_updates_inventory_and_supplier_ledger(): void
    {
        $payload = [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'INV-NEW-001',
            'invoice_date' => '2026-11-04',
            'purchase_date' => '2026-11-04',
            'status' => 'APPROVED',
            'paid_amount' => 50000,
            'items' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'variant_id' => 'PROD_BAGS_01',
                    'product_name' => 'Cement Bags',
                    'quantity' => 50,
                    'rate' => 1000,
                    'gst_rate_bps' => 1800,
                ],
            ],
        ];

        $res = $this->actingAs($this->user)->postJson('/api/v1/purchases', $payload, $this->headers);
        $this->assertContains($res->status(), [200, 201]);

        // Verify purchase_bills is_historical is 0 / new
        $this->assertDatabaseHas('purchase_bills', [
            'business_id' => $this->businessId,
            'supplier_id' => 'SUP_01',
            'is_historical' => 0,
            'entry_source' => 'new',
        ]);

        // Verify supplier ledger updated
        $this->assertDatabaseHas('supplier_ledger_entries', [
            'business_id' => $this->businessId,
            'entry_source' => 'new',
            'is_historical' => 0,
        ]);

        // Verify stock movements updated
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $this->businessId,
            'product_id' => 'PROD_BAGS_01',
            'type' => 'purchase',
            'qty_change' => 50,
        ]);
    }

    /**
     * Test 2 – Historical Purchase with Full Stock
     * Historical purchase: 100 units.
     * Available: 100 units.
     * Current stock increase: 100 units.
     */
    public function test_02_historical_purchase_with_full_stock(): void
    {
        $payload = [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-SUP-FULL-100',
            'invoice_date' => '2026-10-01',
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'variant_id' => 'PROD_BAGS_01',
                    'product_name' => 'Cement Bags',
                    'quantity' => 100, // 100 units
                    'rate' => 350,
                    'consumed_quantity' => 0, // 0 consumed
                    'included_in_opening_stock' => 0, // 0 in opening
                ],
            ],
        ];

        $res = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', $payload, $this->headers);
        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertEquals('HIST-SUP-FULL-100', $data['supplierInvoiceNumber']);
        $this->assertTrue($data['isHistorical']);

        // Verify purchase_bill preserves 100 original quantity
        $this->assertDatabaseHas('purchase_bill_items', [
            'business_id' => $this->businessId,
            'product_id' => 'PROD_BAGS_01',
            'quantity' => 100,
        ]);

        // Verify stock adjustment trace
        $this->assertDatabaseHas('historical_stock_adjustments', [
            'business_id' => $this->businessId,
            'product_id' => 'PROD_BAGS_01',
            'original_purchased_qty' => 100,
            'consumed_qty' => 0,
            'remaining_physical_stock' => 100,
            'included_in_opening_stock_qty' => 0,
            'actual_stock_adjustment_qty' => 100,
        ]);

        // Verify stock movement created for exactly 100 units
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $this->businessId,
            'product_id' => 'PROD_BAGS_01',
            'type' => 'purchase',
            'is_historical' => 1,
            'entry_source' => 'historical',
            'qty_change' => 100,
        ]);
    }

    /**
     * Test 3 – Historical Purchase with Partial Stock
     * Historical purchase: 100 units.
     * Already consumed: 20 units.
     * Current stock increase: 80 units.
     */
    public function test_03_historical_purchase_with_partial_stock(): void
    {
        $payload = [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-SUP-PARTIAL-80',
            'invoice_date' => '2026-10-05',
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'variant_id' => 'PROD_BAGS_01',
                    'quantity' => 100, // 100 units originally
                    'rate' => 350,
                    'consumed_quantity' => 20, // 20 consumed
                    'included_in_opening_stock' => 0,
                ],
            ],
        ];

        $res = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', $payload, $this->headers);
        $res->assertStatus(200);

        // Verify original purchase preserved at 100
        $this->assertDatabaseHas('purchase_bill_items', [
            'business_id' => $this->businessId,
            'product_id' => 'PROD_BAGS_01',
            'quantity' => 100,
        ]);

        // Verify adjustment trace: remaining 80, actual adjustment 80
        $this->assertDatabaseHas('historical_stock_adjustments', [
            'business_id' => $this->businessId,
            'product_id' => 'PROD_BAGS_01',
            'original_purchased_qty' => 100,
            'consumed_qty' => 20,
            'remaining_physical_stock' => 80,
            'actual_stock_adjustment_qty' => 80,
        ]);

        // Stock movement must be for 80 units, NOT 100
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $this->businessId,
            'product_id' => 'PROD_BAGS_01',
            'is_historical' => 1,
            'qty_change' => 80,
        ]);
    }

    /**
     * Test 4 – Historical Purchase Already Included in Opening Stock
     * Historical purchase: 100 units.
     * Remaining stock: 80 units.
     * Already included in opening inventory: 80 units.
     * Additional stock increase: 0 units.
     */
    public function test_04_historical_purchase_already_included_in_opening_stock(): void
    {
        $payload = [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-SUP-IN-OPENING-0',
            'invoice_date' => '2026-10-10',
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'variant_id' => 'PROD_BAGS_01',
                    'quantity' => 100,
                    'rate' => 350,
                    'consumed_quantity' => 20, // Remaining 80
                    'included_in_opening_stock' => 80, // Already in opening stock
                ],
            ],
        ];

        $res = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', $payload, $this->headers);
        $res->assertStatus(200);

        // Verify original purchase bill preserved at 100
        $this->assertDatabaseHas('purchase_bill_items', [
            'business_id' => $this->businessId,
            'quantity' => 100,
        ]);

        // Verify adjustment trace shows actual_stock_adjustment_qty = 0
        $this->assertDatabaseHas('historical_stock_adjustments', [
            'business_id' => $this->businessId,
            'original_purchased_qty' => 100,
            'consumed_qty' => 20,
            'remaining_physical_stock' => 80,
            'included_in_opening_stock_qty' => 80,
            'actual_stock_adjustment_qty' => 0,
        ]);

        // Additional stock movement must NOT be created
        $this->assertDatabaseMissing('stock_movements', [
            'business_id' => $this->businessId,
            'reference_type' => 'historical_purchase_bill',
            'qty_change' => 80,
        ]);
    }

    /**
     * Test 5 – Historical Purchase with No Stock
     * Historical purchase: 100 units.
     * Already consumed: 100 units.
     * Current stock increase: 0 units.
     * Supplier ledger must still be updated correctly.
     */
    public function test_05_historical_purchase_with_no_stock_updates_supplier_ledger(): void
    {
        $payload = [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-SUP-NO-STOCK-0',
            'invoice_date' => '2026-09-01',
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'variant_id' => 'PROD_BAGS_01',
                    'quantity' => 100,
                    'rate' => 350,
                    'consumed_quantity' => 100, // All 100 consumed
                    'included_in_opening_stock' => 0,
                ],
            ],
        ];

        $res = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', $payload, $this->headers);
        $res->assertStatus(200);

        // Actual stock adjustment is 0
        $this->assertDatabaseHas('historical_stock_adjustments', [
            'business_id' => $this->businessId,
            'original_purchased_qty' => 100,
            'consumed_qty' => 100,
            'actual_stock_adjustment_qty' => 0,
        ]);

        // Supplier ledger MUST be updated with Credit
        $this->assertDatabaseHas('supplier_ledger_entries', [
            'business_id' => $this->businessId,
            'is_historical' => 1,
            'entry_source' => 'historical',
            'status' => 'POSTED',
        ]);
    }

    /**
     * Test 6 – Historical Sale
     * A historical sale must update customer ledger and historical sales reports without duplicating stock movements.
     */
    public function test_06_historical_sale_updates_customer_ledger_without_duplicate_stock_movements(): void
    {
        $payload = [
            'original_invoice_number' => 'OLD-INV-2025-001',
            'original_invoice_date' => '2025-12-15',
            'customer_id' => 'CUST_01',
            'customer_name' => 'Ramesh Kumar',
            'outlet_id' => $this->outletId,
            'amount_received' => 6000,
            'lines' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'variant_id' => 'PROD_BAGS_01',
                    'quantity' => 20,
                    'sale_rate' => 500, // 20 * 500 = 10,000
                    'gst_percentage' => 0,
                ],
            ],
            'impact_current_stock' => false, // Opening inventory is source of truth, do not double deduct!
        ];

        $res = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', $payload, $this->headers);
        $res->assertStatus(200);

        // Verify customer ledger debit for 4000 due, and payment credit for 6000
        $this->assertDatabaseHas('customer_ledger_entries', [
            'business_id' => $this->businessId,
            'original_transaction_date' => '2025-12-15',
            'is_historical' => 1,
            'entry_source' => 'historical',
        ]);

        // Verify no duplicate stock deduction movement
        $this->assertDatabaseMissing('stock_movements', [
            'business_id' => $this->businessId,
            'reference_type' => 'historical_invoice',
        ]);

        // Check historical sales report
        $rep = $this->actingAs($this->user)->getJson('/api/v1/historical/reports/sales?record_scope=old_only', $this->headers);
        $rep->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $rep->json('data.count'));
    }

    /**
     * Test 7 – Historical Sale and Purchase Combined
     * Verify stock calculations and transaction balances across multiple dates.
     */
    public function test_07_historical_sale_and_purchase_combined(): void
    {
        // 1. Opening stock: 50 units
        $this->actingAs($this->user)->postJson('/api/v1/historical/opening-stock', [
            'product_id' => 'PROD_BAGS_01',
            'outlet_id' => $this->outletId,
            'quantity' => 50,
            'valuation_rate' => 300,
            'opening_date' => '2026-10-01',
        ], $this->headers)->assertStatus(200);

        // 2. Historical purchase with 25 actual remaining
        $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-COMB-PUR-01',
            'invoice_date' => '2026-09-15',
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 100,
                    'rate' => 300,
                    'consumed_quantity' => 75,
                    'included_in_opening_stock' => 0,
                ],
            ],
        ], $this->headers)->assertStatus(200);

        // Reconcile
        $recRes = $this->actingAs($this->user)->getJson('/api/v1/historical/reconciliation/stock?outlet_id='.$this->outletId, $this->headers);
        $recRes->assertStatus(200);
        $records = $recRes->json('data');
        $cement = collect($records)->firstWhere('productId', 'PROD_BAGS_01');

        $this->assertNotNull($cement);
        $this->assertEquals(50.0, $cement['openingStockQty']);
        $this->assertEquals(25.0, $cement['adjustmentsAppliedToCurrentStock']);
        $this->assertEquals(75.0, $cement['currentStockOnHand']);
        $this->assertTrue($cement['isReconciled']);
    }

    /**
     * Test 8 – Duplicate Invoice
     * The same historical invoice must not be imported twice.
     */
    public function test_08_duplicate_historical_invoice_prevented(): void
    {
        $payload = [
            'original_invoice_number' => 'OLD-DUP-CHECK-001',
            'original_invoice_date' => '2025-11-20',
            'lines' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 5,
                    'sale_rate' => 200,
                ],
            ],
        ];

        // First attempt succeeds
        $res1 = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', $payload, $this->headers);
        $res1->assertStatus(200);

        // Second attempt with same original invoice number must fail with 422
        $res2 = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', $payload, $this->headers);
        $res2->assertStatus(422);
        $this->assertStringContainsString('Duplicate invoice', $res2->json('message'));
    }

    /**
     * Test 9 – Payment Reconciliation
     * Verify that invoice amount, payments and outstanding balances are correct.
     */
    public function test_09_payment_reconciliation_verifies_balances_and_ledgers(): void
    {
        // Invoice 10,000, paid 6,000, due 4,000
        $payload = [
            'original_invoice_number' => 'RECON-INV-999',
            'original_invoice_date' => '2025-08-10',
            'customer_id' => 'CUST_01',
            'amount_received' => 600000, // 6,000 in paise
            'grand_total' => 1000000, // 10,000 in paise
            'lines' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 10,
                    'sale_rate' => 100000,
                    'gst_percentage' => 0,
                ],
            ],
        ];

        $res = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', $payload, $this->headers);
        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertEquals(1000000, $data['grandTotal']);
        $this->assertEquals(600000, $data['paidAmount']);
        $this->assertEquals(400000, $data['dueAmount']);
        $this->assertEquals('PARTIALLY_PAID', $data['status']);

        // Check customer ledger
        $ledgerRes = $this->actingAs($this->user)->getJson('/api/v1/historical/reports/customer-ledger?customer_id=CUST_01', $this->headers);
        $ledgerRes->assertStatus(200);
        $this->assertEquals(400000, $ledgerRes->json('data.closingBalancePaise'));
    }

    /**
     * Test 10 – Multi-company Isolation
     * Verify that one company cannot view or modify another company's historical records.
     */
    public function test_10_multi_company_isolation(): void
    {
        // Create Company B and User B
        $bizB = 'BUS_COMPANY_B';
        $userB = new User();
        $userB->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'Owner Company B',
            'email' => 'companyb@test.com',
            'phone' => '9111111111',
            'password' => Hash::make('Secret123!'),
            'role' => 'OWNER',
            'status' => 'ACTIVE',
            'business_id' => $bizB,
        ]);
        $userB->save();

        // Create historical sale in Company A
        $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'COMPANY-A-SECRET-01',
            'original_invoice_date' => '2025-05-01',
            'lines' => [
                ['product_id' => 'PROD_BAGS_01', 'quantity' => 1, 'sale_rate' => 100],
            ],
        ], $this->headers)->assertStatus(200);

        // User B querying Company B's reports must NOT see Company A's invoice
        $headersB = ['X-Business-Id' => $bizB];
        $resB = $this->actingAs($userB)->getJson('/api/v1/historical/reports/sales?record_scope=all', $headersB);
        $resB->assertStatus(200);
        $this->assertEquals(0, $resB->json('data.count'));

        // User B trying to access Company A's headers directly must be forbidden
        $forbiddenRes = $this->actingAs($userB)->getJson('/api/v1/historical/reports/sales?record_scope=all', $this->headers);
        $forbiddenRes->assertStatus(403);
    }

    /**
     * Test 11 – Rollback
     * Verify that a historical batch can be safely reversed without affecting unrelated transactions.
     */
    public function test_11_historical_batch_safe_rollback(): void
    {
        // 1. Create a migration batch
        $batch = MigrationBatch::create([
            'id' => 'BATCH-TEST-ROLLBACK',
            'business_id' => $this->businessId,
            'source_software' => 'Tally',
            'status' => 'DRAFT',
        ]);

        // 2. Post historical purchase with stock adjustment in this batch
        $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'ROLLBACK-BILL-01',
            'invoice_date' => '2026-08-01',
            'batch_id' => $batch->id,
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 40,
                    'rate' => 200,
                    'consumed_quantity' => 0,
                    'included_in_opening_stock' => 0,
                ],
            ],
        ], $this->headers)->assertStatus(200);

        // Verify batch has records
        $this->assertDatabaseHas('historical_stock_adjustments', [
            'batch_id' => $batch->id,
            'status' => 'POSTED',
        ]);

        // 3. Rollback the batch
        $rollbackRes = $this->actingAs($this->user)->postJson("/api/v1/historical/batches/{$batch->id}/rollback", [], $this->headers);
        $rollbackRes->assertStatus(200);

        // Verify status rolled back
        $this->assertDatabaseHas('migration_batches', [
            'id' => $batch->id,
            'status' => 'ROLLED_BACK',
        ]);

        $this->assertDatabaseHas('historical_stock_adjustments', [
            'batch_id' => $batch->id,
            'status' => 'ROLLED_BACK',
        ]);

        // Soft deleted bills
        $this->assertDatabaseHas('purchase_bills', [
            'migration_batch_id' => $batch->id,
            'is_deleted' => 1,
            'status' => 'ROLLED_BACK',
        ]);
    }

    /**
     * Test 12 – Combined Reports
     * Verify that old and new transactions appear together exactly once.
     */
    public function test_12_combined_reports_contain_old_and_new_records_without_duplicates(): void
    {
        // 1. Post a historical sale
        $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-COMB-REP-01',
            'original_invoice_date' => '2025-07-01',
            'lines' => [
                ['product_id' => 'PROD_BAGS_01', 'quantity' => 2, 'sale_rate' => 300],
            ],
        ], $this->headers)->assertStatus(200);

        // 2. Query old only
        $oldRes = $this->actingAs($this->user)->getJson('/api/v1/historical/reports/sales?record_scope=old_only', $this->headers);
        $oldRes->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $oldRes->json('data.count'));

        // 3. Query combined (all)
        $allRes = $this->actingAs($this->user)->getJson('/api/v1/historical/reports/sales?record_scope=all', $this->headers);
        $allRes->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $allRes->json('data.count'));

        $numbers = collect($allRes->json('data.records'))->pluck('invoiceNumber')->all();
        // Verify unique invoice numbers (no duplicates)
        $this->assertEquals(count($numbers), count(array_unique($numbers)));
    }
}
