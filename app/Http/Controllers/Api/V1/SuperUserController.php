<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperUserController extends Controller
{
    /**
     * List owner accounts and their KYC review state for the control center.
     */
    public function owners(Request $request): JsonResponse
    {
        $status = strtoupper((string) $request->query('status', 'ALL'));
        $search = strtolower(trim((string) $request->query('search', '')));
        $limit = min(max((int) $request->query('limit', 100), 1), 200);

        $query = User::query()
            ->with(['documents' => fn ($documents) => $documents->orderBy('type')])
            ->whereRaw('UPPER(role) NOT IN (?, ?, ?, ?)', [
                'ADMIN',
                'SUPER_ADMIN',
                'DEVELOPER',
                'SUPER_USER',
            ]);

        if ($status === 'ACTIVE') {
            $query->whereRaw('UPPER(status) = ?', ['ACTIVE']);
        } elseif (in_array($status, ['INACTIVE', 'PENDING'], true)) {
            $query->whereRaw('UPPER(status) != ?', ['ACTIVE']);
        }

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function ($owners) use ($like): void {
                $owners
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhere('phone', 'like', $like);
            });
        }

        $owners = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return ApiResponse::success([
            'owners' => $owners->map(fn (User $owner): array => [
                'id' => (string) $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'status' => strtoupper((string) $owner->status),
                'isActive' => $owner->isActive(),
                'isEmailVerified' => $owner->email_verified_at !== null,
                'emailVerifiedAt' => $owner->email_verified_at?->toIso8601String(),
                'registeredAt' => $owner->created_at?->toIso8601String(),
                'activatedAt' => $owner->activated_at?->toIso8601String(),
                'activatedBy' => $owner->activated_by ? (string) $owner->activated_by : null,
                'documents' => $owner->documents->map(fn ($document): array => [
                    'id' => (string) $document->id,
                    'type' => strtoupper((string) $document->type),
                    'status' => strtoupper((string) $document->status),
                    'rejectReason' => $document->reject_reason,
                    'reviewedAt' => $document->reviewed_at?->toIso8601String(),
                    'hasFile' => $document->file_path !== null,
                ])->values()->all(),
            ])->values()->all(),
            'total' => $owners->count(),
        ], 'Owner accounts retrieved.');
    }

    /**
     * Get live active user sessions with real-time screening.
     */
    public function liveSessions(Request $request): JsonResponse
    {
        $status = strtoupper($request->query('status', 'ALL'));
        $platform = $request->query('platform');
        $businessId = $request->query('business_id');
        $search = strtolower(trim((string) $request->query('search', '')));
        $limit = min(max((int) $request->query('limit', 50), 1), 200);

        $now = Carbon::now();
        $onlineThreshold = $now->copy()->subMinutes(3);

        $query = DB::table('app_user_sessions');

        if ($businessId) {
            $query->where('business_id', $businessId);
        }
        if ($platform && $platform !== 'ALL') {
            $query->where('device_platform', strtolower($platform));
        }

        if ($status === 'ONLINE') {
            $query->where('status', '!=', 'LOGGED_OUT')
                ->where('last_heartbeat_at', '>=', $onlineThreshold);
        } elseif ($status === 'LOGGED_OUT') {
            $query->where('status', 'LOGGED_OUT');
        } elseif ($status === 'IDLE') {
            $query->where('status', '!=', 'LOGGED_OUT')
                ->where('last_heartbeat_at', '<', $onlineThreshold);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where(DB::raw('LOWER(user_name)'), 'like', "%{$search}%")
                    ->orWhere(DB::raw('LOWER(user_email)'), 'like', "%{$search}%")
                    ->orWhere(DB::raw('LOWER(business_name)'), 'like', "%{$search}%")
                    ->orWhere(DB::raw('LOWER(current_screen)'), 'like', "%{$search}%")
                    ->orWhere(DB::raw('LOWER(session_id)'), 'like', "%{$search}%");
            });
        }

        $sessions = $query->orderBy('last_heartbeat_at', 'desc')->limit($limit)->get();

        $data = $sessions->map(function ($s) use ($onlineThreshold) {
            $lastHeartbeat = Carbon::parse($s->last_heartbeat_at);
            $isLiveOnline = ($s->status !== 'LOGGED_OUT' && $lastHeartbeat->gte($onlineThreshold));

            return [
                'sessionId' => $s->session_id,
                'userId' => $s->user_id,
                'userName' => $s->user_name,
                'userEmail' => $s->user_email,
                'role' => $s->role,
                'businessId' => $s->business_id,
                'businessName' => $s->business_name ?? 'Primary Store',
                'outletId' => $s->outlet_id,
                'outletName' => $s->outlet_name ?? 'Main Outlet',
                'platform' => $s->device_platform,
                'deviceInfo' => $s->device_info,
                'appVersion' => $s->app_version,
                'ipAddress' => $s->ip_address,
                'currentScreen' => $s->current_screen,
                'currentAction' => $s->current_action,
                'status' => $s->status === 'LOGGED_OUT' ? 'LOGGED_OUT' : ($isLiveOnline ? 'ONLINE' : 'IDLE'),
                'isLiveOnline' => $isLiveOnline,
                'loginAt' => Carbon::parse($s->login_at)->toIso8601String(),
                'lastHeartbeatAt' => $lastHeartbeat->toIso8601String(),
                'lastActiveAgo' => $lastHeartbeat->diffForHumans(),
                'logoutAt' => $s->logout_at ? Carbon::parse($s->logout_at)->toIso8601String() : null,
                'metadata' => json_decode($s->metadata ?: '{}', true),
            ];
        });

        return ApiResponse::success([
            'sessions' => $data,
            'total' => count($data),
            'onlineCount' => $data->where('isLiveOnline', true)->count(),
        ], 'Live user sessions retrieved.');
    }

    /**
     * Dashboard overview & analytics for developer tracking.
     */
    public function overview(Request $request): JsonResponse
    {
        $now = Carbon::now();
        $onlineThreshold = $now->copy()->subMinutes(3);
        $todayStart = Carbon::today();

        // 1. Live Online Users
        $liveOnlineCount = DB::table('app_user_sessions')
            ->where('status', '!=', 'LOGGED_OUT')
            ->where('last_heartbeat_at', '>=', $onlineThreshold)
            ->count();

        // 2. Today's Total Logins
        $todayLogins = DB::table('app_user_sessions')
            ->where('login_at', '>=', $todayStart)
            ->count();

        // 3. Unique Active Businesses Today
        $activeBusinessesToday = DB::table('app_user_sessions')
            ->where('last_heartbeat_at', '>=', $todayStart)
            ->distinct('business_id')
            ->count('business_id');

        // 4. Live Screen Breakdown (What screens are users viewing right now)
        $liveScreens = DB::table('app_user_sessions')
            ->select('current_screen', DB::raw('count(*) as count'))
            ->where('status', '!=', 'LOGGED_OUT')
            ->where('last_heartbeat_at', '>=', $onlineThreshold)
            ->groupBy('current_screen')
            ->orderBy('count', 'desc')
            ->get();

        // 5. Platform Breakdown
        $platforms = DB::table('app_user_sessions')
            ->select('device_platform', DB::raw('count(*) as count'))
            ->where('last_heartbeat_at', '>=', $todayStart)
            ->groupBy('device_platform')
            ->get();

        // 6. Recent Screen Transitions
        $recentTraces = DB::table('app_screen_traces')
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get()
            ->map(fn ($t) => [
                'screen' => $t->screen_name,
                'previousScreen' => $t->previous_screen,
                'platform' => $t->device_platform,
                'durationSeconds' => $t->duration_seconds,
                'createdAt' => Carbon::parse($t->created_at)->toIso8601String(),
                'timeAgo' => Carbon::parse($t->created_at)->diffForHumans(),
            ]);

        // 7. Active Feature Flags Count
        $activeFlagsCount = DB::table('app_remote_feature_flags')->where('is_enabled', 1)->count();
        $totalFlagsCount = DB::table('app_remote_feature_flags')->count();

        return ApiResponse::success([
            'metrics' => [
                'liveOnlineUsers' => $liveOnlineCount,
                'todayLogins' => $todayLogins,
                'activeBusinessesToday' => max($activeBusinessesToday, 1),
                'activeFeatureFlags' => "$activeFlagsCount / $totalFlagsCount",
            ],
            'liveScreenBreakdown' => $liveScreens,
            'platformDistribution' => $platforms,
            'recentScreenTransitions' => $recentTraces,
            'serverTime' => $now->toIso8601String(),
        ], 'Developer tracking overview generated.');
    }

    /**
     * Get real-time screen traces.
     */
    public function screenTraces(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 200);
        $traces = DB::table('app_screen_traces')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'sessionId' => $t->session_id,
                'userId' => $t->user_id,
                'screenName' => $t->screen_name,
                'previousScreen' => $t->previous_screen,
                'durationSeconds' => $t->duration_seconds,
                'platform' => $t->device_platform,
                'createdAt' => Carbon::parse($t->created_at)->toIso8601String(),
                'timeAgo' => Carbon::parse($t->created_at)->diffForHumans(),
            ]);

        return ApiResponse::success([
            'traces' => $traces,
            'total' => count($traces),
        ], 'Screen traces retrieved.');
    }

    /**
     * List all remote feature flags and kill switches.
     */
    public function featureFlags(Request $request): JsonResponse
    {
        $flags = DB::table('app_remote_feature_flags')
            ->orderBy('category')
            ->orderBy('feature_name')
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'key' => $f->feature_key,
                'name' => $f->feature_name,
                'description' => $f->description,
                'category' => $f->category,
                'isEnabled' => (bool) $f->is_enabled,
                'targetBusinessId' => $f->target_business_id,
                'targetPlatform' => $f->target_platform,
                'minAppVersion' => $f->min_app_version,
                'disabledReason' => $f->disabled_reason,
                'updatedBy' => $f->updated_by,
                'updatedAt' => Carbon::parse($f->updated_at)->toIso8601String(),
            ]);

        return ApiResponse::success([
            'flags' => $flags,
            'total' => count($flags),
        ], 'Feature flags retrieved.');
    }

    /**
     * Remote Toggle Kill Switch for any feature.
     */
    public function toggleFeature(Request $request): JsonResponse
    {
        $featureKey = $request->input('feature_key') ?? $request->input('featureKey');
        $hasEnabled = $request->has('is_enabled') || $request->has('isEnabled');

        if (! $featureKey || ! $hasEnabled) {
            return ApiResponse::error('feature_key and is_enabled (boolean) are required.', 422);
        }

        $isEnabled = $request->has('is_enabled')
            ? $request->boolean('is_enabled')
            : $request->boolean('isEnabled');

        $disabledReason = $request->input('disabled_reason') ?? $request->input('disabledReason');
        $targetBusinessId = $request->input('target_business_id') ?? $request->input('targetBusinessId');

        $now = Carbon::now();
        $user = $request->user();

        $flag = DB::table('app_remote_feature_flags')->where('feature_key', $featureKey)->first();
        if (! $flag) {
            return ApiResponse::error('Feature flag not found.', 404);
        }

        DB::table('app_remote_feature_flags')
            ->where('feature_key', $featureKey)
            ->update([
                'is_enabled' => $isEnabled ? 1 : 0,
                'disabled_reason' => $isEnabled ? null : ($disabledReason ?? 'Disabled by Super User'),
                'target_business_id' => $targetBusinessId ?? $flag->target_business_id,
                'updated_by' => $user?->name ?? 'Super User',
                'updated_at' => $now,
            ]);

        $stateLabel = $isEnabled ? 'ENABLED' : 'DISABLED (KILLED)';

        return ApiResponse::success([
            'feature_key' => $featureKey,
            'is_enabled' => (bool) $isEnabled,
            'state' => $stateLabel,
            'updated_at' => $now->toIso8601String(),
        ], "Feature {$featureKey} successfully {$stateLabel}.");
    }

    /**
     * Create or edit a feature flag.
     */
    public function saveFeature(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'feature_key' => 'required|string',
            'feature_name' => 'required|string',
            'description' => 'nullable|string',
            'category' => 'nullable|string',
            'is_enabled' => 'nullable|boolean',
            'target_business_id' => 'nullable|string',
            'target_platform' => 'nullable|string',
            'disabled_reason' => 'nullable|string',
        ]);

        $now = Carbon::now();
        $user = $request->user();

        DB::table('app_remote_feature_flags')->updateOrInsert(
            ['feature_key' => $validated['feature_key']],
            [
                'feature_name' => $validated['feature_name'],
                'description' => $validated['description'] ?? null,
                'category' => strtoupper($validated['category'] ?? 'MODULE'),
                'is_enabled' => isset($validated['is_enabled']) ? ($validated['is_enabled'] ? 1 : 0) : 1,
                'target_business_id' => $validated['target_business_id'] ?? null,
                'target_platform' => $validated['target_platform'] ?? null,
                'disabled_reason' => $validated['disabled_reason'] ?? null,
                'updated_by' => $user?->name ?? 'Super User',
                'updated_at' => $now,
            ]
        );

        return ApiResponse::success([
            'feature_key' => $validated['feature_key'],
            'updated_at' => $now->toIso8601String(),
        ], 'Feature flag saved successfully.');
    }

    /**
     * Remotely terminate a user session.
     */
    public function terminateSession(Request $request, string $sessionId): JsonResponse
    {
        $now = Carbon::now();
        $updated = DB::table('app_user_sessions')
            ->where('session_id', $sessionId)
            ->update([
                'status' => 'TERMINATED',
                'logout_at' => $now,
                'updated_at' => $now,
            ]);

        if (! $updated) {
            return ApiResponse::error('Session not found.', 404);
        }

        return ApiResponse::success([
            'session_id' => $sessionId,
            'status' => 'TERMINATED',
            'terminated_at' => $now->toIso8601String(),
        ], "Session {$sessionId} has been forcefully terminated.");
    }
}
