<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EmployeePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeePermissionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persists_revoked_permission_ids_in_employee_payload(): void
    {
        $admin = User::factory()->create(['phone' => '9876543210']);
        $admin->forceFill(['role' => 'ADMIN', 'status' => 'ACTIVE'])->save();
        $this->createRole('role_manager', ['perm_inventory_view', 'perm_inventory_edit']);
        $this->createPermission('perm_extra_create');
        $this->createPermission('perm_inventory_view');
        $this->createBusinessAndOutlet('business_1', 'outlet_1', (string) $admin->id);

        $response = $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Test Employee',
            'loginEnabled' => false,
            'roleIds' => ['role_manager'],
            'permissionIds' => ['perm_extra_create'],
            'revoked_permission_ids' => ['perm_inventory_view'],
            'businessIds' => ['business_1'],
            'outletIds' => ['outlet_1'],
        ]);

        $response->assertCreated();
        $employee = DB::table('employees')->where('business_id', 'business_1')->first();
        $this->assertNotNull($employee);
        $payload = json_decode((string) $employee->payload, true);
        $this->assertSame(['perm_inventory_view'], $payload['fields']['revoked_permission_ids']);
    }

    public function test_create_rejects_non_string_revoked_permission_ids(): void
    {
        $admin = User::factory()->create(['phone' => '9876543210']);
        $admin->forceFill(['role' => 'ADMIN', 'status' => 'ACTIVE'])->save();

        $response = $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Test Employee',
            'loginEnabled' => false,
            'revoked_permission_ids' => [123],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['revoked_permission_ids.0']);
    }

    public function test_permissions_for_excludes_revoked_role_permissions_and_keeps_other_grants(): void
    {
        $owner = User::factory()->create(['phone' => '9876543210']);
        $staff = User::factory()->create(['phone' => '9876543211']);
        $staff->forceFill(['role' => 'STAFF', 'status' => 'ACTIVE'])->save();
        $this->createRole('role_manager', ['perm_inventory_view', 'perm_inventory_edit']);
        $this->createPermission('perm_inventory_view');
        $this->createPermission('perm_inventory_edit');
        $this->createPermission('perm_reports_view');
        $this->createEmployee('employee_1', (string) $owner->id, (string) $staff->id, [
            'roleIds' => ['role_manager'],
            'permissionIds' => ['perm_reports_view'],
            'revoked_permission_ids' => ['perm_inventory_view'],
        ]);

        $effectivePermissions = app(EmployeePermissionService::class)->permissionsFor($staff);

        $this->assertNotContains('perm_inventory_view', $effectivePermissions);
        $this->assertContains('perm_inventory_edit', $effectivePermissions);
        $this->assertContains('perm_reports_view', $effectivePermissions);
    }

    public function test_partial_update_retains_existing_revoked_permission_ids(): void
    {
        $admin = User::factory()->create(['phone' => '9876543210']);
        $admin->forceFill(['role' => 'ADMIN', 'status' => 'ACTIVE'])->save();
        $this->createRole('role_manager', []);
        $this->createPermission('perm_inventory_view');
        $this->createBusinessAndOutlet('business_1', 'outlet_1', (string) $admin->id);
        $this->createEmployee('employee_1', (string) $admin->id, '', [
            'name' => 'Before Update',
            'email' => 'employee@test.com',
            'roleIds' => ['role_manager'],
            'permissionIds' => [],
            'revoked_permission_ids' => ['perm_inventory_view'],
            'businessIds' => ['business_1'],
            'outletIds' => ['outlet_1'],
        ]);

        $response = $this->actingAs($admin)->putJson('/api/v1/employees/employee_1', [
            'name' => 'After Update',
        ]);

        $response->assertOk();
        $payload = json_decode((string) DB::table('employees')->where('id', 'employee_1')->value('payload'), true);
        $this->assertSame(['perm_inventory_view'], $payload['fields']['revoked_permission_ids']);
        $this->assertSame('After Update', $payload['fields']['name']);
    }

    /** @param list<string> $permissions */
    private function createRole(string $roleId, array $permissions): void
    {
        DB::table('roles')->insert([
            'id' => $roleId,
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['roleId' => $roleId, 'permissions' => $permissions, 'isActive' => true]]),
        ]);
    }

    private function createPermission(string $permissionId): void
    {
        DB::table('permissions')->insert([
            'id' => $permissionId,
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['permissionId' => $permissionId, 'status' => 'active']]),
        ]);
    }

    private function createBusinessAndOutlet(string $businessId, string $outletId, string $ownerId): void
    {
        DB::table('businesses')->insert([
            'id' => $businessId,
            'name' => 'Test Business',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['businessId' => $businessId, 'ownerUid' => $ownerId]]),
        ]);
        DB::table('outlets')->insert([
            'id' => $outletId,
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['outletId' => $outletId, 'businessId' => $businessId]]),
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function createEmployee(string $id, string $ownerId, string $userId, array $fields): void
    {
        $fields = array_merge([
            'employeeId' => $id,
            'uid' => $userId,
            'ownerUid' => $ownerId,
            'name' => 'Test Employee',
            'email' => 'employee@test.com',
            'status' => 'active',
            'roleIds' => ['role_manager'],
            'permissionIds' => [],
            'revoked_permission_ids' => [],
            'businessIds' => ['business_1'],
            'outletIds' => ['outlet_1'],
        ], $fields);

        DB::table('employees')->insert([
            'id' => $id,
            'business_id' => 'business_1',
            'outlet_id' => 'outlet_1',
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => $fields]),
        ]);
    }
}
