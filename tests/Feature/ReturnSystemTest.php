<?php

namespace Tests\Feature;

use App\Models\ReturnModel;
use App\Models\ReturnReason;
use App\Models\User;
use Database\Seeders\ReturnReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected string $businessId;

    protected string $outletId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessId = 'bus_test_123';
        $this->outletId = 'out_test_123';

        // Create verified, active user with business access
        $this->user = new User;
        $this->user->forceFill([
            'name' => 'Store Owner',
            'email' => 'owner@nubibilling.com',
            'phone' => '9876543210',
            'password' => Hash::make('Password123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'business_id' => $this->businessId,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $this->user->save();

        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // Seed default return reasons
        $this->seed(ReturnReasonSeeder::class);
    }

    public function test_create_sale_return_restocks_inventory_and_credits_customer_ledger(): void
    {
        $productId = 'prod_shirt_1';
        $customerId = 'cust_alice_1';

        // Seed existing stock balance of 10 (10000 milli-units)
        DB::table('stock_balances')->insert([
            'id' => "stock_{$this->businessId}_{$productId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'productId' => $productId,
                    'quantityOnHand' => 10000,
                    'currentStock' => 10000,
                ],
            ]),
        ]);

        // Seed customer balance with 1000 INR due (100000 paise)
        DB::table('customer_balances')->insert([
            'id' => $customerId,
            'business_id' => $this->businessId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'customerId' => $customerId,
                    'duePaise' => 100000,
                ],
            ]),
        ]);

        $reason = ReturnReason::where('code', 'CUSTOMER_CHANGED_MIND')->first();

        $payload = [
            'type' => 'sale_return',
            'party_id' => $customerId,
            'party_name' => 'Alice Sharma',
            'party_phone' => '9123456780',
            'refund_mode' => 'adjust_to_ledger',
            'reason_id' => $reason?->id,
            'reason_note' => 'Wrong size purchased',
            'party_state_code' => '27',
            'business_state_code' => '27',
            'items' => [
                [
                    'product_id' => $productId,
                    'product_name' => 'Cotton Shirt Blue M',
                    'hsn_code' => '6205',
                    'unit' => 'PCS',
                    'qty_returned' => 2,
                    'unit_price' => 500.00,
                    'tax_rate' => 5.0,
                    'condition' => 'resalable',
                    'restock' => true,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->withHeader('X-Outlet-Id', $this->outletId)
            ->postJson('/api/v1/returns', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'sale_return')
            ->assertJsonPath('data.return_no', 'SR-0001')
            ->assertJsonPath('data.subtotal', 1000)
            ->assertJsonPath('data.tax_total', 50)
            ->assertJsonPath('data.grand_total', 1050);

        // Verify Stock was restocked: 10000 + 2000 = 12000 milli-units
        $stockRow = DB::table('stock_balances')->where('id', "stock_{$this->businessId}_{$productId}")->first();
        $stockPayload = json_decode($stockRow->payload, true);
        $this->assertEquals(12000, (int) $stockPayload['fields']['quantityOnHand']);

        // Verify stock movement logged
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'type' => 'sale_return',
            'qty_change' => 2.0,
            'condition' => 'resalable',
        ]);

        // Verify Customer Ledger was credited
        $this->assertDatabaseHas('ledger_entries', [
            'party_id' => $customerId,
            'party_type' => 'customer',
            'type' => 'credit',
            'amount' => 1050.00,
        ]);
    }

    public function test_create_sale_return_damaged_condition_logs_wastage_without_restock(): void
    {
        $productId = 'prod_glass_1';
        $customerId = 'cust_bob_1';

        // Seed stock balance of 5 (5000 milli-units)
        DB::table('stock_balances')->insert([
            'id' => "stock_{$this->businessId}_{$productId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'productId' => $productId,
                    'quantityOnHand' => 5000,
                    'currentStock' => 5000,
                ],
            ]),
        ]);

        $payload = [
            'type' => 'sale_return',
            'party_id' => $customerId,
            'party_name' => 'Bob Verma',
            'refund_mode' => 'cash',
            'items' => [
                [
                    'product_id' => $productId,
                    'product_name' => 'Glass Mug Set',
                    'qty_returned' => 1,
                    'unit_price' => 300.00,
                    'tax_rate' => 18.0,
                    'condition' => 'damaged',
                    'restock' => false,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->withHeader('X-Outlet-Id', $this->outletId)
            ->postJson('/api/v1/returns', $payload);

        $response->assertStatus(201);

        // Verify stock was NOT increased (remains 5000 milli-units)
        $stockRow = DB::table('stock_balances')->where('id', "stock_{$this->businessId}_{$productId}")->first();
        $stockPayload = json_decode($stockRow->payload, true);
        $this->assertEquals(5000, (int) $stockPayload['fields']['quantityOnHand']);

        // Verify wastage movement was logged
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'type' => 'wastage',
            'qty_change' => 0.0,
            'condition' => 'damaged',
        ]);
    }

    public function test_over_return_is_rejected_with_422(): void
    {
        $billId = 'inv_test_999';
        $billItemId = 'item_test_111';
        $productId = 'prod_book_1';

        // Seed original invoice item with quantity = 3 and returned_qty = 1 (available: 2)
        DB::table('invoice_items')->insert([
            'id' => $billItemId,
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'returned_qty' => 1.0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'invoiceId' => $billId,
                    'productId' => $productId,
                    'quantity' => 3.0,
                    'returnedQty' => 1.0,
                ],
            ]),
        ]);

        // Attempt to return 3 (which exceeds remaining 2)
        $payload = [
            'type' => 'sale_return',
            'original_bill_id' => $billId,
            'party_id' => 'cust_test_1',
            'items' => [
                [
                    'product_id' => $productId,
                    'product_name' => 'Maths Workbook',
                    'original_bill_item_id' => $billItemId,
                    'qty_returned' => 3, // Exceeds available returnable qty (2)
                    'unit_price' => 150.00,
                    'tax_rate' => 0,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->postJson('/api/v1/returns', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_partial_return_tracks_remaining_returnable_qty(): void
    {
        $billId = 'inv_test_part';
        $billItemId = 'item_test_part';
        $productId = 'prod_bag_1';

        DB::table('invoice_items')->insert([
            'id' => $billItemId,
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'returned_qty' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'invoiceId' => $billId,
                    'productId' => $productId,
                    'quantity' => 5.0,
                    'returnedQty' => 0.0,
                ],
            ]),
        ]);

        // 1st Partial Return: Return 2 out of 5
        $payload = [
            'type' => 'sale_return',
            'original_bill_id' => $billId,
            'party_id' => 'cust_test_2',
            'items' => [
                [
                    'product_id' => $productId,
                    'product_name' => 'Leather Bag',
                    'original_bill_item_id' => $billItemId,
                    'qty_returned' => 2,
                    'unit_price' => 800.00,
                    'tax_rate' => 12.0,
                ],
            ],
        ];

        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->postJson('/api/v1/returns', $payload);

        $res1->assertStatus(201);

        // Verify returned_qty is now 2
        $itemRow = DB::table('invoice_items')->where('id', $billItemId)->first();
        $this->assertEquals(2.0, (float) $itemRow->returned_qty);

        // 2nd Partial Return: Return remaining 3 out of 3
        $payload['items'][0]['qty_returned'] = 3;
        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->postJson('/api/v1/returns', $payload);

        $res2->assertStatus(201);

        // Verify returned_qty is now 5
        $itemRow2 = DB::table('invoice_items')->where('id', $billItemId)->first();
        $this->assertEquals(5.0, (float) $itemRow2->returned_qty);

        // 3rd Return should fail because 0 is remaining
        $payload['items'][0]['qty_returned'] = 1;
        $res3 = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->postJson('/api/v1/returns', $payload);

        $res3->assertStatus(422);
    }

    public function test_create_purchase_return_deducts_stock_and_debits_supplier_ledger(): void
    {
        $productId = 'prod_oil_1';
        $supplierId = 'supp_raj_1';

        // Seed stock balance of 20 (20000 milli-units)
        DB::table('stock_balances')->insert([
            'id' => "stock_{$this->businessId}_{$productId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'productId' => $productId,
                    'quantityOnHand' => 20000,
                    'currentStock' => 20000,
                ],
            ]),
        ]);

        $payload = [
            'type' => 'purchase_return',
            'party_id' => $supplierId,
            'party_name' => 'Raj Wholesalers',
            'party_phone' => '9888877777',
            'refund_mode' => 'adjust_to_ledger',
            'items' => [
                [
                    'product_id' => $productId,
                    'product_name' => 'Sunflower Oil 5L',
                    'qty_returned' => 5,
                    'unit_price' => 600.00,
                    'tax_rate' => 5.0,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->withHeader('X-Outlet-Id', $this->outletId)
            ->postJson('/api/v1/returns', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'purchase_return')
            ->assertJsonPath('data.return_no', 'PR-0001')
            ->assertJsonPath('data.grand_total', 3150);

        // Verify stock was deducted: 20000 - 5000 = 15000 milli-units
        $stockRow = DB::table('stock_balances')->where('id', "stock_{$this->businessId}_{$productId}")->first();
        $stockPayload = json_decode($stockRow->payload, true);
        $this->assertEquals(15000, (int) $stockPayload['fields']['quantityOnHand']);

        // Verify stock movement logged
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'type' => 'purchase_return',
            'qty_change' => -5.0,
        ]);

        // Verify Supplier Ledger debited
        $this->assertDatabaseHas('ledger_entries', [
            'party_id' => $supplierId,
            'party_type' => 'supplier',
            'type' => 'debit',
            'amount' => 3150.00,
        ]);
    }

    public function test_idempotency_key_prevents_duplicate_returns(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'idempotency_key' => $idempotencyKey,
            'type' => 'sale_return',
            'party_id' => 'cust_idem_1',
            'items' => [
                [
                    'product_id' => 'prod_idem_1',
                    'product_name' => 'Widget A',
                    'qty_returned' => 1,
                    'unit_price' => 100.00,
                    'tax_rate' => 0,
                ],
            ],
        ];

        // 1st Call
        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->postJson('/api/v1/returns', $payload);
        $res1->assertStatus(201);
        $returnId1 = $res1->json('data.id');

        // 2nd Call with identical idempotency key
        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->postJson('/api/v1/returns', $payload);
        $res2->assertStatus(201);
        $returnId2 = $res2->json('data.id');

        // Must return identical existing return record without duplicate insertions
        $this->assertEquals($returnId1, $returnId2);
        $this->assertEquals(1, ReturnModel::where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_cancelling_return_reverses_stock_and_ledger_effects(): void
    {
        $productId = 'prod_cancel_1';
        $customerId = 'cust_cancel_1';

        // Initial stock: 10 (10000 milli-units)
        DB::table('stock_balances')->insert([
            'id' => "stock_{$this->businessId}_{$productId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'productId' => $productId,
                    'quantityOnHand' => 10000,
                    'currentStock' => 10000,
                ],
            ]),
        ]);

        // Create Sales Return of 4 (stock becomes 14)
        $payload = [
            'type' => 'sale_return',
            'party_id' => $customerId,
            'refund_mode' => 'adjust_to_ledger',
            'items' => [
                [
                    'product_id' => $productId,
                    'product_name' => 'Item to cancel',
                    'qty_returned' => 4,
                    'unit_price' => 250.00,
                    'tax_rate' => 0,
                    'condition' => 'resalable',
                    'restock' => true,
                ],
            ],
        ];

        $res = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->withHeader('X-Outlet-Id', $this->outletId)
            ->postJson('/api/v1/returns', $payload);

        $res->assertStatus(201);
        $returnId = $res->json('data.id');

        // Stock is now 14000 milli-units (14 units)
        $stockBeforeCancel = DB::table('stock_balances')->where('id', "stock_{$this->businessId}_{$productId}")->first();
        $this->assertEquals(14000, (int) json_decode($stockBeforeCancel->payload, true)['fields']['quantityOnHand']);

        // Now Cancel the return
        $cancelRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->postJson("/api/v1/returns/{$returnId}/cancel", [
                'reason' => 'Customer mistakenly processed return on wrong bill',
            ]);

        $cancelRes->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Customer mistakenly processed return on wrong bill');

        // Stock must be restored back to 10000 milli-units (10 units)
        $stockAfterCancel = DB::table('stock_balances')->where('id', "stock_{$this->businessId}_{$productId}")->first();
        $this->assertEquals(10000, (int) json_decode($stockAfterCancel->payload, true)['fields']['quantityOnHand']);

        // Reversal stock movement must exist
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'type' => 'adjustment',
            'qty_change' => -4.0,
        ]);

        // Reversal ledger entry must exist
        $this->assertDatabaseHas('ledger_entries', [
            'party_id' => $customerId,
            'type' => 'debit',
            'amount' => 1000.00,
            'is_reversal' => true,
        ]);
    }

    public function test_profit_loss_and_reports_reflect_return_deductions(): void
    {
        // 1. Create a Sales Invoice of ₹2000 (200000 paise)
        DB::table('invoices')->insert([
            'id' => 'inv_pl_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'PAID',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'grandTotal' => 200000,
                ],
            ]),
        ]);

        // 2. Create a Sales Return of ₹500
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->postJson('/api/v1/returns', [
                'type' => 'sale_return',
                'party_id' => 'cust_pl_1',
                'items' => [
                    [
                        'product_id' => 'prod_pl_1',
                        'product_name' => 'PL Test Item',
                        'qty_returned' => 1,
                        'unit_price' => 500.00,
                        'tax_rate' => 0,
                    ],
                ],
            ]);

        // 3. Query Profit & Loss report
        $plRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->getJson('/api/v1/reports/profit-loss?startDate='.now()->startOfMonth()->toDateString().'&endDate='.now()->toDateString());

        $plRes->assertStatus(200)
            ->assertJsonPath('data.grossSalesRupees', 2000)
            ->assertJsonPath('data.salesReturnsRupees', 500)
            ->assertJsonPath('data.netSalesRupees', 1500);

        // 4. Query Returns Summary report
        $sumRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->getJson('/api/v1/reports/returns?startDate='.now()->startOfMonth()->toDateString().'&endDate='.now()->toDateString());

        $sumRes->assertStatus(200)
            ->assertJsonPath('data.totalReturns', 1)
            ->assertJsonPath('data.salesReturnsValue', 500);
    }

    public function test_return_bill_pdf_endpoint_streams_pdf(): void
    {
        // Create return
        $createRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->postJson('/api/v1/returns', [
                'type' => 'sale_return',
                'party_id' => 'cust_pdf_1',
                'party_name' => 'Meena Gupta',
                'items' => [
                    [
                        'product_id' => 'prod_pdf_1',
                        'product_name' => 'Saree Banarasi Silk',
                        'qty_returned' => 1,
                        'unit_price' => 2400.00,
                        'tax_rate' => 5.0,
                    ],
                ],
            ]);

        $returnId = $createRes->json('data.id');

        // Request PDF
        $pdfRes = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->get("/api/v1/returns/{$returnId}/pdf");

        $pdfRes->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $pdfRes->headers->get('Content-Type') ?? '');
    }

    public function test_returnable_bill_endpoint_returns_available_quantities(): void
    {
        $billId = 'inv_returnable_test';
        $itemId = 'item_returnable_1';

        DB::table('invoices')->insert([
            'id' => $billId,
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'PAID',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'invoiceNumber' => 'INV-2026-0089',
                    'customerId' => 'cust_999',
                    'customerName' => 'Pooja Roy',
                ],
            ]),
        ]);

        DB::table('invoice_items')->insert([
            'id' => $itemId,
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'returned_qty' => 1.0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode([
                'fields' => [
                    'invoiceId' => $billId,
                    'productId' => 'prod_cooker_1',
                    'productName' => 'Pressure Cooker 3L',
                    'quantity' => 4.0,
                    'returnedQty' => 1.0,
                    'unitPricePaise' => 120000,
                    'gstRateBps' => 1800,
                ],
            ]),
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->withHeader('X-Business-Id', $this->businessId)
            ->getJson("/api/v1/bills/{$billId}/returnable");

        $res->assertStatus(200)
            ->assertJsonPath('data.bill_no', 'INV-2026-0089')
            ->assertJsonPath('data.party_name', 'Pooja Roy')
            ->assertJsonPath('data.items.0.original_qty', 4)
            ->assertJsonPath('data.items.0.already_returned_qty', 1)
            ->assertJsonPath('data.items.0.remaining_returnable_qty', 3);
    }
}
