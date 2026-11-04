<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\EmployeePermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    /**
     * List employee salary profiles.
     */
    public function profiles(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $employeeId = $request->query('employee_id') ?? $request->query('employeeId');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('salary_profiles')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $profiles = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $base = (int) ($fields['baseRatePaise'] ?? $fields['baseSalaryPaise'] ?? 0);
            $daily = (int) ($fields['dailyWagePaise'] ?? 0);
            $salaryType = strtoupper((string) ($fields['salaryType'] ?? ($daily > 0 ? 'DAILY' : 'MONTHLY')));
            $paymentCycle = strtoupper((string) ($fields['paymentCycle'] ?? $fields['paymentFrequency'] ?? 'MONTHLY'));

            $profiles[] = [
                'profileId' => (string) $row->id,
                'id' => (string) $row->id,
                'businessId' => (string) $row->business_id,
                'outletId' => $row->outlet_id,
                'employeeId' => (string) ($row->employee_id ?? $fields['employeeId'] ?? ''),
                'baseSalaryPaise' => $base,
                'baseRatePaise' => $base,
                'salaryType' => $salaryType,
                'paymentCycle' => $paymentCycle,
                'paymentFrequency' => $paymentCycle,
                'dailyWagePaise' => $daily,
                'overtimeRatePaisePerHour' => (int) ($fields['overtimeRatePaisePerHour'] ?? 0),
                'effectiveFrom' => $fields['effectiveFrom'] ?? null,
                'effectiveTo' => $fields['effectiveTo'] ?? null,
                'status' => (string) ($row->status ?? $fields['status'] ?? 'ACTIVE'),
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        }

        return ApiResponse::success($profiles, 'Salary profiles retrieved successfully.');
    }

    /**
     * Store or update an employee salary profile.
     */
    public function storeProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'outletId' => 'nullable|string',
            'employee_id' => 'required|string',
            'employeeId' => 'nullable|string',
            'baseSalaryPaise' => 'nullable|integer|min:0',
            'baseRatePaise' => 'nullable|integer|min:0',
            'salaryType' => 'nullable|string',
            'paymentCycle' => 'nullable|string',
            'dailyWagePaise' => 'nullable|integer|min:0',
            'overtimeRatePaisePerHour' => 'nullable|integer|min:0',
            'paymentFrequency' => 'nullable|string',
            'effectiveFrom' => 'nullable|string',
            'effectiveTo' => 'nullable|string',
            'initial_allocation' => 'nullable|array',
            'initial_allocation.effectiveFrom' => 'nullable|date',
            'initial_allocation.effectiveTo' => 'nullable|date',
            'initial_allocation.outletBasisPoints' => 'required_with:initial_allocation|array|min:1',
            'initial_allocation.outletBasisPoints.*' => 'required|integer|min:1|max:10000',
            'initial_allocation.allocationTotalBasisPoints' => 'nullable|integer|in:10000',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            return ApiResponse::error('A business context is required to configure salary.', 422, null, 'VALIDATION_ERROR');
        }

        $employeeId = $validated['employee_id'] ?? $validated['employeeId'];
        $outletId = $validated['outlet_id'] ?? $validated['outletId'] ?? null;
        $employeeRow = DB::table('employees')
            ->where('id', $employeeId)
            ->where('is_deleted', 0)
            ->first();
        if (! $employeeRow) {
            return ApiResponse::error('The selected employee was not found.', 404, null, 'EMPLOYEE_NOT_FOUND');
        }
        $employeePayload = json_decode((string) $employeeRow->payload, true) ?: [];
        $employeeFields = $employeePayload['fields'] ?? $employeePayload;
        $employeeBusinesses = $employeeFields['businessIds'] ?? [];
        $employeeOutlets = $employeeFields['outletIds'] ?? [];
        if (! is_array($employeeBusinesses)) {
            $employeeBusinesses = [$employeeBusinesses];
        }
        if (! is_array($employeeOutlets)) {
            $employeeOutlets = [$employeeOutlets];
        }
        if ($employeeRow->outlet_id && ! in_array((string) $employeeRow->outlet_id, $employeeOutlets, true)) {
            $employeeOutlets[] = (string) $employeeRow->outlet_id;
        }
        if (! in_array((string) $businessId, array_map('strval', $employeeBusinesses), true)) {
            return ApiResponse::error('The selected employee is not assigned to this business.', 422, null, 'EMPLOYEE_SCOPE_MISMATCH');
        }
        if ($outletId !== null && ! in_array((string) $outletId, array_map('strval', $employeeOutlets), true)) {
            return ApiResponse::error('The selected employee is not assigned to this outlet.', 422, null, 'EMPLOYEE_SCOPE_MISMATCH');
        }
        $profileId = "SAL_PROF_{$businessId}_{$employeeId}";

        $nowDateTime = now();
        $nowIso = now()->toIso8601String();

        $baseRate = (int) ($validated['baseRatePaise'] ?? $validated['baseSalaryPaise'] ?? 0);
        $salaryType = strtoupper($validated['salaryType'] ?? 'MONTHLY');
        $paymentCycle = strtoupper($validated['paymentCycle'] ?? $validated['paymentFrequency'] ?? 'MONTHLY');

        $fields = [
            'profileId' => $profileId,
            'businessId' => $businessId,
            'outletId' => $outletId,
            'employeeId' => $employeeId,
            'tenantId' => $userId,
            'baseSalaryPaise' => $baseRate,
            'baseRatePaise' => $baseRate,
            'salaryType' => $salaryType,
            'paymentCycle' => $paymentCycle,
            'paymentFrequency' => $paymentCycle,
            'dailyWagePaise' => (int) ($validated['dailyWagePaise'] ?? ($salaryType === 'DAILY' ? $baseRate : 0)),
            'overtimeRatePaisePerHour' => (int) ($validated['overtimeRatePaisePerHour'] ?? 0),
            'effectiveFrom' => $validated['effectiveFrom'] ?? $nowIso,
            'effectiveTo' => $validated['effectiveTo'] ?? null,
            'status' => 'ACTIVE',
            'createdAt' => $nowIso,
            'updatedAt' => $nowIso,
        ];

        $initialAllocation = $validated['initial_allocation'] ?? null;
        if ($initialAllocation !== null && array_sum($initialAllocation['outletBasisPoints']) !== 10000) {
            throw ValidationException::withMessages([
                'initial_allocation.outletBasisPoints' => ['Outlet allocations must total exactly 10000 basis points.'],
            ]);
        }

        $allocation = null;
        if ($initialAllocation !== null) {
            $effectiveFrom = Carbon::parse($initialAllocation['effectiveFrom'] ?? $fields['effectiveFrom'])->utc();
            $effectiveTo = isset($initialAllocation['effectiveTo'])
                ? Carbon::parse($initialAllocation['effectiveTo'])->utc()
                : (isset($fields['effectiveTo']) ? Carbon::parse($fields['effectiveTo'])->utc() : null);
            if ($effectiveTo && ! $effectiveTo->gt($effectiveFrom)) {
                throw ValidationException::withMessages([
                    'initial_allocation.effectiveTo' => ['The allocation end date must be after its start date.'],
                ]);
            }

            foreach (array_keys($initialAllocation['outletBasisPoints']) as $allocationOutletId) {
                $outlet = DB::table('outlets')->where('id', $allocationOutletId)
                    ->where('business_id', $businessId)->where('is_deleted', 0)->first();
                if (! $outlet || ! in_array((string) $allocationOutletId, array_map('strval', $employeeOutlets), true)) {
                    return ApiResponse::error('Every allocated outlet must be assigned to the employee and business.', 422, null, 'OUTLET_EMPLOYEE_MISMATCH');
                }
            }

            $businessRow = DB::table('businesses')->where('id', $businessId)->first();
            $businessPayload = json_decode((string) ($businessRow->payload ?? ''), true) ?: [];
            $businessFields = $businessPayload['fields'] ?? $businessPayload;
            $tenantId = (string) ($businessFields['ownerUid'] ?? $businessFields['tenantId'] ?? ($businessRow->tenant_id ?? null) ?? $userId);
            $allocationId = 'SALA-'.substr(hash('sha256', implode('|', [
                $tenantId,
                $businessId,
                $employeeId,
                $effectiveFrom->format('Ymd'),
            ])), 0, 40);
            $allocation = compact('allocationId', 'tenantId', 'effectiveFrom', 'effectiveTo');
        }

        DB::transaction(function () use ($profileId, $businessId, $outletId, $employeeId, $fields, $nowDateTime, $initialAllocation, $allocation, $userId): void {
            DB::table('salary_profiles')->updateOrInsert(
                ['id' => $profileId],
                [
                    'document_path' => "salary_profiles/{$profileId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'employee_id' => $employeeId,
                    'status' => 'ACTIVE',
                    'created_at' => $nowDateTime,
                    'updated_at' => $nowDateTime,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        '_documentId' => $profileId,
                        '_documentPath' => "salary_profiles/{$profileId}",
                        'fields' => $fields,
                    ]),
                ]
            );

            if ($initialAllocation === null || $allocation === null) {
                return;
            }

            DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first();
            $existingAllocation = DB::table('employee_outlet_allocations')->where('id', $allocation['allocationId'])->first();
            if ($existingAllocation) {
                $existingPayload = json_decode((string) $existingAllocation->payload, true) ?: [];
                $existingFields = $existingPayload['fields'] ?? $existingPayload;
                if (($existingFields['outletBasisPoints'] ?? []) !== $initialAllocation['outletBasisPoints'] ||
                    Carbon::parse($existingAllocation->effective_from)->ne($allocation['effectiveFrom']) ||
                    (($existingAllocation->effective_to === null) !== ($allocation['effectiveTo'] === null)) ||
                    ($existingAllocation->effective_to && Carbon::parse($existingAllocation->effective_to)->ne($allocation['effectiveTo']))) {
                    throw ValidationException::withMessages([
                        'initial_allocation' => ['This allocation effective date already exists with different details.'],
                    ]);
                }

                return;
            }
            $priorRows = DB::table('employee_outlet_allocations')
                ->where('business_id', $businessId)->where('employee_id', $employeeId)
                ->where('is_deleted', 0)->lockForUpdate()->get();
            foreach ($priorRows as $prior) {
                $priorStart = Carbon::parse($prior->effective_from);
                $priorEnd = $prior->effective_to ? Carbon::parse($prior->effective_to) : null;
                $overlaps = (! $allocation['effectiveTo'] || $priorStart->lt($allocation['effectiveTo']))
                    && (! $priorEnd || $priorEnd->gt($allocation['effectiveFrom']));
                if (! $overlaps) {
                    continue;
                }
                if ($priorStart->lt($allocation['effectiveFrom']) && (! $priorEnd || $priorEnd->gt($allocation['effectiveFrom']))) {
                    $priorPayload = json_decode((string) $prior->payload, true) ?: [];
                    $priorFields = $priorPayload['fields'] ?? $priorPayload;
                    $priorFields['effectiveTo'] = $allocation['effectiveFrom']->toIso8601String();
                    $priorPayload['fields'] = $priorFields;
                    DB::table('employee_outlet_allocations')->where('id', $prior->id)->update([
                        'effective_to' => $allocation['effectiveFrom'],
                        'payload' => json_encode($priorPayload),
                        'updated_at' => now(),
                    ]);

                    continue;
                }
                throw ValidationException::withMessages([
                    'initial_allocation.effectiveFrom' => ['Outlet allocation effective dates cannot overlap.'],
                ]);
            }

            $allocationFields = [
                'allocationId' => $allocation['allocationId'],
                'tenantId' => $allocation['tenantId'],
                'businessId' => (string) $businessId,
                'employeeId' => $employeeId,
                'effectiveFrom' => $allocation['effectiveFrom']->toIso8601String(),
                'effectiveTo' => $allocation['effectiveTo']?->toIso8601String(),
                'outletBasisPoints' => $initialAllocation['outletBasisPoints'],
                'allocationTotalBasisPoints' => 10000,
                'status' => 'ACTIVE',
                'createdBy' => $userId,
                'createdAt' => now()->utc()->toIso8601String(),
            ];
            $documentPath = "employee_outlet_allocations/{$allocation['allocationId']}";
            $now = now();
            DB::table('employee_outlet_allocations')->insert([
                'id' => $allocation['allocationId'],
                'document_path' => $documentPath,
                'business_id' => $businessId,
                'employee_id' => $employeeId,
                'status' => 'ACTIVE',
                'effective_from' => $allocation['effectiveFrom'],
                'effective_to' => $allocation['effectiveTo'],
                'outlet_basis_points' => json_encode($initialAllocation['outletBasisPoints']),
                'allocation_total_basis_points' => 10000,
                'created_by' => $userId,
                'is_deleted' => 0,
                'payload' => json_encode(['_documentId' => $allocation['allocationId'], '_documentPath' => $documentPath, 'fields' => $allocationFields]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return ApiResponse::success($fields, 'Salary profile saved successfully.', 201);
    }

    /**
     * List payroll periods.
     */
    public function periods(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $employeeId = $request->query('employee_id') ?? $request->query('employeeId');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('salary_periods')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $periods = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $rowEmpId = (string) ($fields['employeeId'] ?? '');
            if ($employeeId && $rowEmpId && $rowEmpId !== $employeeId) {
                continue;
            }

            $periods[] = [
                'periodId' => (string) $row->id,
                'id' => (string) $row->id,
                'businessId' => (string) $row->business_id,
                'outletId' => $row->outlet_id,
                'employeeId' => $rowEmpId,
                'profileId' => (string) ($fields['profileId'] ?? ''),
                'periodStart' => $fields['periodStart'] ?? null,
                'periodEnd' => $fields['periodEnd'] ?? null,
                'workUnitsMilli' => (int) ($fields['workUnitsMilli'] ?? 0),
                'grossEarningsPaise' => (int) ($fields['grossEarningsPaise'] ?? $fields['totalGrossPaise'] ?? 0),
                'netEarnedPaise' => (int) ($fields['netEarnedPaise'] ?? $fields['totalNetPaise'] ?? 0),
                'bonusPaise' => (int) ($fields['bonusPaise'] ?? 0),
                'overtimePaise' => (int) ($fields['overtimePaise'] ?? 0),
                'approvedDeductionsPaise' => (int) ($fields['approvedDeductionsPaise'] ?? $fields['totalDeductionsPaise'] ?? 0),
                'unpaidLeaveDays' => (float) ($fields['unpaidLeaveDays'] ?? $fields['unpaid_leave_days'] ?? 0),
                'unpaidLeaveDeductionPaise' => (int) ($fields['unpaidLeaveDeductionPaise'] ?? $fields['deduction_amount_paise'] ?? 0),
                'dailyRatePaise' => (int) ($fields['dailyRatePaise'] ?? 0),
                'paidAmountPaise' => (int) ($fields['paidAmountPaise'] ?? 0),
                'advanceAdjustedPaise' => (int) ($fields['advanceAdjustedPaise'] ?? 0),
                'staffPurchaseAdjustedPaise' => (int) ($fields['staffPurchaseAdjustedPaise'] ?? 0),
                'periodMonth' => (int) ($fields['periodMonth'] ?? 0),
                'periodYear' => (int) ($fields['periodYear'] ?? 0),
                'totalEmployees' => (int) ($fields['totalEmployees'] ?? 1),
                'totalGrossPaise' => (int) ($fields['totalGrossPaise'] ?? $fields['grossEarningsPaise'] ?? 0),
                'totalDeductionsPaise' => (int) ($fields['totalDeductionsPaise'] ?? $fields['approvedDeductionsPaise'] ?? 0),
                'totalNetPaise' => (int) ($fields['totalNetPaise'] ?? $fields['netEarnedPaise'] ?? 0),
                'status' => (string) ($row->status ?? $fields['status'] ?? 'APPROVED'),
                'approvedAt' => $fields['approvedAt'] ?? $fields['disbursedAt'] ?? $row->created_at,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        }

        return ApiResponse::success($periods, 'Salary periods retrieved.');
    }

    /**
     * Disburse or approve a salary period.
     */
    public function disburse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'outletId' => 'nullable|string',
            'employee_id' => 'nullable|string',
            'employeeId' => 'nullable|string',
            'profile_id' => 'nullable|string',
            'profileId' => 'nullable|string',
            'periodStart' => 'nullable|string',
            'periodEnd' => 'nullable|string',
            'workUnitsMilli' => 'nullable|integer',
            'grossEarningsPaise' => 'nullable|integer',
            'netEarnedPaise' => 'nullable|integer',
            'bonusPaise' => 'nullable|integer',
            'overtimePaise' => 'nullable|integer',
            'approvedDeductionsPaise' => 'nullable|integer',
            'otherDeductionsPaise' => 'nullable|integer',
            'unpaid_leave_days' => 'nullable|numeric|min:0',
            'unpaidLeaveDays' => 'nullable|numeric|min:0',
            'periodMonth' => 'nullable|integer|between:1,12',
            'periodYear' => 'nullable|integer|min:2020|max:2050',
            'employeeIds' => 'nullable|array',
            'status' => 'nullable|string',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $requestedStatus = strtoupper((string) ($validated['status'] ?? 'DISBURSED'));
        $requiredPermissions = $requestedStatus === 'APPROVED'
            ? ['SALARY_APPROVE', 'staff_salary.approve']
            : ['SALARY_PAY', 'staff_salary.pay'];
        if (! app(EmployeePermissionService::class)->isAuthorized($user, $requiredPermissions)) {
            return ApiResponse::error('You do not have permission to perform this operation.', 403, null, 'PERMISSION_DENIED');
        }

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            return ApiResponse::error('A business context is required for payroll approval.', 422, null, 'VALIDATION_ERROR');
        }

        $month = (int) ($validated['periodMonth'] ?? now()->month);
        $year = (int) ($validated['periodYear'] ?? now()->year);
        $outletId = $validated['outlet_id'] ?? $validated['outletId'] ?? null;
        $employeeId = $validated['employee_id'] ?? $validated['employeeId'] ?? null;
        if (! $employeeId && ! empty($validated['employeeIds'])) {
            $employeeId = $validated['employeeIds'][0];
        }

        if ($employeeId) {
            $employeeRow = DB::table('employees')->where('id', $employeeId)->where('is_deleted', 0)->first();
            if (! $employeeRow) {
                return ApiResponse::error('The selected employee was not found.', 404, null, 'EMPLOYEE_NOT_FOUND');
            }
            $employeePayload = json_decode((string) $employeeRow->payload, true) ?: [];
            $employeeFields = $employeePayload['fields'] ?? $employeePayload;
            $employeeBusinesses = $employeeFields['businessIds'] ?? [];
            $employeeOutlets = $employeeFields['outletIds'] ?? [];
            if (! is_array($employeeBusinesses)) {
                $employeeBusinesses = [$employeeBusinesses];
            }
            if (! is_array($employeeOutlets)) {
                $employeeOutlets = [$employeeOutlets];
            }
            if ($employeeRow->outlet_id && ! in_array((string) $employeeRow->outlet_id, $employeeOutlets, true)) {
                $employeeOutlets[] = (string) $employeeRow->outlet_id;
            }
            if (! in_array((string) $businessId, array_map('strval', $employeeBusinesses), true)) {
                return ApiResponse::error('The selected employee is not assigned to this business.', 422, null, 'EMPLOYEE_SCOPE_MISMATCH');
            }
            if ($outletId !== null && ! in_array((string) $outletId, array_map('strval', $employeeOutlets), true)) {
                return ApiResponse::error('The selected employee is not assigned to this outlet.', 422, null, 'EMPLOYEE_SCOPE_MISMATCH');
            }
        }

        $nowDateTime = now();
        $nowIso = now()->toIso8601String();

        // Single employee period approval
        if ($employeeId) {
            $periodStart = $validated['periodStart'] ?? Carbon::create($year, $month, 1)->startOfMonth()->toIso8601String();
            $periodEnd = $validated['periodEnd'] ?? Carbon::create($year, $month, 1)->endOfMonth()->toIso8601String();
            $periodId = sprintf('SALPER_%s_%s_%04d%02d', $businessId, $employeeId, $year, $month);

            // Fetch profile if rate not provided
            $profileQuery = DB::table('salary_profiles')
                ->where('business_id', $businessId)
                ->where('employee_id', $employeeId)
                ->where('is_deleted', 0);
            $requestedProfileId = $validated['profile_id'] ?? $validated['profileId'] ?? null;
            if ($requestedProfileId) {
                $profileQuery->where('id', $requestedProfileId);
            }
            $prof = $profileQuery->first();
            if (! $prof) {
                return ApiResponse::error('The effective salary profile is unavailable.', 422, null, 'SALARY_PROFILE_UNAVAILABLE');
            }
            $pPayload = json_decode((string) $prof->payload, true) ?: [];
            $pF = $pPayload['fields'] ?? $pPayload;
            $salaryType = strtoupper((string) ($pF['salaryType'] ?? 'MONTHLY'));
            $baseRate = (int) ($pF['baseRatePaise'] ?? $pF['baseSalaryPaise'] ?? $pF['dailyWagePaise'] ?? 0);
            $units = (int) ($validated['workUnitsMilli'] ?? 1000);
            $bonus = (int) ($validated['bonusPaise'] ?? 0);
            $overtime = (int) ($validated['overtimePaise'] ?? 0);
            $deductions = (int) ($validated['approvedDeductionsPaise'] ?? 0);
            $baseEarnings = $salaryType === 'MONTHLY'
                ? $baseRate
                : intdiv(($baseRate * $units) + 500, 1000);
            $gross = (int) ($validated['grossEarningsPaise'] ?? ($baseEarnings + $bonus + $overtime));

            // Dynamic Unpaid Leave Days calculation:
            // $dailyRate = $monthlyGrossSalary / $daysInMonth
            // $deductionAmount = $dailyRate * $request->unpaid_leave_days
            // $netPayable = $monthlyGrossSalary - $deductionAmount
            $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth ?: 30;
            $unpaidLeaveDays = (float) ($validated['unpaid_leave_days'] ?? $validated['unpaidLeaveDays'] ?? 0);
            $monthlyGrossSalary = $gross / 100.0;
            $dailyRate = $daysInMonth > 0 ? ($monthlyGrossSalary / $daysInMonth) : 0.0;
            $deductionAmount = round($dailyRate * $unpaidLeaveDays, 2);

            $dailyRatePaise = (int) round($dailyRate * 100);
            $unpaidLeaveDeductionPaise = (int) round($deductionAmount * 100);

            // Other deductions
            $otherDeductionsPaise = (int) ($validated['otherDeductionsPaise'] ?? $validated['approvedDeductionsPaise'] ?? 0);
            $totalDeductionsPaise = $otherDeductionsPaise + $unpaidLeaveDeductionPaise;

            $net = max(0, $gross - $totalDeductionsPaise);
            $netPayable = $net / 100.0;

            if ($gross < 0 || $net < 0) {
                return ApiResponse::error('Deductions cannot exceed salary earnings.', 422, null, 'INVALID_SALARY_TOTAL');
            }

            $fields = [
                'periodId' => $periodId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'employeeId' => $employeeId,
                'profileId' => $prof->id,
                'periodStart' => $periodStart,
                'periodEnd' => $periodEnd,
                'daysInMonth' => $daysInMonth,
                'workUnitsMilli' => (int) ($validated['workUnitsMilli'] ?? 1000),
                'grossEarningsPaise' => $gross,
                'monthlyGrossSalary' => $monthlyGrossSalary,
                'dailyRate' => round($dailyRate, 2),
                'dailyRatePaise' => $dailyRatePaise,
                'unpaidLeaveDays' => $unpaidLeaveDays,
                'unpaid_leave_days' => $unpaidLeaveDays,
                'deductionAmount' => $deductionAmount,
                'unpaidLeaveDeductionPaise' => $unpaidLeaveDeductionPaise,
                'otherDeductionsPaise' => $otherDeductionsPaise,
                'approvedDeductionsPaise' => $totalDeductionsPaise,
                'netEarnedPaise' => $net,
                'netPayable' => $netPayable,
                'bonusPaise' => (int) ($validated['bonusPaise'] ?? 0),
                'overtimePaise' => (int) ($validated['overtimePaise'] ?? 0),
                'paidAmountPaise' => 0,
                'advanceAdjustedPaise' => 0,
                'staffPurchaseAdjustedPaise' => 0,
                'periodMonth' => $month,
                'periodYear' => $year,
                'status' => $requestedStatus,
                'approvedBy' => $userId,
                'approvedAt' => $nowIso,
                'createdAt' => $nowIso,
                'updatedAt' => $nowIso,
            ];

            // 1. Save to `salaries` table
            $salaryId = sprintf('SAL_%s_%s_%04d%02d', $businessId, $employeeId, $year, $month);
            DB::table('salaries')->updateOrInsert(
                ['id' => $salaryId],
                [
                    'document_path' => "salaries/{$salaryId}",
                    'period_id' => $periodId,
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'employee_id' => $employeeId,
                    'profile_id' => $prof->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'month' => $month,
                    'year' => $year,
                    'days_in_month' => $daysInMonth,
                    'monthly_gross_salary' => $monthlyGrossSalary,
                    'monthly_gross_salary_paise' => $gross,
                    'daily_rate' => round($dailyRate, 2),
                    'daily_rate_paise' => $dailyRatePaise,
                    'unpaid_leave_days' => $unpaidLeaveDays,
                    'deduction_amount' => $deductionAmount,
                    'deduction_amount_paise' => $unpaidLeaveDeductionPaise,
                    'unpaid_leave_deduction' => $deductionAmount,
                    'unpaid_leave_deduction_paise' => $unpaidLeaveDeductionPaise,
                    'other_deductions' => $otherDeductionsPaise / 100.0,
                    'other_deductions_paise' => $otherDeductionsPaise,
                    'bonus' => $bonus / 100.0,
                    'bonus_paise' => $bonus,
                    'overtime' => $overtime / 100.0,
                    'overtime_paise' => $overtime,
                    'net_payable' => $netPayable,
                    'net_payable_paise' => $net,
                    'status' => $requestedStatus,
                    'created_by' => $userId,
                    'is_deleted' => 0,
                    'payload' => json_encode($fields),
                    'created_at' => $nowDateTime,
                    'updated_at' => $nowDateTime,
                ]
            );

            // 2. Save to `salary_periods` table
            DB::table('salary_periods')->updateOrInsert(
                ['id' => $periodId],
                [
                    'document_path' => "salary_periods/{$periodId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => $fields['status'],
                    'created_at' => $nowDateTime,
                    'updated_at' => $nowDateTime,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        '_documentId' => $periodId,
                        '_documentPath' => "salary_periods/{$periodId}",
                        'fields' => $fields,
                    ]),
                ]
            );

            return ApiResponse::success($fields, "Salary period approved for {$month}/{$year}.", 201);
        }

        // Bulk disbursement
        $periodId = sprintf('PER_%s_%04d%02d', $businessId, $year, $month);

        return DB::transaction(function () use ($businessId, $outletId, $month, $year, $periodId, $userId, $nowDateTime, $nowIso) {
            $profiles = DB::table('salary_profiles')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->get();

            $totalEmployees = 0;
            $totalGross = 0;
            $totalDeductions = 0;

            foreach ($profiles as $prof) {
                $pData = json_decode($prof->payload, true) ?: [];
                $pF = $pData['fields'] ?? $pData;
                $base = (int) ($pF['baseRatePaise'] ?? $pF['baseSalaryPaise'] ?? 0);
                if ($base > 0) {
                    $totalEmployees++;
                    $totalGross += $base;
                }
            }

            $totalNet = max(0, $totalGross - $totalDeductions);

            $fields = [
                'periodId' => $periodId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'periodMonth' => $month,
                'periodYear' => $year,
                'totalEmployees' => $totalEmployees,
                'totalGrossPaise' => $totalGross,
                'totalDeductionsPaise' => $totalDeductions,
                'totalNetPaise' => $totalNet,
                'status' => 'DISBURSED',
                'disbursedBy' => $userId,
                'disbursedAt' => $nowIso,
                'createdAt' => $nowIso,
                'updatedAt' => $nowIso,
            ];

            DB::table('salary_periods')->updateOrInsert(
                ['id' => $periodId],
                [
                    'document_path' => "salary_periods/{$periodId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'DISBURSED',
                    'created_at' => $nowDateTime,
                    'updated_at' => $nowDateTime,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        '_documentId' => $periodId,
                        '_documentPath' => "salary_periods/{$periodId}",
                        'fields' => $fields,
                    ]),
                ]
            );

            return ApiResponse::success($fields, "Payroll disbursed for {$month}/{$year}.", 201);
        });
    }

    /**
     * List staff advance and khata transactions.
     */
    public function khata(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $employeeId = $request->query('employee_id') ?? $request->query('employeeId');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('staff_khata_transactions')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $transactions = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $type = (string) ($fields['type'] ?? 'ADVANCE');
            $txType = (string) ($fields['transactionType'] ?? ($type === 'SALARY_PAYMENT' ? 'SALARY_PAYMENT' : 'CASH_ADVANCE'));

            $transactions[] = [
                'transactionId' => (string) $row->id,
                'id' => (string) $row->id,
                'recordId' => (string) $row->id,
                'businessId' => (string) $row->business_id,
                'outletId' => $row->outlet_id,
                'employeeId' => (string) ($row->employee_id ?? $fields['employeeId'] ?? ''),
                'amountPaise' => (int) ($fields['amountPaise'] ?? 0),
                'type' => $type,
                'transactionType' => $txType,
                'paymentMode' => (string) ($fields['paymentMode'] ?? 'CASH'),
                'status' => (string) ($row->status ?? $fields['status'] ?? 'POSTED'),
                'balanceAfterPaise' => (int) ($fields['balanceAfterPaise'] ?? 0),
                'transactionDate' => $fields['transactionDate'] ?? $fields['createdAt'] ?? $row->created_at,
                'notes' => $fields['notes'] ?? null,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        }

        return ApiResponse::success($transactions, 'Staff khata transactions retrieved.');
    }

    /**
     * Record a staff salary advance or payout.
     */
    public function recordAdvance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'outletId' => 'nullable|string',
            'employee_id' => 'required|string',
            'employeeId' => 'nullable|string',
            'amountPaise' => 'required|integer|min:1',
            'type' => 'nullable|string|in:ADVANCE,SALARY_PAYMENT,ADJUSTMENT,advance,salary_payment,adjustment',
            'transactionType' => 'nullable|string',
            'paymentMode' => 'nullable|string',
            'transactionDate' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('A valid, authorized business context is required to record advance.', 403, null, 'FORBIDDEN');
        }

        $employeeId = $validated['employee_id'] ?? $validated['employeeId'];
        $outletId = $validated['outlet_id'] ?? $validated['outletId'] ?? null;
        $amountPaise = (int) $validated['amountPaise'];
        $type = strtoupper($validated['type'] ?? 'ADVANCE');
        $txType = strtoupper($validated['transactionType'] ?? ($type === 'SALARY_PAYMENT' ? 'SALARY_PAYMENT' : 'CASH_ADVANCE'));
        $paymentMode = strtoupper($validated['paymentMode'] ?? 'CASH');

        return DB::transaction(function () use ($businessId, $outletId, $employeeId, $amountPaise, $type, $txType, $paymentMode, $validated, $userId) {
            $nowDateTime = now();
            $nowIso = now()->toIso8601String();
            $txId = 'SKT-'.strtoupper(Str::random(24));

            // Find current outstanding advance balance
            $lastTx = DB::table('staff_khata_transactions')
                ->where('business_id', $businessId)
                ->where('employee_id', $employeeId)
                ->where('is_deleted', 0)
                ->orderBy('created_at', 'desc')
                ->lockForUpdate()
                ->first();

            $prevBalance = 0;
            if ($lastTx) {
                $p = json_decode($lastTx->payload, true) ?: [];
                $f = $p['fields'] ?? $p;
                $prevBalance = (int) ($f['balanceAfterPaise'] ?? 0);
            }

            $newBalance = $type === 'ADVANCE' ? ($prevBalance + $amountPaise) : max(0, $prevBalance - $amountPaise);

            $fields = [
                'transactionId' => $txId,
                'recordId' => $txId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'employeeId' => $employeeId,
                'tenantId' => $userId,
                'amountPaise' => $amountPaise,
                'type' => $type,
                'transactionType' => $txType,
                'paymentMode' => $paymentMode,
                'status' => 'POSTED',
                'balanceAfterPaise' => $newBalance,
                'transactionDate' => $validated['transactionDate'] ?? $nowIso,
                'notes' => $validated['notes'] ?? ($type === 'ADVANCE' ? 'Staff cash advance' : 'Staff salary payout'),
                'createdAt' => $nowIso,
                'updatedAt' => $nowIso,
            ];

            DB::table('staff_khata_transactions')->insert([
                'id' => $txId,
                'document_path' => "staff_khata_transactions/{$txId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'employee_id' => $employeeId,
                'status' => 'POSTED',
                'created_at' => $nowDateTime,
                'updated_at' => $nowDateTime,
                'is_deleted' => 0,
                'payload' => json_encode([
                    '_documentId' => $txId,
                    '_documentPath' => "staff_khata_transactions/{$txId}",
                    'fields' => $fields,
                ]),
            ]);

            return ApiResponse::success($fields, 'Advance of ₹'.number_format($amountPaise / 100, 2).' recorded. Outstanding: ₹'.number_format($newBalance / 100, 2), 201);
        });
    }

    /** Record a salary payment against an approved payroll period. */
    public function recordSalaryPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'required|string',
            'outlet_id' => 'required|string',
            'employee_id' => 'required|string',
            'period_id' => 'required|string',
            'amountPaise' => 'required|integer|min:1',
            'paymentMode' => 'required|string|in:CASH,UPI,BANK_TRANSFER,CHEQUE,CARD',
            'transactionDate' => 'required|date',
            'request_id' => ['required', 'string', 'max:70', 'regex:/^[A-Za-z0-9_-]+$/'],
            'referenceNo' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;
        $businessId = $validated['business_id'];
        $outletId = $validated['outlet_id'];
        $employeeId = $validated['employee_id'];
        $periodId = $validated['period_id'];
        $amountPaise = (int) $validated['amountPaise'];
        $transactionId = 'SKH-PAY-'.$validated['request_id'];
        $financialId = 'FIN-SAL-'.$validated['request_id'];

        $authorization = app(EmployeePermissionService::class);
        if (! $authorization->isAuthorized($user, ['SALARY_PAY', 'staff_salary.pay'])) {
            return ApiResponse::error('You do not have permission to disburse salary payments.', 403, null, 'PERMISSION_DENIED');
        }
        if (! $authorization->isWithinAssignedScope($user, $businessId, $outletId)) {
            return ApiResponse::error('This business or outlet is not assigned to your account.', 403, null, 'PERMISSION_DENIED');
        }

        $result = DB::transaction(function () use ($user, $userId, $businessId, $outletId, $employeeId, $periodId, $amountPaise, $transactionId, $financialId, $validated) {
            $business = DB::table('businesses')->where('id', $businessId)->where('is_deleted', 0)->first();
            if (! $business) {
                return ['error' => 'Business not found.', 'status' => 404];
            }
            $businessPayload = json_decode($business->payload, true) ?: [];
            $businessFields = $businessPayload['fields'] ?? $businessPayload;
            $ownerUid = (string) ($businessFields['ownerUid'] ?? $business->owner_uid ?? '');
            $isOwner = $ownerUid === $userId;
            $isStaff = strtoupper((string) $user->role) === 'STAFF';
            if (! $user->isAdmin() && ! $isStaff && ! $isOwner) {
                return ['error' => 'Unauthorized access to this business.', 'status' => 403];
            }

            $outlet = DB::table('outlets')->where('id', $outletId)->where('business_id', $businessId)->where('is_deleted', 0)->first();
            if (! $outlet) {
                return ['error' => 'Payment outlet does not belong to this business.', 'status' => 422];
            }
            $outletPayload = json_decode($outlet->payload, true) ?: [];
            $outletFields = $outletPayload['fields'] ?? $outletPayload;
            if (($outletFields['isActive'] ?? (($outletFields['status'] ?? $outlet->status ?? 'active') === 'active')) !== true) {
                return ['error' => 'The selected payment outlet is inactive.', 'status' => 422];
            }

            $employee = DB::table('employees')->where('id', $employeeId)->where('is_deleted', 0)->first();
            if (! $employee) {
                return ['error' => 'Employee not found.', 'status' => 404];
            }
            $employeePayload = json_decode($employee->payload, true) ?: [];
            $employeeFields = $employeePayload['fields'] ?? $employeePayload;
            $employeeBusinessIds = $employeeFields['businessIds'] ?? [];
            $employeeBusinessIds = is_array($employeeBusinessIds) ? $employeeBusinessIds : [$employeeBusinessIds];
            if ($employee->business_id && ! in_array($employee->business_id, $employeeBusinessIds, true)) {
                $employeeBusinessIds[] = $employee->business_id;
            }
            if (! in_array($businessId, $employeeBusinessIds, true)) {
                return ['error' => 'Employee is not assigned to this business.', 'status' => 422];
            }
            $employeeOutletIds = $employeeFields['outletIds'] ?? [];
            $employeeOutletIds = is_array($employeeOutletIds) ? $employeeOutletIds : [$employeeOutletIds];
            if ($employee->outlet_id && ! in_array($employee->outlet_id, $employeeOutletIds, true)) {
                $employeeOutletIds[] = $employee->outlet_id;
            }
            if (! $isOwner && ! $user->isAdmin() && ! in_array($outletId, $employeeOutletIds, true)) {
                return ['error' => 'Payment outlet is not assigned to this employee.', 'status' => 422];
            }

            $existing = DB::table('staff_khata_transactions')->where('id', $transactionId)->where('business_id', $businessId)->where('is_deleted', 0)->first();
            if ($existing) {
                $existingPayload = json_decode($existing->payload, true) ?: [];
                $existingFields = $existingPayload['fields'] ?? $existingPayload;
                if (($existingFields['employeeId'] ?? null) !== $employeeId || ($existingFields['periodId'] ?? null) !== $periodId || (int) ($existingFields['amountPaise'] ?? 0) !== $amountPaise || ($existingFields['outletId'] ?? null) !== $outletId) {
                    return ['error' => 'This payment request ID was already used for different details.', 'status' => 409];
                }

                return ['fields' => $existingFields, 'alreadyRecorded' => true];
            }

            $period = DB::table('salary_periods')->where('id', $periodId)->where('business_id', $businessId)->where('is_deleted', 0)->lockForUpdate()->first();
            if (! $period) {
                return ['error' => 'Approved salary payable was not found.', 'status' => 404];
            }
            $periodPayload = json_decode($period->payload, true) ?: [];
            $periodFields = $periodPayload['fields'] ?? $periodPayload;
            $periodStatus = strtoupper((string) ($period->status ?? $periodFields['status'] ?? ''));
            $periodOutletId = (string) ($periodFields['outletId'] ?? $period->outlet_id ?? '');
            if (($periodFields['employeeId'] ?? null) !== $employeeId ||
                ($periodOutletId !== '' && $periodOutletId !== $outletId) ||
                ! in_array($periodStatus, ['APPROVED', 'PARTIALLY_PAID'], true)) {
                return ['error' => 'Approved salary payable was not found.', 'status' => 404];
            }

            $earned = (int) ($periodFields['netEarnedPaise'] ?? $periodFields['totalNetPaise'] ?? 0);
            $paid = (int) ($periodFields['paidAmountPaise'] ?? 0);
            $advances = (int) ($periodFields['advanceAdjustedPaise'] ?? 0);
            $purchases = (int) ($periodFields['staffPurchaseAdjustedPaise'] ?? 0);
            if ($amountPaise > $earned - $paid - $advances - $purchases) {
                return ['error' => 'Payment exceeds remaining salary payable.', 'status' => 422];
            }

            $now = now();
            $nowIso = $now->toIso8601String();
            $nextPaid = $paid + $amountPaise;
            $periodFields['paidAmountPaise'] = $nextPaid;
            $periodFields['status'] = $nextPaid + $advances + $purchases === $earned ? 'PAID' : 'PARTIALLY_PAID';
            $periodFields['updatedAt'] = $nowIso;
            $periodPayload['fields'] = $periodFields;
            DB::table('salary_periods')->where('id', $periodId)->update([
                'status' => $periodFields['status'],
                'updated_at' => $now,
                'payload' => json_encode($periodPayload),
            ]);

            $transactionDate = Carbon::parse($validated['transactionDate']);
            $paymentFields = [
                'transactionId' => $transactionId,
                'recordId' => $transactionId,
                'tenantId' => $ownerUid,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'employeeId' => $employeeId,
                'periodId' => $periodId,
                'transactionType' => 'SALARY_PAYMENT',
                'amountPaise' => $amountPaise,
                'paymentMode' => $validated['paymentMode'],
                'transactionDate' => $transactionDate->toIso8601String(),
                'financialTransactionId' => $financialId,
                'requestId' => $validated['request_id'],
                'referenceNo' => $validated['referenceNo'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'POSTED',
                'createdBy' => $userId,
                'createdAt' => $nowIso,
                'updatedAt' => $nowIso,
            ];
            DB::table('staff_khata_transactions')->insert([
                'id' => $transactionId,
                'document_path' => "staff_khata_transactions/{$transactionId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'employee_id' => $employeeId,
                'status' => 'POSTED',
                'created_at' => $now,
                'updated_at' => $now,
                'is_deleted' => 0,
                'payload' => json_encode(['_documentId' => $transactionId, '_documentPath' => "staff_khata_transactions/{$transactionId}", 'fields' => $paymentFields]),
            ]);

            $financialFields = [
                'transactionId' => $financialId,
                'tenantId' => $ownerUid,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'direction' => 'OUT',
                'transactionType' => 'SALARY_PAYMENT',
                'partyType' => 'EMPLOYEE',
                'partyId' => $employeeId,
                'amount' => $amountPaise,
                'paymentMode' => $validated['paymentMode'],
                'referenceType' => 'SALARY_PERIOD',
                'referenceId' => $periodId,
                'transactionDate' => $transactionDate->toIso8601String(),
                'referenceNo' => $validated['referenceNo'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'createdByUid' => $userId,
                'createdAt' => $nowIso,
                'updatedAt' => $nowIso,
                'status' => 'POSTED',
            ];
            DB::table('financial_transactions')->insert([
                'id' => $financialId,
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'tenant_id' => $ownerUid,
                'transaction_type' => 'SALARY_PAYMENT',
                'category' => 'STAFF_SALARY',
                'amount_paise' => $amountPaise,
                'payment_mode' => $validated['paymentMode'],
                'reference_id' => $periodId,
                'reference_type' => 'SALARY_PERIOD',
                'description' => 'Salary payment to '.($employeeFields['name'] ?? 'employee'),
                'transaction_date' => $transactionDate->toDateTimeString(),
                'recorded_by' => $userId,
                'status' => 'COMPLETED',
                'document_path' => "businesses/{$businessId}/financial_transactions/{$financialId}",
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $financialFields]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['fields' => $paymentFields, 'alreadyRecorded' => false];
        });

        if (isset($result['error'])) {
            return ApiResponse::error($result['error'], $result['status']);
        }

        return ApiResponse::success($result['fields'], $result['alreadyRecorded'] ? 'Salary payment already recorded.' : 'Salary payment recorded successfully.', $result['alreadyRecorded'] ? 200 : 201);
    }

    /**
     * Apply a posted staff advance to an approved salary period.
     * This is a non-cash ledger adjustment, so it does not create a financial
     * transaction or cash movement.
     */
    public function adjustAdvance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'required|string',
            'outlet_id' => 'required|string',
            'employee_id' => 'required|string',
            'period_id' => 'required|string',
            'amountPaise' => 'required|integer|min:1',
            'request_id' => ['required', 'string', 'max:70', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $user = $request->user();
        $userId = (string) $user->id;
        $businessId = $validated['business_id'];
        $outletId = $validated['outlet_id'];
        $employeeId = $validated['employee_id'];
        $periodId = $validated['period_id'];
        $amountPaise = (int) $validated['amountPaise'];
        $transactionId = 'SKH-ADJ-'.$validated['request_id'];

        $authorization = app(EmployeePermissionService::class);
        if (! $authorization->isAuthorized($user, ['SALARY_APPROVE', 'staff_salary.approve'])) {
            return ApiResponse::error('You do not have permission to adjust an employee advance.', 403, null, 'PERMISSION_DENIED');
        }
        if (! $authorization->isWithinAssignedScope($user, $businessId, $outletId)) {
            return ApiResponse::error('This business or outlet is not assigned to your account.', 403, null, 'PERMISSION_DENIED');
        }

        $result = DB::transaction(function () use (
            $user,
            $userId,
            $businessId,
            $outletId,
            $employeeId,
            $periodId,
            $amountPaise,
            $transactionId
        ) {
            $business = DB::table('businesses')
                ->where('id', $businessId)
                ->where('is_deleted', 0)
                ->first();
            if (! $business) {
                return ['error' => 'Business not found.', 'status' => 404];
            }

            $businessPayload = json_decode($business->payload, true) ?: [];
            $businessFields = $businessPayload['fields'] ?? $businessPayload;
            $ownerUid = (string) ($businessFields['ownerUid'] ?? $business->owner_uid ?? '');
            $isStaff = strtoupper((string) $user->role) === 'STAFF';
            if (! $user->isAdmin() && ! $isStaff && $ownerUid !== $userId) {
                return ['error' => 'Unauthorized access to this business.', 'status' => 403];
            }

            $employee = DB::table('employees')
                ->where('id', $employeeId)
                ->where('is_deleted', 0)
                ->first();
            if (! $employee) {
                return ['error' => 'Employee not found.', 'status' => 404];
            }
            $employeePayload = json_decode($employee->payload, true) ?: [];
            $employeeFields = $employeePayload['fields'] ?? $employeePayload;
            $businessIds = $employeeFields['businessIds'] ?? [];
            $businessIds = is_array($businessIds) ? $businessIds : [$businessIds];
            if ($employee->business_id && ! in_array($employee->business_id, $businessIds, true)) {
                $businessIds[] = $employee->business_id;
            }
            $outletIds = $employeeFields['outletIds'] ?? [];
            $outletIds = is_array($outletIds) ? $outletIds : [$outletIds];
            if ($employee->outlet_id && ! in_array($employee->outlet_id, $outletIds, true)) {
                $outletIds[] = $employee->outlet_id;
            }
            if (! in_array($businessId, $businessIds, true) || ! in_array($outletId, $outletIds, true)) {
                return ['error' => 'Adjustment outlet is not assigned to this employee and business.', 'status' => 422];
            }

            $outlet = DB::table('outlets')
                ->where('id', $outletId)
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->first();
            if (! $outlet) {
                return ['error' => 'Adjustment outlet is not assigned to this employee and business.', 'status' => 422];
            }
            $outletPayload = json_decode($outlet->payload, true) ?: [];
            $outletFields = $outletPayload['fields'] ?? $outletPayload;
            if (($outletFields['isActive'] ?? (($outletFields['status'] ?? $outlet->status ?? 'active') === 'active')) !== true) {
                return ['error' => 'The selected outlet is inactive.', 'status' => 422];
            }

            $existing = DB::table('staff_khata_transactions')
                ->where('id', $transactionId)
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->first();
            if ($existing) {
                $existingPayload = json_decode($existing->payload, true) ?: [];
                $existingFields = $existingPayload['fields'] ?? $existingPayload;
                if (($existingFields['employeeId'] ?? null) !== $employeeId ||
                    ($existingFields['periodId'] ?? null) !== $periodId ||
                    (int) ($existingFields['amountPaise'] ?? 0) !== $amountPaise) {
                    return ['error' => 'This adjustment request ID was already used for different details.', 'status' => 409];
                }

                return ['fields' => $existingFields, 'alreadyRecorded' => true];
            }

            $period = DB::table('salary_periods')
                ->where('id', $periodId)
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->lockForUpdate()
                ->first();
            if (! $period) {
                return ['error' => 'Approved salary payable was not found.', 'status' => 404];
            }
            $periodPayload = json_decode($period->payload, true) ?: [];
            $periodFields = $periodPayload['fields'] ?? $periodPayload;
            $periodStatus = strtoupper((string) ($period->status ?? $periodFields['status'] ?? ''));
            $periodOutletId = (string) ($periodFields['outletId'] ?? $period->outlet_id ?? '');
            if (($periodFields['employeeId'] ?? null) !== $employeeId ||
                ($periodOutletId !== '' && $periodOutletId !== $outletId) ||
                ! in_array($periodStatus, ['APPROVED', 'PARTIALLY_PAID'], true)) {
                return ['error' => 'Approved salary payable was not found.', 'status' => 404];
            }

            $khataRows = DB::table('staff_khata_transactions')
                ->where('business_id', $businessId)
                ->where('employee_id', $employeeId)
                ->where('is_deleted', 0)
                ->lockForUpdate()
                ->get();
            $advanceBalance = 0;
            foreach ($khataRows as $row) {
                $payload = json_decode($row->payload, true) ?: [];
                $fields = $payload['fields'] ?? $payload;
                if (strtoupper((string) ($row->status ?? $fields['status'] ?? 'POSTED')) !== 'POSTED') {
                    continue;
                }
                $type = strtoupper((string) ($fields['transactionType'] ?? ''));
                $kind = strtoupper((string) ($fields['type'] ?? ''));
                $value = (int) ($fields['amountPaise'] ?? 0);
                if (in_array($type, ['CASH_ADVANCE', 'UPI_ADVANCE', 'BANK_ADVANCE'], true) || $kind === 'ADVANCE') {
                    $advanceBalance += $value;
                } elseif ($type === 'ADVANCE_ADJUSTMENT' || $kind === 'ADJUSTMENT') {
                    $advanceBalance -= $value;
                }
            }

            $earned = (int) ($periodFields['netEarnedPaise'] ?? $periodFields['totalNetPaise'] ?? 0);
            $paid = (int) ($periodFields['paidAmountPaise'] ?? 0);
            $adjusted = (int) ($periodFields['advanceAdjustedPaise'] ?? 0);
            $purchaseAdjusted = (int) ($periodFields['staffPurchaseAdjustedPaise'] ?? 0);
            if ($amountPaise > $advanceBalance ||
                $amountPaise > $earned - $paid - $adjusted - $purchaseAdjusted) {
                return ['error' => 'Advance adjustment exceeds the advance balance or salary payable.', 'status' => 422];
            }

            $now = now();
            $nowIso = $now->toIso8601String();
            $nextAdjusted = $adjusted + $amountPaise;
            $periodFields['advanceAdjustedPaise'] = $nextAdjusted;
            $periodFields['status'] = $paid + $nextAdjusted + $purchaseAdjusted === $earned
                ? 'PAID'
                : 'PARTIALLY_PAID';
            $periodFields['updatedAt'] = $nowIso;
            $periodPayload['fields'] = $periodFields;
            DB::table('salary_periods')->where('id', $periodId)->update([
                'status' => $periodFields['status'],
                'updated_at' => $now,
                'payload' => json_encode($periodPayload),
            ]);

            $fields = [
                'transactionId' => $transactionId,
                'recordId' => $transactionId,
                'tenantId' => $ownerUid,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'employeeId' => $employeeId,
                'periodId' => $periodId,
                'transactionType' => 'ADVANCE_ADJUSTMENT',
                'amountPaise' => $amountPaise,
                'paymentMode' => 'NON_CASH',
                'status' => 'POSTED',
                'balanceAfterPaise' => max(0, $advanceBalance - $amountPaise),
                'requestId' => substr($transactionId, strlen('SKH-ADJ-')),
                'transactionDate' => $nowIso,
                'approvedBy' => $userId,
                'createdBy' => $userId,
                'createdAt' => $nowIso,
                'updatedAt' => $nowIso,
            ];
            DB::table('staff_khata_transactions')->insert([
                'id' => $transactionId,
                'document_path' => "staff_khata_transactions/{$transactionId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'employee_id' => $employeeId,
                'status' => 'POSTED',
                'created_at' => $now,
                'updated_at' => $now,
                'is_deleted' => 0,
                'payload' => json_encode([
                    '_documentId' => $transactionId,
                    '_documentPath' => "staff_khata_transactions/{$transactionId}",
                    'fields' => $fields,
                ]),
            ]);

            return ['fields' => $fields, 'alreadyRecorded' => false];
        });

        if (isset($result['error'])) {
            return ApiResponse::error($result['error'], $result['status']);
        }

        return ApiResponse::success(
            $result['fields'],
            $result['alreadyRecorded'] ? 'Advance adjustment already recorded.' : 'Advance adjustment applied successfully.'
        );
    }
}
