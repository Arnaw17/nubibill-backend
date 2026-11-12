<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class QuotationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $businessId = 'bus_qtn_1';

    private string $outletId = 'out_qtn_1';

    private string $variantId = 'VAR_QTN_1';

    private string $productId = 'PROD_QTN_1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = new User;
        $this->owner->forceFill([
            'id' => 'usr_qtn_owner',
            'name' => 'Quotation Owner',
            'email' => 'qtn_owner@nubibilling.com',
            'phone' => '9999900111',
            'password' => Hash::make('Secret123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'business_id' => $this->businessId,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $this->owner->save();

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
                'businessName' => 'John Hardware',
            ]]),
        ]);

        DB::table('customers')->insert([
            'id' => 'cust_qtn_1',
            'document_path' => 'customers/cust_qtn_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'customerId' => 'cust_qtn_1',
                'name' => 'Anil Contractors',
                'phone' => '9876543210',
            ]]),
        ]);

        DB::table('customer_balances')->insert([
            'id' => 'cust_qtn_1',
            'document_path' => 'customer_balances/cust_qtn_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'customerId' => 'cust_qtn_1',
                'duePaise' => 0,
                'advancePaise' => 0,
            ]]),
        ]);

        DB::table('products')->insert([
            'id' => $this->productId,
            'document_path' => "products/{$this->productId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'productId' => $this->productId,
                'name' => 'Portland Cement',
                'baseUnitId' => 'SYS_UNIT_BAG',
                'defaultSalesUnitId' => 'SYS_UNIT_BAG',
            ]]),
        ]);

        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'document_path' => "product_variants/{$this->variantId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'product_id' => $this->productId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'variantId' => $this->variantId,
                'productId' => $this->productId,
                'name' => 'Portland Cement (50kg Bag)',
                'baseUnitId' => 'SYS_UNIT_BAG',
                'defaultSalesUnitId' => 'SYS_UNIT_BAG',
                'unitSymbol' => 'BAG',
                'unitDisplayName' => 'Bag',
                'salesConversionFactor' => 1.0,
                'allowFractionalQuantity' => false,
                'sellingPrice' => 40000,
                'trackStock' => true,
            ]]),
        ]);

        // Seed 10 bags = 10000 milli units in stock
        $stockId = "{$this->owner->id}_{$this->outletId}_{$this->variantId}";
        DB::table('stock_balances')->insert([
            'id' => $stockId,
            'document_path' => "stock_balances/{$stockId}",
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'product_id' => $this->productId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'businessId' => $this->businessId,
                'outletId' => $this->outletId,
                'productId' => $this->productId,
                'variantId' => $this->variantId,
                'quantityOnHand' => 10000,
                'currentStock' => 10000,
                'stockScale' => 'MILLI',
                'totalValue' => 350000,
            ]]),
        ]);
    }

    public function test_save_quotation_does_not_deduct_stock_or_create_invoice_and_finalize_creates_final_bill(): void
    {
        // 1. Save quotation for 5 bags @ Rs 400 (40000 paise) = Rs 2000 (200000 paise)
        $saveRes = $this->actingAs($this->owner)->postJson('/api/v1/quotations', [
            'request_id' => 'REQ_QUOTE_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'business_short_code' => 'JH',
            'customer_id' => 'cust_qtn_1',
            'customer_name' => 'Anil Contractors',
            'customer_phone' => '9876543210',
            'tax_mode' => 'GST',
            'valid_until' => '2026-11-20',
            'notes' => 'Site delivery extra',
            'lines' => [
                [
                    'product_id' => $this->productId,
                    'variant_id' => $this->variantId,
                    'product_name' => 'Portland Cement (50kg Bag)',
                    'variant_name' => 'Portland Cement (50kg Bag)',
                    'quantity' => 5,
                    'unit_id' => 'SYS_UNIT_BAG',
                    'unit_symbol' => 'BAG',
                    'unit_price_paise' => 40000,
                    'gst_rate_bps' => 0,
                    'price_includes_tax' => true,
                    'track_stock' => true,
                ],
            ],
        ])->assertStatus(201);

        $quotationId = $saveRes->json('data.quotationId');
        $quotationNumber = $saveRes->json('data.quotationNumber');
        $this->assertStringStartsWith('QTN-JH-', $quotationNumber);
        $this->assertFalse($saveRes->json('data.existing'));

        // Stock must remain 10000 milli, invoices count 0, ledger count 0
        $stock = DB::table('stock_balances')->first();
        $stockFields = json_decode($stock->payload, true)['fields'];
        $this->assertSame(10000, (int) $stockFields['quantityOnHand']);
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('customer_ledger_entries')->count());

        // Idempotent retry returns same quotation
        $retryRes = $this->actingAs($this->owner)->postJson('/api/v1/quotations', [
            'request_id' => 'REQ_QUOTE_1',
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'lines' => [
                [
                    'product_id' => $this->productId,
                    'variant_id' => $this->variantId,
                    'product_name' => 'Portland Cement (50kg Bag)',
                    'quantity' => 5,
                    'unit_price_paise' => 40000,
                    'gst_rate_bps' => 0,
                ],
            ],
        ])->assertOk();
        $this->assertTrue($retryRes->json('data.existing'));
        $this->assertSame($quotationId, $retryRes->json('data.quotationId'));

        // 2. Update quotation qty from 5 to 6 bags
        $this->actingAs($this->owner)->putJson("/api/v1/quotations/{$quotationId}", [
            'customer_id' => 'cust_qtn_1',
            'customer_name' => 'Anil Contractors',
            'notes' => 'Updated to 6 bags',
            'lines' => [
                [
                    'product_id' => $this->productId,
                    'variant_id' => $this->variantId,
                    'product_name' => 'Portland Cement (50kg Bag)',
                    'variant_name' => 'Portland Cement (50kg Bag)',
                    'quantity' => 6,
                    'unit_id' => 'SYS_UNIT_BAG',
                    'unit_symbol' => 'BAG',
                    'unit_price_paise' => 40000,
                    'gst_rate_bps' => 0,
                    'price_includes_tax' => true,
                    'track_stock' => true,
                ],
            ],
        ])->assertOk();

        // 3. Finalize quotation into final bill: 6 * 40000 = 240000 paise (140000 cash + 100000 due)
        $finalizeRes = $this->actingAs($this->owner)->postJson("/api/v1/quotations/{$quotationId}/finalize", [
            'request_id' => 'FINAL_REQ_1',
            'business_short_code' => 'JH',
            'cash_paise' => 140000,
            'upi_paise' => 0,
            'due_paise' => 100000,
            'due_date' => '2026-12-01',
        ])->assertStatus(201);

        $invoiceId = $finalizeRes->json('data.invoiceId');
        $invoiceNumber = $finalizeRes->json('data.invoiceNumber');
        $this->assertStringStartsWith('JH-', $invoiceNumber);
        $this->assertSame($quotationId, $finalizeRes->json('data.quotationId'));

        // Verify quotation marked CONVERTED and linked
        $qRow = DB::table('quotations')->where('id', $quotationId)->first();
        $this->assertSame('CONVERTED', $qRow->status);
        $this->assertSame($invoiceId, $qRow->converted_invoice_id);
        $this->assertSame($invoiceNumber, $qRow->converted_invoice_number);

        // Verify real invoice created, stock deducted (10 - 6 = 4 bags = 4000 milli), customer due updated
        $this->assertSame(1, DB::table('invoices')->where('id', $invoiceId)->count());
        $stockAfter = json_decode(DB::table('stock_balances')->first()->payload, true)['fields'];
        $this->assertSame(4000, (int) $stockAfter['quantityOnHand']);

        $balAfter = json_decode(DB::table('customer_balances')->where('id', 'cust_qtn_1')->first()->payload, true)['fields'];
        $this->assertSame(100000, (int) $balAfter['duePaise']);

        // Double finalize with different request_id must be rejected with 409
        $this->actingAs($this->owner)->postJson("/api/v1/quotations/{$quotationId}/finalize", [
            'request_id' => 'FINAL_REQ_2',
            'cash_paise' => 240000,
        ])->assertStatus(409);

        // Cannot update or cancel converted quotation
        $this->actingAs($this->owner)->postJson("/api/v1/quotations/{$quotationId}/cancel", [])
            ->assertStatus(400);
    }

    public function test_cancelled_quotation_cannot_be_finalized_and_insufficient_stock_blocks_finalize(): void
    {
        $resCancelled = $this->actingAs($this->owner)->postJson('/api/v1/quotations', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'lines' => [[
                'product_id' => $this->productId,
                'variant_id' => $this->variantId,
                'product_name' => 'Portland Cement (50kg Bag)',
                'quantity' => 2,
                'unit_id' => 'SYS_UNIT_BAG',
                'unit_price_paise' => 40000,
                'gst_rate_bps' => 0,
            ]],
        ])->assertStatus(201);
        $cancelledId = $resCancelled->json('data.quotationId');

        $this->actingAs($this->owner)->postJson("/api/v1/quotations/{$cancelledId}/cancel", [
            'reason' => 'Customer declined',
        ])->assertOk();

        $this->actingAs($this->owner)->postJson("/api/v1/quotations/{$cancelledId}/finalize", [
            'cash_paise' => 80000,
        ])->assertStatus(400);

        // Quotation for 50 bags can be SAVED (estimate), but finalizing fails due to 10 bags stock
        $resLarge = $this->actingAs($this->owner)->postJson('/api/v1/quotations', [
            'business_id' => $this->businessId,
            'outlet_id' => $this->outletId,
            'lines' => [[
                'product_id' => $this->productId,
                'variant_id' => $this->variantId,
                'product_name' => 'Portland Cement (50kg Bag)',
                'quantity' => 50,
                'unit_id' => 'SYS_UNIT_BAG',
                'unit_price_paise' => 40000,
                'gst_rate_bps' => 0,
                'track_stock' => true,
            ]],
        ])->assertStatus(201);
        $largeId = $resLarge->json('data.quotationId');

        $this->actingAs($this->owner)->postJson("/api/v1/quotations/{$largeId}/finalize", [
            'cash_paise' => 2000000,
        ])->assertStatus(422);
    }
}
