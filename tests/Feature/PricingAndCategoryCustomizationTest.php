<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReturnReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PricingAndCategoryCustomizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherOwner;

    private string $businessId = 'bus_pcc_1';

    private string $otherBusinessId = 'bus_pcc_2';

    private string $outletId = 'out_pcc_1';

    private string $productA = 'prod_cement_1';

    private string $variantA = 'var_cement_1';

    private string $productB = 'prod_steel_1';

    private string $variantB = 'var_steel_1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = new User;
        $this->owner->forceFill([
            'id' => 'usr_pcc_1',
            'name' => 'Builder Owner',
            'email' => 'owner@pcc.test',
            'phone' => '9876500001',
            'password' => Hash::make('Secret123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'business_id' => $this->businessId,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $this->owner->save();

        $this->otherOwner = new User;
        $this->otherOwner->forceFill([
            'id' => 'usr_pcc_2',
            'name' => 'Other Owner',
            'email' => 'other@pcc.test',
            'phone' => '9876500002',
            'password' => Hash::make('Secret123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'business_id' => $this->otherBusinessId,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $this->otherOwner->save();

        $this->seed(ReturnReasonSeeder::class);

        foreach ([$this->businessId => 'Main Builders', $this->otherBusinessId => 'Isolated Traders'] as $bId => $bName) {
            DB::table('businesses')->insert([
                'id' => $bId,
                'document_path' => "businesses/{$bId}",
                'business_id' => $bId,
                'status' => 'ACTIVE',
                'is_deleted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'payload' => json_encode(['fields' => [
                    'businessId' => $bId,
                    'businessName' => $bName,
                ]]),
            ]);
        }

        DB::table('suppliers')->insert([
            'id' => 'sup_pcc_1',
            'document_path' => 'suppliers/sup_pcc_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'supplierId' => 'sup_pcc_1',
                'name' => 'Quarry & Hardware Co',
            ]]),
        ]);

        DB::table('customers')->insert([
            'id' => 'cust_pcc_1',
            'document_path' => 'customers/cust_pcc_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'customerId' => 'cust_pcc_1',
                'name' => 'Metro Constructions',
                'phone' => '9811122233',
            ]]),
        ]);

        DB::table('customer_balances')->insert([
            'id' => 'cust_pcc_1',
            'document_path' => 'customer_balances/cust_pcc_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'customerId' => 'cust_pcc_1',
                'duePaise' => 0,
                'advancePaise' => 0,
            ]]),
        ]);

        // Product A: purchase price ₹100 (10000 paise), selling price ₹120 (12000 paise)
        $this->seedSimpleProduct($this->productA, $this->variantA, 'Item A Pipe', 10000, 12000, 50000);
        // Product B: purchase price ₹200 (20000 paise), selling price ₹300 (30000 paise)
        $this->seedSimpleProduct($this->productB, $this->variantB, 'Item B Valve', 20000, 30000, 50000);
    }

    private function seedSimpleProduct(
        string $productId,
        string $variantId,
        string $name,
        int $purchaseRatePaise,
        int $sellingPricePaise,
        int $stockMilli,
    ): void {
        DB::table('products')->insert([
            'id' => $productId,
            'document_path' => "products/{$productId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'productId' => $productId,
                'name' => $name,
                'baseUnitId' => 'SYS_UNIT_PIECE',
                'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
                'unitSymbol' => 'PCS',
            ]]),
        ]);

        DB::table('product_variants')->insert([
            'id' => $variantId,
            'document_path' => "product_variants/{$variantId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'product_id' => $productId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'variantId' => $variantId,
                'productId' => $productId,
                'name' => 'Standard',
                'baseUnitId' => 'SYS_UNIT_PIECE',
                'defaultSalesUnitId' => 'SYS_UNIT_PIECE',
                'unitSymbol' => 'PCS',
                'purchaseRate' => $purchaseRatePaise,
                'sellingPrice' => $sellingPricePaise,
                'mrp' => $sellingPricePaise,
                'allowFractionalQuantity' => false,
                'trackStock' => true,
            ]]),
        ]);

        DB::table('stock_balances')->insert([
            'id' => "sb_{$variantId}",
            'document_path' => "stock_balances/sb_{$variantId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'variant_id' => $variantId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'variantId' => $variantId,
                'outletId' => $this->outletId,
                'quantityOnHand' => $stockMilli,
            ]]),
        ]);
    }

    public function test_selling_below_purchase_price_rejected_across_all_discount_mechanisms_and_exact_match_allowed(): void
    {
        // 1. Direct manual selling price below purchase price (₹95 < ₹100) without discount -> 422
        $res1 = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'request_id' => 'REQ_PRICE_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_pcc_1',
            'customer_name' => 'Metro Constructions',
            'lines' => [[
                'product_id' => $this->productA,
                'variant_id' => $this->variantA,
                'product_name' => 'Item A Pipe',
                'quantity' => 2,
                'unit_id' => 'SYS_UNIT_PIECE',
                'unit_symbol' => 'PCS',
                'unit_price_paise' => 9500,
                'discount_paise' => 0,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(422);
        $this->assertStringContainsString('Selling price cannot be lower than the purchase price.', json_encode($res1->json()));

        // 2. Fixed item-level discount dropping selling price below cost (₹120 - ₹30 = ₹90 < ₹100) -> 422
        $res2 = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'request_id' => 'REQ_PRICE_2',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_pcc_1',
            'customer_name' => 'Metro Constructions',
            'lines' => [[
                'product_id' => $this->productA,
                'variant_id' => $this->variantA,
                'product_name' => 'Item A Pipe',
                'quantity' => 1,
                'unit_id' => 'SYS_UNIT_PIECE',
                'unit_symbol' => 'PCS',
                'unit_price_paise' => 12000,
                'discount_paise' => 3000,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(422);
        $this->assertStringContainsString('Selling price cannot be lower than the purchase price.', json_encode($res2->json()));

        // 3. Percentage item discount dropping effective price below purchase price (25% off ₹120 = ₹90 < ₹100) -> 422
        $res3 = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'request_id' => 'REQ_PRICE_3',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_pcc_1',
            'customer_name' => 'Metro Constructions',
            'lines' => [[
                'product_id' => $this->productA,
                'variant_id' => $this->variantA,
                'product_name' => 'Item A Pipe',
                'quantity' => 2,
                'unit_id' => 'SYS_UNIT_PIECE',
                'unit_symbol' => 'PCS',
                'unit_price_paise' => 12000,
                'discount_percent' => 25,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(422);
        $this->assertStringContainsString('Selling price cannot be lower than the purchase price.', json_encode($res3->json()));

        // 4. Overall bill-level discount distributed across two items:
        //    Item A: ₹120 x 1 = ₹120 (cost ₹100)
        //    Item B: ₹300 x 1 = ₹300 (cost ₹200)
        //    Total gross = ₹420; Bill discount = 20% -> Item A becomes ₹96 (< ₹100 cost), Item B becomes ₹240 (>= ₹200 cost)
        //    Even though combined total (₹336) > combined cost (₹300), Item A violates its individual purchase floor -> 422!
        $res4 = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'request_id' => 'REQ_PRICE_4',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_pcc_1',
            'customer_name' => 'Metro Constructions',
            'bill_discount_percent' => 20,
            'lines' => [
                [
                    'product_id' => $this->productA,
                    'variant_id' => $this->variantA,
                    'product_name' => 'Item A Pipe',
                    'quantity' => 1,
                    'unit_id' => 'SYS_UNIT_PIECE',
                    'unit_symbol' => 'PCS',
                    'unit_price_paise' => 12000,
                    'gst_rate_bps' => 0,
                ],
                [
                    'product_id' => $this->productB,
                    'variant_id' => $this->variantB,
                    'product_name' => 'Item B Valve',
                    'quantity' => 1,
                    'unit_id' => 'SYS_UNIT_PIECE',
                    'unit_symbol' => 'PCS',
                    'unit_price_paise' => 30000,
                    'gst_rate_bps' => 0,
                ],
            ],
        ])->assertStatus(422);
        $this->assertStringContainsString('Selling price cannot be lower than the purchase price.', json_encode($res4->json()));

        // 5. Quotations and Offline Sync commit must also reject below-purchase price items -> 422
        $this->actingAs($this->owner)->postJson('/api/v1/quotations', [
            'request_id' => 'REQ_QTN_LOW',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_pcc_1',
            'customer_name' => 'Metro Constructions',
            'lines' => [[
                'product_id' => $this->productA,
                'variant_id' => $this->variantA,
                'product_name' => 'Item A Pipe',
                'quantity' => 1,
                'unit_id' => 'SYS_UNIT_PIECE',
                'unit_price_paise' => 12000,
                'discount_paise' => 2500,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(422);

        $this->actingAs($this->owner)->postJson('/api/v1/sync/commit', [
            'business_id' => $this->businessId,
            'writes' => [[
                'update' => [
                    'name' => 'invoices/inv_offline_low_1',
                    'fields' => [
                        'businessId' => $this->businessId,
                        'outletId' => $this->outletId,
                        'customerId' => 'cust_pcc_1',
                        'status' => 'FINAL',
                        'items' => [[
                            'productId' => $this->productA,
                            'variantId' => $this->variantA,
                            'productName' => 'Item A Pipe',
                            'quantity' => 1,
                            'unitId' => 'SYS_UNIT_PIECE',
                            'unitPricePaise' => 9500,
                            'discountPaise' => 0,
                        ]],
                    ],
                ],
            ]],
        ])->assertStatus(422);

        // 6. Selling at EXACT purchase price (₹120 - ₹20 = ₹100) must be permitted -> 201
        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'request_id' => 'REQ_PRICE_EXACT',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_pcc_1',
            'customer_name' => 'Metro Constructions',
            'lines' => [[
                'product_id' => $this->productA,
                'variant_id' => $this->variantA,
                'product_name' => 'Item A Pipe',
                'quantity' => 2,
                'unit_id' => 'SYS_UNIT_PIECE',
                'unit_symbol' => 'PCS',
                'unit_price_paise' => 12000,
                'discount_paise' => 4000,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(201);
    }

    public function test_gravels_dynamic_size_and_quality_crud_isolation_and_historical_snapshots(): void
    {
        // Add custom Size ("25mm Crushed") & Quality ("Super Premium")
        $sizeRes = $this->actingAs($this->owner)->postJson('/api/v1/category-config/gravels/options', [
            'business_id' => $this->businessId,
            'attribute_type' => 'size',
            'name' => '25mm Crushed',
        ])->assertStatus(201);
        $sizeId = $sizeRes->json('data.id');

        $qualRes = $this->actingAs($this->owner)->postJson('/api/v1/category-config/gravels/options', [
            'business_id' => $this->businessId,
            'attribute_type' => 'quality',
            'name' => 'Super Premium',
        ])->assertStatus(201);
        $qualId = $qualRes->json('data.id');

        // Business isolation: other business must NOT receive Main Builders' custom options
        $otherCfg = $this->actingAs($this->otherOwner)
            ->getJson("/api/v1/category-config?business_id={$this->otherBusinessId}")
            ->assertOk();
        $otherSizes = collect($otherCfg->json('data.gravels.sizes'))->pluck('name')->all();
        $this->assertNotContains('25mm Crushed', $otherSizes);

        // Create Gravels product with the new custom Size & Quality
        $gravelRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Blue Metal Gravel',
            'category' => 'Gravels',
            'category_id' => 'SYS_CAT_CONST_GRAVELS',
            'hsn_code' => '2517',
            'default_gst_rate_bps' => 500,
            'attributeValues' => [
                'aggregateSize' => '25mm Crushed',
                'gradeQuality' => 'Super Premium',
            ],
            'variants' => [[
                'name' => '25mm Crushed • Super Premium',
                'baseUnitId' => 'SYS_UNIT_CFT',
                'purchaseUnitId' => 'SYS_UNIT_CFT',
                'defaultSalesUnitId' => 'SYS_UNIT_CFT',
                'unitSymbol' => 'CFT',
                'purchaseRate' => 3500,
                'sellingPrice' => 4500,
                'mrp' => 5000,
                'allowFractionalQuantity' => true,
                'attributeValues' => [
                    'aggregateSize' => '25mm Crushed',
                    'gradeQuality' => 'Super Premium',
                ],
            ]],
        ])->assertStatus(201);
        $gravelProdId = $gravelRes->json('data.product.productId');
        $gravelVarId = $gravelRes->json('data.product.variants.0.variantId');

        // Deleting an in-use Gravel Size or Quality must be blocked with 422
        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/category-config/gravels/options/{$sizeId}?business_id={$this->businessId}")
            ->assertStatus(422);

        // Record purchase & sale preserving Size & Quality snapshots
        $purchaseRes = $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_pcc_1',
            'status' => 'APPROVED',
            'items' => [[
                'product_id' => $gravelProdId,
                'variant_id' => $gravelVarId,
                'product_name' => 'Blue Metal Gravel',
                'quantity' => 100,
                'quantity_scale' => 'UNIT',
                'unit_id' => 'SYS_UNIT_CFT',
                'unit_symbol' => 'CFT',
                'rate' => 3500,
                'gst_rate_bps' => 500,
                'aggregate_size' => '25mm Crushed',
                'grade_quality' => 'Super Premium',
            ]],
        ])->assertStatus(201);
        $purchaseId = $purchaseRes->json('data.purchaseBill.purchaseBillId');

        $invRes = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'request_id' => 'REQ_GRAVEL_INV_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_pcc_1',
            'customer_name' => 'Metro Constructions',
            'lines' => [[
                'product_id' => $gravelProdId,
                'variant_id' => $gravelVarId,
                'product_name' => 'Blue Metal Gravel',
                'quantity' => 20,
                'unit_id' => 'SYS_UNIT_CFT',
                'unit_symbol' => 'CFT',
                'unit_price_paise' => 4500,
                'gst_rate_bps' => 500,
                'aggregate_size' => '25mm Crushed',
                'grade_quality' => 'Super Premium',
            ]],
        ])->assertStatus(201);
        $invoiceId = $invRes->json('data.invoiceId');

        // Edit the Size & Quality names in config and add & delete an unused Size
        $this->actingAs($this->owner)->putJson("/api/v1/category-config/gravels/options/{$qualId}", [
            'business_id' => $this->businessId,
            'name' => 'Export Grade A',
        ])->assertOk();

        $unusedSizeRes = $this->actingAs($this->owner)->postJson('/api/v1/category-config/gravels/options', [
            'business_id' => $this->businessId,
            'attribute_type' => 'size',
            'name' => '99mm Temp',
        ])->assertStatus(201);
        $this->actingAs($this->owner)
            ->deleteJson('/api/v1/category-config/gravels/options/'.$unusedSizeRes->json('data.id')."?business_id={$this->businessId}")
            ->assertOk();

        // Historical invoice & purchase bills must still retain original snapshots ("25mm Crushed", "Super Premium")
        $invShow = $this->actingAs($this->owner)->getJson("/api/v1/invoices/{$invoiceId}")->assertOk();
        $invLine = $invShow->json('data.items.0') ?? $invShow->json('data.lines.0');
        $this->assertSame('25mm Crushed', $invLine['aggregateSize']);
        $this->assertSame('Super Premium', $invLine['gradeQuality']);

        $purchRow = DB::table('purchase_bills')->where('id', $purchaseId)->first();
        $purchFields = json_decode($purchRow->payload, true)['fields'];
        $this->assertSame('25mm Crushed', $purchFields['items'][0]['aggregateSize']);
        $this->assertSame('Super Premium', $purchFields['items'][0]['gradeQuality']);
    }

    public function test_construction_accessories_custom_fields_all_types_custom_units_reorder_and_reports(): void
    {
        // 1. Add custom measurement unit "Drum (DRM)"
        $unitRes = $this->actingAs($this->owner)->postJson('/api/v1/category-config/units', [
            'business_id' => $this->businessId,
            'name' => 'Drum',
            'symbol' => 'DRM',
            'display_name' => 'Drum (200L)',
            'allow_fractional' => true,
            'decimal_precision' => 2,
            'measurement_dimension' => 'VOLUME',
        ])->assertStatus(201);
        $customUnitId = $unitRes->json('data.id');

        // 2. Add custom fields covering all 5 types: TEXT, NUMBER, DROPDOWN, MEASUREMENT, BOOLEAN
        $fText = $this->actingAs($this->owner)->postJson('/api/v1/category-config/accessories/fields', [
            'business_id' => $this->businessId,
            'name' => 'Coat Spec',
            'field_type' => 'TEXT',
            'required' => true,
            'default_value' => 'Anti-Rust',
        ])->assertStatus(201)->json('data.id');

        $fNum = $this->actingAs($this->owner)->postJson('/api/v1/category-config/accessories/fields', [
            'business_id' => $this->businessId,
            'name' => 'Tensile Rating',
            'field_type' => 'NUMBER',
            'required' => false,
            'default_value' => 500,
        ])->assertStatus(201)->json('data.id');

        $fDrop = $this->actingAs($this->owner)->postJson('/api/v1/category-config/accessories/fields', [
            'business_id' => $this->businessId,
            'name' => 'Finish Grade',
            'field_type' => 'DROPDOWN',
            'required' => true,
            'options' => ['Matte', 'Gloss', 'Heavy Duty'],
            'default_value' => 'Heavy Duty',
        ])->assertStatus(201)->json('data.id');

        $fMeas = $this->actingAs($this->owner)->postJson('/api/v1/category-config/accessories/fields', [
            'business_id' => $this->businessId,
            'name' => 'Net Volume',
            'field_type' => 'MEASUREMENT',
            'measurement_unit_id' => $customUnitId,
            'measurement_unit_symbol' => 'DRM',
            'required' => false,
        ])->assertStatus(201)->json('data.id');

        $fBool = $this->actingAs($this->owner)->postJson('/api/v1/category-config/accessories/fields', [
            'business_id' => $this->businessId,
            'name' => 'Waterproof Certified',
            'field_type' => 'BOOLEAN',
            'default_value' => true,
        ])->assertStatus(201)->json('data.id');

        // Reorder fields (place Bool before Text before Drop before Num before Meas) & verify order persists
        $this->actingAs($this->owner)->postJson('/api/v1/category-config/accessories/fields/reorder', [
            'business_id' => $this->businessId,
            'field_ids' => [$fBool, $fText, $fDrop, $fNum, $fMeas],
        ])->assertOk();

        // Rename custom field & verify
        $this->actingAs($this->owner)->putJson("/api/v1/category-config/accessories/fields/{$fNum}", [
            'business_id' => $this->businessId,
            'name' => 'Strength Index',
        ])->assertOk();

        $cfgAfter = $this->actingAs($this->owner)->getJson("/api/v1/category-config?business_id={$this->businessId}")->assertOk();
        $fieldsList = collect($cfgAfter->json('data.accessories.fields'));
        $idsOrder = $fieldsList->pluck('id')->all();
        $this->assertLessThan(array_search($fText, $idsOrder, true), array_search($fBool, $idsOrder, true));
        $this->assertLessThan(array_search($fDrop, $idsOrder, true), array_search($fText, $idsOrder, true));
        $this->assertLessThan(array_search($fNum, $idsOrder, true), array_search($fDrop, $idsOrder, true));
        $this->assertLessThan(array_search($fMeas, $idsOrder, true), array_search($fNum, $idsOrder, true));
        $this->assertSame('Strength Index', $fieldsList->firstWhere('id', $fNum)['name']);

        // 3. Create accessory product using custom unit DRM & all custom field values
        $customAttrsPayload = [
            'Coat Spec' => 'Epoxy Coat',
            'Strength Index' => 650,
            'Finish Grade' => 'Heavy Duty',
            'Net Volume' => 1.5,
            'Waterproof Certified' => true,
        ];
        $accRes = $this->actingAs($this->owner)->postJson('/api/v1/products', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'name' => 'Industrial Primer Solvent',
            'category' => 'Construction Accessories',
            'category_id' => 'SYS_CAT_CONST_ACCESSORIES',
            'subcategory' => 'Paint Accessories',
            'subcategoryId' => 'SYS_SUB_ACC_PAINT',
            'reorder_level' => 10,
            'hsn_code' => '3814',
            'default_gst_rate_bps' => 1800,
            'attributeValues' => [
                'customAttributes' => $customAttrsPayload,
            ],
            'variants' => [[
                'name' => 'Heavy Duty Drum',
                'baseUnitId' => $customUnitId,
                'purchaseUnitId' => $customUnitId,
                'defaultSalesUnitId' => $customUnitId,
                'unitSymbol' => 'DRM',
                'purchaseRate' => 150000,
                'sellingPrice' => 195000,
                'mrp' => 210000,
                'reorderLevel' => 10,
                'allowFractionalQuantity' => true,
                'attributeValues' => [
                    'customAttributes' => $customAttrsPayload,
                ],
            ]],
        ])->assertStatus(201);
        $accProdId = $accRes->json('data.product.productId');
        $accVarId = $accRes->json('data.product.variants.0.variantId');

        // Deleting an in-use custom unit must fail with 422
        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/category-config/units/{$customUnitId}?business_id={$this->businessId}")
            ->assertStatus(422);

        // Purchase 5.50 DRM, Sell 1.50 DRM -> Inventory report must reflect 4.00 DRM and custom attributes
        $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'supplier_id' => 'sup_pcc_1',
            'status' => 'APPROVED',
            'items' => [[
                'product_id' => $accProdId,
                'variant_id' => $accVarId,
                'product_name' => 'Industrial Primer Solvent',
                'quantity' => 5.5,
                'quantity_scale' => 'UNIT',
                'unit_id' => $customUnitId,
                'unit_symbol' => 'DRM',
                'rate' => 150000,
                'gst_rate_bps' => 1800,
                'custom_attributes' => $customAttrsPayload,
            ]],
        ])->assertStatus(201);

        $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'request_id' => 'REQ_ACC_INV_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'customer_id' => 'cust_pcc_1',
            'customer_name' => 'Metro Constructions',
            'lines' => [[
                'product_id' => $accProdId,
                'variant_id' => $accVarId,
                'product_name' => 'Industrial Primer Solvent',
                'quantity' => 1.5,
                'unit_id' => $customUnitId,
                'unit_symbol' => 'DRM',
                'unit_price_paise' => 195000,
                'gst_rate_bps' => 1800,
                'custom_attributes' => $customAttrsPayload,
            ]],
        ])->assertStatus(201);

        $reportRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/reports/inventory?business_id={$this->businessId}&outlet_id={$this->outletId}")
            ->assertOk();
        $invRow = collect($reportRes->json('data.lowStockItems'))->firstWhere('productId', $accProdId);
        $this->assertNotNull($invRow);
        $this->assertSame('DRM', $invRow['unitSymbol']);
        $this->assertEquals(4.0, (float) $invRow['currentStock']);
        $this->assertSame('Epoxy Coat', $invRow['attributeValues']['customAttributes']['Coat Spec']);

        // Unused custom field can be deleted cleanly
        $unusedFieldId = $this->actingAs($this->owner)->postJson('/api/v1/category-config/accessories/fields', [
            'business_id' => $this->businessId,
            'name' => 'Temp Removable Note',
            'field_type' => 'TEXT',
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/category-config/accessories/fields/{$unusedFieldId}?business_id={$this->businessId}")
            ->assertOk();
    }
}
