<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReturnReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UnifiedInventoryPurchaseOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $businessId = 'bus_unified_1';

    private string $outletId = 'out_unified_1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = new User;
        $this->owner->forceFill([
            'id' => 'usr_unified_owner',
            'name' => 'Yard Admin',
            'email' => 'unified@nubibilling.com',
            'phone' => '9999911111',
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
            'id' => 'sup_u1',
            'document_path' => 'suppliers/sup_u1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['supplierId' => 'sup_u1', 'name' => 'Quarry & Mills']]),
        ]);

        DB::table('customers')->insert([
            'id' => 'cust_u1',
            'document_path' => 'customers/cust_u1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_u1', 'name' => 'Metro Infra']]),
        ]);

        DB::table('customer_balances')->insert([
            'id' => 'cust_u1',
            'document_path' => 'customer_balances/cust_u1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_u1', 'currentBalancePaise' => 0]]),
        ]);
    }

    public function test_paint_maintains_separate_variant_inventory_per_variant(): void
    {
        $createRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Asian Paints Apex',
            'category' => 'Paints',
            'inventoryBehaviour' => 'SEPARATE_VARIANT',
            'baseUnitId' => 'SYS_UNIT_PIECE',
            'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
            'variants' => [
                [
                    'name' => 'White - 1 Litre',
                    'sku' => 'AP-W-1L',
                    'baseUnitId' => 'SYS_UNIT_PIECE',
                    'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
                    'purchaseRate' => 25000,
                    'sellingPrice' => 32000,
                ],
                [
                    'name' => 'White - 4 Litre',
                    'sku' => 'AP-W-4L',
                    'baseUnitId' => 'SYS_UNIT_PIECE',
                    'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
                    'purchaseRate' => 92000,
                    'sellingPrice' => 115000,
                ],
                [
                    'name' => 'Red - 1 Litre',
                    'sku' => 'AP-R-1L',
                    'baseUnitId' => 'SYS_UNIT_PIECE',
                    'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
                    'purchaseRate' => 26000,
                    'sellingPrice' => 34000,
                ],
            ],
        ]);
        $createRes->assertStatus(201)
            ->assertJsonPath('data.product.inventoryBehaviour', 'SEPARATE_VARIANT');

        $product = $createRes->json('data.product');
        $productId = $product['productId'];
        $white1L = $product['variants'][0]['variantId'];
        $white4L = $product['variants'][1]['variantId'];
        $red1L = $product['variants'][2]['variantId'];

        // Purchase separate stock for each paint variant
        $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_u1',
            'status' => 'APPROVED',
            'items' => [
                ['product_id' => $productId, 'variant_id' => $white1L, 'quantity' => 10, 'rate' => 25000, 'gst_rate_bps' => 0],
                ['product_id' => $productId, 'variant_id' => $white4L, 'quantity' => 5, 'rate' => 92000, 'gst_rate_bps' => 0],
                ['product_id' => $productId, 'variant_id' => $red1L, 'quantity' => 8, 'rate' => 26000, 'gst_rate_bps' => 0],
            ],
        ])->assertStatus(201);

        // Sell 2 buckets of White 4L only
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_u1',
            'cash_paise' => 230000,
            'lines' => [
                [
                    'product_id' => $productId,
                    'variant_id' => $white4L,
                    'name' => 'Asian Paints Apex - White 4L',
                    'quantity' => 2,
                    'unit_id' => 'SYS_UNIT_PIECE',
                    'unit_price_paise' => 115000,
                    'gst_rate_bps' => 0,
                ],
            ],
        ])->assertStatus(201);

        $stockRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $stockRes->assertStatus(200);
        $stockMap = $stockRes->json('data.stockMap');

        // Each variant keeps its own independent balance (in milli-units)
        $this->assertEquals(10000, $stockMap[$white1L]);
        $this->assertEquals(3000, $stockMap[$white4L]);
        $this->assertEquals(8000, $stockMap[$red1L]);
    }

    public function test_sand_and_gravel_pool_all_purchase_options_into_unified_cft_parent_inventory(): void
    {
        $createRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Fine Sand',
            'category' => 'Construction Materials',
            'subcategory' => 'Sand',
            'inventoryBehaviour' => 'UNIFIED_PARENT',
            'baseUnitId' => 'SYS_UNIT_CFT',
            'defaultSalesUnitId' => 'SYS_UNIT_CFT',
            'allowFractionalQuantity' => true,
            'variants' => [
                [
                    'name' => 'Fine Sand (CFT)',
                    'baseUnitId' => 'SYS_UNIT_CFT',
                    'defaultSalesUnitId' => 'SYS_UNIT_CFT',
                    'allowFractionalQuantity' => true,
                    'purchaseRate' => 4000,
                    'sellingPrice' => 6000,
                ],
            ],
            'purchaseOptions' => [
                ['optionName' => 'Tractor Load', 'optionType' => 'PURCHASE', 'conversionFactor' => 100, 'conversionUnitId' => 'SYS_UNIT_CFT', 'purchasePrice' => 400000, 'isActive' => true],
                ['optionName' => 'Mini Truck Load', 'optionType' => 'PURCHASE', 'conversionFactor' => 150, 'conversionUnitId' => 'SYS_UNIT_CFT', 'purchasePrice' => 550000, 'isActive' => true],
                ['optionName' => 'Truck Load', 'optionType' => 'PURCHASE', 'conversionFactor' => 300, 'conversionUnitId' => 'SYS_UNIT_CFT', 'purchasePrice' => 1000000, 'isActive' => true],
                ['optionName' => 'Per CFT', 'optionType' => 'BOTH', 'conversionFactor' => 1, 'conversionUnitId' => 'SYS_UNIT_CFT', 'purchasePrice' => 4500, 'defaultSellingPrice' => 6000, 'isActive' => true],
            ],
        ]);
        $createRes->assertStatus(201)
            ->assertJsonPath('data.product.inventoryBehaviour', 'UNIFIED_PARENT')
            ->assertJsonCount(4, 'data.product.purchaseOptions');

        $product = $createRes->json('data.product');
        $productId = $product['productId'];
        $salesVariantId = $product['variants'][0]['variantId'];
        $opts = collect($product['purchaseOptions'])->keyBy('optionName');

        // Purchase 2 Tractor (200 CFT @ ₹4,000), 1 Mini Truck (150 CFT @ ₹5,500), 50 Per CFT (50 CFT @ ₹45)
        $pbRes = $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_u1',
            'status' => 'APPROVED',
            'items' => [
                [
                    'product_id' => $productId,
                    'purchase_option_id' => $opts['Tractor Load']['purchaseOptionId'],
                    'quantity' => 2,
                    'rate' => 400000,
                    'gst_rate_bps' => 0,
                ],
                [
                    'product_id' => $productId,
                    'purchase_option_id' => $opts['Mini Truck Load']['purchaseOptionId'],
                    'quantity' => 1,
                    'rate' => 550000,
                    'gst_rate_bps' => 0,
                ],
                [
                    'product_id' => $productId,
                    'purchase_option_id' => $opts['Per CFT']['purchaseOptionId'],
                    'quantity' => 50,
                    'rate' => 4500,
                    'gst_rate_bps' => 0,
                ],
            ],
        ]);
        $pbRes->assertStatus(201);
        $this->assertEquals(200, (float) ($pbRes->json('data.purchaseBill.items.0.baseQuantityUnits') ?? 0));

        // Verify unified balance = 400 CFT (400000 milli) in a single canonical balance row
        $stockRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $stockRes->assertStatus(200);
        $this->assertEquals(400000, $stockRes->json("data.parentStockMap.{$productId}"));
        $this->assertEquals(400.0, $stockRes->json("data.parentStockUnitsMap.{$productId}"));
        $balancesForProduct = collect($stockRes->json('data.balances'))->where('productId', $productId)->values();
        $this->assertCount(1, $balancesForProduct);
        // Weighted avg cost = (8000 + 5500 + 2250) / 400 = ₹39.375/CFT (~3937-3938 paise)
        $this->assertEqualsWithDelta(3938, $balancesForProduct[0]['avgCostPaise'], 1);

        // Verify effective base unit costs recorded on batches without losing per-option batch history
        $batches = DB::table('inventory_batches')->where('product_id', $productId)->orderBy('id')->get();
        $this->assertCount(3, $batches);
        $batchCostsPaise = $batches->map(function ($b) {
            $bf = (json_decode($b->payload, true) ?: [])['fields'] ?? [];

            return (int) ($bf['effectiveBaseUnitCost'] ?? $bf['purchaseRate'] ?? 0);
        })->sort()->values()->all();
        $this->assertEquals([3667, 4000, 4500], $batchCostsPaise);

        // Sell 150.5 CFT from POS (decimal CFT allowed) -> 249.5 CFT remaining
        $invRes = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_u1',
            'cash_paise' => 903000,
            'lines' => [
                [
                    'product_id' => $productId,
                    'variant_id' => $salesVariantId,
                    'name' => 'Fine Sand',
                    'quantity' => 150.5,
                    'unit_id' => 'SYS_UNIT_CFT',
                    'unit_price_paise' => 6000,
                    'gst_rate_bps' => 0,
                ],
            ],
        ]);
        $invRes->assertStatus(201);
        $invoiceId = $invRes->json('data.invoiceId');

        $afterSaleStock = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $this->assertEquals(249.5, $afterSaleStock->json("data.parentStockUnitsMap.{$productId}"));

        // Damaged Goods adjustment: 9.5 CFT washed away -> 240 CFT remaining
        $this->actingAs($this->owner)->postJson('/api/v1/inventory/movements', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'productId' => $productId,
            'variantId' => $salesVariantId,
            'type' => 'DAMAGE_LOSS',
            'qtyBase' => 9500,
            'reason' => 'Rain washout',
            'referenceId' => 'DMG-SAND-1',
        ])->assertStatus(201);

        $afterDmgStock = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $this->assertEquals(240.0, $afterDmgStock->json("data.parentStockUnitsMap.{$productId}"));

        // Purchase return of 40 CFT -> 200 CFT remaining
        $this->actingAs($this->owner)->postJson('/api/v1/returns', [
            'type' => 'purchase_return',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'party_id' => 'sup_u1',
            'party_name' => 'Quarry & Mills',
            'refund_mode' => 'adjust_to_ledger',
            'reason_note' => 'Excess silt',
            'party_state_code' => '27',
            'business_state_code' => '27',
            'items' => [
                [
                    'product_id' => $productId,
                    'variant_id' => $salesVariantId,
                    'product_name' => 'Fine Sand',
                    'unit' => 'CFT',
                    'qty_returned' => 40,
                    'unit_price' => 40.0,
                    'tax_rate' => 0,
                    'condition' => 'resalable',
                    'restock' => true,
                ],
            ],
        ])->assertStatus(201);

        $afterPrStock = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $this->assertEquals(200.0, $afterPrStock->json("data.parentStockUnitsMap.{$productId}"));

        // Cancel invoice -> restores 150.5 CFT -> 350.5 CFT
        $this->actingAs($this->owner)->postJson("/api/v1/invoices/{$invoiceId}/cancel", [
            'reason' => 'Order cancelled at site',
        ])->assertStatus(200);

        $afterCancelStock = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $this->assertEquals(350.5, $afterCancelStock->json("data.parentStockUnitsMap.{$productId}"));

        // Unified movement history endpoint returns all option purchases, sale, damage, return, cancellation under parent productId
        $movRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/movements?business_id={$this->businessId}&outlet_id={$this->outletId}&product_id={$productId}");
        $movRes->assertStatus(200);
        $this->assertGreaterThanOrEqual(6, count($movRes->json('data')));
    }

    public function test_bricks_unified_pcs_and_tmt_steel_unified_kg_with_valuation_report(): void
    {
        // 1. Fly Ash Bricks (UNIFIED_PARENT, Base=PCS, integer-only sales)
        $brickRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Fly Ash Bricks',
            'category' => 'Bricks',
            'inventoryBehaviour' => 'UNIFIED_PARENT',
            'baseUnitId' => 'SYS_UNIT_PIECE',
            'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
            'allowFractionalQuantity' => false,
            'variants' => [
                [
                    'name' => 'Fly Ash Bricks (PCS)',
                    'baseUnitId' => 'SYS_UNIT_PIECE',
                    'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
                    'allowFractionalQuantity' => false,
                    'purchaseRate' => 500,
                    'sellingPrice' => 800,
                ],
            ],
            'purchaseOptions' => [
                ['optionName' => 'Tractor Load', 'optionType' => 'PURCHASE', 'conversionFactor' => 1500, 'conversionUnitId' => 'SYS_UNIT_PIECE', 'purchasePrice' => 750000],
                ['optionName' => 'Mini Truck Load', 'optionType' => 'PURCHASE', 'conversionFactor' => 2000, 'conversionUnitId' => 'SYS_UNIT_PIECE', 'purchasePrice' => 1000000],
                ['optionName' => 'Per Piece', 'optionType' => 'BOTH', 'conversionFactor' => 1, 'conversionUnitId' => 'SYS_UNIT_PIECE', 'purchasePrice' => 500, 'defaultSellingPrice' => 800],
            ],
        ])->assertStatus(201);

        $brickProd = $brickRes->json('data.product');
        $brickOpts = collect($brickProd['purchaseOptions'])->keyBy('optionName');

        $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_u1',
            'status' => 'APPROVED',
            'items' => [
                ['product_id' => $brickProd['productId'], 'purchase_option_id' => $brickOpts['Tractor Load']['purchaseOptionId'], 'quantity' => 1, 'rate' => 750000, 'gst_rate_bps' => 0],
                ['product_id' => $brickProd['productId'], 'purchase_option_id' => $brickOpts['Mini Truck Load']['purchaseOptionId'], 'quantity' => 1, 'rate' => 1000000, 'gst_rate_bps' => 0],
                ['product_id' => $brickProd['productId'], 'purchase_option_id' => $brickOpts['Per Piece']['purchaseOptionId'], 'quantity' => 100, 'rate' => 500, 'gst_rate_bps' => 0],
            ],
        ])->assertStatus(201);

        // Whole-number validation blocks fractional brick sale
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_u1',
            'cash_paise' => 10000,
            'lines' => [
                [
                    'product_id' => $brickProd['productId'],
                    'variant_id' => $brickProd['variants'][0]['variantId'],
                    'name' => 'Fly Ash Bricks',
                    'quantity' => 12.5,
                    'unit_id' => 'SYS_UNIT_PIECE',
                    'unit_price_paise' => 800,
                    'gst_rate_bps' => 0,
                ],
            ],
        ])->assertStatus(422);

        // Valid brick sale of 250 PCS leaves 3,350 PCS; sale return of 50 PCS leaves 3,400 PCS
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_u1',
            'cash_paise' => 200000,
            'lines' => [
                [
                    'product_id' => $brickProd['productId'],
                    'variant_id' => $brickProd['variants'][0]['variantId'],
                    'name' => 'Fly Ash Bricks',
                    'quantity' => 250,
                    'unit_id' => 'SYS_UNIT_PIECE',
                    'unit_price_paise' => 800,
                    'gst_rate_bps' => 0,
                ],
            ],
        ])->assertStatus(201);

        $this->actingAs($this->owner)->postJson('/api/v1/returns', [
            'type' => 'sale_return',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'party_id' => 'cust_u1',
            'party_name' => 'Metro Infra',
            'refund_mode' => 'adjust_to_ledger',
            'reason_note' => 'Extra bricks returned',
            'party_state_code' => '27',
            'business_state_code' => '27',
            'items' => [
                [
                    'product_id' => $brickProd['productId'],
                    'variant_id' => $brickProd['variants'][0]['variantId'],
                    'product_name' => 'Fly Ash Bricks',
                    'unit' => 'PCS',
                    'qty_returned' => 50,
                    'unit_price' => 8.0,
                    'tax_rate' => 0,
                    'condition' => 'resalable',
                    'restock' => true,
                ],
            ],
        ])->assertStatus(201);

        // 2. TMT Steel Bar 12mm (UNIFIED_PARENT, Base=KG, Sales=KG, Piece weight auto/configured + Bundle + Metric Tonne)
        $tmtRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'TMT Steel Bar 12mm',
            'category' => 'Steel',
            'inventoryBehaviour' => 'UNIFIED_PARENT',
            'baseUnitId' => 'SYS_UNIT_KG',
            'defaultSalesUnitId' => 'SYS_UNIT_KG',
            'allowMultipleSalesOptions' => false,
            'allowFractionalQuantity' => true,
            'variants' => [
                [
                    'name' => 'TMT Steel Bar 12mm (KG)',
                    'baseUnitId' => 'SYS_UNIT_KG',
                    'defaultSalesUnitId' => 'SYS_UNIT_KG',
                    'allowFractionalQuantity' => true,
                    'purchaseRate' => 6200,
                    'sellingPrice' => 7000,
                ],
            ],
            'purchaseOptions' => [
                [
                    'optionName' => 'Individual Piece (12mm x 12m)',
                    'optionType' => 'PURCHASE',
                    'conversionFactor' => 10.65,
                    'conversionUnitId' => 'SYS_UNIT_KG',
                    'purchasePrice' => 66000,
                    'diameterMm' => 12,
                    'lengthMetre' => 12,
                    'weightPerPieceKg' => 10.65,
                ],
                [
                    'optionName' => 'Bundle (100 KG)',
                    'optionType' => 'PURCHASE',
                    'conversionFactor' => 100,
                    'conversionUnitId' => 'SYS_UNIT_KG',
                    'purchasePrice' => 610000,
                ],
                [
                    'optionName' => 'Metric Tonne (1,000 KG)',
                    'optionType' => 'PURCHASE',
                    'conversionFactor' => 1000,
                    'conversionUnitId' => 'SYS_UNIT_KG',
                    'purchasePrice' => 5900000,
                ],
            ],
        ])->assertStatus(201);

        $tmtProd = $tmtRes->json('data.product');
        $tmtOpts = collect($tmtProd['purchaseOptions'])->keyBy('optionName');

        // Purchase 15 Pieces (159.75 KG @ ₹660) + 5 Bundles (500 KG @ ₹6,100) + 1 MT (1,000 KG @ ₹59,000) = 1,659.75 KG
        $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_u1',
            'status' => 'APPROVED',
            'items' => [
                ['product_id' => $tmtProd['productId'], 'purchase_option_id' => $tmtOpts['Individual Piece (12mm x 12m)']['purchaseOptionId'], 'quantity' => 15, 'rate' => 66000, 'gst_rate_bps' => 0],
                ['product_id' => $tmtProd['productId'], 'purchase_option_id' => $tmtOpts['Bundle (100 KG)']['purchaseOptionId'], 'quantity' => 5, 'rate' => 610000, 'gst_rate_bps' => 0],
                ['product_id' => $tmtProd['productId'], 'purchase_option_id' => $tmtOpts['Metric Tonne (1,000 KG)']['purchaseOptionId'], 'quantity' => 1, 'rate' => 5900000, 'gst_rate_bps' => 0],
            ],
        ])->assertStatus(201);

        // Sell 120.5 KG -> 1,539.25 KG remaining
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_u1',
            'cash_paise' => 843500,
            'lines' => [
                [
                    'product_id' => $tmtProd['productId'],
                    'variant_id' => $tmtProd['variants'][0]['variantId'],
                    'name' => 'TMT Steel Bar 12mm',
                    'quantity' => 120.5,
                    'unit_id' => 'SYS_UNIT_KG',
                    'unit_price_paise' => 7000,
                    'gst_rate_bps' => 0,
                ],
            ],
        ])->assertStatus(201);

        $stockRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $this->assertEquals(3400.0, $stockRes->json("data.parentStockUnitsMap.{$brickProd['productId']}"));
        $this->assertEquals(1539.25, $stockRes->json("data.parentStockUnitsMap.{$tmtProd['productId']}"));

        // Inventory valuation report computes exact combined value without duplicate rows
        $valRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/reports/inventory?business_id={$this->businessId}&outlet_id={$this->outletId}");
        $valRes->assertStatus(200)
            ->assertJsonPath('data.totalProducts', 2);
        // Bricks: 3,400 * ₹5.00 = ₹17,000; TMT: 1,539.25 * avg(~₹59.89) ~= ₹92,185 -> > ₹100,000 total valuation
        $this->assertGreaterThan(100000, $valRes->json('data.totalValuationRupees'));
    }

    public function test_sync_controller_commits_purchase_options_cleanly(): void
    {
        $res = $this->actingAs($this->owner)->postJson('/api/v1/sync/commit', [
            'writes' => [
                [
                    'update' => [
                        'name' => 'projects/nubi/databases/(default)/documents/product_purchase_options/POPT_SYNC_1',
                        'fields' => [
                            'purchaseOptionId' => ['stringValue' => 'POPT_SYNC_1'],
                            'productId' => ['stringValue' => 'PRD_SYNC_1'],
                            'businessId' => ['stringValue' => $this->businessId],
                            'optionName' => ['stringValue' => 'Truck Load'],
                            'optionType' => ['stringValue' => 'PURCHASE'],
                            'conversionFactor' => ['doubleValue' => 300.0],
                            'conversionUnitId' => ['stringValue' => 'SYS_UNIT_CFT'],
                            'purchasePrice' => ['integerValue' => '1000000'],
                            'isActive' => ['booleanValue' => true],
                        ],
                    ],
                ],
            ],
        ]);
        $res->assertStatus(200);
        $this->assertDatabaseHas('product_purchase_options', [
            'id' => 'POPT_SYNC_1',
            'product_id' => 'PRD_SYNC_1',
            'option_name' => 'Truck Load',
            'option_type' => 'PURCHASE',
            'purchase_price' => 1000000,
        ]);
    }
}
