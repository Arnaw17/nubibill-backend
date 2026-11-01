<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelemetryController extends Controller
{
    /**
     * Helper to resolve active feature flags for a business and platform.
     */
    public static function getActiveFlags(?string $businessId = null, ?string $platform = null): array
    {
        $flagsQuery = DB::table('app_remote_feature_flags');
        if ($businessId) {
            $flagsQuery->where(function ($q) use ($businessId) {
                $q->whereNull('target_business_id')
                    ->orWhere('target_business_id', $businessId);
            });
        }
        if ($platform) {
            $flagsQuery->where(function ($q) use ($platform) {
                $q->whereNull('target_platform')
                    ->orWhere('target_platform', $platform);
            });
        }

        $flags = $flagsQuery->get();
        $map = [];
        foreach ($flags as $f) {
            $map[$f->feature_key] = [
                'key' => $f->feature_key,
                'name' => $f->feature_name,
                'category' => $f->category,
                'enabled' => (bool) $f->is_enabled,
                'disabledReason' => $f->disabled_reason,
                'minVersion' => $f->min_app_version,
            ];
        }

        return $map;
    }

    /**
     * Record User Login & initialize tracking session.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'nullable|string',
            'user_id' => 'nullable|string',
            'user_email' => 'nullable|string',
            'user_name' => 'nullable|string',
            'role' => 'nullable|string',
            'business_id' => 'nullable|string',
            'business_name' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'outlet_name' => 'nullable|string',
            'device_platform' => 'nullable|string',
            'device_info' => 'nullable|string',
            'app_version' => 'nullable|string',
            'current_screen' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $user = $request->user();
        $sessionId = $validated['session_id'] ?? (string) Str::uuid();
        $now = Carbon::now();

        $existing = DB::table('app_user_sessions')
            ->where('session_id', $sessionId)
            ->first();
        if ($existing && (string) $existing->user_id !== (string) $user->id) {
            return ApiResponse::error(
                'This telemetry session belongs to another account.',
                409,
                null,
                'SESSION_CONFLICT'
            );
        }

        $sessionData = [
            'session_id' => $sessionId,
            // Identity comes from Sanctum, never from client-supplied telemetry fields.
            'user_id' => (string) $user->id,
            'user_email' => $user->email,
            'user_name' => $user->name,
            'role' => $user->role,
            'business_id' => $validated['business_id'] ?? null,
            'business_name' => $validated['business_name'] ?? null,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'outlet_name' => $validated['outlet_name'] ?? null,
            'device_platform' => strtolower($validated['device_platform'] ?? 'windows'),
            'device_info' => $validated['device_info'] ?? $request->header('User-Agent'),
            'app_version' => $validated['app_version'] ?? '1.0.0',
            'ip_address' => $request->ip(),
            'current_screen' => $validated['current_screen'] ?? 'Dashboard',
            'current_action' => 'User Logged In',
            'status' => 'ONLINE',
            'login_at' => $now,
            'last_heartbeat_at' => $now,
            'logout_at' => null,
            'metadata' => json_encode($validated['metadata'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('app_user_sessions')->updateOrInsert(
            ['session_id' => $sessionId],
            $sessionData
        );

        $flags = self::getActiveFlags($sessionData['business_id'], $sessionData['device_platform']);

        return ApiResponse::success([
            'session_id' => $sessionId,
            'status' => 'ONLINE',
            'login_at' => $now->toIso8601String(),
            'feature_flags' => $flags,
        ], 'Session initiated successfully.');
    }

    /**
     * Ping Heartbeat to update live screening & retrieve latest feature kill-switches.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'current_screen' => 'nullable|string',
            'current_action' => 'nullable|string',
            'business_id' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $sessionId = $validated['session_id'];
        $now = Carbon::now();

        $user = $request->user();
        $session = DB::table('app_user_sessions')->where('session_id', $sessionId)->first();
        if ($session && (string) $session->user_id !== (string) $user->id) {
            return ApiResponse::error('Telemetry session not found.', 404, null, 'NOT_FOUND');
        }

        // A terminated session must not be revived by a stale client heartbeat.
        if ($session && $session->status === 'TERMINATED') {
            $user?->currentAccessToken()?->delete();

            return ApiResponse::success([
                'session_id' => $sessionId,
                'is_terminated' => true,
                'server_time' => $now->toIso8601String(),
                'feature_flags' => self::getActiveFlags($session->business_id, $session->device_platform),
            ], 'Session was terminated by an administrator.');
        }

        $updateData = [
            'last_heartbeat_at' => $now,
            'status' => 'ONLINE',
            'ip_address' => $request->ip(),
            'updated_at' => $now,
        ];

        if (! empty($validated['current_screen'])) {
            $updateData['current_screen'] = $validated['current_screen'];
        }
        if (! empty($validated['current_action'])) {
            $updateData['current_action'] = $validated['current_action'];
        }
        if (! empty($validated['business_id'])) {
            $updateData['business_id'] = $validated['business_id'];
        }
        if (! empty($validated['outlet_id'])) {
            $updateData['outlet_id'] = $validated['outlet_id'];
        }

        if ($session) {
            DB::table('app_user_sessions')
                ->where('session_id', $sessionId)
                ->where('user_id', (string) $user->id)
                ->update($updateData);
            $businessId = $session->business_id;
            $platform = $session->device_platform;
            $isTerminated = false;
        } else {
            // Register session on the fly if not exists
            $updateData['session_id'] = $sessionId;
            $updateData['user_id'] = (string) $user->id;
            $updateData['user_email'] = $user->email;
            $updateData['user_name'] = $user->name;
            $updateData['role'] = $user->role;
            $updateData['device_platform'] = 'windows';
            $updateData['login_at'] = $now;
            $updateData['created_at'] = $now;
            DB::table('app_user_sessions')->insert($updateData);
            $businessId = $validated['business_id'] ?? null;
            $platform = 'windows';
            $isTerminated = false;
        }

        $flags = self::getActiveFlags($businessId, $platform);

        return ApiResponse::success([
            'session_id' => $sessionId,
            'is_terminated' => $isTerminated,
            'server_time' => $now->toIso8601String(),
            'feature_flags' => $flags,
        ], 'Heartbeat received.');
    }

    /**
     * Record screen transition for live screen auditing.
     */
    public function recordScreen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'screen_name' => 'required|string',
            'previous_screen' => 'nullable|string',
            'duration_seconds' => 'nullable|integer',
            'business_id' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $now = Carbon::now();
        $session = DB::table('app_user_sessions')->where('session_id', $validated['session_id'])->first();

        if (! $session || (string) $session->user_id !== (string) $request->user()->id) {
            return ApiResponse::error('Telemetry session not found.', 404, null, 'NOT_FOUND');
        }

        DB::table('app_screen_traces')->insert([
            'session_id' => $validated['session_id'],
            'user_id' => $session?->user_id,
            'business_id' => $validated['business_id'] ?? $session?->business_id,
            'outlet_id' => $validated['outlet_id'] ?? $session?->outlet_id,
            'screen_name' => $validated['screen_name'],
            'previous_screen' => $validated['previous_screen'],
            'duration_seconds' => $validated['duration_seconds'] ?? 0,
            'device_platform' => $session?->device_platform ?? 'windows',
            'metadata' => json_encode($validated['metadata'] ?? []),
            'created_at' => $now,
        ]);

        // Also update session's current screen
        DB::table('app_user_sessions')
            ->where('session_id', $validated['session_id'])
            ->where('user_id', (string) $request->user()->id)
            ->update([
                'current_screen' => $validated['screen_name'],
                'last_heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

        return ApiResponse::success([
            'screen_name' => $validated['screen_name'],
            'recorded_at' => $now->toIso8601String(),
        ], 'Screen transition logged.');
    }

    /**
     * Record User Logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        $now = Carbon::now();
        DB::table('app_user_sessions')
            ->where('session_id', $validated['session_id'])
            ->where('user_id', (string) $request->user()->id)
            ->update([
                'status' => 'LOGGED_OUT',
                'logout_at' => $now,
                'updated_at' => $now,
            ]);

        return ApiResponse::success([
            'session_id' => $validated['session_id'],
            'logout_at' => $now->toIso8601String(),
        ], 'Logged out successfully.');
    }

    /**
     * Get feature flags for the app client.
     */
    public function featureFlags(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->header('X-Business-Id');
        $platform = $request->query('platform') ?? 'windows';

        $flags = self::getActiveFlags($businessId, $platform);

        return ApiResponse::success([
            'flags' => $flags,
            'updated_at' => Carbon::now()->toIso8601String(),
        ], 'Feature flags retrieved.');
    }
}
