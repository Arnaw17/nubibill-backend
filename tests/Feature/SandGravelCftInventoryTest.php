<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReturnReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SandGravelCftInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $businessId = 'bus_cft_1';

    private string $outletA = 'out_yard_a';

    private string $outletB = 'out_yard_b';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = new User;
        $this->owner->forceFill([
            'id' => 'usr_cft_owner',
            'name' => 'Yard Owner',
            'email' => 'cft@nubibilling.com',
            'phone' => '9999922222',
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
                'businessName' => 'Deccan Aggregates & Sands',
            ]]),
        ]);

        DB::table('suppliers')->insert([
            'id' => 'sup_cft_1',
            'document_path' => 'suppliers/sup_cft_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['supplierId' => 'sup_cft_1', 'name' => 'Granite Crushers']]),
        ]);

        DB::table('customers')->insert([
            'id' => 'cust_cft_1',
            'document_path' => 'customers/cust_cft_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_cft_1', 'name' => 'Skyline Builders']]),
        ]);

        DB::table('customer_balances')->insert([
            'id' => 'cust_cft_1',
            'document_path' => 'customer_balances/cust_cft_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_cft_1', 'currentBalancePaise' => 0]]),
        ]);
    }

    private function createCoarseAggregateProduct(): array
    {
        $res = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'name' => '20mm Coarse Aggregate',
            'category' => 'Gravel & Aggregates',
            'subcategory' => 'Coarse Aggregate',
            'inventoryBehaviour' => 'UNIFIED_PARENT',
            'baseUnitId' => 'SYS_UNIT_CFT',
            'defaultSalesUnitId' => 'SYS_UNIT_CFT',
            'allowMultiplePurchaseOptions' => true,
            'allowMultipleSalesOptions' => true,
            'variants' => [[
                'name' => '20mm Coarse Aggregate (CFT)',
                'sku' => 'AGG-20MM-CFT',
                'baseUnitId' => 'SYS_UNIT_CFT',
                'defaultSalesUnitId' => 'SYS_UNIT_CFT',
                'sellingPrice' => 5500,
                'mrp' => 6000,
                'reorderLevel' => 300,
                'trackStock' => true,
            ]],
            'purchaseOptions' => [
                [
                    'optionName' => 'Tractor Load',
                    'optionType' => 'BOTH',
                    'conversionFactor' => 100,
                    'conversionUnitId' => 'SYS_UNIT_CFT',
                    'purchasePrice' => 400000,
                    'defaultSellingPrice' => 520000,
                    'isActive' => true,
                ],
                [
                    'optionName' => 'Truck Load',
                    'optionType' => 'BOTH',
                    'conversionFactor' => 400,
                    'conversionUnitId' => 'SYS_UNIT_CFT',
                    'purchasePrice' => 1400000,
                    'defaultSellingPrice' => 1900000,
                    'isActive' => true,
                ],
                [
                    'optionName' => 'Per CFT',
                    'optionType' => 'BOTH',
                    'conversionFactor' => 1,
                    'conversionUnitId' => 'SYS_UNIT_CFT',
                    'purchasePrice' => 3500,
                    'defaultSellingPrice' => 5500,
                    'isActive' => true,
                ],
            ],
        ])->assertStatus(201);

        return $res->json('data.product');
    }

    public function test_cft_purchases_sales_returns_adjustments_valuation_and_multi_outlet_isolation(): void
    {
        $product = $this->createCoarseAggregateProduct();
        $productId = $product['productId'];
        $variantId = $product['variants'][0]['variantId'];
        $opts = collect($product['purchaseOptions']);
        $tractorOpt = $opts->firstWhere('optionName', 'Tractor Load');
        $truckOpt = $opts->firstWhere('optionName', 'Truck Load');
        $cftOpt = $opts->firstWhere('optionName', 'Per CFT');

        // 1. Opening Stock in Yard A: 500 CFT at ₹35/CFT
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'productId' => $productId,
            'variantId' => "UNIFIED_{$productId}",
            'type' => 'OPENING_STOCK',
            'qtyBase' => 500000,
            'unitCostPaise' => 3500,
            'reason' => 'Initial Yard A CFT count',
            'referenceId' => 'OP-CFT-1',
        ])->assertStatus(201);

        // 2. Purchase 2 Trucks (800 CFT @ ₹14,000/truck) + 1 Tractor (100 CFT @ ₹4,000) -> +900 CFT = 1,400 CFT
        // Send conversionFactor > 100 (400 CFT & 100 CFT) without milli collision
        $pbRes = $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'supplier_id' => 'sup_cft_1',
            'supplier_invoice_number' => 'INV-AGG-01',
            'invoice_date' => now()->toDateString(),
            'tax_type' => 'INTRA_STATE',
            'status' => 'APPROVED',
            'items' => [
                [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'purchase_option_id' => $truckOpt['purchaseOptionId'],
                    'purchase_option_name' => 'Truck Load',
                    'product_name' => '20mm Coarse Aggregate',
                    'quantity' => 2,
                    'quantity_scale' => 'UNIT',
                    'unit_id' => 'SYS_UNIT_CFT',
                    'conversion_factor' => 400,
                    'rate' => 1400000,
                    'gst_rate_bps' => 0,
                ],
                [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'purchase_option_id' => $tractorOpt['purchaseOptionId'],
                    'purchase_option_name' => 'Tractor Load',
                    'product_name' => '20mm Coarse Aggregate',
                    'quantity' => 1,
                    'quantity_scale' => 'UNIT',
                    'unit_id' => 'SYS_UNIT_CFT',
                    'conversion_factor' => 100,
                    'rate' => 400000,
                    'gst_rate_bps' => 0,
                ],
            ],
        ])->assertStatus(201);
        $purchaseId = $pbRes->json('data.purchaseBill.purchaseBillId');
        $tractorLineId = $pbRes->json('data.purchaseBill.items.1.purchaseItemId');

        // Verify Yard A stock reads 1,400 CFT (1,400,000 milli), NOT 1 or 3
        $stockRes = $this->actingAs($this->owner)->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(1400000, $stockRes->json("data.parentStockMap.{$productId}"));
        $this->assertEquals(1400.0, $stockRes->json("data.parentStockUnitsMap.{$productId}"));

        // Yard B stays 0 (multi-outlet isolation)
        $yardBRes = $this->actingAs($this->owner)->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletB}")
            ->assertStatus(200);
        $this->assertNull($yardBRes->json("data.parentStockMap.{$productId}"));

        // 3. Sell 1 Truck (400 CFT) + 125.5 CFT -> remaining 1,400 - 525.5 = 874.5 CFT
        $invRes = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'customer_id' => 'cust_cft_1',
            'customer_name' => 'Skyline Builders',
            'lines' => [
                [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'product_name' => '20mm Coarse Aggregate',
                    'variant_name' => 'Truck Load',
                    'quantity' => 1,
                    'conversion_factor' => 400,
                    'unit_id' => 'SYS_UNIT_CFT',
                    'unit_symbol' => 'Truck',
                    'unit_price_paise' => 1900000,
                    'gst_rate_bps' => 0,
                ],
                [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'purchase_option_id' => $cftOpt['purchaseOptionId'],
                    'product_name' => '20mm Coarse Aggregate',
                    'variant_name' => 'Per CFT',
                    'quantity' => 125.5,
                    'conversion_factor' => 1,
                    'unit_id' => 'SYS_UNIT_CFT',
                    'unit_symbol' => 'CFT',
                    'unit_price_paise' => 5500,
                    'gst_rate_bps' => 0,
                ],
            ],
        ])->assertStatus(201);
        $invoiceId = $invRes->json('data.invoice.invoiceId');
        $cftLineId = $invRes->json('data.invoice.lines.1.itemId');

        // 4. Customer Sales Return (+25.5 CFT) -> 900 CFT
        $reason = DB::table('return_reasons')->first();
        $this->actingAs($this->owner)->postJson('/api/v1/returns', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'type' => 'sale_return',
            'original_bill_id' => $invoiceId,
            'party_id' => 'cust_cft_1',
            'party_name' => 'Skyline Builders',
            'reason_id' => $reason->id,
            'refund_mode' => 'adjust_to_ledger',
            'items' => [[
                'original_bill_item_id' => $cftLineId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'product_name' => '20mm Coarse Aggregate',
                'unit' => 'CFT',
                'qty_returned' => 25.5,
                'conversion_factor' => 1.0,
                'unit_price' => 55.0,
                'condition' => 'resalable',
                'restock' => true,
            ]],
        ])->assertStatus(201);

        // 5. Supplier Purchase Return (return 1 tractor load = 100 CFT) -> 800 CFT
        $this->actingAs($this->owner)->postJson('/api/v1/returns', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'type' => 'purchase_return',
            'original_bill_id' => $purchaseId,
            'party_id' => 'sup_cft_1',
            'party_name' => 'Granite Crushers',
            'reason_id' => $reason->id,
            'refund_mode' => 'adjust_to_ledger',
            'items' => [[
                'original_bill_item_id' => $tractorLineId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'product_name' => '20mm Coarse Aggregate',
                'unit' => 'Tractor',
                'qty_returned' => 1,
                'conversion_factor' => 100.0,
                'unit_price' => 4000.0,
                'condition' => 'resalable',
                'restock' => false,
            ]],
        ])->assertStatus(201);

        // 6. Manual Stock Adjustments: +200 CFT & -50 CFT -> 950 CFT (plus test idempotent duplicate ref)
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'productId' => $productId,
            'variantId' => "UNIFIED_{$productId}",
            'type' => 'ADJUST_IN',
            'qtyBase' => 200000,
            'reason' => 'Stock found in yard B stack',
            'referenceId' => 'ADJ-CFT-IN-1',
        ])->assertStatus(201);

        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'productId' => $productId,
            'variantId' => "UNIFIED_{$productId}",
            'type' => 'ADJUST_OUT',
            'qtyBase' => 50000,
            'reason' => 'Rain wash shrinkage',
            'referenceId' => 'ADJ-CFT-OUT-1',
        ])->assertStatus(201);

        // Retry with duplicate referenceId must NOT deduct twice
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'productId' => $productId,
            'variantId' => "UNIFIED_{$productId}",
            'type' => 'ADJUST_OUT',
            'qtyBase' => 50000,
            'reason' => 'Rain wash shrinkage retry',
            'referenceId' => 'ADJ-CFT-OUT-1',
        ])->assertStatus(200);

        $finalStockRes = $this->actingAs($this->owner)->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(950000, $finalStockRes->json("data.parentStockMap.{$productId}"));
        $this->assertEquals(950.0, $finalStockRes->json("data.parentStockUnitsMap.{$productId}"));

        // 7. Verify Dashboard metrics reflect healthy 950 CFT (never >= 1000 division bug!)
        $dashRes = $this->actingAs($this->owner)->getJson("/api/v1/dashboard/metrics?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(0, $dashRes->json('data.outOfStockCount'));
    }

    public function test_unscaled_1500_cft_balance_is_not_divided_by_1000_and_corrupted_balance_reconciles_from_ledger(): void
    {
        $product = $this->createCoarseAggregateProduct();
        $productId = $product['productId'];
        $canonicalVarId = "UNIFIED_{$productId}";

        // Part A: Seed a legacy unscaled balance where both column & payload say 1500 (1,500 CFT) without stockScale
        DB::table('stock_balances')->insert([
            'id' => "{$this->businessId}_{$this->outletA}_{$canonicalVarId}",
            'document_path' => "stock_balances/{$this->businessId}_{$this->outletA}_{$canonicalVarId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'product_id' => $productId,
            'variant_id' => $canonicalVarId,
            'quantity_on_hand' => 1500,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'productId' => $productId,
                'variantId' => $canonicalVarId,
                'quantityOnHand' => 1500,
                'currentStock' => 1500,
                'costPrice' => 3500,
            ]]),
        ]);

        // Reading /inventory/stock MUST return 1,500 CFT (1,500,000 milli), NOT 1.5!
        $stockRes = $this->actingAs($this->owner)->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(1500000, $stockRes->json("data.parentStockMap.{$productId}"));
        $this->assertEquals(1500.0, $stockRes->json("data.parentStockUnitsMap.{$productId}"));

        // Part B: Simulate corrupted stock_balances showing '1' (1,000 milli) while verified purchases totaled 1,500 CFT
        DB::table('stock_balances')
            ->where('id', "{$this->businessId}_{$this->outletA}_{$canonicalVarId}")
            ->update([
                'quantity_on_hand' => 1,
                'payload' => json_encode(['fields' => [
                    'productId' => $productId,
                    'variantId' => $canonicalVarId,
                    'quantityOnHand' => 1000,
                    'stockScale' => 'MILLI',
                ]]),
            ]);

        DB::table('purchase_bills')->insert([
            'id' => 'pb_hist_fix_1',
            'document_path' => 'purchase_bills/pb_hist_fix_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'status' => 'APPROVED',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['purchaseBillId' => 'pb_hist_fix_1', 'status' => 'APPROVED']]),
        ]);
        DB::table('purchase_bill_items')->insert([
            'id' => 'pbi_hist_fix_1',
            'document_path' => 'purchase_bill_items/pbi_hist_fix_1',
            'business_id' => $this->businessId,
            'purchase_bill_id' => 'pb_hist_fix_1',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'productId' => $productId,
                'variantId' => $canonicalVarId,
                'quantity' => 15,
                'conversionFactor' => 100,
                'baseQuantity' => 1500000,
                'taxableAmount' => 5250000,
                'taxAmount' => 0,
            ]]),
        ]);

        $recRes = $this->actingAs($this->owner)->postJson('/api/v1/inventory/reconcile-cft', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'apply' => true,
        ])->assertStatus(200);
        $this->assertSame(1, $recRes->json('data.reconciledCount'));
        $this->assertSame(0, $recRes->json('data.flaggedCount'));

        $fixedStock = $this->actingAs($this->owner)->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(1500000, $fixedStock->json("data.parentStockMap.{$productId}"));
        // 1,500 CFT at ₹35/CFT = ₹52,500 = 5,250,000 paise
        $this->assertSame(5250000, $fixedStock->json("data.valuationMap.{$canonicalVarId}"));
    }

    public function test_unverified_historical_load_entry_is_flagged_for_reconciliation_without_guessing(): void
    {
        $product = $this->createCoarseAggregateProduct();
        $productId = $product['productId'];
        $canonicalVarId = "UNIFIED_{$productId}";

        DB::table('stock_balances')->insert([
            'id' => "{$this->businessId}_{$this->outletA}_{$canonicalVarId}",
            'document_path' => "stock_balances/{$this->businessId}_{$this->outletA}_{$canonicalVarId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'product_id' => $productId,
            'variant_id' => $canonicalVarId,
            'quantity_on_hand' => 1,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'productId' => $productId,
                'variantId' => $canonicalVarId,
                'quantityOnHand' => 1000,
                'stockScale' => 'MILLI',
            ]]),
        ]);

        // Seed historical load purchase WITHOUT purchaseOptionId, WITHOUT conversionFactor (>1), and WITHOUT baseQuantity
        DB::table('purchase_bills')->insert([
            'id' => 'pb_unverified_1',
            'document_path' => 'purchase_bills/pb_unverified_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'status' => 'APPROVED',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['purchaseBillId' => 'pb_unverified_1', 'status' => 'APPROVED']]),
        ]);
        DB::table('purchase_bill_items')->insert([
            'id' => 'pbi_unverified_1',
            'document_path' => 'purchase_bill_items/pbi_unverified_1',
            'business_id' => $this->businessId,
            'purchase_bill_id' => 'pb_unverified_1',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'productId' => $productId,
                'quantity' => 2,
                'unitSymbol' => 'Tipper Load',
                'conversionFactor' => 1,
            ]]),
        ]);

        $recRes = $this->actingAs($this->owner)->postJson('/api/v1/inventory/reconcile-cft', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'apply' => true,
        ])->assertStatus(200);

        $this->assertSame(0, $recRes->json('data.reconciledCount'));
        $this->assertSame(1, $recRes->json('data.flaggedCount'));
        $this->assertTrue($recRes->json('data.items.0.needsReconciliation'));

        // Stock balance is NOT guessed/overwritten and carries reconciliation warning flag
        $stockRes = $this->actingAs($this->owner)->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(1000, $stockRes->json("data.parentStockMap.{$productId}"));
        $this->assertNotEmpty($stockRes->json("data.reconciliationMap.{$productId}"));
    }

    public function test_purchase_600_cft_then_sell_200_cft_deducts_to_400_cft_across_synthetic_variant_shapes(): void
    {
        $sandRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'name' => 'River Sand Fine',
            'category' => 'Sand & Aggregates',
            'subcategory' => 'River Sand',
            'inventoryBehaviour' => 'UNIFIED_PARENT',
            'baseUnitId' => 'SYS_UNIT_CFT',
            'defaultSalesUnitId' => 'SYS_UNIT_CFT',
            'allowManualUnitOverride' => false,
            'allowMultiplePurchaseOptions' => true,
            'allowMultipleSalesOptions' => true,
            'variants' => [[
                'name' => 'Tractor - 120 CFT',
                'sku' => 'SAND-RIVER-120',
                'baseUnitId' => 'SYS_UNIT_CFT',
                'defaultSalesUnitId' => 'SYS_UNIT_CFT',
                'allowManualUnitOverride' => false,
                'sellingPrice' => 600000,
                'mrp' => 620000,
                'trackStock' => true,
            ]],
            'purchaseOptions' => [[
                'optionName' => 'Loose (CFT)',
                'optionType' => 'BOTH',
                'conversionFactor' => 1,
                'conversionUnitId' => 'SYS_UNIT_CFT',
                'purchasePrice' => 4000,
                'defaultSellingPrice' => 5000,
                'isActive' => true,
            ]],
        ])->assertStatus(201);

        $sand = $sandRes->json('data.product');
        $sandProdId = $sand['productId'];
        $sandVarId = $sand['variants'][0]['variantId'];
        $sandOptId = $sand['purchaseOptions'][0]['purchaseOptionId'];

        // Purchase 600 CFT of River Sand
        $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'supplier_id' => 'sup_cft_1',
            'supplier_invoice_number' => 'INV-SAND-600',
            'invoice_date' => now()->toDateString(),
            'tax_type' => 'INTRA_STATE',
            'status' => 'APPROVED',
            'items' => [[
                'product_id' => $sandProdId,
                'variant_id' => $sandVarId,
                'purchase_option_id' => $sandOptId,
                'purchase_option_name' => 'Loose (CFT)',
                'product_name' => 'River Sand Fine',
                'quantity' => 600,
                'quantity_scale' => 'UNIT',
                'unit_id' => 'SYS_UNIT_CFT',
                'conversion_factor' => 1,
                'rate' => 4000,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(201);

        $before = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(600000, $before->json("data.parentStockMap.{$sandProdId}"));

        // 1) Sell 200 CFT using synthetic UNIFIED_<pid>__OPT__<optId> even when legacy client sends product_id == variant_id
        $inv1 = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'customer_name' => 'Site Customer A',
            'request_id' => 'REQ-SAND-OPT-200',
            'tax_mode' => 'NON_GST',
            'payment_mode' => 'CASH',
            'cash_paise' => 1000000,
            'lines' => [[
                'product_id' => "UNIFIED_{$sandProdId}__OPT__{$sandOptId}",
                'variant_id' => "UNIFIED_{$sandProdId}__OPT__{$sandOptId}",
                'product_name' => 'River Sand Fine',
                'quantity' => 200,
                'unit_id' => 'SYS_UNIT_CFT',
                'unit_symbol' => 'CFT',
                'sales_unit_conversion_factor' => 1,
                'unit_price_paise' => 5000,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(201);

        $afterOpt = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(400000, $afterOpt->json("data.parentStockMap.{$sandProdId}"));
        $this->assertEquals(400.0, $afterOpt->json("data.parentStockUnitsMap.{$sandProdId}"));

        $mov1 = DB::table('stock_movements')
            ->where('reference_id', $inv1->json('data.invoice.invoiceId'))
            ->first();
        $this->assertNotNull($mov1);
        $this->assertEquals(-200.0, (float) $mov1->qty_change);

        // 2) Purchase 600 CFT Gravel (Single-variant SEPARATE_VARIANT edge case stored under UNIFIED or child) & sell 200 CFT via _base_loose
        $gravel = $this->createCoarseAggregateProduct();
        $gravelProdId = $gravel['productId'];
        $gravelVarId = $gravel['variants'][0]['variantId'];

        $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'supplier_id' => 'sup_cft_1',
            'supplier_invoice_number' => 'INV-GRAVEL-600',
            'invoice_date' => now()->toDateString(),
            'tax_type' => 'INTRA_STATE',
            'status' => 'APPROVED',
            'items' => [[
                'product_id' => $gravelProdId,
                'variant_id' => $gravelVarId,
                'product_name' => '20mm Coarse Aggregate',
                'quantity' => 600,
                'quantity_scale' => 'UNIT',
                'unit_id' => 'SYS_UNIT_CFT',
                'conversion_factor' => 1,
                'rate' => 4000,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(201);

        $inv2 = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'customer_name' => 'Site Customer B',
            'request_id' => 'REQ-GRAVEL-LOOSE-200',
            'tax_mode' => 'NON_GST',
            'payment_mode' => 'CASH',
            'cash_paise' => 1100000,
            'lines' => [[
                'product_id' => "{$gravelVarId}_base_loose",
                'variant_id' => "{$gravelVarId}_base_loose",
                'product_name' => '20mm Coarse Aggregate',
                'quantity' => 200,
                'unit_id' => 'SYS_UNIT_CFT',
                'unit_symbol' => 'CFT',
                'sales_unit_conversion_factor' => 1,
                'unit_price_paise' => 5500,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(201);

        $afterGravel = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(400000, $afterGravel->json("data.parentStockMap.{$gravelProdId}"));
        $this->assertEquals(400.0, $afterGravel->json("data.parentStockUnitsMap.{$gravelProdId}"));

        // 3) Create and finalize a 50 CFT Quotation for Sand -> deducts 400 -> 350 CFT
        $quoteRes = $this->actingAs($this->owner)->postJson('/api/v1/quotations', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletA,
            'customer_name' => 'Quotation Customer',
            'tax_mode' => 'NON_GST',
            'lines' => [[
                'product_id' => $sandProdId,
                'variant_id' => $sandVarId,
                'product_name' => 'River Sand Fine',
                'quantity' => 50,
                'unit_id' => 'SYS_UNIT_CFT',
                'unit_symbol' => 'CFT',
                'sales_unit_conversion_factor' => 1,
                'unit_price_paise' => 5000,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(201);
        $quotationId = $quoteRes->json('data.quotation.quotationId');

        $this->actingAs($this->owner)->postJson("/api/v1/quotations/{$quotationId}/finalize", [
            'request_id' => 'REQ-QFIN-50',
            'tax_mode' => 'NON_GST',
            'payment_mode' => 'CASH',
            'cash_paise' => 250000,
        ])->assertStatus(201);

        $afterQuote = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletA}")
            ->assertStatus(200);
        $this->assertSame(350000, $afterQuote->json("data.parentStockMap.{$sandProdId}"));
    }
}
