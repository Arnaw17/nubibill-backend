<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\EmployeePermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401, null, 'UNAUTHORIZED');
        }

        $authorization = app(EmployeePermissionService::class);
        if (! $authorization->isAuthorized($user, $permissions)) {
            return ApiResponse::error('You do not have permission to perform this operation.', 403, null, 'PERMISSION_DENIED');
        }

        $businessId = $request->input('business_id')
            ?? $request->input('businessId')
            ?? $request->query('business_id')
            ?? $request->query('businessId')
            ?? $request->header('X-Business-Id')
            ?? $request->route('businessId');
        if (! $businessId && $request->is('api/v1/businesses/*/settings')) {
            $businessId = $request->route('id');
        }
        $outletId = $request->input('outlet_id')
            ?? $request->input('outletId')
            ?? $request->query('outlet_id')
            ?? $request->query('outletId')
            ?? $request->header('X-Outlet-Id')
            ?? $request->route('outletId');

        if (strtoupper((string) $user->role) === 'STAFF' && ! $businessId) {
            return ApiResponse::error('A business context is required for this operation.', 403, null, 'PERMISSION_DENIED');
        }

        if ($businessId !== null && ! $user->canAccessBusiness((string) $businessId)) {
            return ApiResponse::error('This business or outlet is not assigned to your account.', 403, null, 'PERMISSION_DENIED');
        }

        if (! $authorization->isWithinAssignedScope(
            $user,
            $businessId !== null ? (string) $businessId : null,
            $outletId !== null ? (string) $outletId : null,
        )) {
            return ApiResponse::error('This business or outlet is not assigned to your account.', 403, null, 'PERMISSION_DENIED');
        }

        return $next($request);
    }
}
