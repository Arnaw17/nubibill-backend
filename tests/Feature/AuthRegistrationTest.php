<?php

namespace Tests\Feature;

use App\Models\EmailOtp;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Mail::fake();
    }

    private function createAdminUser(): User
    {
        $admin = new User([
            'name' => 'System Admin',
            'email' => 'admin@nubibilling.com',
            'phone' => '9999999999',
            'password' => Hash::make('AdminPass123!'),
            'role' => 'ADMIN',
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $admin->status = 'ACTIVE';
        $admin->save();

        return $admin;
    }

    public function test_register_without_aadhaar_or_pan_returns_422(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '9876543210',
            'address' => '123 Market Street',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonValidationErrors(['aadhaar_file', 'pan_file']);
    }

    public function test_register_ignores_status_payload_and_creates_inactive_user(): void
    {
        $aadhaar = UploadedFile::fake()->create('aadhaar.pdf', 500, 'application/pdf');
        $pan = UploadedFile::fake()->image('pan.jpg');

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Alice Smith',
            'email' => 'alice@example.com',
            'phone' => '9876543211',
            'address' => '456 Commercial Road',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'status' => 'ACTIVE', // Should be ignored
            'role' => 'ADMIN',    // Should be ignored
            'aadhaar_file' => $aadhaar,
            'pan_file' => $pan,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => 'alice@example.com',
                ],
            ]);

        $user = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('INACTIVE', $user->status);
        $this->assertEquals('USER', $user->role);
        $this->assertNull($user->email_verified_at);

        // Verify documents are saved as PENDING
        $this->assertDatabaseHas('user_documents', [
            'user_id' => $user->id,
            'type' => 'AADHAAR',
            'status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('user_documents', [
            'user_id' => $user->id,
            'type' => 'PAN',
            'status' => 'PENDING',
        ]);

        // Verify OTP was created
        $this->assertDatabaseHas('email_otps', [
            'email' => 'alice@example.com',
        ]);
    }

    public function test_register_duplicate_email_or_phone_returns_422(): void
    {
        $user = new User([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'phone' => '9876543210',
            'password' => Hash::make('Secret123!'),
        ]);
        $user->save();

        $aadhaar = UploadedFile::fake()->image('aadhaar.jpg');
        $pan = UploadedFile::fake()->image('pan.jpg');

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Another User',
            'email' => 'existing@example.com',
            'phone' => '9876543210',
            'address' => '789 Road',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'aadhaar_file' => $aadhaar,
            'pan_file' => $pan,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_otp_verification_wrong_five_times_locks_code(): void
    {
        EmailOtp::create([
            'email' => 'locktest@example.com',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        // 4 failed attempts
        for ($i = 0; $i < 4; $i++) {
            $res = $this->postJson('/api/v1/auth/email-otp/verify', [
                'email' => 'locktest@example.com',
                'otp' => '999999',
            ]);
            $res->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'code' => 'OTP_INVALID',
                ]);
        }

        // 5th attempt reaches maximum and returns OTP_LOCKED
        $res5 = $this->postJson('/api/v1/auth/email-otp/verify', [
            'email' => 'locktest@example.com',
            'otp' => '999999',
        ]);
        $res5->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'OTP_LOCKED',
            ]);

        // Subsequent attempt is blocked as OTP_LOCKED even with correct OTP
        $res6 = $this->postJson('/api/v1/auth/email-otp/verify', [
            'email' => 'locktest@example.com',
            'otp' => '123456',
        ]);
        $res6->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'OTP_LOCKED',
            ]);
    }

    public function test_otp_expired_returns_otp_expired(): void
    {
        EmailOtp::create([
            'email' => 'expired@example.com',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinutes(1),
            'attempts' => 0,
            'created_at' => now()->subMinutes(11),
        ]);

        $res = $this->postJson('/api/v1/auth/email-otp/verify', [
            'email' => 'expired@example.com',
            'otp' => '123456',
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'OTP_EXPIRED',
            ]);
    }

    public function test_otp_correct_verifies_email(): void
    {
        $user = new User([
            'name' => 'Verify Test',
            'email' => 'verify@example.com',
            'phone' => '9876543212',
            'password' => Hash::make('Secret123!'),
        ]);
        $user->save();

        EmailOtp::create([
            'email' => 'verify@example.com',
            'code_hash' => Hash::make('654321'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        $res = $this->postJson('/api/v1/auth/email-otp/verify', [
            'email' => 'verify@example.com',
            'otp' => '654321',
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);

        // Verify that OTP is consumed
        $otpRecord = EmailOtp::where('email', 'verify@example.com')->first();
        $this->assertNotNull($otpRecord->consumed_at);
    }

    public function test_otp_resend_cooldown_returns_429(): void
    {
        EmailOtp::create([
            'email' => 'cooldown@example.com',
            'code_hash' => Hash::make('111111'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'created_at' => now()->subSeconds(20), // 20s ago
        ]);

        $res = $this->postJson('/api/v1/auth/email-otp/resend', [
            'email' => 'cooldown@example.com',
        ]);

        $res->assertStatus(429)
            ->assertJson([
                'success' => false,
                'code' => 'THROTTLED',
            ]);
    }

    public function test_login_before_otp_returns_email_not_verified(): void
    {
        $user = new User([
            'name' => 'Unverified User',
            'email' => 'unverified@example.com',
            'phone' => '9876543213',
            'password' => Hash::make('Password123!'),
        ]);
        $user->save();

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'unverified@example.com',
            'password' => 'Password123!',
        ]);

        $res->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'EMAIL_NOT_VERIFIED',
            ]);
    }

    public function test_login_verified_but_inactive_returns_account_inactive(): void
    {
        $user = new User([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'phone' => '9876543214',
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
        ]);
        $user->status = 'INACTIVE';
        $user->save();

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'Password123!',
        ]);

        $res->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'ACCOUNT_INACTIVE',
            ]);
    }

    public function test_admin_activation_blocked_until_documents_approved_then_login_works(): void
    {
        $admin = $this->createAdminUser();
        $adminToken = $admin->createToken('admin-test')->plainTextToken;

        // Register a user
        $user = new User([
            'name' => 'Pending Approval User',
            'email' => 'pending@example.com',
            'phone' => '9876543215',
            'password' => Hash::make('UserPass123!'),
            'email_verified_at' => now(),
        ]);
        $user->status = 'INACTIVE';
        $user->save();

        $aadhaar = UserDocument::create([
            'user_id' => $user->id,
            'type' => 'AADHAAR',
            'file_path' => 'documents/aadhaar.pdf',
            'status' => 'PENDING',
        ]);
        $pan = UserDocument::create([
            'user_id' => $user->id,
            'type' => 'PAN',
            'file_path' => 'documents/pan.jpg',
            'status' => 'PENDING',
        ]);

        // Attempt activation while docs are PENDING -> Must fail (422)
        $failRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->patchJson("/api/v1/admin/users/{$user->id}/status", [
                'status' => 'ACTIVE',
            ]);

        $failRes->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'ACTIVATION_PREREQUISITE_FAILED',
            ]);

        // Admin approves Aadhaar document with masked number
        $aadhaarRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->patchJson("/api/v1/admin/users/{$user->id}/documents/{$aadhaar->id}", [
                'status' => 'APPROVED',
                'number' => '123456789012',
            ]);
        $aadhaarRes->assertStatus(200);

        // Verify Aadhaar only stored last 4 digits
        $aadhaar->refresh();
        $this->assertEquals('APPROVED', $aadhaar->status);
        $this->assertEquals('XXXX-XXXX-9012', $aadhaar->number_encrypted);

        // Still blocked because PAN is not approved
        $failRes2 = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->patchJson("/api/v1/admin/users/{$user->id}/status", [
                'status' => 'ACTIVE',
            ]);
        $failRes2->assertStatus(422);

        // Admin approves PAN document
        $panRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->patchJson("/api/v1/admin/users/{$user->id}/documents/{$pan->id}", [
                'status' => 'APPROVED',
                'number' => 'ABCDE1234F',
            ]);
        $panRes->assertStatus(200);

        // Now activate user
        $activateRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->patchJson("/api/v1/admin/users/{$user->id}/status", [
                'status' => 'ACTIVE',
            ]);
        $activateRes->assertStatus(200);

        $user->refresh();
        $this->assertTrue($user->isActive());

        // Now login succeeds
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'pending@example.com',
            'password' => 'UserPass123!',
        ]);

        $loginRes->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'phone',
                        'status',
                    ],
                ],
            ]);
    }

    public function test_deactivating_logged_in_user_revokes_tokens_and_blocks_next_request(): void
    {
        $admin = $this->createAdminUser();
        $adminToken = $admin->createToken('admin-test')->plainTextToken;

        $user = new User([
            'name' => 'Active Worker',
            'email' => 'worker@example.com',
            'phone' => '9876543216',
            'password' => Hash::make('Worker123!'),
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $user->status = 'ACTIVE';
        $user->save();

        // User logs in and gets token
        $userToken = $user->createToken('worker-token')->plainTextToken;

        // User can access /auth/me
        $meRes = $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->getJson('/api/v1/auth/me');
        if ($meRes->status() !== 200) {
            $this->fail(json_encode($meRes->json()));
        }
        $meRes->assertStatus(200);

        // Admin deactivates user
        auth()->forgetGuards();
        $deactivateRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->patchJson("/api/v1/admin/users/{$user->id}/status", [
                'status' => 'INACTIVE',
            ]);
        $deactivateRes->assertStatus(200);

        // User's next request fails with 401 or 403
        auth()->forgetGuards();
        $meResAfter = $this->withHeader('Authorization', 'Bearer ' . $userToken)
            ->getJson('/api/v1/auth/me');
        $this->assertContains($meResAfter->status(), [401, 403]);
    }

    public function test_non_admin_on_admin_routes_returns_403(): void
    {
        $user = new User([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'phone' => '9876543217',
            'password' => Hash::make('Regular123!'),
            'role' => 'USER',
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $user->status = 'ACTIVE';
        $user->save();

        $token = $user->createToken('regular-token')->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson("/api/v1/admin/users/{$user->id}/status", [
                'status' => 'ACTIVE',
            ]);

        $res->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'FORBIDDEN',
            ]);
    }

    public function test_admin_can_stream_document_file(): void
    {
        $admin = $this->createAdminUser();
        $adminToken = $admin->createToken('admin-test')->plainTextToken;

        $filePath = 'documents/sample.pdf';
        Storage::disk('private')->put($filePath, 'PDF content mock');

        $doc = UserDocument::create([
            'user_id' => $admin->id,
            'type' => 'AADHAAR',
            'file_path' => $filePath,
            'status' => 'PENDING',
        ]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->get("/api/v1/admin/users/{$admin->id}/documents/{$doc->id}/file");

        $res->assertStatus(200);
    }

    public function test_regular_user_cannot_fetch_another_users_document(): void
    {
        $admin = $this->createAdminUser();

        $user = new User([
            'name' => 'Regular User 2',
            'email' => 'regular2@example.com',
            'phone' => '9876543218',
            'password' => Hash::make('Regular123!'),
            'role' => 'USER',
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $user->status = 'ACTIVE';
        $user->save();

        $token = $user->createToken('regular2-token')->plainTextToken;

        $doc = UserDocument::create([
            'user_id' => $admin->id,
            'type' => 'AADHAAR',
            'file_path' => 'documents/admin_doc.pdf',
            'status' => 'PENDING',
        ]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get("/api/v1/admin/users/{$admin->id}/documents/{$doc->id}/file");

        $res->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'FORBIDDEN',
            ]);
    }
}
