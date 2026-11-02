<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeOutletAllocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id')
            ?? $request->query('businessId')
            ?? $request->header('X-Business-Id');
        $employeeId = $request->query('employee_id') ?? $request->query('employeeId');
        $user = $request->user();

        if (! $businessId || ! $user->canAccessBusiness((string) $businessId)) {
            return ApiResponse::error('A valid, authorized business context is required.', 403, null, 'FORBIDDEN');
        }

        $query = DB::table('employee_outlet_allocations')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        $allocations = $query->orderBy('effective_from')->get()->map(function ($row): array {
            $fields = $this->fields($row->payload);

            return [
                'allocationId' => (string) $row->id,
                'tenantId' => (string) ($fields['tenantId'] ?? $row->tenant_id ?? ''),
                'businessId' => (string) $row->business_id,
                'employeeId' => (string) $row->employee_id,
                'effectiveFrom' => $fields['effectiveFrom'] ?? $row->effective_from,
                'effectiveTo' => $fields['effectiveTo'] ?? $row->effective_to,
                'outletBasisPoints' => $fields['outletBasisPoints'] ?? json_decode($row->outlet_basis_points, true) ?? [],
                'allocationTotalBasisPoints' => (int) ($fields['allocationTotalBasisPoints'] ?? $row->allocation_total_basis_points),
                'status' => (string) ($fields['status'] ?? $row->status),
                'createdBy' => $fields['createdBy'] ?? $row->created_by,
                'createdAt' => $fields['createdAt'] ?? $row->created_at,
            ];
        });

        return ApiResponse::success($allocations, 'Employee outlet allocations retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'businessId' => $request->input('businessId', $request->input('business_id')),
            'employeeId' => $request->input('employeeId', $request->input('employee_id')),
            'effectiveFrom' => $request->input('effectiveFrom', $request->input('effective_from')),
            'effectiveTo' => $request->input('effectiveTo', $request->input('effective_to')),
            'outletBasisPoints' => $request->input('outletBasisPoints', $request->input('outlet_basis_points')),
        ]);
        $validated = $request->validate([
            'businessId' => 'nullable|string|max:100',
            'employeeId' => 'required|string|max:255',
            'effectiveFrom' => 'required|date',
            'effectiveTo' => 'nullable|date|after:effectiveFrom',
            'outletBasisPoints' => 'required|array|min:1',
            'outletBasisPoints.*' => 'required|integer|min:1|max:10000',
            'allocationTotalBasisPoints' => 'nullable|integer|in:10000',
        ]);

        $businessId = $validated['businessId'] ?? $request->header('X-Business-Id');
        $user = $request->user();
        if (! $businessId || ! $user->canAccessBusiness((string) $businessId)) {
            return ApiResponse::error('A valid, authorized business context is required.', 403, null, 'FORBIDDEN');
        }

        if (array_sum($validated['outletBasisPoints']) !== 10000) {
            throw ValidationException::withMessages([
                'outletBasisPoints' => ['Outlet allocations must total exactly 10000 basis points.'],
            ]);
        }

        $employee = DB::table('employees')
            ->where('id', $validated['employeeId'])
            ->where('is_deleted', 0)
            ->first();
        if (! $employee) {
            return ApiResponse::error('The selected employee was not found.', 404, null, 'EMPLOYEE_NOT_FOUND');
        }
        $employeeFields = $this->fields($employee->payload);
        if (! in_array($businessId, $employeeFields['businessIds'] ?? [], true)) {
            return ApiResponse::error('The selected employee is not assigned to this business.', 422, null, 'EMPLOYEE_BUSINESS_MISMATCH');
        }

        foreach (array_keys($validated['outletBasisPoints']) as $outletId) {
            $outlet = DB::table('outlets')->where('id', $outletId)->where('business_id', $businessId)->where('is_deleted', 0)->first();
            if (! $outlet || ! in_array($outletId, $employeeFields['outletIds'] ?? [], true)) {
                return ApiResponse::error('Every allocated outlet must be assigned to the employee and business.', 422, null, 'OUTLET_EMPLOYEE_MISMATCH');
            }
        }

        $effectiveFrom = Carbon::parse($validated['effectiveFrom'])->utc();
        $effectiveTo = isset($validated['effectiveTo']) ? Carbon::parse($validated['effectiveTo'])->utc() : null;
        $dateKey = $effectiveFrom->format('Ymd');
        $allocationId = 'SALA-'.substr(hash('sha256', implode('|', [
            $this->tenantIdForBusiness((string) $businessId, (string) $user->id),
            $businessId,
            $validated['employeeId'],
            $dateKey,
        ])), 0, 40);

        $allocation = DB::transaction(function () use ($allocationId, $businessId, $validated, $effectiveFrom, $effectiveTo, $user): array {
            DB::table('employees')->where('id', $validated['employeeId'])->lockForUpdate()->first();

            $existing = DB::table('employee_outlet_allocations')->where('id', $allocationId)->first();
            if ($existing) {
                $existingFields = $this->fields($existing->payload);
                if (($existingFields['outletBasisPoints'] ?? []) !== $validated['outletBasisPoints'] ||
                    Carbon::parse($existing->effective_from)->ne($effectiveFrom) ||
                    ($existing->effective_to && (! $effectiveTo || Carbon::parse($existing->effective_to)->ne($effectiveTo)))) {
                    return [];
                }

                return $this->allocationResponse($existing);
            }

            $priorRows = DB::table('employee_outlet_allocations')
                ->where('business_id', $businessId)
                ->where('employee_id', $validated['employeeId'])
                ->where('is_deleted', 0)
                ->lockForUpdate()
                ->get();
            foreach ($priorRows as $prior) {
                $priorStart = Carbon::parse($prior->effective_from);
                $priorEnd = $prior->effective_to ? Carbon::parse($prior->effective_to) : null;
                $overlaps = (! $effectiveTo || $priorStart->lt($effectiveTo)) && (! $priorEnd || $priorEnd->gt($effectiveFrom));
                if (! $overlaps) {
                    continue;
                }
                if ($priorStart->lt($effectiveFrom) && (! $priorEnd || $priorEnd->gt($effectiveFrom))) {
                    $priorFields = $this->fields($prior->payload);
                    $priorFields['effectiveTo'] = $effectiveFrom->toIso8601String();
                    $this->updateAllocation($prior->id, $priorFields, $effectiveFrom);

                    continue;
                }

                throw ValidationException::withMessages([
                    'effectiveFrom' => ['Outlet allocation effective dates cannot overlap.'],
                ]);
            }

            $tenantId = $this->tenantIdForBusiness((string) $businessId, (string) $user->id);
            $fields = [
                'allocationId' => $allocationId,
                'tenantId' => $tenantId,
                'businessId' => (string) $businessId,
                'employeeId' => $validated['employeeId'],
                'effectiveFrom' => $effectiveFrom->toIso8601String(),
                'effectiveTo' => $effectiveTo?->toIso8601String(),
                'outletBasisPoints' => $validated['outletBasisPoints'],
                'allocationTotalBasisPoints' => 10000,
                'status' => 'ACTIVE',
                'createdBy' => (string) $user->id,
                'createdAt' => now()->utc()->toIso8601String(),
            ];
            $now = now();
            DB::table('employee_outlet_allocations')->insert([
                'id' => $allocationId,
                'document_path' => "employee_outlet_allocations/{$allocationId}",
                'business_id' => $businessId,
                'employee_id' => $validated['employeeId'],
                'status' => 'ACTIVE',
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'outlet_basis_points' => json_encode($validated['outletBasisPoints']),
                'allocation_total_basis_points' => 10000,
                'created_by' => (string) $user->id,
                'is_deleted' => 0,
                'payload' => json_encode(['_documentId' => $allocationId, '_documentPath' => "employee_outlet_allocations/{$allocationId}", 'fields' => $fields]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $fields;
        });

        if ($allocation === []) {
            return ApiResponse::error('This allocation request ID already exists with different details.', 409, null, 'ALLOCATION_CONFLICT');
        }

        return ApiResponse::success($allocation, 'Employee outlet allocation saved.', 201);
    }

    /** @return array<string, mixed> */
    private function allocationResponse(object $row): array
    {
        $fields = $this->fields($row->payload);
        $fields['allocationId'] = $row->id;
        $fields['businessId'] = $row->business_id;
        $fields['employeeId'] = $row->employee_id;
        $fields['effectiveFrom'] = $fields['effectiveFrom'] ?? $row->effective_from;
        $fields['effectiveTo'] = $fields['effectiveTo'] ?? $row->effective_to;
        $fields['outletBasisPoints'] = $fields['outletBasisPoints'] ?? json_decode($row->outlet_basis_points, true) ?? [];
        $fields['allocationTotalBasisPoints'] = (int) ($fields['allocationTotalBasisPoints'] ?? $row->allocation_total_basis_points);

        return $fields;
    }

    /** @return array<string, mixed> */
    private function fields(?string $payload): array
    {
        $decoded = json_decode((string) $payload, true) ?: [];

        return $decoded['fields'] ?? $decoded;
    }

    /** @param array<string, mixed> $fields */
    private function updateAllocation(string $id, array $fields, Carbon $effectiveTo): void
    {
        $row = DB::table('employee_outlet_allocations')->where('id', $id)->first();
        $payload = json_decode((string) $row->payload, true) ?: [];
        $payload['fields'] = $fields;
        DB::table('employee_outlet_allocations')->where('id', $id)->update([
            'effective_to' => $effectiveTo,
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ]);
    }

    private function tenantIdForBusiness(string $businessId, string $fallback): string
    {
        $business = DB::table('businesses')->where('id', $businessId)->first();
        $fields = $this->fields($business?->payload);

        return (string) ($fields['ownerUid'] ?? $fields['tenantId'] ?? $business?->tenant_id ?? $fallback);
    }
}
