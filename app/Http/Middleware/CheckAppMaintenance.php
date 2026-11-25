<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class CheckAppMaintenance
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Allow public config check, OTA updates, superuser/admin routes, and telemetry heartbeats
        if ($request->is('api/v1/app-config') ||
            $request->is('api/v1/updates/*') ||
            $request->is('api/v1/admin/*') ||
            $request->is('api/v1/superuser/*') ||
            $request->is('api/v1/telemetry/*') ||
            $request->is('admin*') ||
            $request->is('up')) {
            return $next($request);
        }

        // 2. Check per-user maintenance override (SuperUser per-user blackout)
        $user = $request->user();
        if ($user && Schema::hasTable('app_user_feature_overrides')) {
            $userMaint = DB::table('app_user_feature_overrides')
                ->where('user_id', $user->id)
                ->where('feature_key', 'maintenance_mode')
                ->where('is_deleted', 0)
                ->first();

            if ($userMaint && (bool) $userMaint->is_enabled) {
                return ApiResponse::error(
                    $userMaint->disabled_reason ?: 'Your account is currently under scheduled maintenance. Please check back shortly.',
                    503,
                    [
                        'maintenanceTitle' => 'Account Maintenance',
                        'maintenanceMessage' => $userMaint->disabled_reason ?: 'Your account is currently under scheduled maintenance.',
                        'isUserMaintenance' => true,
                    ],
                    'USER_MAINTENANCE'
                );
            }
        }

        // 3. Allow system super administrators to access the API during global maintenance
        if ($user && in_array(strtoupper((string) $user->role), ['SUPER_ADMIN', 'SUPERUSER'], true)) {
            return $next($request);
        }

        // 4. Check global maintenance mode from app_remote_feature_flags
        if (Schema::hasTable('app_remote_feature_flags')) {
            $globalFlag = DB::table('app_remote_feature_flags')
                ->where('feature_key', 'app_maintenance_mode')
                ->where('is_deleted', 0)
                ->first();

            if ($globalFlag && (bool) $globalFlag->is_enabled) {
                return ApiResponse::error(
                    $globalFlag->disabled_reason ?: 'The ERP system is currently under maintenance. Please check back shortly.',
                    503,
                    [
                        'maintenanceTitle' => 'System Maintenance',
                        'maintenanceMessage' => $globalFlag->disabled_reason ?: 'The ERP system is currently under maintenance.',
                    ],
                    'SYSTEM_MAINTENANCE'
                );
            }
        }

        // 5. Check system configuration in MySQL app_config
        if (Schema::hasTable('app_config')) {
            $row = DB::table('app_config')->where('id', 'system')->where('is_deleted', 0)->first();
            if ($row) {
                $payload = json_decode($row->payload, true);
                $fields = $payload['fields'] ?? [];
                $isActive = (bool) ($fields['isActive'] ?? true);

                if (! $isActive) {
                    return ApiResponse::error(
                        $fields['maintenanceMessage'] ?? 'The ERP system is currently under maintenance. Please check back shortly.',
                        503,
                        [
                            'maintenanceTitle' => $fields['maintenanceTitle'] ?? 'System Maintenance',
                            'maintenanceMessage' => $fields['maintenanceMessage'] ?? null,
                            'maintenanceImageUrl' => $fields['maintenanceImageUrl'] ?? null,
                        ],
                        'SYSTEM_MAINTENANCE'
                    );
                }
            }
        }

        return $next($request);
    }
}
