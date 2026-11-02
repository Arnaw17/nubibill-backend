<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeAuthenticationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_staff_can_log_in_and_use_only_non_revoked_permissions(): void
    {
        $admin = User::factory()->create(['phone' => '9876543210']);
        $admin->forceFill(['role' => 'ADMIN', 'status' => 'ACTIVE'])->save();
        $this->createRole('role_inventory_clerk', ['inventory.view', 'reports.view']);
        $this->createPermission('inventory.view');
        $this->createPermission('reports.view');
        $this->createBusinessAndOutlet('business_auth', 'outlet_auth', (string) $admin->id);

        $createResponse = $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Staff Login Test',
            'email' => 'staff-login@example.com',
            'mobile' => '9876543211',
            'loginEnabled' => true,
            'password' => 'StaffPass123!',
            'roleIds' => ['role_inventory_clerk'],
            'permissionIds' => ['reports.view'],
            'revokedPermissionIds' => ['reports.view'],
            'businessIds' => ['business_auth'],
            'outletIds' => ['outlet_auth'],
        ]);

        $createResponse->assertCreated();
        Auth::forgetGuards();

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff-login@example.com',
            'password' => 'StaffPass123!',
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('data.user.email', 'staff-login@example.com')
            ->assertJsonPath('data.user.role', 'staff')
            ->assertJsonPath('data.user.permissions', ['inventory.view']);
        $token = $loginResponse->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $this->withToken($token)
            ->getJson('/api/v1/inventory/stock?business_id=business_auth&outlet_id=outlet_auth')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/reports/reconciliation?business_id=business_auth&outlet_id=outlet_auth')
            ->assertForbidden();
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
            'name' => 'Authentication Test Business',
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
}
