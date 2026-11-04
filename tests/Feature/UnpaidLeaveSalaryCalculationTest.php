<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnpaidLeaveSalaryCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_generation_with_unpaid_leave_days_calculates_and_stores_correctly(): void
    {
        // 1. Create authenticated Admin User
        $user = User::factory()->create([
            'email' => 'admin-payroll@example.com',
            'phone' => '9888877777',
            'password' => Hash::make('Secret123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);

        $businessId = 'BUS-TEST-100';
        $outletId = 'OUT-TEST-100';
        $employeeId = 'EMP-TEST-001';
        $profileId = 'PROF-TEST-001';

        // 2. Setup Business & Outlet
        DB::table('businesses')->insert([
            'id' => $businessId,
            'name' => 'Payroll Test Business',
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => [
                'ownerUid' => (string) $user->id,
                'businessId' => $businessId,
                'businessName' => 'Payroll Test Business',
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outlets')->insert([
            'id' => $outletId,
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => [
                'outletId' => $outletId,
                'businessId' => $businessId,
                'isActive' => true,
                'status' => 'active',
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Setup Employee assigned to business
        DB::table('employees')->insert([
            'id' => $employeeId,
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => [
                'employeeId' => $employeeId,
                'name' => 'Rahul Sharma',
                'businessIds' => [$businessId],
                'outletIds' => [$outletId],
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Base monthly salary profile: ₹30,000 (3,000,000 paise)
        $baseSalaryPaise = 3000000; // ₹30,000.00
        DB::table('salary_profiles')->insert([
            'id' => $profileId,
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'employee_id' => $employeeId,
            'status' => 'ACTIVE',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => [
                'profileId' => $profileId,
                'employeeId' => $employeeId,
                'salaryType' => 'MONTHLY',
                'paymentCycle' => 'MONTHLY',
                'baseRatePaise' => $baseSalaryPaise,
                'baseSalaryPaise' => $baseSalaryPaise,
                'status' => 'ACTIVE',
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Test Month: November 2026 (30 days in month)
        // Expected Daily Rate: 30,000 / 30 = ₹1,000 / day
        // Unpaid leave days: 2 days
        // Deduction amount: 2 * 1,000 = ₹2,000 (200,000 paise)
        // Net payable: 30,000 - 2,000 = ₹28,000 (2,800,000 paise)
        $month = 11;
        $year = 2026;
        $unpaidLeaveDays = 2;

        $response = $this->actingAs($user)->withoutMiddleware()->postJson('/api/v1/payroll/disburse', [
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'employee_id' => $employeeId,
            'profile_id' => $profileId,
            'periodMonth' => $month,
            'periodYear' => $year,
            'unpaid_leave_days' => $unpaidLeaveDays,
            'status' => 'APPROVED',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        // Verify API response figures
        $this->assertEquals(3000000, $data['grossEarningsPaise']); // ₹30,000
        $this->assertEquals(2800000, $data['netEarnedPaise']);     // ₹28,000
        $this->assertEquals(200000, $data['approvedDeductionsPaise']); // ₹2,000 deduction
        $this->assertEquals(2, $data['unpaidLeaveDays']);
        $this->assertEquals(1000.00, $data['dailyRate']);
        $this->assertEquals(2000.00, $data['deductionAmount']);
        $this->assertEquals(28000.00, $data['netPayable']);

        // 6. Verify `salaries` database table storage
        $this->assertDatabaseHas('salaries', [
            'business_id' => $businessId,
            'employee_id' => $employeeId,
            'month' => $month,
            'year' => $year,
            'days_in_month' => 30,
            'monthly_gross_salary' => 30000.00,
            'daily_rate' => 1000.00,
            'unpaid_leave_days' => 2.00,
            'deduction_amount' => 2000.00,
            'unpaid_leave_deduction' => 2000.00,
            'net_payable' => 28000.00,
            'monthly_gross_salary_paise' => 3000000,
            'daily_rate_paise' => 100000,
            'deduction_amount_paise' => 200000,
            'unpaid_leave_deduction_paise' => 200000,
            'net_payable_paise' => 2800000,
            'status' => 'APPROVED',
        ]);

        // 7. Verify `salary_periods` database table storage
        $periodId = sprintf('SALPER_%s_%s_%04d%02d', $businessId, $employeeId, $year, $month);
        $period = DB::table('salary_periods')->where('id', $periodId)->first();
        $this->assertNotNull($period);
        $periodPayload = json_decode($period->payload, true);
        $this->assertEquals(2800000, $periodPayload['fields']['netEarnedPaise']);
        $this->assertEquals(2, $periodPayload['fields']['unpaidLeaveDays']);
        $this->assertEquals(2000.00, $periodPayload['fields']['deductionAmount']);

        // 8. Verify Employee's base monthly salary profile in `salary_profiles` was NOT altered
        $prof = DB::table('salary_profiles')->where('id', $profileId)->first();
        $profPayload = json_decode($prof->payload, true);
        $this->assertEquals(3000000, $profPayload['fields']['baseRatePaise']);
        $this->assertEquals(3000000, $profPayload['fields']['baseSalaryPaise']);

        // 9. Verify Khata / Ledger payment tracking against the adjusted net amount
        // Record salary payment of the adjusted ₹28,000 (2,800,000 paise)
        $requestId = 'REQ-TEST-'.Str::random(10);
        $paymentResponse = $this->actingAs($user)->withoutMiddleware()->postJson('/api/v1/payroll/salary-payment', [
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'employee_id' => $employeeId,
            'period_id' => $periodId,
            'amountPaise' => 2800000,
            'paymentMode' => 'BANK_TRANSFER',
            'transactionDate' => now()->toDateString(),
            'request_id' => $requestId,
            'notes' => 'Salary payout adjusted for 2 unpaid leaves',
        ]);

        $paymentResponse->assertStatus(201);

        // Verify transaction posted to `staff_khata_transactions`
        $khata = DB::table('staff_khata_transactions')
            ->where('business_id', $businessId)
            ->where('employee_id', $employeeId)
            ->where('id', 'SKH-PAY-'.$requestId)
            ->first();
        $this->assertNotNull($khata);
        $khataPayload = json_decode($khata->payload, true);
        $this->assertEquals(2800000, $khataPayload['fields']['amountPaise']);
        $this->assertEquals('SALARY_PAYMENT', $khataPayload['fields']['transactionType']);

        // Attempting to pay more after fully paid should be rejected
        $excessResponse = $this->actingAs($user)->withoutMiddleware()->postJson('/api/v1/payroll/salary-payment', [
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'employee_id' => $employeeId,
            'period_id' => $periodId,
            'amountPaise' => 100, // 1 rupee extra
            'paymentMode' => 'CASH',
            'transactionDate' => now()->toDateString(),
            'request_id' => 'REQ-EXCESS-'.Str::random(10),
        ]);

        $this->assertTrue(in_array($excessResponse->status(), [404, 422], true));
    }
}
