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

        if ($authorization->isBillingStaff($user)) {
            if ($request->is([
                '*purchases*',
                '*suppliers*',
                '*supplier-refunds*',
                '*payroll*',
                '*financial*',
                '*reports*',
                '*expenses*',
                '*storage/locations*',
            ]) || ($request->is('*employees*') && ! $request->is('*employees/by-uid*'))) {
                return ApiResponse::error('You do not have permission to perform this operation.', 403, null, 'PERMISSION_DENIED');
            }
        }

        if ($authorization->isAccountant($user)) {
            // 1. Block POS counter billing or creating retail sales slips
            if (($request->is('*invoices*') || $request->is('*bills*')) && ! $request->isMethod('GET')) {
                return ApiResponse::error('Accountant is restricted from POS counter billing and modifying sales.', 403, null, 'PERMISSION_DENIED');
            }

            // 2. Block physical warehouse stock adjustments or godown inventory transfers
            if ($request->is([
                '*storage/locations*',
                '*storage/racks*',
                '*storage/stock-buckets*',
                '*inventory/movements*',
                '*inventory/batches*',
                '*inventory/batch-movements*',
            ]) && ! $request->isMethod('GET')) {
                return ApiResponse::error('Accountant is restricted from physical inventory adjustments and transfers.', 403, null, 'PERMISSION_DENIED');
            }

            // 3. Block system user management, role creation, or granting security permissions
            if (($request->is('*employees*') && ! $request->is('*employees/by-uid*') && ! $request->isMethod('GET'))
                || ($request->is('*roles*') && ! $request->isMethod('GET'))
                || ($request->is('*permissions*') && ! $request->isMethod('GET'))) {
                return ApiResponse::error('Accountant is restricted from user management and security permissions.', 403, null, 'PERMISSION_DENIED');
            }

            // 4. Block core business setting configurations (tax IDs, business profile edits)
            if (($request->is('*businesses/*/settings*') && ! $request->isMethod('GET'))
                || ($request->is('*businesses*') && ! $request->isMethod('GET'))
                || ($request->is('*outlets*') && ! $request->isMethod('GET'))) {
                return ApiResponse::error('Accountant is restricted from modifying business and outlet configurations.', 403, null, 'PERMISSION_DENIED');
            }
        }

        if ($authorization->isManager($user)) {
            // Managers have operational outlet access but cannot alter global settings, tax schemas, or role configurations
            if (($request->is('*businesses/*/settings*') || $request->is('*settings*') || $request->is('*roles*') || $request->is('*permissions*')) && ! $request->isMethod('GET')) {
                return ApiResponse::error('Managers cannot alter global settings, tax schemas, or role configurations.', 403, null, 'PERMISSION_DENIED');
            }
        }

        if ($authorization->isSalesManager($user)) {
            // Sales Managers have retail & POS access but no access to supplier purchases or payroll
            if ($request->is([
                '*purchases*',
                '*suppliers*',
                '*supplier-refunds*',
                '*payroll*',
                '*salary*',
            ])) {
                return ApiResponse::error('Sales Managers are restricted from supplier purchases, vendor ledgers, and payroll.', 403, null, 'PERMISSION_DENIED');
            }
        }

        if ($authorization->isPurchaseManager($user)) {
            // Purchase Managers have procurement access but no retail billing or payroll
            if ((($request->is('*invoices*') || $request->is('*bills*')) && ! $request->isMethod('GET'))
                || $request->is('*payroll*') || $request->is('*salary*')) {
                return ApiResponse::error('Purchase Managers are restricted from retail POS billing and payroll management.', 403, null, 'PERMISSION_DENIED');
            }
        }

        if ($authorization->isInventoryStaff($user)) {
            // Inventory Staff have stock adjustment access but no financial sales, khata, or supplier bills
            if ($request->is([
                '*invoices*',
                '*bills*',
                '*customers*',
                '*purchases*',
                '*suppliers*',
                '*supplier-refunds*',
                '*payments*',
                '*financial*',
                '*expenses*',
                '*payroll*',
                '*salary*',
            ])) {
                return ApiResponse::error('Inventory Staff are restricted from financial sales, customer khata, and supplier bills.', 403, null, 'PERMISSION_DENIED');
            }
        }

        if ($authorization->isGodownManager($user)) {
            // Godown Managers have warehouse logistics access but no retail billing counters or financial accounts
            if ($request->is([
                '*invoices*',
                '*bills*',
                '*customers*',
                '*payments*',
                '*financial*',
                '*expenses*',
                '*payroll*',
                '*salary*',
            ])) {
                return ApiResponse::error('Godown Managers are restricted from retail billing counters and financial accounts.', 403, null, 'PERMISSION_DENIED');
            }
        }

        if ($authorization->isSupplierManager($user)) {
            // Supplier Managers have vendor CRUD & ledger access but no retail sales or inventory adjustments
            if ($request->is([
                '*invoices*',
                '*bills*',
                '*pos*',
            ]) || ($request->is(['*inventory/adjust*', '*inventory/transfer*', '*storage*']) && ! $request->isMethod('GET'))) {
                return ApiResponse::error('Supplier Managers are restricted from retail sales and physical inventory adjustments.', 403, null, 'PERMISSION_DENIED');
            }
        }

        if ($authorization->isReportsViewer($user)) {
            // Reports Viewers have strictly read-only access
            if (! $request->isMethod('GET')) {
                return ApiResponse::error('Reports Viewers have strictly read-only access and cannot modify or create records.', 403, null, 'PERMISSION_DENIED');
            }
        }

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

        if (strtoupper((string) $user->role) === 'STAFF' && ! $outletId) {
            $outletId = $authorization->defaultOutletFor($user, $businessId);
            if ($outletId) {
                $request->headers->set('X-Outlet-Id', $outletId);
            }
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
