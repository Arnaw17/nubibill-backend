<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckAppMaintenance
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Allow public config check and admin routes
        if ($request->is('api/v1/app-config') || $request->is('api/v1/admin/*') || $request->is('admin*') || $request->is('up')) {
            return $next($request);
        }

        // 2. Allow authenticated administrators to access API during maintenance
        $user = $request->user();
        if ($user && in_array(strtoupper((string) $user->role), ['ADMIN', 'SUPER_ADMIN'], true)) {
            return $next($request);
        }

        // 3. Check system configuration in MySQL app_config
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

        return $next($request);
    }
}
