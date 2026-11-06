<?php

namespace Tests\Feature;

use App\Mail\SendOtpMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ForgotPasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerUser;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->ownerUser = new User;
        $this->ownerUser->forceFill([
            'name' => 'Store Owner',
            'email' => 'owner@example.com',
            'phone' => '9876543210',
            'password' => Hash::make('OldPassword123!'),
            'role' => 'USER',
            'status' => 'ACTIVE',
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $this->ownerUser->save();
    }

    public function test_send_forgot_password_otp_dispatches_email_and_does_not_reveal_otp(): void
    {
        config(['app.debug' => true]); // Even with debug mode enabled!

        $res = $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'email' => 'owner@example.com',
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => 'owner@example.com',
                    'expiresInMinutes' => 5,
                ],
            ]);

        // CRITICAL SECURITY ASSERTION: No debug_otp or plain OTP in response
        $this->assertArrayNotHasKey('debug_otp', $res->json('data') ?? []);
        $this->assertArrayNotHasKey('otp', $res->json('data') ?? []);
        $this->assertStringNotContainsString('debug_otp', $res->getContent());

        // Verify Mail dispatched
        Mail::assertSent(SendOtpMail::class, function (SendOtpMail $mail) {
            return $mail->hasTo('owner@example.com')
                && $mail->purpose === EmailOtp::PURPOSE_FORGOT_PASSWORD
                && strlen($mail->otp) === 6
                && $mail->expiresInMinutes === 5;
        });

        // Verify stored in DB
        $this->assertDatabaseHas('email_otps', [
            'email' => 'owner@example.com',
            'purpose' => EmailOtp::PURPOSE_FORGOT_PASSWORD,
            'consumed_at' => null,
            'attempts' => 0,
        ]);
    }

    public function test_send_forgot_password_enforces_60_second_cooldown(): void
    {
        $res1 = $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'email' => 'owner@example.com',
        ]);
        $res1->assertStatus(200);

        // Immediate retry must be throttled with HTTP 429
        $res2 = $this->postJson('/api/v1/auth/forgot-password/send-otp', [
            'email' => 'owner@example.com',
        ]);
        $res2->assertStatus(429)
            ->assertJson([
                'success' => false,
                'code' => 'THROTTLED',
            ]);
    }

    public function test_reset_password_with_valid_otp_succeeds_and_hashes_password(): void
    {
        $otp = '654321';
        EmailOtp::create([
            'email' => 'owner@example.com',
            'purpose' => EmailOtp::PURPOSE_FORGOT_PASSWORD,
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        $res = $this->postJson('/api/v1/auth/forgot-password/reset', [
            'email' => 'owner@example.com',
            'otp' => $otp,
            'password' => 'NewPassword123!',
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Verify OTP is marked consumed
        $this->assertDatabaseMissing('email_otps', [
            'email' => 'owner@example.com',
            'purpose' => EmailOtp::PURPOSE_FORGOT_PASSWORD,
            'consumed_at' => null,
        ]);

        // Verify password in DB is updated
        $this->ownerUser->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $this->ownerUser->password));

        // Verify login with old password fails
        $loginOld = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'OldPassword123!',
        ]);
        $loginOld->assertStatus(401);

        // Verify login with new password succeeds
        $loginNew = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'NewPassword123!',
        ]);
        $loginNew->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_cannot_reuse_already_consumed_otp(): void
    {
        $otp = '789123';
        EmailOtp::create([
            'email' => 'owner@example.com',
            'purpose' => EmailOtp::PURPOSE_FORGOT_PASSWORD,
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'consumed_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(2),
        ]);

        $res = $this->postJson('/api/v1/auth/forgot-password/reset', [
            'email' => 'owner@example.com',
            'otp' => $otp,
            'password' => 'AnotherNewPass1!',
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'OTP_INVALID',
            ]);
    }

    public function test_invalid_otp_increments_attempts_and_locks_after_5_attempts(): void
    {
        $otp = '555666';
        $record = EmailOtp::create([
            'email' => 'owner@example.com',
            'purpose' => EmailOtp::PURPOSE_FORGOT_PASSWORD,
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        // 4 failed attempts
        for ($i = 1; $i <= 4; $i++) {
            $res = $this->postJson('/api/v1/auth/forgot-password/reset', [
                'email' => 'owner@example.com',
                'otp' => '000000',
                'password' => 'NewPassword123!',
            ]);
            $res->assertStatus(422)->assertJson(['code' => 'OTP_INVALID']);
        }

        $record->refresh();
        $this->assertEquals(4, $record->attempts);

        // 5th attempt locks the code
        $res5 = $this->postJson('/api/v1/auth/forgot-password/reset', [
            'email' => 'owner@example.com',
            'otp' => '000000',
            'password' => 'NewPassword123!',
        ]);
        $res5->assertStatus(422)->assertJson(['code' => 'OTP_LOCKED']);

        // 6th attempt with CORRECT OTP is still locked
        $res6 = $this->postJson('/api/v1/auth/forgot-password/reset', [
            'email' => 'owner@example.com',
            'otp' => $otp,
            'password' => 'NewPassword123!',
        ]);
        $res6->assertStatus(422)->assertJson(['code' => 'OTP_LOCKED']);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $otp = '112233';
        EmailOtp::create([
            'email' => 'owner@example.com',
            'purpose' => EmailOtp::PURPOSE_FORGOT_PASSWORD,
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->subMinute(), // Expired
            'attempts' => 0,
            'created_at' => now()->subMinutes(6),
        ]);

        $res = $this->postJson('/api/v1/auth/forgot-password/reset', [
            'email' => 'owner@example.com',
            'otp' => $otp,
            'password' => 'NewPassword123!',
        ]);

        $res->assertStatus(422)->assertJson(['code' => 'OTP_EXPIRED']);
    }

    public function test_registration_otp_cannot_be_used_for_password_reset(): void
    {
        $otp = '999888';
        // Create an OTP explicitly for REGISTRATION
        EmailOtp::create([
            'email' => 'owner@example.com',
            'purpose' => EmailOtp::PURPOSE_REGISTRATION,
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        // Attempt to use it on forgot password reset
        $res = $this->postJson('/api/v1/auth/forgot-password/reset', [
            'email' => 'owner@example.com',
            'otp' => $otp,
            'password' => 'NewPassword123!',
        ]);

        // Must reject because purpose does not match
        $res->assertStatus(422)->assertJson(['code' => 'OTP_INVALID']);
    }

    public function test_security_headers_present_on_api_responses(): void
    {
        $res = $this->getJson('/api/v1/app-config');
        $res->assertStatus(200);

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
