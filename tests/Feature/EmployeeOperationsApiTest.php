<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/payroll/outlet-allocations?business_id=business-api-test')->assertUnauthorized();
        $this->postJson('/api/v1/supplier-refunds', [])->assertUnauthorized();
        $this->getJson('/api/v1/attendance/events?business_id=business-api-test')->assertUnauthorized();
    }

    public function test_employee_outlet_allocation_can_be_saved_and_retrieved(): void
    {
        $user = $this->createAdmin();
        $this->createBusinessEmployeeAndOutlet((string) $user->id);
        $this->actingAs($user)->withoutMiddleware();
        $payload = [
            'employeeId' => 'employee-api-test',
            'effectiveFrom' => '2026-11-01T00:00:00Z',
            'effectiveTo' => '2026-12-01T00:00:00Z',
            'outletBasisPoints' => ['outlet-api-test' => 10000],
            'allocationTotalBasisPoints' => 10000,
        ];

        $this->withHeader('X-Business-Id', 'business-api-test')
            ->postJson('/api/v1/payroll/outlet-allocations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.outletBasisPoints.outlet-api-test', 10000);
        $this->withHeader('X-Business-Id', 'business-api-test')
            ->getJson('/api/v1/payroll/outlet-allocations?employee_id=employee-api-test')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.allocationTotalBasisPoints', 10000);
    }

    public function test_salary_profile_and_initial_outlet_allocation_are_saved_atomically(): void
    {
        $user = $this->createAdmin();
        $this->createBusinessEmployeeAndOutlet((string) $user->id);
        $this->actingAs($user)->withoutMiddleware();
        $profile = [
            'business_id' => 'business-api-test',
            'outlet_id' => 'outlet-api-test',
            'employee_id' => 'employee-api-test',
            'baseRatePaise' => 4000000,
            'salaryType' => 'MONTHLY',
            'paymentCycle' => 'MONTHLY',
            'effectiveFrom' => '2026-11-01T00:00:00Z',
        ];
        $initialAllocation = [
            'effectiveFrom' => '2026-11-01T00:00:00Z',
            'outletBasisPoints' => ['outlet-api-test' => 10000],
        ];

        $this->postJson('/api/v1/payroll/profiles', $profile + ['initial_allocation' => $initialAllocation])
            ->assertCreated()
            ->assertJsonPath('data.employeeId', 'employee-api-test');
        $this->assertDatabaseCount('salary_profiles', 1);
        $this->assertDatabaseCount('employee_outlet_allocations', 1);

        $this->postJson('/api/v1/payroll/profiles', array_merge($profile, [
            'baseRatePaise' => 5000000,
            'initial_allocation' => array_merge($initialAllocation, ['effectiveTo' => '2026-12-01T00:00:00Z']),
        ]))->assertUnprocessable();

        $payload = json_decode((string) DB::table('salary_profiles')->value('payload'), true);
        $this->assertSame(4000000, $payload['fields']['baseRatePaise']);
        $this->assertDatabaseCount('employee_outlet_allocations', 1);
    }

    public function test_attendance_punches_create_immutable_linked_audit_events(): void
    {
        $user = $this->createAdmin();
        $this->createBusinessEmployeeAndOutlet((string) $user->id);
        $this->actingAs($user)->withoutMiddleware();

        foreach ([['CHECK_IN', '2026-11-02T09:00:00Z'], ['CHECK_OUT', '2026-11-02T17:00:00Z']] as [$type, $timestamp]) {
            $this->withHeader('X-Business-Id', 'business-api-test')
                ->postJson('/api/v1/attendance/punch', [
                    'outlet_id' => 'outlet-api-test',
                    'employee_id' => 'employee-api-test',
                    'type' => $type,
                    'timestamp' => $timestamp,
                    'attendanceMethod' => 'FACE_KIOSK',
                    'workTimeZoneId' => 'Asia/Kolkata',
                    'kioskDeviceId' => 'kiosk-api-test',
                    'recognitionModelVersion' => 'model-v1',
                    'livenessCheckVersion' => 'live-v1',
                    'livenessChallenges' => ['blink'],
                ])
                ->assertSuccessful();
        }

        $attendanceId = DB::table('staff_attendance')->value('id');
        $this->assertDatabaseCount('staff_attendance_events', 2);
        $this->assertDatabaseHas('staff_attendance_events', [
            'attendance_id' => $attendanceId,
            'event_type' => 'CHECK_IN',
            'immutable' => 1,
        ]);
        $this->withHeader('X-Business-Id', 'business-api-test')
            ->getJson('/api/v1/attendance/events?employee_id=employee-api-test')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_supplier_refund_is_idempotent_and_updates_linked_ledger_records(): void
    {
        $user = $this->createAdmin();
        $this->createBusinessEmployeeAndOutlet((string) $user->id);
        DB::table('suppliers')->insert([
            'id' => 'supplier-api-test',
            'business_id' => 'business-api-test',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['supplierId' => 'supplier-api-test']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplier_businesses')->insert([
            'id' => 'supplier-api-test_business-api-test',
            'business_id' => 'business-api-test',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => [
                'supplierBusinessId' => 'supplier-api-test_business-api-test',
                'supplierId' => 'supplier-api-test',
                'businessId' => 'business-api-test',
                'tenantId' => (string) $user->id,
                'status' => 'ACTIVE',
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplier_balances')->insert([
            'id' => 'supplier-api-test_business-api-test',
            'business_id' => 'business-api-test',
            'outlet_id' => 'outlet-api-test',
            'tenant_id' => (string) $user->id,
            'supplier_id' => 'supplier-api-test',
            'balance' => 0,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => [
                'supplierBusinessId' => 'supplier-api-test_business-api-test',
                'supplierId' => 'supplier-api-test',
                'businessId' => 'business-api-test',
                'unallocatedAdvance' => 9000,
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($user)->withoutMiddleware();
        $refund = [
            'outletId' => 'outlet-api-test',
            'supplierId' => 'supplier-api-test',
            'supplierBusinessId' => 'supplier-api-test_business-api-test',
            'amount' => 3000,
            'refundDate' => '2026-11-02T10:00:00Z',
            'paymentMode' => 'CASH',
            'reason' => 'Advance refund',
            'requestId' => 'request-api-test',
        ];

        $this->withHeader('X-Business-Id', 'business-api-test')
            ->postJson('/api/v1/supplier-refunds', $refund)
            ->assertCreated()
            ->assertJsonPath('data.financialTransactionId', 'FIN-SREF-request-api-test');
        $this->withHeader('X-Business-Id', 'business-api-test')
            ->postJson('/api/v1/supplier-refunds', $refund)
            ->assertOk();

        $this->assertDatabaseCount('supplier_refunds', 1);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('supplier_ledger_entries', 1);
        $balancePayload = json_decode((string) DB::table('supplier_balances')->value('payload'), true);
        $this->assertSame(6000, $balancePayload['fields']['unallocatedAdvance']);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'email' => 'operations-api@example.com',
            'phone' => '9998887776',
            'password' => Hash::make('Password123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);
    }

    private function createBusinessEmployeeAndOutlet(string $ownerUid): void
    {
        DB::table('businesses')->insert([
            'id' => 'business-api-test',
            'name' => 'Operations API Test',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['businessId' => 'business-api-test', 'ownerUid' => $ownerUid]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('outlets')->insert([
            'id' => 'outlet-api-test',
            'business_id' => 'business-api-test',
            'outlet_id' => 'outlet-api-test',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['outletId' => 'outlet-api-test', 'businessId' => 'business-api-test']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'id' => 'employee-api-test',
            'business_id' => 'business-api-test',
            'outlet_id' => 'outlet-api-test',
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => [
                'employeeId' => 'employee-api-test',
                'ownerUid' => $ownerUid,
                'businessIds' => ['business-api-test'],
                'outletIds' => ['outlet-api-test'],
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
