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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    /**
     * Handle user registration (multipart).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
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
                'role' => 'USER',
            ]);
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

            // Generate 6-digit OTP
            $otp = (string) random_int(100000, 999999);
            EmailOtp::create([
                'email' => $user->email,
                'code_hash' => Hash::make($otp),
                'expires_at' => now()->addMinutes(10),
                'attempts' => 0,
                'created_at' => now(),
            ]);

            // Send Queued OTP Email
            Mail::to($user->email)->queue(new SendOtpMail($otp));

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
        $email = $request->email;

        // Check 60-second cooldown
        $latestOtp = EmailOtp::where('email', $email)->latest('created_at')->first();
        if ($latestOtp && $latestOtp->created_at->diffInSeconds(now()) < 60) {
            $remaining = 60 - $latestOtp->created_at->diffInSeconds(now());
            return ApiResponse::error(
                "Please wait {$remaining} seconds before requesting a new OTP.",
                429,
                null,
                'THROTTLED'
            );
        }

        // Generate fresh OTP
        $otp = (string) random_int(100000, 999999);
        EmailOtp::create([
            'email' => $email,
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        Mail::to($email)->queue(new SendOtpMail($otp));

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
        $email = $request->email;
        $otp = $request->otp;

        $record = EmailOtp::where('email', $email)
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

        // OTP is valid
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
        $user = User::where('email', $email)->first();

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
            'user' => $user->toApiArray(),
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
}
