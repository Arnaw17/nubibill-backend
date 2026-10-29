<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    public function showByUid(Request $request, string $uid): JsonResponse
    {
        $row = DB::table('employees')->where('is_deleted', 0)->get()->first(function ($employeeRow) use ($uid, $request): bool {
            $payload = json_decode($employeeRow->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            return (string) ($fields['uid'] ?? '') === $uid
                && ((string) $request->user()->id === $uid
                    || (string) ($fields['ownerUid'] ?? '') === (string) $request->user()->id);
        });

        if (! $row) {
            return ApiResponse::error('Employee not found.', 404, null, 'NOT_FOUND');
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;

        return ApiResponse::success([
            ...$fields,
            'employeeId' => (string) $row->id,
            'uid' => (string) ($fields['uid'] ?? ''),
            'ownerUid' => (string) ($fields['ownerUid'] ?? ''),
            'status' => (string) ($row->status ?? $fields['status'] ?? 'active'),
        ], 'Employee details retrieved.');
    }

    /**
     * List employees for a business or outlet.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $status = strtolower((string) ($request->query('status') ?? 'all'));
        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));

        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        if (! $businessId) {
            return ApiResponse::success([], 'No active business found.');
        }

        $query = DB::table('employees')->where('is_deleted', 0);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $employees = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $bIds = $fields['businessIds'] ?? [];
            if (! is_array($bIds)) {
                $bIds = [$bIds];
            }
            if ($row->business_id && ! in_array($row->business_id, $bIds)) {
                $bIds[] = $row->business_id;
            }

            // Scope check: must belong to business
            if (! in_array($businessId, $bIds)) {
                continue;
            }

            // Outlet filter
            $oIds = $fields['outletIds'] ?? [];
            if (! is_array($oIds)) {
                $oIds = [$oIds];
            }
            if ($row->outlet_id && ! in_array($row->outlet_id, $oIds)) {
                $oIds[] = $row->outlet_id;
            }

            if ($outletId && ! in_array($outletId, $oIds)) {
                continue;
            }

            $name = (string) ($fields['name'] ?? '');
            $email = (string) ($fields['email'] ?? '');
            $mobile = (string) ($fields['mobile'] ?? $fields['phone'] ?? '');

            if ($search) {
                $target = strtolower("{$name} {$email} {$mobile}");
                if (! str_contains($target, strtolower($search))) {
                    continue;
                }
            }

            $employees[] = [
                'employeeId' => (string) $row->id,
                'id' => (string) $row->id,
                'uid' => (string) ($fields['uid'] ?? ''),
                'ownerUid' => (string) ($fields['ownerUid'] ?? $row->business_id ?? ''),
                'name' => $name,
                'email' => $email,
                'mobile' => $mobile,
                'phone' => $mobile,
                'status' => (string) ($row->status ?? $fields['status'] ?? 'active'),
                'roleIds' => $fields['roleIds'] ?? (isset($fields['roleId']) ? [$fields['roleId']] : []),
                'permissionIds' => $fields['permissionIds'] ?? [],
                'businessIds' => $bIds,
                'outletIds' => $oIds,
                'employeeCode' => $fields['employeeCode'] ?? null,
                'joiningDate' => $fields['joiningDate'] ?? null,
                'loginEnabled' => (bool) ($fields['loginEnabled'] ?? (! empty($fields['uid']) && ! empty($fields['email']))),
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        }

        return ApiResponse::success($employees, 'Employees retrieved successfully.');
    }

    /**
     * Show a single employee.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $row = DB::table('employees')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Employee not found.', 404, null, 'NOT_FOUND');
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;

        if (strtoupper((string) $request->user()->role) === 'STAFF') {
            $businessId = (string) ($request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id') ?? '');
            $outletId = (string) ($request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id') ?? '');
            $businessIds = $fields['businessIds'] ?? [];
            $outletIds = $fields['outletIds'] ?? [];
            if (! is_array($businessIds)) {
                $businessIds = [$businessIds];
            }
            if (! is_array($outletIds)) {
                $outletIds = [$outletIds];
            }
            if ($businessId === '' || ! in_array($businessId, $businessIds, true)
                || ($outletId !== '' && strtoupper($outletId) !== 'ALL' && ! in_array($outletId, $outletIds, true))) {
                return ApiResponse::error('Employee not found.', 404, null, 'NOT_FOUND');
            }
        }

        $bIds = $fields['businessIds'] ?? [];
        if (! is_array($bIds)) {
            $bIds = [$bIds];
        }
        $oIds = $fields['outletIds'] ?? [];
        if (! is_array($oIds)) {
            $oIds = [$oIds];
        }

        $mobile = (string) ($fields['mobile'] ?? $fields['phone'] ?? '');

        return ApiResponse::success([
            'employeeId' => (string) $row->id,
            'id' => (string) $row->id,
            'uid' => (string) ($fields['uid'] ?? ''),
            'ownerUid' => (string) ($fields['ownerUid'] ?? ''),
            'name' => (string) ($fields['name'] ?? ''),
            'email' => (string) ($fields['email'] ?? ''),
            'mobile' => $mobile,
            'phone' => $mobile,
            'status' => (string) ($row->status ?? $fields['status'] ?? 'active'),
            'roleIds' => $fields['roleIds'] ?? [],
            'permissionIds' => $fields['permissionIds'] ?? [],
            'businessIds' => $bIds,
            'outletIds' => $oIds,
            'employeeCode' => $fields['employeeCode'] ?? null,
            'joiningDate' => $fields['joiningDate'] ?? null,
            'loginEnabled' => (bool) ($fields['loginEnabled'] ?? (! empty($fields['uid']) && ! empty($fields['email']))),
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ], 'Employee details retrieved.');
    }

    /**
     * Create a new employee.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isStaffCreator = strtoupper((string) $user->role) === 'STAFF';
        $creatorEmployee = $isStaffCreator ? $this->employeeForUser($user) : null;
        if ($isStaffCreator && $creatorEmployee === null) {
            return ApiResponse::error('Your employee authorization record is unavailable.', 403, null, 'FORBIDDEN');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required_if:loginEnabled,true|nullable|string|email|max:255',
            'mobile' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:30',
            'roleIds' => 'nullable|array',
            'roleIds.*' => 'string',
            'roleId' => 'nullable|string',
            'permissionIds' => 'nullable|array',
            'permissionIds.*' => 'string',
            'businessIds' => 'nullable|array',
            'businessIds.*' => 'string',
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outletIds' => 'nullable|array',
            'outletIds.*' => 'string',
            'outlet_id' => 'nullable|string',
            'outletId' => 'nullable|string',
            'employeeCode' => 'nullable|string|max:50',
            'joiningDate' => 'nullable|string',
            'loginEnabled' => 'nullable|boolean',
            'password' => 'required_if:loginEnabled,true|string|min:6|max:255',
            'status' => 'nullable|string|in:active,inactive,ACTIVE,INACTIVE',
        ]);

        $ownerUid = $isStaffCreator
            ? (string) ($creatorEmployee['fields']['ownerUid'] ?? '')
            : (string) $user->id;

        $name = trim($validated['name']);
        $email = ! empty($validated['email']) ? trim(strtolower($validated['email'])) : '';
        $mobile = ! empty($validated['mobile']) ? trim($validated['mobile']) : (! empty($validated['phone']) ? trim($validated['phone']) : '');
        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');

        $businessIds = $validated['businessIds'] ?? ($businessId ? [$businessId] : []);
        $outletIds = $validated['outletIds'] ?? [];
        if (! empty($validated['outlet_id']) && ! in_array($validated['outlet_id'], $outletIds)) {
            $outletIds[] = $validated['outlet_id'];
        }
        if (! empty($validated['outletId']) && ! in_array($validated['outletId'], $outletIds)) {
            $outletIds[] = $validated['outletId'];
        }

        // Canonicalize legacy database row IDs and public business/outlet IDs
        // before comparing employee assignments or persisting the new account.
        $businessIds = $this->canonicalBusinessIds($businessIds);
        $outletIds = $this->canonicalOutletIds($outletIds);

        $roleIds = $validated['roleIds'] ?? (! empty($validated['roleId']) ? [$validated['roleId']] : ['ROLE_STAFF']);
        $permissionIds = $validated['permissionIds'] ?? [];

        if ($isStaffCreator) {
            $assignedBusinesses = $this->canonicalBusinessIds(array_merge(
                $this->asStringList($creatorEmployee['fields']['businessIds'] ?? []),
                $this->asStringList($creatorEmployee['fields']['businessId'] ?? $creatorEmployee['fields']['business_id'] ?? []),
            ));
            $assignedOutlets = $this->canonicalOutletIds(array_merge(
                $this->asStringList($creatorEmployee['fields']['outletIds'] ?? []),
                $this->asStringList($creatorEmployee['fields']['outletId'] ?? $creatorEmployee['fields']['outlet_id'] ?? []),
            ));
            if ($ownerUid === '' || $businessIds === [] || $outletIds === []
                || array_diff($businessIds, $assignedBusinesses) !== []
                || array_diff($outletIds, $assignedOutlets) !== []) {
                return ApiResponse::error('Employees can only be assigned within your business and outlet.', 403, null, 'FORBIDDEN');
            }

            $creatorPermissions = app(\App\Services\EmployeePermissionService::class)->permissionsFor($user);
            if (! $this->staffMayAssignRoles($roleIds)) {
                return ApiResponse::error('One or more selected roles are reserved for owners or administrators.', 403, null, 'FORBIDDEN');
            }
            $candidatePermissions = array_map(
                fn (string $permission): string => $this->normalizePermissionIdentifier($permission),
                $permissionIds,
            );
            if ($creatorPermissions === null || array_diff(
                array_unique(array_map(fn (string $permission): string => $this->normalizePermissionIdentifier($permission), $candidatePermissions)),
                array_map(fn (string $permission): string => $this->normalizePermissionIdentifier($permission), $creatorPermissions),
            ) !== []) {
                return ApiResponse::error('You cannot assign permissions you do not have.', 403, null, 'FORBIDDEN');
            }
        } elseif (! $user->isAdmin() && ! $this->ownsBusinesses($user, $businessIds)) {
            return ApiResponse::error('You may assign employees only to your own businesses.', 403, null, 'FORBIDDEN');
        }
        if (! $this->hasActiveAssignments($roleIds, $permissionIds)
            || ! $this->outletsBelongToBusinesses($outletIds, $businessIds)) {
            return ApiResponse::error('The selected roles, permissions, or outlets are unavailable.', 422, null, 'INVALID_ASSIGNMENT');
        }

        // Check duplicate email
        if ($email) {
            $existingEmail = DB::table('employees')
                ->where('is_deleted', 0)
                ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.email'))) = ?", [$email])
                ->exists();

            if ($existingEmail) {
                return ApiResponse::error("An employee with email '{$email}' already exists.", 422, null, 'duplicate_email');
            }
        }

        // Check duplicate mobile
        if ($mobile) {
            $existingMobile = DB::table('employees')
                ->where('is_deleted', 0)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.mobile')) = ? OR JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.phone')) = ?", [$mobile, $mobile])
                ->exists();

            if ($existingMobile) {
                return ApiResponse::error("An employee with phone '{$mobile}' already exists.", 422, null, 'duplicate_phone');
            }
        }

        $now = now()->toIso8601String();
        $nowDateTime = now();
        $employeeId = 'EMP-'.strtoupper(Str::random(32));
        $loginEnabled = (bool) ($validated['loginEnabled'] ?? true);
        if ($loginEnabled && User::where('email', $email)->exists()) {
            return ApiResponse::error('An account with this email already exists.', 422, null, 'duplicate_email');
        }

        $uid = '';

        $status = ! empty($validated['status']) ? strtolower($validated['status']) : 'active';

        $fields = [
            'employeeId' => $employeeId,
            'uid' => $uid,
            'ownerUid' => $ownerUid,
            'name' => $name,
            'email' => $email,
            'mobile' => $mobile,
            'phone' => $mobile,
            'status' => $status,
            'roleIds' => $roleIds,
            'permissionIds' => $permissionIds,
            'businessIds' => $businessIds,
            'outletIds' => $outletIds,
            'employeeCode' => $validated['employeeCode'] ?? null,
            'joiningDate' => $validated['joiningDate'] ?? $now,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];

        DB::transaction(function () use ($loginEnabled, $email, $name, $mobile, $businessIds, $validated, $employeeId, $status, $nowDateTime, &$uid, &$fields): void {
            if ($loginEnabled) {
                $staffUser = new User;
                $staffUser->name = $name;
                $staffUser->email = $email;
                $staffUser->phone = $mobile;
                $staffUser->password = $validated['password'];
                $staffUser->role = 'STAFF';
                $staffUser->status = $status === 'active' ? 'ACTIVE' : 'INACTIVE';
                $staffUser->email_verified_at = now();
                $staffUser->activated_at = $status === 'active' ? now() : null;
                $staffUser->business_id = $businessIds[0] ?? null;
                $staffUser->save();
                $uid = (string) $staffUser->id;
                $fields['uid'] = $uid;
            }

            DB::table('employees')->insert([
                'id' => $employeeId,
                'document_path' => "employees/{$employeeId}",
                'business_id' => $businessIds[0] ?? null,
                'outlet_id' => $outletIds[0] ?? null,
                'status' => $status,
                'created_at' => $nowDateTime,
                'updated_at' => $nowDateTime,
                'is_deleted' => 0,
                'payload' => json_encode([
                    '_documentId' => $employeeId,
                    '_documentPath' => "employees/{$employeeId}",
                    'fields' => $fields,
                ]),
            ]);
        });

        return ApiResponse::success($fields, "Employee '{$name}' created successfully.", 201);
    }

    /**
     * Update existing employee.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|string|email|max:255',
            'mobile' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:30',
            'roleIds' => 'nullable|array',
            'roleIds.*' => 'string',
            'permissionIds' => 'nullable|array',
            'permissionIds.*' => 'string',
            'businessIds' => 'nullable|array',
            'businessIds.*' => 'string',
            'outletIds' => 'nullable|array',
            'outletIds.*' => 'string',
            'employeeCode' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,inactive,ACTIVE,INACTIVE',
            'password' => 'nullable|string|min:6|max:255',
        ]);

        $row = DB::table('employees')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Employee not found.', 404, null, 'NOT_FOUND');
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;
        $actor = $request->user();
        if ((string) ($fields['ownerUid'] ?? '') !== (string) $actor->id) {
            return ApiResponse::error('You are not authorized to update this employee.', 403, null, 'FORBIDDEN');
        }

        $candidateRoleIds = $validated['roleIds'] ?? ($fields['roleIds'] ?? []);
        $candidatePermissionIds = $validated['permissionIds'] ?? ($fields['permissionIds'] ?? []);
        $storedRoleIds = $fields['roleIds'] ?? (isset($fields['roleId']) ? [$fields['roleId']] : []);
        $candidateRoleIds = is_array($candidateRoleIds) ? $candidateRoleIds : [$candidateRoleIds];
        $storedRoleIds = is_array($storedRoleIds) ? $storedRoleIds : [$storedRoleIds];
        $candidatePermissionIds = is_array($candidatePermissionIds) ? $candidatePermissionIds : [];
        $roleAssignmentsChanged = array_values($candidateRoleIds) !== array_values($storedRoleIds);
        if ($roleAssignmentsChanged) {
            // Older Flutter forms copied role permissions into permissionIds.
            // Remove those inherited grants when roles change so the old role
            // cannot keep authorizing this employee as a direct grant.
            $inheritedPermissions = $this->permissionsForRoles($storedRoleIds);
            $candidatePermissionIds = array_values(array_filter(
                $candidatePermissionIds,
                fn (string $permission): bool => ! in_array($this->normalizePermissionIdentifier($permission), $inheritedPermissions, true),
            ));
        }
        $candidateBusinessIds = $validated['businessIds'] ?? ($fields['businessIds'] ?? []);
        $candidateOutletIds = $validated['outletIds'] ?? ($fields['outletIds'] ?? []);
        if (! $this->hasActiveAssignments($candidateRoleIds, $candidatePermissionIds)
            || ! $this->ownsBusinesses($request->user(), $candidateBusinessIds)
            || ! $this->outletsBelongToBusinesses($candidateOutletIds, $candidateBusinessIds)) {
            return ApiResponse::error('The selected roles, permissions, businesses, or outlets are unavailable.', 422, null, 'INVALID_ASSIGNMENT');
        }

        if (isset($validated['name'])) {
            $fields['name'] = trim($validated['name']);
        }
        if (array_key_exists('email', $validated)) {
            $fields['email'] = ! empty($validated['email']) ? trim(strtolower($validated['email'])) : '';
        }
        if (isset($validated['mobile']) || isset($validated['phone'])) {
            $mob = $validated['mobile'] ?? $validated['phone'];
            $fields['mobile'] = $mob;
            $fields['phone'] = $mob;
        }
        if (isset($validated['roleIds'])) {
            $fields['roleIds'] = $candidateRoleIds;
            $fields['roleId'] = $candidateRoleIds[0] ?? null;
        }
        if (isset($validated['permissionIds']) || $roleAssignmentsChanged) {
            $fields['permissionIds'] = $candidatePermissionIds;
        }
        if (isset($validated['businessIds'])) {
            $fields['businessIds'] = $validated['businessIds'];
        }
        if (isset($validated['outletIds'])) {
            $fields['outletIds'] = $validated['outletIds'];
        }
        if (array_key_exists('employeeCode', $validated)) {
            $fields['employeeCode'] = $validated['employeeCode'];
        }
        if (isset($validated['status'])) {
            $fields['status'] = strtolower($validated['status']);
        }

        $now = now()->toIso8601String();
        $fields['updatedAt'] = $now;
        $payload['fields'] = $fields;

        if (! empty($validated['password'])) {
            $staffUser = ! empty($fields['uid'])
                ? User::whereKey($fields['uid'])->first()
                : null;
            $staffUser ??= User::where('email', strtolower((string) ($fields['email'] ?? '')))->first();
            if ($staffUser && strtoupper((string) $staffUser->role) !== 'STAFF') {
                return ApiResponse::error('This email is already attached to a non-staff account.', 422, null, 'duplicate_email');
            }
            if ($staffUser && strtolower((string) $staffUser->email) !== strtolower((string) ($fields['email'] ?? ''))) {
                return ApiResponse::error('This employee is linked to a different login email.', 422, null, 'employee_email_mismatch');
            }

            $staffUser ??= new User;
            $staffUser->name = (string) ($fields['name'] ?? '');
            $staffUser->email = strtolower((string) ($fields['email'] ?? ''));
            $staffUser->phone = (string) ($fields['mobile'] ?? $fields['phone'] ?? '');
            $staffUser->password = $validated['password'];
            $staffUser->role = 'STAFF';
            $staffUser->status = ($fields['status'] ?? 'active') === 'active' ? 'ACTIVE' : 'INACTIVE';
            $staffUser->email_verified_at ??= now();
            $staffUser->activated_at = $staffUser->status === 'ACTIVE' ? now() : null;
            $staffUser->business_id = ($fields['businessIds'][0] ?? null);
            $staffUser->save();

            $fields['uid'] = (string) $staffUser->id;
            $fields['loginEnabled'] = true;
            $payload['fields'] = $fields;
        }

        if (! empty($fields['uid'])) {
            $staffUser = User::whereKey($fields['uid'])->first();
            if ($staffUser && strtoupper((string) $staffUser->role) === 'STAFF') {
                $staffUser->status = ($fields['status'] ?? 'active') === 'active' ? 'ACTIVE' : 'INACTIVE';
                $staffUser->activated_at = $staffUser->status === 'ACTIVE' ? ($staffUser->activated_at ?? now()) : null;
                $staffUser->save();
            }
        }

        DB::transaction(function () use ($id, $fields, $payload, $row): void {
            DB::table('employees')->where('id', $id)->update([
                'status' => $fields['status'] ?? $row->status,
                'updated_at' => now(),
                'payload' => json_encode($payload),
            ]);
        });

        return ApiResponse::success($fields, 'Employee updated successfully.');
    }

    /**
     * Toggle active/inactive status.
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:active,inactive,ACTIVE,INACTIVE',
        ]);

        $status = strtolower($validated['status']);
        $row = DB::table('employees')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Employee not found.', 404, null, 'NOT_FOUND');
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;
        $actor = $request->user();
        $isOwner = (string) ($fields['ownerUid'] ?? '') === (string) $actor->id;
        if (! $isOwner) {
            $manager = strtoupper((string) $actor->role) === 'STAFF'
                ? $this->employeeForUser($actor)
                : null;
            $managerFields = $manager['fields'] ?? [];
            $targetRoles = $this->asStringList($fields['roleIds'] ?? []);
            $managerBusinesses = $this->asStringList($managerFields['businessIds'] ?? []);
            $managerOutlets = $this->asStringList($managerFields['outletIds'] ?? []);
            $targetBusinesses = $this->asStringList($fields['businessIds'] ?? []);
            $targetOutlets = $this->asStringList($fields['outletIds'] ?? []);
            $sameOwner = (string) ($fields['ownerUid'] ?? '') !== ''
                && (string) ($fields['ownerUid'] ?? '') === (string) ($managerFields['ownerUid'] ?? '');
            $withinScope = array_intersect($targetBusinesses, $managerBusinesses) !== []
                && array_intersect($targetOutlets, $managerOutlets) !== [];
            if ($manager === null || ! $sameOwner || ! $withinScope
                || (string) ($fields['uid'] ?? '') === (string) $actor->id
                || ($targetRoles !== [] && ! $this->staffMayAssignRoles($targetRoles))) {
                return ApiResponse::error('You are not authorized to update this employee.', 403, null, 'FORBIDDEN');
            }
        }
        $fields['status'] = $status;
        $fields['updatedAt'] = now()->toIso8601String();
        $payload['fields'] = $fields;

        if (! empty($fields['uid'])) {
            $staffUser = User::whereKey($fields['uid'])->first();
            if ($staffUser && strtoupper((string) $staffUser->role) === 'STAFF') {
                $staffUser->status = $status === 'active' ? 'ACTIVE' : 'INACTIVE';
                $staffUser->activated_at = $status === 'active' ? ($staffUser->activated_at ?? now()) : null;
                $staffUser->save();
            }
        }

        DB::table('employees')->where('id', $id)->update([
            'status' => $status,
            'updated_at' => now(),
            'payload' => json_encode($payload),
        ]);

        return ApiResponse::success([
            'employeeId' => $id,
            'status' => $status,
        ], "Employee status updated to {$status}.");
    }

    /**
     * List all system and custom roles.
     */
    public function roles(Request $request): JsonResponse
    {
        $rows = DB::table('roles')->where('is_deleted', 0)->get();
        $roles = [];

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $roles[] = [
                'roleId' => (string) ($fields['roleId'] ?? $row->id),
                'roleName' => (string) ($fields['roleName'] ?? $fields['name'] ?? $row->id),
                'description' => $fields['description'] ?? null,
                'status' => (string) ($row->status ?? $fields['status'] ?? 'active'),
                'permissions' => $fields['permissions'] ?? $fields['permissionIds'] ?? [],
                'accessLevel' => $fields['accessLevel'] ?? null,
                'isSystemRole' => (bool) ($fields['isSystemRole'] ?? false),
            ];
        }

        return ApiResponse::success($roles, 'Roles retrieved successfully.');
    }

    /**
     * List system permissions.
     */
    public function permissions(Request $request): JsonResponse
    {
        $rows = DB::table('permissions')->where('is_deleted', 0)->get();
        $permissions = [];

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $permissions[] = [
                'permissionId' => (string) ($fields['permissionId'] ?? $row->id),
                'permissionName' => (string) ($fields['permissionName'] ?? $fields['name'] ?? $row->id),
                'module' => (string) ($fields['module'] ?? ''),
                'action' => (string) ($fields['action'] ?? ''),
                'description' => $fields['description'] ?? null,
                'status' => (string) ($row->status ?? $fields['status'] ?? 'active'),
            ];
        }

        return ApiResponse::success($permissions, 'Permissions retrieved successfully.');
    }

    /** @param list<string> $businessIds */
    private function ownsBusinesses(User $user, array $businessIds): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($businessIds === []) {
            return false;
        }

        $owned = [];
        foreach (DB::table('businesses')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $ownerUid = (string) ($fields['ownerUid'] ?? $row->owner_uid ?? '');
            $businessKey = (string) ($fields['businessId'] ?? $row->business_id ?? $row->id);
            if ($ownerUid === (string) $user->id) {
                $owned[] = $businessKey;
                $owned[] = (string) $row->id;
            }
        }

        return array_diff($businessIds, $owned) === [];
    }

    /** @param list<string> $roleIds @param list<string> $permissionIds */
    private function hasActiveAssignments(array $roleIds, array $permissionIds): bool
    {
        if ($roleIds === []) {
            return false;
        }

        $activeRoles = [];
        foreach (DB::table('roles')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $active = $fields['isActive'] ?? null;
            $status = strtolower((string) ($row->status ?? $fields['status'] ?? ''));
            if ($active === true || ($active === null && $status === 'active')) {
                $activeRoles[] = (string) ($fields['roleId'] ?? $row->id);
            }
        }
        if (array_diff($roleIds, $activeRoles) !== []) {
            return false;
        }

        $activePermissions = [];
        foreach (DB::table('permissions')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $status = strtolower((string) ($row->status ?? $fields['status'] ?? 'active'));
            if ($status === 'active') {
                $activePermissions[] = (string) ($fields['permissionId'] ?? $row->id);
            }
        }

        $activePermissions = [...$activePermissions,
            'staff.view', 'staff.manage',
            'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
        ];

        return array_diff($permissionIds, $activePermissions) === [];
    }

    /** @param list<string> $roleIds @return list<string> */
    private function permissionsForRoles(array $roleIds): array
    {
        $permissions = [];
        foreach (DB::table('roles')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $roleId = (string) ($fields['roleId'] ?? $row->id);
            if (! in_array($roleId, $roleIds, true)) {
                continue;
            }
            $rolePermissions = $fields['permissions'] ?? $fields['permissionIds'] ?? [];
            if (is_array($rolePermissions)) {
                foreach ($rolePermissions as $permission) {
                    if (is_string($permission)) {
                        $permissions[] = $this->normalizePermissionIdentifier($permission);
                    }
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    private function normalizePermissionIdentifier(string $permission): string
    {
        return strtolower(str_replace(['.', '-'], '_', trim($permission)));
    }

    /** @return array{fields: array<string, mixed>}|null */
    private function employeeForUser(User $user): ?array
    {
        foreach (DB::table('employees')->where('is_deleted', 0)->where('status', 'active')->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            if ((string) ($fields['uid'] ?? '') === (string) $user->id
                && strtolower((string) ($fields['status'] ?? $row->status ?? 'active')) === 'active') {
                return ['fields' => $fields];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function asStringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        return array_values(array_filter(array_map('strval', $value), fn (string $item): bool => $item !== ''));
    }

    /** @param list<string> $roleIds */
    private function staffMayAssignRoles(array $roleIds): bool
    {
        if ($roleIds === []) {
            return false;
        }

        $found = [];
        foreach (DB::table('roles')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $roleId = (string) ($fields['roleId'] ?? $row->id);
            if (! in_array($roleId, $roleIds, true)) {
                continue;
            }
            $roleName = strtolower(trim((string) ($fields['roleName'] ?? $fields['name'] ?? '')));
            $accessLevel = strtolower(trim((string) ($fields['accessLevel'] ?? '')));
            if (in_array($roleId, ['ROLE_OWNER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true)
                || in_array($roleName, ['owner', 'admin', 'super admin', 'super user'], true)
                || in_array($accessLevel, ['owner', 'admin', 'super_admin'], true)) {
                return false;
            }
            $found[] = $roleId;
        }

        return array_diff($roleIds, $found) === [];
    }

    /** @param list<string> $outletIds @param list<string> $businessIds */
    private function outletsBelongToBusinesses(array $outletIds, array $businessIds): bool
    {
        if ($outletIds === [] || $businessIds === []) {
            return false;
        }

        $validOutlets = [];
        foreach (DB::table('outlets')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $outletId = (string) ($fields['outletId'] ?? $row->outlet_id ?? $row->id);
            $businessId = (string) ($fields['businessId'] ?? $row->business_id ?? '');
            $businessId = $this->canonicalBusinessIds([$businessId])[0] ?? $businessId;
            if (in_array($businessId, $businessIds, true)) {
                $validOutlets[] = $outletId;
            }
        }

        return array_diff($outletIds, $validOutlets) === [];
    }

    /** @param list<string> $ids @return list<string> */
    private function canonicalBusinessIds(array $ids): array
    {
        $canonical = [];
        $knownAliases = [];
        foreach (DB::table('businesses')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $key = (string) ($fields['businessId'] ?? $row->business_id ?? $row->id);
            $aliases = array_filter([$key, (string) ($row->business_id ?? ''), (string) $row->id]);
            if (array_intersect($ids, $aliases) !== []) {
                $canonical[] = $key;
                $knownAliases = [...$knownAliases, ...$aliases];
            }
        }
        return array_values(array_unique([...$canonical, ...array_diff($ids, $knownAliases)]));
    }

    /** @param list<string> $ids @return list<string> */
    private function canonicalOutletIds(array $ids): array
    {
        $canonical = [];
        $knownAliases = [];
        foreach (DB::table('outlets')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $key = (string) ($fields['outletId'] ?? $row->outlet_id ?? $row->id);
            $aliases = array_filter([$key, (string) ($row->outlet_id ?? ''), (string) $row->id]);
            if (array_intersect($ids, $aliases) !== []) {
                $canonical[] = $key;
                $knownAliases = [...$knownAliases, ...$aliases];
            }
        }
        return array_values(array_unique([...$canonical, ...array_diff($ids, $knownAliases)]));
    }
}
