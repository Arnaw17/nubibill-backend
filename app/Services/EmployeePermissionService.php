<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class EmployeePermissionService
{
    private const FALLBACK_PERMISSION_IDS = [
        'billing',
        'staff.view', 'staff.manage',
        'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
        'inventory.opening_stock',
        'SALARY_VIEW', 'SALARY_MANAGE', 'SALARY_APPROVE', 'SALARY_PAY', 'SALARY_REPORT',
        'ATTENDANCE_VIEW', 'ATTENDANCE_MANAGE', 'ATTENDANCE_APPROVE',
        'BIOMETRIC_ENROLL', 'BIOMETRIC_REVOKE', 'STAFF_KHATA_CHARGE',
    ];

    /**
     * Resolve grants from an employee's active roles and direct permissions.
     *
     * @return list<string>|null Null means the staff authorization record is invalid or inactive.
     */
    public function permissionsFor(User $user): ?array
    {
        if (strtoupper((string) $user->role) !== 'STAFF') {
            return ['*'];
        }

        $employee = $this->employeeFor($user);
        if ($employee === null) {
            return null;
        }

        $permissions = $this->stringList($employee['fields']['permissionIds'] ?? []);
        $roleIds = $this->stringList(
            $employee['fields']['roleIds'] ?? (isset($employee['fields']['roleId']) ? [$employee['fields']['roleId']] : [])
        );
        if ($roleIds === []) {
            return null;
        }
        $roles = DB::table('roles')->where('is_deleted', 0)->get();
        $matchedRoleIds = [];
        $foundRoleIds = [];
        foreach ($roles as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $roleId = (string) ($fields['roleId'] ?? $row->id);
            if (in_array($roleId, $roleIds, true)) {
                $foundRoleIds[] = $roleId;
            }
            $active = $fields['isActive'] ?? null;
            $status = strtolower((string) ($row->status ?? $fields['status'] ?? ''));
            if (($active === true || ($active === null && $status === 'active')) && in_array($roleId, $roleIds, true)) {
                $matchedRoleIds[] = $roleId;
                $permissions = [...$permissions, ...$this->stringList($fields['permissions'] ?? $fields['permissionIds'] ?? [])];
                $roleName = strtolower(str_replace([' ', '-'], '_', (string) ($fields['roleName'] ?? $fields['name'] ?? '')));
                if (in_array(strtolower($roleId), ['role_billing', 'billing'], true)
                    || in_array($roleName, ['billing', 'billing_staff', 'role_billing'], true)) {
                    $permissions[] = 'billing';
                }
            }
        }

        if (array_diff($roleIds, $foundRoleIds) !== []) {
            return null;
        }

        $activePermissionIds = [];
        $knownPermissionIds = [];
        foreach (DB::table('permissions')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $permissionId = (string) ($fields['permissionId'] ?? $row->id);
            $knownPermissionIds[] = $this->normalize($permissionId);
            $status = strtolower((string) ($row->status ?? $fields['status'] ?? 'active'));
            if ($status === 'active') {
                $activePermissionIds[] = $this->normalize($permissionId);
            }
        }

        foreach (self::FALLBACK_PERMISSION_IDS as $permissionId) {
            $normalized = $this->normalize($permissionId);
            if (! in_array($normalized, $knownPermissionIds, true)) {
                $activePermissionIds[] = $normalized;
            }
        }

        if ($activePermissionIds === []) {
            return null;
        }

        return array_values(array_unique(array_filter(
            $permissions,
            fn (string $permission): bool => in_array($this->normalize($permission), $activePermissionIds, true)
        )));
    }

    /** @param list<string> $requiredPermissions */
    public function isAuthorized(User $user, array $requiredPermissions): bool
    {
        if (strtoupper((string) $user->role) !== 'STAFF') {
            return true;
        }

        $permissions = $this->permissionsFor($user);
        if ($permissions === null) {
            return false;
        }

        foreach ($requiredPermissions as $required) {
            if ($this->allows((string) $required, $permissions)) {
                return true;
            }
        }

        return false;
    }

    public function isWithinAssignedScope(User $user, ?string $businessId, ?string $outletId, bool $resourceScoped = false): bool
    {
        if (strtoupper((string) $user->role) !== 'STAFF') {
            return true;
        }

        $employee = $this->employeeFor($user);
        if ($employee === null) {
            return false;
        }

        $businessIds = $this->canonicalBusinessIds(
            $this->stringList($employee['fields']['businessIds'] ?? $employee['fields']['businessId'] ?? [])
        );
        $outletIds = $this->canonicalOutletIds(
            $this->stringList($employee['fields']['outletIds'] ?? $employee['fields']['outletId'] ?? [])
        );
        $businessId = $businessId === null
            ? null
            : ($this->canonicalBusinessIds([$businessId])[0] ?? $businessId);
        $outletId = $outletId === null || strtoupper($outletId) === 'ALL'
            ? $outletId
            : ($this->canonicalOutletIds([$outletId])[0] ?? $outletId);
        if ($businessId !== null && ! in_array($businessId, $businessIds, true)) {
            return false;
        }
        if ($outletId !== null && strtoupper($outletId) !== 'ALL' && ! in_array($outletId, $outletIds, true)) {
            return false;
        }
        if ($businessId !== null && ! $resourceScoped && ($outletId === null || strtoupper($outletId) === 'ALL')) {
            $businessOutletIds = [];
            foreach (DB::table('outlets')->where('is_deleted', 0)->get() as $row) {
                $payload = json_decode((string) $row->payload, true) ?: [];
                $fields = $payload['fields'] ?? $payload;
                $outletBusinessId = (string) ($fields['businessId'] ?? $row->business_id ?? '');
                $outletBusinessId = $this->canonicalBusinessIds([$outletBusinessId])[0] ?? $outletBusinessId;
                if ($outletBusinessId === $businessId) {
                    $businessOutletIds[] = (string) ($fields['outletId'] ?? $row->outlet_id ?? $row->id);
                }
            }
            $businessOutletIds = $this->canonicalOutletIds($businessOutletIds);
            if ($businessOutletIds === [] || array_diff($businessOutletIds, $outletIds) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $permissions */
    private function allows(string $required, array $permissions): bool
    {
        $module = $this->canonicalAction($this->normalize($required));
        $baseModule = $this->baseModule($module);
        foreach ($permissions as $permission) {
            $grant = $this->canonicalAction($this->normalize($permission));
            if ($grant === '*' || $grant === 'all' || $grant === $module || $grant === "manage_{$baseModule}") {
                return true;
            }
            // A module grant permits its operations. An operation grant does
            // not imply permission to perform a different operation.
            if ($module === $baseModule && $grant === $baseModule) {
                return true;
            }
            if ($grant === $baseModule && $module !== $baseModule) {
                return true;
            }
            if (in_array($module, ["{$baseModule}_view", "{$baseModule}_read"], true) && $grant === "view_{$baseModule}") {
                return true;
            }
        }

        return false;
    }

    private function canonicalAction(string $permission): string
    {
        foreach (['_read' => '_view', '_list' => '_view'] as $alias => $canonical) {
            if (str_ends_with($permission, $alias)) {
                return substr($permission, 0, -strlen($alias)).$canonical;
            }
        }

        return $permission;
    }

    private function normalize(string $permission): string
    {
        $key = strtolower(str_replace(['-', '.'], '_', trim($permission)));
        foreach (['view_', 'manage_'] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $remainder = $this->normalizeModule(substr($key, strlen($prefix)));
                if ($remainder !== substr($key, strlen($prefix))) {
                    return $prefix.$remainder;
                }
            }
        }

        return $this->normalizeModule($key);
    }

    private function normalizeModule(string $key): string
    {
        foreach (['product' => 'products', 'customer' => 'customers', 'supplier' => 'suppliers'] as $alias => $canonical) {
            if ($key === $alias || str_starts_with($key, "{$alias}.") || str_starts_with($key, "{$alias}_")) {
                return $canonical.substr($key, strlen($alias));
            }
        }

        return $key;
    }

    private function baseModule(string $permission): string
    {
        foreach (['_view', '_read', '_create', '_edit', '_update', '_delete', '_manage', '_approve', '_pay', '_adjust', '_cancel', '_report', '_collect', '_enroll', '_revoke'] as $suffix) {
            if (str_ends_with($permission, $suffix)) {
                return substr($permission, 0, -strlen($suffix));
            }
        }

        return $permission;
    }

    /** @return array{fields: array<string, mixed>}|null */
    private function employeeFor(User $user): ?array
    {
        foreach (DB::table('employees')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            if ((string) ($fields['uid'] ?? '') !== (string) $user->id) {
                continue;
            }

            $status = strtolower((string) ($row->status ?? $fields['status'] ?? ''));
            $businessIds = $this->stringList($fields['businessIds'] ?? []);
            $outletIds = $this->stringList($fields['outletIds'] ?? []);
            if ($businessIds === []) {
                $businessIds = $this->stringList($fields['businessId'] ?? $fields['business_id'] ?? []);
            }
            if ($outletIds === []) {
                $outletIds = $this->stringList($fields['outletId'] ?? $fields['outlet_id'] ?? []);
            }
            if ($status !== 'active' || empty($fields['ownerUid']) || $businessIds === [] || $outletIds === []) {
                return null;
            }

            $fields['businessIds'] = $businessIds;
            $fields['outletIds'] = $outletIds;

            return ['fields' => $fields];
        }

        return null;
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            $values = [$values];
        }

        return array_values(array_filter($values, 'is_string'));
    }

    /** @param list<string> $ids @return list<string> */
    private function canonicalBusinessIds(array $ids): array
    {
        $canonical = [];
        foreach (DB::table('businesses')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $businessKey = (string) ($fields['businessId'] ?? $row->business_id ?? $row->id);
            $aliases = array_filter([$businessKey, (string) ($row->business_id ?? ''), (string) $row->id]);
            if (array_intersect($ids, $aliases) !== []) {
                $canonical[] = $businessKey;
            }
        }

        return array_values(array_unique([...$canonical, ...array_diff($ids, $this->businessAliases($ids))]));
    }

    /** @param list<string> $ids @return list<string> */
    private function businessAliases(array $ids): array
    {
        $aliases = [];
        foreach (DB::table('businesses')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $group = array_filter([
                (string) ($fields['businessId'] ?? ''),
                (string) ($row->business_id ?? ''),
                (string) $row->id,
            ]);
            if (array_intersect($ids, $group) !== []) {
                $aliases = [...$aliases, ...$group];
            }
        }

        return array_values(array_unique($aliases));
    }

    /** @param list<string> $ids @return list<string> */
    private function canonicalOutletIds(array $ids): array
    {
        $canonical = [];
        foreach (DB::table('outlets')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $outletKey = (string) ($fields['outletId'] ?? $row->outlet_id ?? $row->id);
            $aliases = array_filter([$outletKey, (string) ($row->outlet_id ?? ''), (string) $row->id]);
            if (array_intersect($ids, $aliases) !== []) {
                $canonical[] = $outletKey;
            }
        }

        return array_values(array_unique([...$canonical, ...array_diff($ids, $this->outletAliases($ids))]));
    }

    /** @param list<string> $ids @return list<string> */
    private function outletAliases(array $ids): array
    {
        $aliases = [];
        foreach (DB::table('outlets')->where('is_deleted', 0)->get() as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            $group = array_filter([
                (string) ($fields['outletId'] ?? ''),
                (string) ($row->outlet_id ?? ''),
                (string) $row->id,
            ]);
            if (array_intersect($ids, $group) !== []) {
                $aliases = [...$aliases, ...$group];
            }
        }

        return array_values(array_unique($aliases));
    }
}
