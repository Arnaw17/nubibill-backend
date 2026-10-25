<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollController extends Controller
{
    /**
     * List employee salary profiles.
     */
    public function profiles(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $employeeId = $request->query('employee_id') ?? $request->query('employeeId');

        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
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

            $profiles[] = [
                'profileId' => (string) $row->id,
                'id' => (string) $row->id,
                'businessId' => (string) $row->business_id,
                'outletId' => $row->outlet_id,
                'employeeId' => (string) ($row->employee_id ?? $fields['employeeId'] ?? ''),
                'baseSalaryPaise' => (int) ($fields['baseSalaryPaise'] ?? 0),
                'dailyWagePaise' => (int) ($fields['dailyWagePaise'] ?? 0),
                'overtimeRatePaisePerHour' => (int) ($fields['overtimeRatePaisePerHour'] ?? 0),
                'paymentFrequency' => $fields['paymentFrequency'] ?? 'MONTHLY',
                'effectiveFrom' => $fields['effectiveFrom'] ?? null,
                'status' => (string) ($row->status ?? 'ACTIVE'),
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
            'baseSalaryPaise' => 'required|integer|min:0',
            'dailyWagePaise' => 'nullable|integer|min:0',
            'overtimeRatePaisePerHour' => 'nullable|integer|min:0',
            'paymentFrequency' => 'nullable|string|in:MONTHLY,WEEKLY,DAILY,monthly,weekly,daily',
            'effectiveFrom' => 'nullable|string',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        $employeeId = $validated['employee_id'] ?? $validated['employeeId'];
        $outletId = $validated['outlet_id'] ?? $validated['outletId'] ?? null;
        $profileId = "SAL_PROF_{$businessId}_{$employeeId}";

        $nowDateTime = now();
        $nowIso = now()->toIso8601String();

        $fields = [
            'profileId' => $profileId,
            'businessId' => $businessId,
            'outletId' => $outletId,
            'employeeId' => $employeeId,
            'tenantId' => $userId,
            'baseSalaryPaise' => (int) $validated['baseSalaryPaise'],
            'dailyWagePaise' => (int) ($validated['dailyWagePaise'] ?? 0),
            'overtimeRatePaisePerHour' => (int) ($validated['overtimeRatePaisePerHour'] ?? 0),
            'paymentFrequency' => strtoupper($validated['paymentFrequency'] ?? 'MONTHLY'),
            'effectiveFrom' => $validated['effectiveFrom'] ?? $nowIso,
            'status' => 'ACTIVE',
            'createdAt' => $nowIso,
            'updatedAt' => $nowIso,
        ];

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

        return ApiResponse::success($fields, 'Salary profile saved successfully.', 201);
    }

    /**
     * List payroll periods.
     */
    public function periods(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');

        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
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

            $periods[] = [
                'periodId' => (string) $row->id,
                'id' => (string) $row->id,
                'businessId' => (string) $row->business_id,
                'outletId' => $row->outlet_id,
                'periodMonth' => (int) ($fields['periodMonth'] ?? 0),
                'periodYear' => (int) ($fields['periodYear'] ?? 0),
                'totalEmployees' => (int) ($fields['totalEmployees'] ?? 0),
                'totalGrossPaise' => (int) ($fields['totalGrossPaise'] ?? 0),
                'totalDeductionsPaise' => (int) ($fields['totalDeductionsPaise'] ?? 0),
                'totalNetPaise' => (int) ($fields['totalNetPaise'] ?? 0),
                'status' => (string) ($row->status ?? 'DISBURSED'),
                'disbursedAt' => $fields['disbursedAt'] ?? $row->created_at,
                'createdAt' => $row->created_at,
            ];
        }

        return ApiResponse::success($periods, 'Salary periods retrieved.');
    }

    /**
     * Disburse and finalize monthly payroll.
     */
    public function disburse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'outletId' => 'nullable|string',
            'periodMonth' => 'required|integer|between:1,12',
            'periodYear' => 'required|integer|min:2020|max:2050',
            'employeeIds' => 'nullable|array',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        $month = (int) $validated['periodMonth'];
        $year = (int) $validated['periodYear'];
        $outletId = $validated['outlet_id'] ?? $validated['outletId'] ?? null;
        $periodId = sprintf('PER_%s_%04d%02d', $businessId, $year, $month);

        return DB::transaction(function () use ($businessId, $outletId, $month, $year, $periodId, $userId) {
            $nowDateTime = now();
            $nowIso = now()->toIso8601String();

            // Fetch employees with salary profiles
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
                $base = (int) ($pF['baseSalaryPaise'] ?? 0);
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

        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
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

            $transactions[] = [
                'transactionId' => (string) $row->id,
                'id' => (string) $row->id,
                'businessId' => (string) $row->business_id,
                'outletId' => $row->outlet_id,
                'employeeId' => (string) ($row->employee_id ?? $fields['employeeId'] ?? ''),
                'amountPaise' => (int) ($fields['amountPaise'] ?? 0),
                'type' => (string) ($fields['type'] ?? 'ADVANCE'),
                'balanceAfterPaise' => (int) ($fields['balanceAfterPaise'] ?? 0),
                'notes' => $fields['notes'] ?? null,
                'createdAt' => $row->created_at,
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
            'type' => 'nullable|string|in:ADVANCE,SALARY_PAYMENT,ADJUSTMENT',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        $employeeId = $validated['employee_id'] ?? $validated['employeeId'];
        $outletId = $validated['outlet_id'] ?? $validated['outletId'] ?? null;
        $amountPaise = (int) $validated['amountPaise'];
        $type = strtoupper($validated['type'] ?? 'ADVANCE');

        return DB::transaction(function () use ($businessId, $outletId, $employeeId, $amountPaise, $type, $validated, $userId) {
            $nowDateTime = now();
            $nowIso = now()->toIso8601String();
            $txId = 'SKT-' . strtoupper(Str::random(24));

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
                'businessId' => $businessId,
                'outletId' => $outletId,
                'employeeId' => $employeeId,
                'tenantId' => $userId,
                'amountPaise' => $amountPaise,
                'type' => $type,
                'balanceAfterPaise' => $newBalance,
                'notes' => $validated['notes'] ?? 'Staff advance recorded',
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

            return ApiResponse::success($fields, "Advance of ₹" . number_format($amountPaise / 100, 2) . " recorded. Outstanding: ₹" . number_format($newBalance / 100, 2), 201);
        });
    }
}
