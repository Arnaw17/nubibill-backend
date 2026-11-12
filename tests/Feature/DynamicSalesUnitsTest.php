<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReturnReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DynamicSalesUnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $businessId = 'bus_units_1';

    private string $outletId = 'out_units_1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = new User;
        $this->owner->forceFill([
            'id' => 'usr_owner_1',
            'name' => 'Yard Owner',
            'email' => 'yard@nubibilling.com',
            'phone' => '9999900001',
            'password' => Hash::make('Secret123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'business_id' => $this->businessId,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $this->owner->save();

        $this->seed(ReturnReasonSeeder::class);

        DB::table('businesses')->insert([
            'id' => $this->businessId,
            'document_path' => "businesses/{$this->businessId}",
            'business_id' => $this->businessId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'businessId' => $this->businessId,
                'ownerUid' => $this->owner->id,
                'businessName' => 'Nubi Constructions & Paints',
            ]]),
        ]);

        DB::table('suppliers')->insert([
            'id' => 'sup_1',
            'document_path' => 'suppliers/sup_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['supplierId' => 'sup_1', 'name' => 'Tata Steel Distributor']]),
        ]);

        DB::table('customers')->insert([
            'id' => 'cust_1',
            'document_path' => 'customers/cust_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_1', 'name' => 'Ramesh Builders']]),
        ]);

        DB::table('customer_balances')->insert([
            'id' => 'cust_1',
            'document_path' => 'customer_balances/cust_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_1', 'duePaise' => 0, 'advancePaise' => 0]]),
        ]);
    }

    public function test_product_masters_contains_all_required_reusable_units(): void
    {
        $res = $this->actingAs($this->owner)->getJson('/api/v1/product-masters');
        $res->assertOk();
        $units = collect($res->json('data.units'));

        foreach ([
            'SYS_UNIT_CFT',
            'SYS_UNIT_KG',
            'SYS_UNIT_GRAM',
            'SYS_UNIT_METRIC_TON',
            'SYS_UNIT_PIECE',
            'SYS_UNIT_BAG',
            'SYS_UNIT_LITRE',
            'SYS_UNIT_ML',
            'SYS_UNIT_BOX',
            'SYS_UNIT_PACKET',
            'SYS_UNIT_BUNDLE',
            'SYS_UNIT_METRE',
            'SYS_UNIT_SQFT',
            'SYS_UNIT_CUBIC_FEET',
            'SYS_UNIT_DOZEN',
        ] as $expectedId) {
            $this->assertTrue($units->contains('id', $expectedId), "Missing unit {$expectedId}");
        }
    }

    public function test_end_to_end_dynamic_units_billing_stock_purchase_returns_ledger_reports_and_sync(): void
    {
        // 1. Create Sand (CFT, fractional allowed)
        $sandRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'River Sand',
            'category' => 'Sand',
            'variants' => [[
                'name' => 'River Sand',
                'baseUnitId' => 'SYS_UNIT_CFT',
                'purchaseUnitId' => 'SYS_UNIT_CFT',
                'purchaseConversionFactor' => 1.0,
                'defaultSalesUnitId' => 'SYS_UNIT_CFT',
                'salesConversionFactor' => 1.0,
                'sellingPrice' => 6000,
                'purchaseRate' => 4500,
                'allowFractionalQuantity' => true,
                'unitDisplayName' => 'Cubic Feet (CFT)',
                'unitSymbol' => 'CFT',
            ]],
        ])->assertStatus(201);
        $sandProdId = $sandRes->json('data.product.productId');
        $sandVarId = $sandRes->json('data.product.variants.0.variantId');

        // 2. Create Bricks (Piece PCS, whole number only)
        $brickRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Red Clay Bricks',
            'category' => 'Bricks',
            'variants' => [[
                'name' => 'Red Clay Bricks',
                'baseUnitId' => 'SYS_UNIT_PIECE',
                'purchaseUnitId' => 'SYS_UNIT_PIECE',
                'purchaseConversionFactor' => 1.0,
                'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
                'salesConversionFactor' => 1.0,
                'sellingPrice' => 800,
                'purchaseRate' => 600,
                'allowFractionalQuantity' => false,
                'allowManualUnitOverride' => false,
                'unitSymbol' => 'PCS',
            ]],
        ])->assertStatus(201);
        $brickProdId = $brickRes->json('data.product.productId');
        $brickVarId = $brickRes->json('data.product.variants.0.variantId');

        // 3. Create Cement (Bag)
        $cementRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'OPC 53 Cement',
            'category' => 'Cement',
            'variants' => [[
                'name' => '50 KG Bag',
                'baseUnitId' => 'SYS_UNIT_BAG',
                'purchaseUnitId' => 'SYS_UNIT_BAG',
                'purchaseConversionFactor' => 1.0,
                'defaultSalesUnitId' => 'SYS_UNIT_BAG',
                'salesConversionFactor' => 1.0,
                'sellingPrice' => 38000,
                'purchaseRate' => 33000,
                'allowFractionalQuantity' => false,
                'allowManualUnitOverride' => false,
                'unitSymbol' => 'Bag',
            ]],
        ])->assertStatus(201);
        $cementProdId = $cementRes->json('data.product.productId');
        $cementVarId = $cementRes->json('data.product.variants.0.variantId');

        // 4. Create Steel Rod (Purchased in Metric Tonne = 1000 KG, Sold in KG)
        $steelRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'TMT Steel Rod 12mm',
            'category' => 'Steel',
            'variants' => [[
                'name' => '12mm TMT Rod',
                'baseUnitId' => 'SYS_UNIT_KG',
                'purchaseUnitId' => 'SYS_UNIT_METRIC_TON',
                'purchaseConversionFactor' => 1000.0,
                'defaultSalesUnitId' => 'SYS_UNIT_KG',
                'salesConversionFactor' => 1.0,
                'sellingPrice' => 6500,
                'purchaseRate' => 5500000,
                'allowFractionalQuantity' => true,
                'allowManualUnitOverride' => true,
                'unitSymbol' => 'KG',
            ]],
        ])->assertStatus(201);
        $steelProdId = $steelRes->json('data.product.productId');
        $steelVarId = $steelRes->json('data.product.variants.0.variantId');

        // 5. Create Paint (Litre)
        $paintRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'WeatherShield Paint',
            'category' => 'Paint',
            'variants' => [[
                'name' => 'White 1L',
                'baseUnitId' => 'SYS_UNIT_LITRE',
                'purchaseUnitId' => 'SYS_UNIT_LITRE',
                'purchaseConversionFactor' => 1.0,
                'defaultSalesUnitId' => 'SYS_UNIT_LITRE',
                'salesConversionFactor' => 1.0,
                'sellingPrice' => 45000,
                'purchaseRate' => 35000,
                'allowFractionalQuantity' => false,
                'unitSymbol' => 'L',
            ]],
        ])->assertStatus(201);
        $paintProdId = $paintRes->json('data.product.productId');
        $paintVarId = $paintRes->json('data.product.variants.0.variantId');

        // Purchase inward:
        // - Sand: 200 CFT
        // - Bricks: 1500 PCS
        // - Cement: 50 Bags
        // - Steel: 2 Metric Tonne -> MUST add 2000 KG (2,000,000 milli-KG)
        // - Paint: 20 Litres
        $purRes = $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_1',
            'status' => 'APPROVED',
            'items' => [
                ['product_id' => $sandProdId, 'variant_id' => $sandVarId, 'product_name' => 'River Sand', 'quantity' => 200, 'quantity_scale' => 'UNIT', 'unit_id' => 'SYS_UNIT_CFT', 'rate' => 4500, 'gst_rate_bps' => 500],
                ['product_id' => $brickProdId, 'variant_id' => $brickVarId, 'product_name' => 'Red Clay Bricks', 'quantity' => 1500, 'quantity_scale' => 'UNIT', 'unit_id' => 'SYS_UNIT_PIECE', 'rate' => 600, 'gst_rate_bps' => 1200],
                ['product_id' => $cementProdId, 'variant_id' => $cementVarId, 'product_name' => 'OPC 53 Cement', 'quantity' => 50, 'quantity_scale' => 'UNIT', 'unit_id' => 'SYS_UNIT_BAG', 'rate' => 33000, 'gst_rate_bps' => 2800],
                ['product_id' => $steelProdId, 'variant_id' => $steelVarId, 'product_name' => 'TMT Steel Rod 12mm', 'quantity' => 2, 'quantity_scale' => 'UNIT', 'unit_id' => 'SYS_UNIT_METRIC_TON', 'rate' => 5500000, 'gst_rate_bps' => 1800],
                ['product_id' => $paintProdId, 'variant_id' => $paintVarId, 'product_name' => 'WeatherShield Paint', 'quantity' => 20, 'quantity_scale' => 'UNIT', 'unit_id' => 'SYS_UNIT_LITRE', 'rate' => 35000, 'gst_rate_bps' => 1800],
            ],
        ])->assertStatus(201);
        $purchaseBillId = $purRes->json('data.purchaseBill.purchaseBillId');

        // Verify inventory stock map: Steel = 2000 KG (2,000,000 milli-KG), Bricks = 1500 PCS (1,500,000)
        $stockRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}")
            ->assertOk();
        $stockMap = $stockRes->json('data.stockMap');
        $this->assertSame(200000, $stockMap[$sandVarId]);
        $this->assertSame(1500000, $stockMap[$brickVarId]);
        $this->assertSame(50000, $stockMap[$cementVarId]);
        $this->assertSame(2000000, $stockMap[$steelVarId]);
        $this->assertSame(20000, $stockMap[$paintVarId]);

        // Reject fractional bricks
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'lines' => [[
                'product_id' => $brickProdId,
                'variant_id' => $brickVarId,
                'product_name' => 'Red Clay Bricks',
                'quantity' => 12.5,
                'unit_price_paise' => 800,
                'unit_id' => 'SYS_UNIT_PIECE',
            ]],
        ])->assertStatus(422);

        // Reject unauthorized unit override on Cement
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'lines' => [[
                'product_id' => $cementProdId,
                'variant_id' => $cementVarId,
                'product_name' => 'OPC 53 Cement',
                'quantity' => 5,
                'unit_price_paise' => 38000,
                'unit_id' => 'SYS_UNIT_CFT',
            ]],
        ])->assertStatus(422);

        // Create POS Invoice with:
        // - 100 CFT Sand (@ ₹60 = ₹6,000)
        // - 200 PCS Bricks (@ ₹8 = ₹1,600)
        // - 20 Bag Cement (@ ₹380 = ₹7,600)
        // - 500 KG Steel (@ ₹65 = ₹32,500)
        // - 5 L Paint (@ ₹450 = ₹2,250)
        $invRes = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'request_id' => 'POS-REQ-001',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_1',
            'customer_name' => 'Ramesh Builders',
            'due_paise' => 4995000,
            'lines' => [
                ['product_id' => $sandProdId, 'variant_id' => $sandVarId, 'product_name' => 'River Sand', 'quantity' => 100, 'unit_price_paise' => 6000, 'unit_id' => 'SYS_UNIT_CFT'],
                ['product_id' => $brickProdId, 'variant_id' => $brickVarId, 'product_name' => 'Red Clay Bricks', 'quantity' => 200, 'unit_price_paise' => 800, 'unit_id' => 'SYS_UNIT_PIECE'],
                ['product_id' => $cementProdId, 'variant_id' => $cementVarId, 'product_name' => 'OPC 53 Cement', 'quantity' => 20, 'unit_price_paise' => 38000, 'unit_id' => 'SYS_UNIT_BAG'],
                ['product_id' => $steelProdId, 'variant_id' => $steelVarId, 'product_name' => 'TMT Steel Rod 12mm', 'quantity' => 500, 'unit_price_paise' => 6500, 'unit_id' => 'SYS_UNIT_KG'],
                ['product_id' => $paintProdId, 'variant_id' => $paintVarId, 'product_name' => 'WeatherShield Paint', 'quantity' => 5, 'unit_price_paise' => 45000, 'unit_id' => 'SYS_UNIT_LITRE'],
            ],
        ])->assertStatus(201);
        $invoiceId = $invRes->json('data.invoiceId');
        $invLines = $invRes->json('data.invoice.items');
        $this->assertSame('CFT', $invLines[0]['unit']);
        $this->assertSame('PCS', $invLines[1]['unit']);
        $this->assertSame('Bag', $invLines[2]['unit']);
        $this->assertSame('KG', $invLines[3]['unit']);
        $this->assertSame('L', $invLines[4]['unit']);
        $this->assertSame(4995000, $invRes->json('data.invoice.totalAmount'));

        // Idempotency retry on same request_id should NOT deduct stock twice
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'request_id' => 'POS-REQ-001',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'lines' => [
                ['product_id' => $sandProdId, 'variant_id' => $sandVarId, 'product_name' => 'River Sand', 'quantity' => 100, 'unit_price_paise' => 6000],
            ],
        ])->assertOk()->assertJsonPath('data.existing', true);

        // Fractional CFT sale (12.5 CFT) + partial brick sale (350 PCS)
        $fracRes = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'lines' => [
                ['product_id' => $sandProdId, 'variant_id' => $sandVarId, 'product_name' => 'River Sand', 'quantity' => 12.5, 'unit_price_paise' => 6000],
                ['product_id' => $brickProdId, 'variant_id' => $brickVarId, 'product_name' => 'Red Clay Bricks', 'quantity' => 350, 'unit_price_paise' => 800],
            ],
        ])->assertStatus(201);
        $this->assertEquals(75000, $fracRes->json('data.invoice.items.0.lineTotalPaise'));

        // Verify remaining balances:
        // Sand: 200 - 100 - 12.5 = 87.5 CFT (87,500)
        // Bricks: 1500 - 200 - 350 = 950 PCS (950,000)
        // Cement: 50 - 20 = 30 Bag (30,000)
        // Steel: 2000 - 500 = 1500 KG (1,500,000)
        // Paint: 20 - 5 = 15 L (15,000)
        $afterStock = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}")
            ->json('data.stockMap');
        $this->assertSame(87500, $afterStock[$sandVarId]);
        $this->assertSame(950000, $afterStock[$brickVarId]);
        $this->assertSame(30000, $afterStock[$cementVarId]);
        $this->assertSame(1500000, $afterStock[$steelVarId]);
        $this->assertSame(15000, $afterStock[$paintVarId]);

        // Sales Return: return 2.5 CFT Sand -> Sand becomes 90 CFT (90,000)
        $reason = DB::table('return_reasons')->first();
        $sandItemId = $invLines[0]['itemId'];
        $this->actingAs($this->owner)->postJson('/api/v1/returns', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'type' => 'sale_return',
            'original_bill_id' => $invoiceId,
            'party_id' => 'cust_1',
            'party_name' => 'Ramesh Builders',
            'reason_id' => $reason->id,
            'refund_mode' => 'adjust_to_ledger',
            'items' => [[
                'original_bill_item_id' => $sandItemId,
                'product_id' => $sandProdId,
                'variant_id' => $sandVarId,
                'product_name' => 'River Sand',
                'unit' => 'CFT',
                'qty_returned' => 2.5,
                'unit_price' => 60.0,
                'condition' => 'resalable',
                'restock' => true,
            ]],
        ])->assertStatus(201);

        // Purchase Return: return 0.5 Metric Tonne (= 500 KG) Steel -> Steel becomes 1000 KG (1,000,000)
        $pbSteelItem = DB::table('purchase_bill_items')
            ->where('business_id', $this->businessId)
            ->where('payload->fields->productId', $steelProdId)
            ->first();
        $this->actingAs($this->owner)->postJson('/api/v1/returns', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'type' => 'purchase_return',
            'original_bill_id' => $purchaseBillId,
            'party_id' => 'sup_1',
            'party_name' => 'Tata Steel Distributor',
            'reason_id' => $reason->id,
            'refund_mode' => 'adjust_to_ledger',
            'items' => [[
                'original_bill_item_id' => $pbSteelItem->id,
                'product_id' => $steelProdId,
                'variant_id' => $steelVarId,
                'product_name' => 'TMT Steel Rod 12mm',
                'unit' => 'MT',
                'conversion_factor' => 1000.0,
                'qty_returned' => 0.5,
                'unit_price' => 55000.0,
                'condition' => 'resalable',
                'restock' => false,
            ]],
        ])->assertStatus(201);

        $retStock = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}")
            ->json('data.stockMap');
        $this->assertSame(90000, $retStock[$sandVarId]);
        $this->assertSame(1000000, $retStock[$steelVarId]);

        // Verify Customer Ledger contains item quantities & units
        $ledgerRes = $this->actingAs($this->owner)->getJson('/api/v1/customers/cust_1/ledger')->assertOk();
        $firstDesc = $ledgerRes->json('data.0.description');
        $this->assertStringContainsString('100 CFT', $firstDesc);
        $this->assertStringContainsString('200 PCS', $firstDesc);
        $this->assertStringContainsString('500 KG', $firstDesc);

        // Verify Offline Sync /sync/commit idempotency with decimal qty_change
        $syncPayload = [
            'writes' => [
                [
                    'update' => [
                        'name' => 'projects/nubibilling-local/databases/(default)/documents/stock_movements/OFFLINE_STM_1',
                        'fields' => [
                            'movementId' => ['stringValue' => 'OFFLINE_STM_1'],
                            'businessId' => ['stringValue' => $this->businessId],
                            'outletId' => ['stringValue' => $this->outletId],
                            'productId' => ['stringValue' => $sandProdId],
                            'variantId' => ['stringValue' => $sandVarId],
                            'type' => ['stringValue' => 'SALE'],
                            'stockScale' => ['stringValue' => 'MILLI'],
                            'changedQuantity' => ['integerValue' => '-500'],
                            'qtyChangeUnits' => ['doubleValue' => -0.5],
                            'unit' => ['stringValue' => 'CFT'],
                            'balanceAfter' => ['integerValue' => '89500'],
                        ],
                    ],
                ],
            ],
        ];
        $this->actingAs($this->owner)->postJson('/api/v1/sync/commit', $syncPayload)->assertOk();
        $this->actingAs($this->owner)->postJson('/api/v1/sync/commit', $syncPayload)->assertOk();
        $this->assertSame(1, DB::table('stock_movements')->where('id', 'OFFLINE_STM_1')->count());
        $this->assertEquals(-0.5, (float) DB::table('stock_movements')->where('id', 'OFFLINE_STM_1')->value('qty_change'));
    }
}
