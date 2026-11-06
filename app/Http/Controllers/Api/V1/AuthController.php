<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Responses\ApiResponse;
use App\Mail\SendOtpMail;
use App\Models\EmailOtp;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\EmployeePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    /**
     * Handle user registration (multipart).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $registrationEnabled = DB::table('app_remote_feature_flags')
            ->where('feature_key', 'registration')
            ->value('is_enabled');

        if ($registrationEnabled === null || ! (bool) $registrationEnabled) {
            return ApiResponse::error(
                'New user registration is temporarily unavailable. Please try again later.',
                503,
                null,
                'REGISTRATION_DISABLED'
            );
        }

        return DB::transaction(function () use ($request) {
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photoPath = $request->file('photo')->store('photos', 'private');
            }

            // Create user with forced INACTIVE status and USER role
            $user = new User([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'photo_path' => $photoPath,
                'password' => Hash::make($request->password),
            ]);
            $user->role = 'USER';
            $user->status = 'INACTIVE'; // Explicitly enforce INACTIVE regardless of request
            $user->save();

            // Store Aadhaar Document
            $aadhaarPath = $request->file('aadhaar_file')->store('documents', 'private');
            UserDocument::create([
                'user_id' => $user->id,
                'type' => 'AADHAAR',
                'file_path' => $aadhaarPath,
                'status' => 'PENDING',
            ]);

            // Store PAN Document
            $panPath = $request->file('pan_file')->store('documents', 'private');
            UserDocument::create([
                'user_id' => $user->id,
                'type' => 'PAN',
                'file_path' => $panPath,
                'status' => 'PENDING',
            ]);

            // Store Voter Card Document (Optional)
            if ($request->hasFile('voter_file')) {
                $voterPath = $request->file('voter_file')->store('documents', 'private');
                UserDocument::create([
                    'user_id' => $user->id,
                    'type' => 'VOTER',
                    'file_path' => $voterPath,
                    'status' => 'PENDING',
                ]);
            }

            // Generate cryptographically secure 6-digit OTP (5-minute expiration)
            $otp = (string) random_int(100000, 999999);
            EmailOtp::create([
                'email' => $user->email,
                'purpose' => EmailOtp::PURPOSE_REGISTRATION,
                'code_hash' => Hash::make($otp),
                'expires_at' => now()->addMinutes(5),
                'attempts' => 0,
                'created_at' => now(),
            ]);

            // Dispatch OTP email through configured SMTP provider
            try {
                Mail::to($user->email)->send(new SendOtpMail($otp, EmailOtp::PURPOSE_REGISTRATION, 5));
            } catch (\Throwable $e) {
                Log::error("SMTP delivery failure for registration OTP to [{$user->email}]: " . $e->getMessage());
                // Throw exception so DB transaction rolls back cleanly
                throw new \RuntimeException('Unable to send the verification email right now. Please try again later.');
            }

            return ApiResponse::success(
                ['email' => $user->email],
                'Registration successful. Please verify your email with the OTP sent.',
                201
            );
        });
    }

    /**
     * Resend verification OTP with 60-second cooldown.
     */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->email));

        // Check 60-second cooldown
        $latestOtp = EmailOtp::where('email', $email)
            ->where('purpose', EmailOtp::PURPOSE_REGISTRATION)
            ->latest('created_at')
            ->first();

        if ($latestOtp && $latestOtp->created_at->diffInSeconds(now()) < 60) {
            $remaining = 60 - $latestOtp->created_at->diffInSeconds(now());

            return ApiResponse::error(
                "Please wait {$remaining} seconds before requesting a new OTP.",
                429,
                null,
                'THROTTLED'
            );
        }

        // Invalidate older unconsumed OTPs for this email address and purpose
        EmailOtp::where('email', $email)
            ->where('purpose', EmailOtp::PURPOSE_REGISTRATION)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        // Generate fresh 6-digit OTP (5-minute expiration)
        $otp = (string) random_int(100000, 999999);
        EmailOtp::create([
            'email' => $email,
            'purpose' => EmailOtp::PURPOSE_REGISTRATION,
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        try {
            Mail::to($email)->send(new SendOtpMail($otp, EmailOtp::PURPOSE_REGISTRATION, 5));
        } catch (\Throwable $e) {
            Log::error("SMTP delivery failure for resend OTP to [{$email}]: " . $e->getMessage());

            return ApiResponse::error(
                'Unable to send the verification email right now. Please try again later.',
                500,
                null,
                'EMAIL_DELIVERY_FAILED'
            );
        }

        return ApiResponse::success(
            null,
            'A new verification code has been sent to your email.'
        );
    }

    /**
     * Verify email with OTP.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->email));
        $otp = trim((string) $request->otp);

        $record = EmailOtp::where('email', $email)
            ->where('purpose', EmailOtp::PURPOSE_REGISTRATION)
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if (! $record) {
            return ApiResponse::error(
                'No pending verification code found. Please request a new one.',
                422,
                null,
                'OTP_INVALID'
            );
        }

        if ($record->isLocked()) {
            return ApiResponse::error(
                'Verification code locked due to too many failed attempts. Please request a new one.',
                422,
                null,
                'OTP_LOCKED'
            );
        }

        if ($record->isExpired()) {
            return ApiResponse::error(
                'Verification code has expired. Please request a new one.',
                422,
                null,
                'OTP_EXPIRED'
            );
        }

        if (! Hash::check($otp, $record->code_hash)) {
            $record->increment('attempts');
            if ($record->attempts >= 5) {
                return ApiResponse::error(
                    'Maximum verification attempts exceeded. Code has been locked.',
                    422,
                    null,
                    'OTP_LOCKED'
                );
            }

            return ApiResponse::error(
                'Invalid verification code. Please check and try again.',
                422,
                null,
                'OTP_INVALID'
            );
        }

        // OTP is valid - consume immediately
        $record->update(['consumed_at' => now()]);

        $user = User::where('email', $email)->first();
        if ($user && ! $user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        return ApiResponse::success(
            null,
            'Email verified successfully.'
        );
    }

    /**
     * Authenticate user.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->email));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return ApiResponse::error(
                'Invalid email or password.',
                401,
                null,
                'INVALID_CREDENTIALS'
            );
        }

        if (! $user->email_verified_at) {
            return ApiResponse::error(
                'Your email has not been verified yet.',
                403,
                null,
                'EMAIL_NOT_VERIFIED'
            );
        }

        if (! $user->isActive()) {
            return ApiResponse::error(
                'Your account is awaiting administrator approval.',
                403,
                null,
                'ACCOUNT_INACTIVE'
            );
        }

        $token = $user->createToken('auth-token', ['db:read', 'db:write', 'sync:push'])->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => [
                ...$user->toApiArray(),
                'permissions' => app(EmployeePermissionService::class)->permissionsFor($user) ?? [],
            ],
        ], 'Login successful.');
    }

    /**
     * Get authenticated user details.
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($request->user()->toApiArray());
    }

    /**
     * Log out authenticated user.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    /**
     * Send password reset OTP for an owner account.
     */
    public function sendForgotPasswordOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        if (! $user) {
            return ApiResponse::error('No account found with this email address.', 404, null, 'USER_NOT_FOUND');
        }

        if (strtoupper((string) $user->role) === 'STAFF') {
            return ApiResponse::error('Staff password resets must be performed by the store owner or administrator in Users & Staff.', 403, null, 'STAFF_ACCOUNT');
        }

        // Check 60-second cooldown
        $latestOtp = EmailOtp::where('email', $email)
            ->where('purpose', EmailOtp::PURPOSE_FORGOT_PASSWORD)
            ->latest('created_at')
            ->first();

        if ($latestOtp && $latestOtp->created_at->diffInSeconds(now()) < 60) {
            $remaining = 60 - $latestOtp->created_at->diffInSeconds(now());

            return ApiResponse::error(
                "Please wait {$remaining} seconds before requesting a new OTP.",
                429,
                null,
                'THROTTLED'
            );
        }

        // Invalidate older unconsumed forgot password OTPs
        EmailOtp::where('email', $email)
            ->where('purpose', EmailOtp::PURPOSE_FORGOT_PASSWORD)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        // Generate fresh 6-digit OTP (5-minute expiration)
        $otp = (string) random_int(100000, 999999);
        EmailOtp::create([
            'email' => $email,
            'purpose' => EmailOtp::PURPOSE_FORGOT_PASSWORD,
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        try {
            Mail::to($email)->send(new SendOtpMail($otp, EmailOtp::PURPOSE_FORGOT_PASSWORD, 5));
        } catch (\Throwable $e) {
            Log::error("SMTP delivery failure for forgot password OTP to [{$email}]: " . $e->getMessage());

            return ApiResponse::error(
                'Unable to send the verification email right now. Please try again later.',
                500,
                null,
                'EMAIL_DELIVERY_FAILED'
            );
        }

        // Never expose OTP or debug_otp in the response
        $responseData = [
            'email' => $email,
            'expiresInMinutes' => 5,
        ];

        return ApiResponse::success(
            $responseData,
            'Password reset verification code sent to your registered email address.'
        );
    }

    /**
     * Reset password using verified OTP for an owner account.
     */
    public function resetPasswordWithOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:8|max:255',
        ]);

        $email = strtolower(trim($validated['email']));
        $otp = trim($validated['otp']);
        $newPassword = $validated['password'];

        $user = User::where('email', $email)->first();
        if (! $user) {
            return ApiResponse::error('User account not found.', 404, null, 'USER_NOT_FOUND');
        }

        if (strtoupper((string) $user->role) === 'STAFF') {
            return ApiResponse::error('Staff password resets must be performed by the store owner or administrator.', 403, null, 'STAFF_ACCOUNT');
        }

        $record = EmailOtp::where('email', $email)
            ->where('purpose', EmailOtp::PURPOSE_FORGOT_PASSWORD)
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if (! $record) {
            return ApiResponse::error(
                'No pending verification code found. Please request a new one.',
                422,
                null,
                'OTP_INVALID'
            );
        }

        if ($record->isLocked()) {
            return ApiResponse::error(
                'Verification code locked due to too many failed attempts. Please request a new one.',
                422,
                null,
                'OTP_LOCKED'
            );
        }

        if ($record->isExpired()) {
            return ApiResponse::error(
                'Verification code has expired. Please request a new one.',
                422,
                null,
                'OTP_EXPIRED'
            );
        }

        if (! Hash::check($otp, $record->code_hash)) {
            $record->increment('attempts');
            if ($record->attempts >= 5) {
                return ApiResponse::error(
                    'Maximum verification attempts exceeded. Code has been locked.',
                    422,
                    null,
                    'OTP_LOCKED'
                );
            }

            return ApiResponse::error(
                'Invalid verification code. Please check and try again.',
                422,
                null,
                'OTP_INVALID'
            );
        }

        // OTP is valid, consume it immediately
        $record->update(['consumed_at' => now()]);

        // Securely update user password with Hash::make
        $user->password = Hash::make($newPassword);
        if (! $user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->save();

        // Invalidate all existing personal access tokens for security
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        return ApiResponse::success(
            ['email' => $email],
            'Password has been reset successfully. You can now log in with your new password.'
        );
    }
}
