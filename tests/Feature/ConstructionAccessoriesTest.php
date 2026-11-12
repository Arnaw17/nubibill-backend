<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UnitConversionService;
use Database\Seeders\ReturnReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConstructionAccessoriesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $businessId = 'bus_acc_1';

    private string $outletId = 'out_acc_1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = new User;
        $this->owner->forceFill([
            'id' => 'usr_acc_owner_1',
            'name' => 'Hardware Owner',
            'email' => 'hardware@nubibilling.com',
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
                'businessName' => 'Nubi Hardware & Accessories',
            ]]),
        ]);

        DB::table('suppliers')->insert([
            'id' => 'sup_acc_1',
            'document_path' => 'suppliers/sup_acc_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['supplierId' => 'sup_acc_1', 'name' => 'Apex Tools & Hardware']]),
        ]);

        DB::table('customers')->insert([
            'id' => 'cust_acc_1',
            'document_path' => 'customers/cust_acc_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_acc_1', 'name' => 'Sharma Contractors']]),
        ]);

        DB::table('customer_balances')->insert([
            'id' => 'cust_acc_1',
            'document_path' => 'customer_balances/cust_acc_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_acc_1', 'duePaise' => 0, 'advancePaise' => 0]]),
        ]);
    }

    public function test_catalogue_seed_and_all_17_standard_measurement_units(): void
    {
        $res = $this->actingAs($this->owner)->getJson('/api/v1/product-masters')->assertOk();
        $units = collect($res->json('data.units'));

        $requiredUnitIds = [
            'SYS_UNIT_PIECE',
            'SYS_UNIT_KG',
            'SYS_UNIT_GRAM',
            'SYS_UNIT_LITRE',
            'SYS_UNIT_ML',
            'SYS_UNIT_METRE',
            'SYS_UNIT_CM',
            'SYS_UNIT_FOOT',
            'SYS_UNIT_ROLL',
            'SYS_UNIT_BOX',
            'SYS_UNIT_PACKET',
            'SYS_UNIT_PAIR',
            'SYS_UNIT_SET',
            'SYS_UNIT_BAG',
            'SYS_UNIT_BUNDLE',
            'SYS_UNIT_DOZEN',
            'SYS_UNIT_CARTON',
        ];

        foreach ($requiredUnitIds as $unitId) {
            $this->assertTrue($units->contains('id', $unitId), "Missing unit {$unitId}");
        }

        $categories = collect($res->json('data.categories'));
        $this->assertTrue($categories->contains('id', 'SYS_CAT_CONST_ACCESSORIES'));

        $subcategories = collect($res->json('data.subcategories'))
            ->where('categoryId', 'SYS_CAT_CONST_ACCESSORIES');
        foreach ([
            'SYS_SUB_ACC_PAINT',
            'SYS_SUB_ACC_FASTENERS',
            'SYS_SUB_ACC_PLUMBING',
            'SYS_SUB_ACC_ELECTRICAL',
            'SYS_SUB_ACC_MASONRY',
        ] as $subId) {
            $this->assertTrue($subcategories->contains('id', $subId), "Missing subcategory {$subId}");
        }

        // Verify category default unit resolution avoids treating Paint Brush as liquid Paint
        $svc = new UnitConversionService;
        $this->assertSame('SYS_UNIT_PIECE', $svc->resolveCategoryDefault('Construction Accessories', 'Paint Accessories', 'Paint Brush')['id']);
        $this->assertSame('SYS_UNIT_LITRE', $svc->resolveCategoryDefault('Construction Accessories', 'Paint Accessories', 'Paint Remover')['id']);
        $this->assertSame('SYS_UNIT_ROLL', $svc->resolveCategoryDefault('Construction Accessories', 'Paint Accessories', 'Masking Tape')['id']);
        $this->assertSame('SYS_UNIT_KG', $svc->resolveCategoryDefault('Construction Accessories', 'Fasteners & Hardware', 'Iron Nails')['id']);
        $this->assertSame('SYS_UNIT_LITRE', $svc->resolveCategoryDefault('Construction Accessories', 'Plumbing Accessories', 'PVC Solvent')['id']);
        $this->assertSame('SYS_UNIT_ROLL', $svc->resolveCategoryDefault('Construction Accessories', 'Electrical Accessories', 'Electrical Tape')['id']);
        $this->assertSame('SYS_UNIT_METRE', $svc->resolveCategoryDefault('Construction Accessories', 'Electrical Accessories', 'Electrical Wire')['id']);
        $this->assertSame('SYS_UNIT_PACKET', $svc->resolveCategoryDefault('Construction Accessories', 'Masonry & General Accessories', 'Tile Spacer')['id']);
        $this->assertSame('SYS_UNIT_PAIR', $svc->resolveCategoryDefault('Construction Accessories', 'Masonry & General Accessories', 'Construction Gloves')['id']);
        $this->assertSame('SYS_UNIT_BAG', $svc->resolveCategoryDefault('Construction Accessories', 'Masonry & General Accessories', 'Tile Adhesive')['id']);
    }

    public function test_accessories_creation_purchase_sales_inventory_validation_and_unit_lock(): void
    {
        // 1. Create Paint Brush without Brand (optional brand) & reorderLevel persisted
        $brushRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Paint Brush',
            'category' => 'Construction Accessories',
            'category_id' => 'SYS_CAT_CONST_ACCESSORIES',
            'subcategory' => 'Paint Accessories',
            'subcategoryId' => 'SYS_SUB_ACC_PAINT',
            'reorder_level' => 15,
            'hsn_code' => '9603',
            'default_gst_rate_bps' => 1800,
            'variants' => [[
                'name' => 'Standard',
                'baseUnitId' => 'SYS_UNIT_PIECE',
                'purchaseUnitId' => 'SYS_UNIT_PIECE',
                'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
                'sellingPrice' => 6000,
                'purchaseRate' => 3500,
                'reorderLevel' => 15,
                'allowFractionalQuantity' => false,
                'unitSymbol' => 'PCS',
            ]],
        ])->assertStatus(201);
        $brushProdId = $brushRes->json('data.product.productId');
        $brushVarId = $brushRes->json('data.product.variants.0.variantId');
        $this->assertSame(15, $brushRes->json('data.product.reorderLevel'));
        $this->assertSame(15, $brushRes->json('data.product.variants.0.reorderLevel'));

        // Verify GET /products returns reorderLevel and optional null brand cleanly
        $listRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/products?business_id={$this->businessId}")
            ->assertOk();
        $listedBrush = collect($listRes->json('data'))->firstWhere('productId', $brushProdId);
        $this->assertNotNull($listedBrush);
        $this->assertSame(15, $listedBrush['variants'][0]['reorderLevel']);

        // 2. Create Iron Nails (Kg, fractional allowed) and Paint Remover (Litre, fractional allowed)
        $nailsRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Iron Nails',
            'category' => 'Construction Accessories',
            'category_id' => 'SYS_CAT_CONST_ACCESSORIES',
            'subcategory' => 'Fasteners & Hardware',
            'subcategoryId' => 'SYS_SUB_ACC_FASTENERS',
            'hsn_code' => '7317',
            'default_gst_rate_bps' => 1800,
            'variants' => [[
                'name' => 'Standard',
                'baseUnitId' => 'SYS_UNIT_KG',
                'purchaseUnitId' => 'SYS_UNIT_KG',
                'defaultSalesUnitId' => 'SYS_UNIT_KG',
                'sellingPrice' => 11000,
                'purchaseRate' => 8500,
                'reorderLevel' => 10,
                'allowFractionalQuantity' => true,
                'unitSymbol' => 'KG',
            ]],
        ])->assertStatus(201);
        $nailsProdId = $nailsRes->json('data.product.productId');
        $nailsVarId = $nailsRes->json('data.product.variants.0.variantId');

        $removerRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Paint Remover',
            'category' => 'Construction Accessories',
            'category_id' => 'SYS_CAT_CONST_ACCESSORIES',
            'subcategory' => 'Paint Accessories',
            'subcategoryId' => 'SYS_SUB_ACC_PAINT',
            'hsn_code' => '3814',
            'default_gst_rate_bps' => 1800,
            'variants' => [[
                'name' => 'Standard',
                'baseUnitId' => 'SYS_UNIT_LITRE',
                'purchaseUnitId' => 'SYS_UNIT_LITRE',
                'defaultSalesUnitId' => 'SYS_UNIT_LITRE',
                'sellingPrice' => 32000,
                'purchaseRate' => 24000,
                'allowFractionalQuantity' => true,
                'unitSymbol' => 'L',
            ]],
        ])->assertStatus(201);
        $removerProdId = $removerRes->json('data.product.productId');
        $removerVarId = $removerRes->json('data.product.variants.0.variantId');

        // 3. Reject fractional purchase for Piece product
        $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_acc_1',
            'status' => 'APPROVED',
            'items' => [[
                'product_id' => $brushProdId,
                'variant_id' => $brushVarId,
                'product_name' => 'Paint Brush',
                'quantity' => 1.5,
                'quantity_scale' => 'UNIT',
                'unit_id' => 'SYS_UNIT_PIECE',
                'rate' => 3500,
                'gst_rate_bps' => 1800,
            ]],
        ])->assertStatus(422);

        // 4. Reject incompatible unit purchase (Iron Nails KG purchased in Piece without conversion)
        $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_acc_1',
            'status' => 'APPROVED',
            'items' => [[
                'product_id' => $nailsProdId,
                'variant_id' => $nailsVarId,
                'product_name' => 'Iron Nails',
                'quantity' => 10,
                'quantity_scale' => 'UNIT',
                'unit_id' => 'SYS_UNIT_PIECE',
                'rate' => 8500,
                'gst_rate_bps' => 1800,
            ]],
        ])->assertStatus(422);

        // 5. Valid Purchase: Paint Brush 100 PCS, Iron Nails 25.5 KG, Paint Remover 20 L
        $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_acc_1',
            'status' => 'APPROVED',
            'items' => [
                [
                    'product_id' => $brushProdId,
                    'variant_id' => $brushVarId,
                    'product_name' => 'Paint Brush',
                    'quantity' => 100,
                    'quantity_scale' => 'UNIT',
                    'unit_id' => 'SYS_UNIT_PIECE',
                    'rate' => 3500,
                    'gst_rate_bps' => 1800,
                ],
                [
                    'product_id' => $nailsProdId,
                    'variant_id' => $nailsVarId,
                    'product_name' => 'Iron Nails',
                    'quantity' => 25.5,
                    'quantity_scale' => 'UNIT',
                    'unit_id' => 'SYS_UNIT_KG',
                    'rate' => 8500,
                    'gst_rate_bps' => 1800,
                ],
                [
                    'product_id' => $removerProdId,
                    'variant_id' => $removerVarId,
                    'product_name' => 'Paint Remover',
                    'quantity' => 20,
                    'quantity_scale' => 'UNIT',
                    'unit_id' => 'SYS_UNIT_LITRE',
                    'rate' => 24000,
                    'gst_rate_bps' => 1800,
                ],
            ],
        ])->assertStatus(201);

        // 6. Reject fractional sales on Piece product (Paint Brush)
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'lines' => [[
                'product_id' => $brushProdId,
                'variant_id' => $brushVarId,
                'product_name' => 'Paint Brush',
                'quantity' => 2.5,
                'unit_price_paise' => 6000,
                'unit_id' => 'SYS_UNIT_PIECE',
            ]],
        ])->assertStatus(422);

        // 7. Reject incompatible sales unit (Paint Brush PCS sold in KG)
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'lines' => [[
                'product_id' => $brushProdId,
                'variant_id' => $brushVarId,
                'product_name' => 'Paint Brush',
                'quantity' => 1,
                'unit_price_paise' => 6000,
                'unit_id' => 'SYS_UNIT_KG',
            ]],
        ])->assertStatus(422);

        // 8. Valid Sales: Sell 10 Paint Brush, 5.25 KG Iron Nails, 3.5 L Paint Remover
        $invRes = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_acc_1',
            'lines' => [
                [
                    'product_id' => $brushProdId,
                    'variant_id' => $brushVarId,
                    'product_name' => 'Paint Brush',
                    'quantity' => 10,
                    'unit_price_paise' => 6000,
                    'unit_id' => 'SYS_UNIT_PIECE',
                ],
                [
                    'product_id' => $nailsProdId,
                    'variant_id' => $nailsVarId,
                    'product_name' => 'Iron Nails',
                    'quantity' => 5.25,
                    'unit_price_paise' => 11000,
                    'unit_id' => 'SYS_UNIT_KG',
                ],
                [
                    'product_id' => $removerProdId,
                    'variant_id' => $removerVarId,
                    'product_name' => 'Paint Remover',
                    'quantity' => 3.5,
                    'unit_price_paise' => 32000,
                    'unit_id' => 'SYS_UNIT_LITRE',
                ],
            ],
        ])->assertStatus(201);

        $lines = $invRes->json('data.invoice.items');
        $this->assertSame('PCS', $lines[0]['unit']);
        $this->assertSame('KG', $lines[1]['unit']);
        $this->assertSame('L', $lines[2]['unit']);

        // 9. Verify exact closing stock:
        // Paint Brush: 100 - 10 = 90 PCS (90,000 milli-units)
        // Iron Nails: 25.5 - 5.25 = 20.25 KG (20,250 milli-units)
        // Paint Remover: 20 - 3.5 = 16.5 L (16,500 milli-units)
        $stockRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/inventory/stock?business_id={$this->businessId}&outlet_id={$this->outletId}")
            ->assertOk();
        $stockMap = $stockRes->json('data.stockMap');
        $this->assertSame(90000, $stockMap[$brushVarId]);
        $this->assertSame(20250, $stockMap[$nailsVarId]);
        $this->assertSame(16500, $stockMap[$removerVarId]);

        // 10. Rule 8.5: Reject changing measurement unit on Paint Brush while stock (90 PCS) > 0
        $this->actingAs($this->owner)->putJson("/api/v1/products/{$brushProdId}", [
            'variants' => [[
                'variantId' => $brushVarId,
                'name' => 'Standard',
                'baseUnitId' => 'SYS_UNIT_KG',
                'sellingPrice' => 6000,
                'purchaseRate' => 3500,
            ]],
        ])->assertStatus(422);

        // Allow update when keeping same unit (just editing price/reorderLevel)
        $this->actingAs($this->owner)->putJson("/api/v1/products/{$brushProdId}", [
            'reorderLevel' => 20,
            'variants' => [[
                'variantId' => $brushVarId,
                'name' => 'Standard',
                'baseUnitId' => 'SYS_UNIT_PIECE',
                'sellingPrice' => 6500,
                'purchaseRate' => 3500,
                'reorderLevel' => 20,
            ]],
        ])->assertOk();

        // 11. Custom unit creation & multi-variant accessory (Paint Brush 1", 2", 3", 4")
        $customUnitRes = $this->actingAs($this->owner)->postJson('/api/v1/product-masters', [
            'business_id' => $this->businessId,
            'type' => 'UNIT',
            'name' => 'Spool',
            'code' => 'Spool',
        ])->assertStatus(201);
        $this->assertSame('Spool', $customUnitRes->json('data.name'));

        $multiBrush = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Paint Brush Pro',
            'category' => 'Construction Accessories',
            'category_id' => 'SYS_CAT_CONST_ACCESSORIES',
            'subcategory' => 'Paint Accessories',
            'subcategoryId' => 'SYS_SUB_ACC_PAINT',
            'inventoryBehaviour' => 'SEPARATE_VARIANT',
            'variants' => [
                ['name' => '1 Inch', 'sku' => 'PB-1', 'baseUnitId' => 'SYS_UNIT_PIECE', 'sellingPrice' => 3000, 'purchaseRate' => 1800, 'unitSymbol' => 'PCS'],
                ['name' => '2 Inch', 'sku' => 'PB-2', 'baseUnitId' => 'SYS_UNIT_PIECE', 'sellingPrice' => 5000, 'purchaseRate' => 3000, 'unitSymbol' => 'PCS'],
                ['name' => '3 Inch', 'sku' => 'PB-3', 'baseUnitId' => 'SYS_UNIT_PIECE', 'sellingPrice' => 7500, 'purchaseRate' => 4500, 'unitSymbol' => 'PCS'],
                ['name' => '4 Inch', 'sku' => 'PB-4', 'baseUnitId' => 'SYS_UNIT_PIECE', 'sellingPrice' => 9500, 'purchaseRate' => 6000, 'unitSymbol' => 'PCS'],
            ],
        ])->assertStatus(201);
        $this->assertCount(4, $multiBrush->json('data.product.variants'));
        $this->assertSame('SEPARATE_VARIANT', $multiBrush->json('data.product.inventoryBehaviour'));
    }
}
