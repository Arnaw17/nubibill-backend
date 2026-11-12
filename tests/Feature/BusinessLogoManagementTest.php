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

class BusinessLogoManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherOwner;

    private string $businessA = 'BUS-LOGO-A';

    private string $businessB = 'BUS-LOGO-B';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->owner = new User;
        $this->owner->forceFill([
            'id' => 'usr_logo_owner_1',
            'name' => 'Owner A',
            'email' => 'ownera@nubibilling.com',
            'phone' => '9000000001',
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
            'id' => 'usr_logo_owner_2',
            'name' => 'Owner B',
            'email' => 'ownerb@nubibilling.com',
            'phone' => '9000000002',
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

    private function createPngBytes(int $width, int $height, int $red = 30, int $green = 100, int $blue = 220): string
    {
        $img = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, $red, $green, $blue);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $color);
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return (string) $bytes;
    }

    public function test_upload_replace_delete_and_versioned_logo_streaming(): void
    {
        $pngV1 = $this->createPngBytes(128, 96, 15, 90, 200);
        $fileV1 = UploadedFile::fake()->createWithContent('logo-v1.png', $pngV1);

        $uploadResp = $this->actingAs($this->owner)
            ->post("/api/v1/businesses/{$this->businessA}/logo", [
                'logo' => $fileV1,
            ]);

        $uploadResp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.logoWidth', 128)
            ->assertJsonPath('data.logoHeight', 96)
            ->assertJsonPath('data.logoMimeType', 'image/png');

        $v1 = $uploadResp->json('data.logoVersion');
        $v1Path = $uploadResp->json('data.logoPath');
        $this->assertNotEmpty($v1);
        Storage::disk('public')->assertExists($v1Path);

        // Settings and business listing expose updated logo metadata
        $settingsResp = $this->actingAs($this->owner)
            ->getJson("/api/v1/businesses/{$this->businessA}/settings");
        $settingsResp->assertStatus(200)
            ->assertJsonPath('data.logoVersion', $v1)
            ->assertJsonPath('data.logoWidth', 128);

        // Replace with a second logo via base64
        $pngV2 = $this->createPngBytes(200, 100, 220, 40, 40);
        $replaceResp = $this->actingAs($this->owner)
            ->postJson("/api/v1/businesses/{$this->businessA}/logo", [
                'logo_base64' => 'data:image/png;base64,'.base64_encode($pngV2),
            ]);

        $replaceResp->assertStatus(200)
            ->assertJsonPath('data.logoWidth', 200)
            ->assertJsonPath('data.logoHeight', 100);
        $v2 = $replaceResp->json('data.logoVersion');
        $this->assertNotSame($v1, $v2);

        // Active unversioned endpoint serves v2 bytes
        $activeStream = $this->get("/api/v1/businesses/{$this->businessA}/logo");
        $activeStream->assertStatus(200);
        $this->assertSame($pngV2, $activeStream->streamedContent());

        // Historical versioned endpoint still serves v1 bytes!
        $v1Stream = $this->get("/api/v1/businesses/{$this->businessA}/logo?v={$v1}");
        $v1Stream->assertStatus(200);
        $this->assertSame($pngV1, $v1Stream->streamedContent());

        // Deleting active logo clears current active logo while preserving v1/v2 for past bills
        $delResp = $this->actingAs($this->owner)
            ->deleteJson("/api/v1/businesses/{$this->businessA}/logo");
        $delResp->assertStatus(200)
            ->assertJsonPath('data.logoUrl', null)
            ->assertJsonPath('data.logoVersion', null);

        $this->get("/api/v1/businesses/{$this->businessA}/logo")->assertStatus(404);
        $this->get("/api/v1/businesses/{$this->businessA}/logo?v={$v1}")->assertStatus(200);
        $this->get("/api/v1/businesses/{$this->businessA}/logo?v={$v2}")->assertStatus(200);
    }

    public function test_logo_validation_rejects_invalid_format_dimensions_and_cross_business_access(): void
    {
        // 1. Small dimensions (< 64px)
        $tooSmall = $this->createPngBytes(32, 32);
        $smallResp = $this->actingAs($this->owner)
            ->postJson("/api/v1/businesses/{$this->businessA}/logo", [
                'logo_base64' => base64_encode($tooSmall),
            ]);
        $smallResp->assertStatus(422)->assertJsonPath('code', 'LOGO_INVALID_DIMENSIONS');

        // 2. Non-image SVG / text payload
        $badFormat = $this->actingAs($this->owner)
            ->postJson("/api/v1/businesses/{$this->businessA}/logo", [
                'logo_base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><rect width="100" height="100"/></svg>'),
            ]);
        $badFormat->assertStatus(422)->assertJsonPath('code', 'LOGO_INVALID_FORMAT');

        // 3. Cross-business isolation (Owner B cannot upload to Business A)
        $valid = $this->createPngBytes(120, 120);
        $forbiddenResp = $this->actingAs($this->otherOwner)
            ->postJson("/api/v1/businesses/{$this->businessA}/logo", [
                'logo_base64' => base64_encode($valid),
            ]);
        $forbiddenResp->assertStatus(403);
    }

    public function test_invoice_purchase_and_return_capture_immutable_logo_snapshots_and_render_return_pdf(): void
    {
        // Upload logo v1 for Business A
        $pngV1 = $this->createPngBytes(160, 80, 10, 120, 90);
        $v1Resp = $this->actingAs($this->owner)
            ->postJson("/api/v1/businesses/{$this->businessA}/logo", [
                'logo_base64' => base64_encode($pngV1),
            ]);
        $v1Resp->assertStatus(200);
        $v1 = $v1Resp->json('data.logoVersion');

        // Setup minimal customer, supplier, product for Business A
        DB::table('customers')->insert([
            'id' => 'cust_logo_1',
            'document_path' => 'customers/cust_logo_1',
            'business_id' => $this->businessA,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['customerId' => 'cust_logo_1', 'name' => 'Walk-in Buyer']]),
        ]);
        DB::table('suppliers')->insert([
            'id' => 'sup_logo_1',
            'document_path' => 'suppliers/sup_logo_1',
            'business_id' => $this->businessA,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => ['supplierId' => 'sup_logo_1', 'name' => 'Paint Distributors']]),
        ]);
        DB::table('products')->insert([
            'id' => 'prod_logo_1',
            'document_path' => 'products/prod_logo_1',
            'business_id' => $this->businessA,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'payload' => json_encode(['fields' => [
                'productId' => 'prod_logo_1',
                'name' => 'Interior Primer',
                'currentStock' => 100,
                'sellingPrice' => 25000,
                'costPrice' => 18000,
                'unit' => 'LTR',
            ]]),
        ]);

        // Create Invoice under v1
        $invResp = $this->actingAs($this->owner)->postJson('/api/v1/invoices', [
            'business_id' => $this->businessA,
            'outlet_id' => 'OUT-LOGO-A',
            'customer_id' => 'cust_logo_1',
            'customer_name' => 'Walk-in Buyer',
            'lines' => [[
                'product_id' => 'prod_logo_1',
                'product_name' => 'Interior Primer',
                'quantity' => 2,
                'unit_price_paise' => 25000,
                'gst_rate_bps' => 0,
                'unit_symbol' => 'LTR',
            ]],
            'cash_paise' => 50000,
        ]);
        $invResp->assertStatus(201);
        $invoiceId = $invResp->json('data.invoiceId');
        $invShow1 = $this->actingAs($this->owner)->getJson("/api/v1/invoices/{$invoiceId}");
        $invShow1->assertStatus(200)
            ->assertJsonPath('data.hasBusinessLogoSnapshot', true)
            ->assertJsonPath('data.businessLogoVersion', $v1);

        // Create Purchase Bill under v1
        $purResp = $this->actingAs($this->owner)->postJson('/api/v1/purchases', [
            'business_id' => $this->businessA,
            'outlet_id' => 'OUT-LOGO-A',
            'supplier_id' => 'sup_logo_1',
            'supplier_name' => 'Paint Distributors',
            'items' => [[
                'product_id' => 'prod_logo_1',
                'product_name' => 'Interior Primer',
                'quantity' => 5,
                'rate' => 18000,
                'gst_rate_bps' => 0,
            ]],
        ]);
        $purResp->assertStatus(201);
        $purchaseId = $purResp->json('data.purchaseBill.purchaseBillId');
        $purShow = $this->actingAs($this->owner)->getJson("/api/v1/purchases/{$purchaseId}");
        $purShow->assertStatus(200)
            ->assertJsonPath('data.hasBusinessLogoSnapshot', true)
            ->assertJsonPath('data.businessLogoVersion', $v1);

        // Create Return under v1
        $retResp = $this->actingAs($this->owner)->postJson('/api/v1/returns', [
            'business_id' => $this->businessA,
            'type' => 'sale_return',
            'original_bill_id' => $invoiceId,
            'original_bill_no' => $invResp->json('data.invoiceNumber'),
            'party_id' => 'cust_logo_1',
            'party_name' => 'Walk-in Buyer',
            'items' => [[
                'product_id' => 'prod_logo_1',
                'product_name' => 'Interior Primer',
                'qty_returned' => 1,
                'unit_price' => 250,
                'tax_rate' => 0,
            ]],
        ]);
        $retResp->assertStatus(201)
            ->assertJsonPath('data.has_business_logo_snapshot', true)
            ->assertJsonPath('data.business_logo_version', $v1);
        $returnId = $retResp->json('data.id');

        // Replace Business A logo with v2
        $pngV2 = $this->createPngBytes(180, 90, 200, 10, 10);
        $v2Resp = $this->actingAs($this->owner)
            ->postJson("/api/v1/businesses/{$this->businessA}/logo", [
                'logo_base64' => base64_encode($pngV2),
            ]);
        $v2Resp->assertStatus(200);
        $v2 = $v2Resp->json('data.logoVersion');

        // Historical documents STILL retain v1 snapshot!
        $this->actingAs($this->owner)->getJson("/api/v1/invoices/{$invoiceId}")
            ->assertJsonPath('data.businessLogoVersion', $v1);
        $this->actingAs($this->owner)->getJson("/api/v1/purchases/{$purchaseId}")
            ->assertJsonPath('data.businessLogoVersion', $v1);
        $this->actingAs($this->owner)->getJson("/api/v1/returns/{$returnId}")
            ->assertJsonPath('data.business_logo_version', $v1);

        // Business B still has NO logo snapshot
        $snapB = BusinessController::resolveActiveLogoSnapshot($this->businessB);
        $this->assertTrue($snapB['hasBusinessLogoSnapshot']);
        $this->assertNull($snapB['businessLogoUrl']);
        $this->assertNull($snapB['businessLogoVersion']);

        // Return PDF renders cleanly with historical v1 data URI
        $pdfResp = $this->actingAs($this->owner)->get("/api/v1/returns/{$returnId}/pdf");
        $pdfResp->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdfResp->headers->get('Content-Type'));
    }
}
