<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\BusinessController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessSignatureManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherOwner;

    private string $businessA = 'BUS-SIG-A';

    private string $businessB = 'BUS-SIG-B';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->owner = new User;
        $this->owner->forceFill([
            'id' => 'usr_sig_owner_1',
            'name' => 'Owner A',
            'email' => 'sigownera@nubibilling.com',
            'phone' => '9000000101',
            'password' => Hash::make('Secret123!'),
            'role' => 'OWNER',
            'status' => 'ACTIVE',
            'business_id' => $this->businessA,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $this->owner->save();

        $this->otherOwner = new User;
        $this->otherOwner->forceFill([
            'id' => 'usr_sig_owner_2',
            'name' => 'Owner B',
            'email' => 'sigownerb@nubibilling.com',
            'phone' => '9000000102',
            'password' => Hash::make('Secret123!'),
            'role' => 'OWNER',
            'status' => 'ACTIVE',
            'business_id' => $this->businessB,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $this->otherOwner->save();

        DB::table('businesses')->insert([
            [
                'id' => $this->businessA,
                'document_path' => "businesses/{$this->businessA}",
                'business_id' => $this->businessA,
                'status' => 'active',
                'is_deleted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'payload' => json_encode(['fields' => [
                    'businessId' => $this->businessA,
                    'ownerUid' => $this->owner->id,
                    'businessName' => 'Alpha Hardware & Paints',
                    'address' => '12 Main Bazaar',
                    'phone' => '9876543210',
                    'gstin' => '29ABCDE1234F1Z5',
                ]]),
            ],
            [
                'id' => $this->businessB,
                'document_path' => "businesses/{$this->businessB}",
                'business_id' => $this->businessB,
                'status' => 'active',
                'is_deleted' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'payload' => json_encode(['fields' => [
                    'businessId' => $this->businessB,
                    'ownerUid' => $this->otherOwner->id,
                    'businessName' => 'Beta Cement Depot',
                ]]),
            ],
        ]);
    }

    private function createTransparentPngBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        $ink = imagecolorallocate($img, 15, 23, 42);
        imageline($img, 8, (int) ($height * 0.7), $width - 8, (int) ($height * 0.3), $ink);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function test_upload_toggle_replace_and_remove_signature_preserve_history_and_isolation(): void
    {
        // Wide short transparent PNG signature (180x48) passes validation.
        $sigV1Bytes = $this->createTransparentPngBytes(180, 48);
        $fileV1 = UploadedFile::fake()->createWithContent('sig_v1.png', $sigV1Bytes);

        $upResp = $this->actingAs($this->owner)->post("/api/v1/businesses/{$this->businessA}/signature", [
            'signature' => $fileV1,
            'signatoryName' => 'Rajesh Sharma',
            'signatoryDesignation' => 'Proprietor',
        ]);
        $upResp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.signatureEnabled', true)
            ->assertJsonPath('data.signatoryName', 'Rajesh Sharma')
            ->assertJsonPath('data.signatoryDesignation', 'Proprietor')
            ->assertJsonPath('data.signatureWidth', 180)
            ->assertJsonPath('data.signatureHeight', 48);

        $v1 = $upResp->json('data.signatureVersion');
        $this->assertNotEmpty($v1);

        // Toggle disabled without removing file.
        $toggleOff = $this->actingAs($this->owner)->putJson("/api/v1/businesses/{$this->businessA}/signature/settings", [
            'signatureEnabled' => false,
            'signatoryName' => 'Rajesh K. Sharma',
            'signatoryDesignation' => 'Director',
        ]);
        $toggleOff->assertStatus(200)
            ->assertJsonPath('data.signatureEnabled', false)
            ->assertJsonPath('data.signatoryName', 'Rajesh K. Sharma')
            ->assertJsonPath('data.signatureVersion', $v1);

        // Snapshot resolves disabled when toggled off.
        $disabledSnap = BusinessController::resolveActiveSignatureSnapshot($this->businessA);
        $this->assertTrue($disabledSnap['hasBusinessSignatureSnapshot']);
        $this->assertFalse($disabledSnap['businessSignatureEnabled']);
        $this->assertNull($disabledSnap['businessSignatureUrl']);

        // Re-enable via saveSettings.
        $this->actingAs($this->owner)->putJson("/api/v1/businesses/{$this->businessA}/settings", [
            'signatureEnabled' => true,
        ])->assertStatus(200)->assertJsonPath('data.signatureEnabled', true);

        // Business B remains untouched.
        $this->actingAs($this->otherOwner)->getJson("/api/v1/businesses/{$this->businessB}/settings")
            ->assertStatus(200)
            ->assertJsonPath('data.signatureEnabled', false)
            ->assertJsonPath('data.signatureUrl', null);

        // Remove signature -> active cleared, historical v1 still streamable for past bills.
        $delResp = $this->actingAs($this->owner)->deleteJson("/api/v1/businesses/{$this->businessA}/signature");
        $delResp->assertStatus(200)
            ->assertJsonPath('data.signatureEnabled', false)
            ->assertJsonPath('data.signatureUrl', null);

        $histStream = $this->get("/api/v1/businesses/{$this->businessA}/signature?v={$v1}");
        $histStream->assertStatus(200);
        $this->assertStringContainsString('image/png', (string) $histStream->headers->get('Content-Type'));
    }

    public function test_signature_validation_and_cross_tenant_authorization(): void
    {
        $tooSmall = $this->createTransparentPngBytes(20, 16);
        $this->actingAs($this->owner)
            ->postJson("/api/v1/businesses/{$this->businessA}/signature", [
                'signature_base64' => base64_encode($tooSmall),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SIGNATURE_INVALID_DIMENSIONS');

        $this->actingAs($this->owner)
            ->postJson("/api/v1/businesses/{$this->businessA}/signature", [
                'signature_base64' => base64_encode('not-an-image'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SIGNATURE_INVALID_FORMAT');

        $valid = $this->createTransparentPngBytes(160, 48);
        $this->actingAs($this->otherOwner)
            ->postJson("/api/v1/businesses/{$this->businessA}/signature", [
                'signature_base64' => base64_encode($valid),
            ])
            ->assertStatus(403);
    }

    public function test_bills_purchases_and_returns_capture_immutable_signature_snapshots(): void
    {
        DB::table('customers')->insert([
            'id' => 'cust_sig_1',
            'document_path' => 'customers/cust_sig_1',
            'business_id' => $this->businessA,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_sig_1', 'name' => 'Walk-in Buyer']]),
        ]);
        DB::table('suppliers')->insert([
            'id' => 'sup_sig_1',
            'document_path' => 'suppliers/sup_sig_1',
            'business_id' => $this->businessA,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['supplierId' => 'sup_sig_1', 'name' => 'Apex Suppliers']]),
        ]);
        DB::table('products')->insert([
            'id' => 'prod_sig_1',
            'document_path' => 'products/prod_sig_1',
            'business_id' => $this->businessA,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'productId' => 'prod_sig_1',
                'name' => 'WeatherCoat Gloss',
                'currentStock' => 100,
                'sellingPrice' => 30000,
                'costPrice' => 20000,
                'unit' => 'LTR',
            ]]),
        ]);

        // 1. Upload signature v1 and create Invoice 1, Purchase 1, Return 1
        $sig1 = $this->createTransparentPngBytes(200, 60);
        $v1Resp = $this->actingAs($this->owner)
            ->postJson("/api/v1/businesses/{$this->businessA}/signature", [
                'signature_base64' => base64_encode($sig1),
                'signatoryName' => 'Rajesh Sharma',
                'signatoryDesignation' => 'Managing Partner',
            ]);
        $v1Resp->assertStatus(200);
        $v1 = $v1Resp->json('data.signatureVersion');

        $inv1 = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessA,
            'outlet_id' => 'OUT-SIG-A',
            'customer_id' => 'cust_sig_1',
            'customer_name' => 'Walk-in Buyer',
            'lines' => [[
                'product_id' => 'prod_sig_1',
                'product_name' => 'WeatherCoat Gloss',
                'quantity' => 2,
                'unit_price_paise' => 30000,
                'gst_rate_bps' => 0,
                'unit_symbol' => 'LTR',
            ]],
            'cash_paise' => 60000,
        ]);
        $inv1->assertStatus(201);
        $inv1Id = $inv1->json('data.invoiceId');

        $pur1 = $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessA,
            'outlet_id' => 'OUT-SIG-A',
            'supplier_id' => 'sup_sig_1',
            'supplier_name' => 'Apex Suppliers',
            'items' => [[
                'product_id' => 'prod_sig_1',
                'product_name' => 'WeatherCoat Gloss',
                'quantity' => 4,
                'rate' => 20000,
                'gst_rate_bps' => 0,
            ]],
        ]);
        $pur1->assertStatus(201);
        $pur1Id = $pur1->json('data.purchaseBill.purchaseBillId');

        $ret1 = $this->actingAs($this->owner)->postJson('/api/v1/returns', [
            'business_id' => $this->businessA,
            'type' => 'sale_return',
            'original_bill_id' => $inv1Id,
            'original_bill_no' => $inv1->json('data.invoiceNumber'),
            'party_id' => 'cust_sig_1',
            'party_name' => 'Walk-in Buyer',
            'items' => [[
                'product_id' => 'prod_sig_1',
                'product_name' => 'WeatherCoat Gloss',
                'qty_returned' => 1,
                'unit_price' => 300,
                'tax_rate' => 0,
            ]],
        ]);
        $ret1->assertStatus(201)
            ->assertJsonPath('data.has_business_signature_snapshot', true)
            ->assertJsonPath('data.business_signature_enabled', true)
            ->assertJsonPath('data.business_signature_version', $v1)
            ->assertJsonPath('data.business_signatory_name', 'Rajesh Sharma');
        $ret1Id = $ret1->json('data.id');

        // 2. Replace signature with v2 & disable before Invoice 2
        $sig2 = $this->createTransparentPngBytes(240, 72);
        $v2Resp = $this->actingAs($this->owner)
            ->postJson("/api/v1/businesses/{$this->businessA}/signature", [
                'signature_base64' => base64_encode($sig2),
                'signatoryName' => 'Anita Verma',
                'signatoryDesignation' => 'Director',
            ]);
        $v2Resp->assertStatus(200);
        $v2 = $v2Resp->json('data.signatureVersion');
        $this->assertNotSame($v1, $v2);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/businesses/{$this->businessA}/signature/settings", [
                'signatureEnabled' => false,
            ])->assertStatus(200);

        $inv2 = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessA,
            'outlet_id' => 'OUT-SIG-A',
            'customer_id' => 'cust_sig_1',
            'customer_name' => 'Walk-in Buyer',
            'lines' => [[
                'product_id' => 'prod_sig_1',
                'product_name' => 'WeatherCoat Gloss',
                'quantity' => 1,
                'unit_price_paise' => 30000,
                'gst_rate_bps' => 0,
                'unit_symbol' => 'LTR',
            ]],
            'cash_paise' => 30000,
        ]);
        $inv2->assertStatus(201);
        $inv2Id = $inv2->json('data.invoiceId');

        // Historical documents STILL retain v1 & Rajesh Sharma!
        $this->actingAs($this->owner)->getJson("/api/v1/invoices/{$inv1Id}")
            ->assertJsonPath('data.businessSignatureEnabled', true)
            ->assertJsonPath('data.businessSignatureVersion', $v1)
            ->assertJsonPath('data.businessSignatoryName', 'Rajesh Sharma');

        $this->actingAs($this->owner)->getJson("/api/v1/purchases/{$pur1Id}")
            ->assertJsonPath('data.businessSignatureEnabled', true)
            ->assertJsonPath('data.businessSignatureVersion', $v1)
            ->assertJsonPath('data.businessSignatoryName', 'Rajesh Sharma');

        $this->actingAs($this->owner)->getJson("/api/v1/returns/{$ret1Id}")
            ->assertJsonPath('data.business_signature_enabled', true)
            ->assertJsonPath('data.business_signature_version', $v1);

        // Invoice 2 (created while disabled) stays disabled cleanly
        $this->actingAs($this->owner)->getJson("/api/v1/invoices/{$inv2Id}")
            ->assertJsonPath('data.hasBusinessSignatureSnapshot', true)
            ->assertJsonPath('data.businessSignatureEnabled', false)
            ->assertJsonPath('data.businessSignatureVersion', null);

        // Return PDF still renders cleanly after signature change/disable
        $pdfResp = $this->actingAs($this->owner)->get("/api/v1/returns/{$ret1Id}/pdf");
        $pdfResp->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdfResp->headers->get('Content-Type'));
    }
}
