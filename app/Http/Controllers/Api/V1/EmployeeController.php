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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required_if:loginEnabled,true|nullable|string|email|max:255',
            'mobile' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:30',
            'roleIds' => 'nullable|array',
            'roleId' => 'nullable|string',
            'permissionIds' => 'nullable|array',
            'businessIds' => 'nullable|array',
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outletIds' => 'nullable|array',
            'outlet_id' => 'nullable|string',
            'outletId' => 'nullable|string',
            'employeeCode' => 'nullable|string|max:50',
            'joiningDate' => 'nullable|string',
            'loginEnabled' => 'nullable|boolean',
            'password' => 'required_if:loginEnabled,true|string|min:6|max:255',
            'status' => 'nullable|string|in:active,inactive,ACTIVE,INACTIVE',
        ]);

        $user = $request->user();
        $ownerUid = (string) $user->id;

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

        $roleIds = $validated['roleIds'] ?? (! empty($validated['roleId']) ? [$validated['roleId']] : ['ROLE_STAFF']);
        $permissionIds = $validated['permissionIds'] ?? [];

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
            'permissionIds' => 'nullable|array',
            'businessIds' => 'nullable|array',
            'outletIds' => 'nullable|array',
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
        if ((string) ($fields['ownerUid'] ?? '') !== (string) $request->user()->id) {
            return ApiResponse::error('You are not authorized to update this employee.', 403, null, 'FORBIDDEN');
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
            $fields['roleIds'] = $validated['roleIds'];
        }
        if (isset($validated['permissionIds'])) {
            $fields['permissionIds'] = $validated['permissionIds'];
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
        if ((string) ($fields['ownerUid'] ?? '') !== (string) $request->user()->id) {
            return ApiResponse::error('You are not authorized to update this employee.', 403, null, 'FORBIDDEN');
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
}
