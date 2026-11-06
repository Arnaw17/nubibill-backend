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
        if (!in_array($res->status(), [200, 201])) { $this->fail('API Error in test_01: ' . json_encode($res->json())); }
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
        if ($res->status() !== 200) { $this->fail('API Error: ' . json_encode($res->json())); }
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

    /**
     * Test 13 – Historical Sale with large amount received in Rupees (e.g. ₹12,000)
     * Verifies that amount_received > 1000 is correctly treated as Rupees (1200000 paise)
     * and does not result in ₹120 paid and ₹11880 due.
     */
    public function test_13_historical_sale_amount_received_treated_correctly_as_rupees(): void
    {
        $payload = [
            'original_invoice_number' => 'HIST-TEST-12000',
            'original_invoice_date' => '2026-10-06',
            'customer_name' => 'Aditya Test',
            'outlet_id' => $this->outletId,
            'amount_received' => 12000,
            'lines' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 20,
                    'sale_rate' => 600, // 20 * 600 = 12000.00
                    'gst_percentage' => 0,
                ],
            ],
            'impact_current_stock' => false,
        ];

        $res = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', $payload, $this->headers);
        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertEquals(1200000, $data['grandTotal']);
        $this->assertEquals(1200000, $data['paidAmount']);
        $this->assertEquals(0, $data['dueAmount']);
        $this->assertEquals('PAID', $data['status']);

        // Check customer ledger entry has credit for 1,200,000 paise (full 12,000 paid)
        $this->assertDatabaseHas('customer_ledger_entries', [
            'business_id' => $this->businessId,
            'is_historical' => 1,
            'entry_source' => 'historical',
        ]);
        $entry = DB::table('customer_ledger_entries')
            ->where('business_id', $this->businessId)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.transactionType')) = 'PAYMENT'")
            ->first();
        $this->assertNotNull($entry);
        $entryPayload = json_decode($entry->payload, true);
        $this->assertEquals(1200000, $entryPayload['fields']['amountPaise']);
        $this->assertEquals(1200000, $entryPayload['fields']['creditPaise']);
    }

    /**
     * Test 14 – Old Sale cannot exceed Old Inventory inward stock, and returns restore stock.
     */
    public function test_14_historical_sale_cannot_exceed_old_inventory_stock_and_restores_on_return(): void
    {
        // 1. Record historical purchase of 50 units
        $purRes = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-STOCK-PUR-50',
            'invoice_date' => '2026-01-10',
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 50,
                    'rate' => 200,
                    'consumed_quantity' => 0,
                    'included_in_opening_stock' => 0,
                ],
            ],
        ], $this->headers);
        $purRes->assertStatus(200);

        // Check stock reconciliation shows 50 available
        $recRes = $this->actingAs($this->user)->getJson('/api/v1/historical/reconciliation/stock?outlet_id='.$this->outletId, $this->headers);
        $recRes->assertStatus(200);
        $prodRecon = collect($recRes->json('data'))->firstWhere('productId', 'PROD_BAGS_01');
        $this->assertEquals(50.0, $prodRecon['availableOldStock']);
        $this->assertEquals(50.0, $prodRecon['historicalPurchasesQty']);
        $this->assertEquals(0.0, $prodRecon['historicalSalesQty']);

        // 2. Attempt historical sale of 51 units -> must fail
        $oversellRes = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-OVERSELL-51',
            'original_invoice_date' => '2026-02-01',
            'customer_name' => 'Over Buyer',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 51,
                    'sale_rate' => 300,
                ],
            ],
        ], $this->headers);
        $oversellRes->assertStatus(422);
        $this->assertStringContainsString('exceeds available old inventory stock', $oversellRes->json('message'));

        // 3. Record historical sale of 50 units -> must succeed
        $exactRes = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-EXACT-50',
            'original_invoice_date' => '2026-02-01',
            'customer_name' => 'Exact Buyer',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 50,
                    'sale_rate' => 300,
                ],
            ],
        ], $this->headers);
        $exactRes->assertStatus(200);

        // Check stock reconciliation shows 0 remaining
        $recRes2 = $this->actingAs($this->user)->getJson('/api/v1/historical/reconciliation/stock?outlet_id='.$this->outletId, $this->headers);
        $prodRecon2 = collect($recRes2->json('data'))->firstWhere('productId', 'PROD_BAGS_01');
        $this->assertEquals(0.0, $prodRecon2['availableOldStock']);
        $this->assertEquals(50.0, $prodRecon2['historicalSalesQty']);

        // 4. Attempt 1 more unit -> must fail with no stock
        $noStockRes = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-NO-STOCK-01',
            'original_invoice_date' => '2026-02-02',
            'customer_name' => 'Late Buyer',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 1,
                    'sale_rate' => 300,
                ],
            ],
        ], $this->headers);
        $noStockRes->assertStatus(422);
        $this->assertStringContainsString('No stock available', $noStockRes->json('message'));

        // 5. Simulate return of 10 units on the sale invoice item
        $saleItem = DB::table('invoice_items')
            ->where('product_id', 'PROD_BAGS_01')
            ->first();
        $this->assertNotNull($saleItem);
        DB::table('invoice_items')->where('id', $saleItem->id)->update(['returned_qty' => 10]);

        // Verify stock reconciliation now has 10 available
        $recRes3 = $this->actingAs($this->user)->getJson('/api/v1/historical/reconciliation/stock?outlet_id='.$this->outletId, $this->headers);
        $prodRecon3 = collect($recRes3->json('data'))->firstWhere('productId', 'PROD_BAGS_01');
        $this->assertEquals(10.0, $prodRecon3['availableOldStock']);
        $this->assertEquals(40.0, $prodRecon3['historicalSalesQty']);

        // 6. Record sale of 10 units -> must succeed now that 10 units were returned
        $restoredSaleRes = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-RESTORED-10',
            'original_invoice_date' => '2026-02-05',
            'customer_name' => 'Restored Buyer',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => 'PROD_BAGS_01',
                    'quantity' => 10,
                    'sale_rate' => 300,
                ],
            ],
        ], $this->headers);
        $restoredSaleRes->assertStatus(200);
    }

    /**
     * Test 15: Old bills strictly reject today's date and future dates (Backend validation).
     */
    public function test_15_old_bills_reject_today_and_future_dates(): void
    {
        $todayStr = \Carbon\Carbon::today()->toDateString();
        $tomorrowStr = \Carbon\Carbon::tomorrow()->toDateString();
        $yesterdayStr = \Carbon\Carbon::yesterday()->toDateString();

        // 1. Old Purchase with today's date -> rejected
        $purTodayRes = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-PUR-TODAY',
            'invoice_date' => $todayStr,
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => 'PROD_DATE_TEST',
                    'quantity' => 10,
                    'rate' => 100,
                ],
            ],
        ], $this->headers);
        $purTodayRes->assertStatus(422);
        $this->assertStringContainsString('Old bill date must be strictly prior to today', $purTodayRes->json('message'));

        // 2. Old Purchase with future date -> rejected
        $purFutureRes = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-PUR-FUTURE',
            'invoice_date' => $tomorrowStr,
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => 'PROD_DATE_TEST',
                    'quantity' => 10,
                    'rate' => 100,
                ],
            ],
        ], $this->headers);
        $purFutureRes->assertStatus(422);
        $this->assertStringContainsString('Old bill date must be strictly prior to today', $purFutureRes->json('message'));

        // 3. Old Sale with today's date -> rejected
        $saleTodayRes = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-SALE-TODAY',
            'original_invoice_date' => $todayStr,
            'customer_name' => 'Date Test Customer',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => 'PROD_DATE_TEST',
                    'quantity' => 5,
                    'sale_rate' => 150,
                ],
            ],
        ], $this->headers);
        $saleTodayRes->assertStatus(422);
        $this->assertStringContainsString('Old bill date must be strictly prior to today', $saleTodayRes->json('message'));

        // 4. Old Sale with future date -> rejected
        $saleFutureRes = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-SALE-FUTURE',
            'original_invoice_date' => $tomorrowStr,
            'customer_name' => 'Date Test Customer',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => 'PROD_DATE_TEST',
                    'quantity' => 5,
                    'sale_rate' => 150,
                ],
            ],
        ], $this->headers);
        $saleFutureRes->assertStatus(422);
        $this->assertStringContainsString('Old bill date must be strictly prior to today', $saleFutureRes->json('message'));
    }

    /**
     * Test 16: Date-wise historical stock availability, first purchase validation, and FIFO batch allocation.
     */
    public function test_16_date_wise_stock_availability_and_fifo_batch_allocation(): void
    {
        $productId = 'PROD_ACE_SUPREMA_20L';

        // 1. Create Old Purchase 1: 70 units on 2026-01-10
        $p1Res = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-ACE-P1',
            'invoice_date' => '2026-01-10',
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => 70,
                    'rate' => 500,
                ],
            ],
        ], $this->headers);
        $p1Res->assertStatus(200);

        // 2. Create Old Purchase 2: 30 units on 2026-01-20
        $p2Res = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-ACE-P2',
            'invoice_date' => '2026-01-20',
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => 30,
                    'rate' => 520,
                ],
            ],
        ], $this->headers);
        $p2Res->assertStatus(200);

        // 3. Query stock availability endpoint before first purchase date (2026-01-05)
        $availPreRes = $this->actingAs($this->user)->getJson(
            "/api/v1/historical/stock-availability?product_id={$productId}&date=2026-01-05&outlet_id={$this->outletId}",
            $this->headers
        );
        $availPreRes->assertStatus(200);
        $this->assertEquals('NOT_YET_PURCHASED', $availPreRes->json('data.status'));
        $this->assertEquals(0.0, $availPreRes->json('data.availableQuantity'));
        $this->assertStringContainsString('The first available stock was purchased on 2026-01-10', $availPreRes->json('data.message'));

        // 4. Attempt old sale on 2026-01-05 -> must fail with clear message
        $salePreRes = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-ACE-S-PRE',
            'original_invoice_date' => '2026-01-05',
            'customer_name' => 'Early Customer',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => $productId,
                    'quantity' => 10,
                    'sale_rate' => 600,
                ],
            ],
        ], $this->headers);
        $salePreRes->assertStatus(422);
        $this->assertStringContainsString('The first available stock was purchased on 2026-01-10', $salePreRes->json('message'));

        // 5. Query stock availability between P1 and P2 (2026-01-15) -> only P1's 70 units available
        $availMidRes = $this->actingAs($this->user)->getJson(
            "/api/v1/historical/stock-availability?product_id={$productId}&date=2026-01-15&outlet_id={$this->outletId}",
            $this->headers
        );
        $availMidRes->assertStatus(200);
        $this->assertEquals('AVAILABLE', $availMidRes->json('data.status'));
        $this->assertEquals(70.0, $availMidRes->json('data.availableQuantity'));

        // 6. Attempt sale of 75 units on 2026-01-15 -> fails because only 70 units purchased by 2026-01-15
        $saleExcessRes = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-ACE-S-EXCESS',
            'original_invoice_date' => '2026-01-15',
            'customer_name' => 'Mid Customer',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => $productId,
                    'quantity' => 75,
                    'sale_rate' => 600,
                ],
            ],
        ], $this->headers);
        $saleExcessRes->assertStatus(422);
        $this->assertStringContainsString('exceeds available historical stock on 2026-01-15', $saleExcessRes->json('message'));

        // 7. Record valid sale 1: 30 units on 2026-01-15 -> succeeds and deducts from P1 (FIFO)
        $s1Res = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-ACE-S1',
            'original_invoice_date' => '2026-01-15',
            'customer_name' => 'Customer One',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => $productId,
                    'quantity' => 30,
                    'sale_rate' => 600,
                ],
            ],
        ], $this->headers);
        $s1Res->assertStatus(200);

        // Verify batches via Inventory API:
        // P1 batch: initial 70, available 40, sold 30
        // P2 batch: initial 30, available 30, sold 0
        $batchRes = $this->actingAs($this->user)->getJson(
            "/api/v1/inventory/batches?product_id={$productId}&outlet_id={$this->outletId}",
            $this->headers
        );
        $batchRes->assertStatus(200);
        $batches = $batchRes->json('data');
        $this->assertCount(2, $batches);

        $b1 = collect($batches)->firstWhere('purchaseDate', '2026-01-10');
        $b2 = collect($batches)->firstWhere('purchaseDate', '2026-01-20');

        $this->assertNotNull($b1);
        $this->assertNotNull($b2);
        $this->assertEquals(40.0, (float) $b1['availableQuantity']);
        $this->assertEquals(30.0, (float) $b1['soldQuantity']);
        $this->assertEquals(30.0, (float) $b2['availableQuantity']);
        $this->assertEquals(0.0, (float) $b2['soldQuantity']);
    }

    /**
     * Test 17: Old Sales Return inventory destination (Old Inventory vs New Inventory).
     */
    public function test_17_old_sales_return_inventory_destination(): void
    {
        $productId = 'PROD_RETURN_DEST_TEST';

        // 1. Inward 20 units historical purchase
        $pRes = $this->actingAs($this->user)->postJson('/api/v1/historical/purchases', [
            'supplier_id' => 'SUP_01',
            'supplier_invoice_number' => 'HIST-RET-P1',
            'invoice_date' => '2026-02-01',
            'outlet_id' => $this->outletId,
            'items' => [
                [
                    'product_id' => $productId,
                    'quantity' => 20,
                    'rate' => 200,
                ],
            ],
        ], $this->headers);
        $pRes->assertStatus(200);

        // 2. Sell 10 units via old sale
        $sRes = $this->actingAs($this->user)->postJson('/api/v1/historical/sales', [
            'original_invoice_number' => 'HIST-RET-S1',
            'original_invoice_date' => '2026-02-05',
            'customer_name' => 'Return Customer',
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => $productId,
                    'quantity' => 10,
                    'sale_rate' => 300,
                ],
            ],
        ], $this->headers);
        $sRes->assertStatus(200);
        $invoiceId = $sRes->json('data.invoiceId');

        $batchBefore = DB::table('inventory_batches')
            ->where('product_id', $productId)
            ->first();
        $bPayloadBefore = json_decode($batchBefore->payload, true) ?: [];
        $availBefore = (float) (isset($batchBefore->quantity_available) ? $batchBefore->quantity_available : ($bPayloadBefore['fields']['availableQuantity'] ?? 0));
        $this->assertEquals(10.0, $availBefore);

        // Check current operational stock before return
        $currentStockBefore = (float) (DB::table('stock_balances')
            ->where('business_id', $this->businessId)
            ->where('outlet_id', $this->outletId)
            ->where('product_id', $productId)
            ->value('quantity_on_hand') ?? 0);

        // 3. Process Return with destination = OLD_INVENTORY (3 units)
        $itemRow = DB::table('invoice_items')->where('invoice_id', $invoiceId)->first();
        $retOldRes = $this->actingAs($this->user)->postJson('/api/v1/returns', [
            'type' => 'sale_return',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'party_id' => 'walk_in',
            'party_name' => 'Return Customer',
            'original_bill_id' => $invoiceId,
            'inventory_destination' => 'OLD_INVENTORY',
            'items' => [
                [
                    'original_bill_item_id' => $itemRow->id,
                    'product_id' => $productId,
                    'product_name' => 'Return Product',
                    'qty_returned' => 3,
                    'unit_price' => 300,
                    'condition' => 'resalable',
                    'restock' => true,
                ],
            ],
        ], $this->headers);
        $retOldRes->assertStatus(201);

        // Verification 1: Historical batch available increased by 3 (from 10 to 13)
        $batchAfterOld = DB::table('inventory_batches')->where('id', $batchBefore->id)->first();
        $bPayloadOld = json_decode($batchAfterOld->payload, true) ?: [];
        $availOld = (float) (isset($batchAfterOld->quantity_available) ? $batchAfterOld->quantity_available : ($bPayloadOld['fields']['availableQuantity'] ?? 0));
        $this->assertEquals(13.0, $availOld);

        // Verification 2: Current operational stock balance UNCHANGED
        $currentStockAfterOld = (float) (DB::table('stock_balances')
            ->where('business_id', $this->businessId)
            ->where('outlet_id', $this->outletId)
            ->where('product_id', $productId)
            ->value('quantity_on_hand') ?? 0);
        $this->assertEquals($currentStockBefore, $currentStockAfterOld);

        // 4. Process Return with destination = NEW_INVENTORY (2 units)
        $retNewRes = $this->actingAs($this->user)->postJson('/api/v1/returns', [
            'type' => 'sale_return',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'party_id' => 'walk_in',
            'party_name' => 'Return Customer',
            'original_bill_id' => $invoiceId,
            'inventory_destination' => 'NEW_INVENTORY',
            'items' => [
                [
                    'original_bill_item_id' => $itemRow->id,
                    'product_id' => $productId,
                    'product_name' => 'Return Product',
                    'qty_returned' => 2,
                    'unit_price' => 300,
                    'condition' => 'resalable',
                    'restock' => true,
                ],
            ],
        ], $this->headers);
        $retNewRes->assertStatus(201);
        // Verification 3: Current operational stock increased by 2
        $currentStockAfterNew = (float) (DB::table('stock_balances')
            ->where('business_id', $this->businessId)
            ->where('outlet_id', $this->outletId)
            ->where('product_id', $productId)
            ->value('quantity_on_hand') ?? 0);
        $this->assertEquals($currentStockBefore + 2.0, $currentStockAfterNew);

        // Verification 4: Historical batch NOT changed for NEW_INVENTORY return (still 13)
        $batchAfterNew = DB::table('inventory_batches')->where('id', $batchBefore->id)->first();
        $bPayloadNew = json_decode($batchAfterNew->payload, true) ?: [];
        $availNew = (float) (isset($batchAfterNew->quantity_available) ? $batchAfterNew->quantity_available : ($bPayloadNew['fields']['availableQuantity'] ?? 0));
        $this->assertEquals(13.0, $availNew);
    }
}
