<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\EmployeePermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /**
     * List staff attendance records.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $employeeId = $request->query('employee_id') ?? $request->query('employeeId');
        $workDate = $request->query('work_date') ?? $request->query('workDate');
        $startDate = $request->query('start_date') ?? $request->query('startDate');
        $endDate = $request->query('end_date') ?? $request->query('endDate');
        $status = $request->query('status');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('staff_attendance')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $records = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $empId = (string) ($fields['employeeId'] ?? '');
            if ($employeeId && $empId !== $employeeId) {
                continue;
            }

            $wDate = substr((string) ($fields['workDate'] ?? ''), 0, 10);
            if ($workDate && $wDate !== substr($workDate, 0, 10)) {
                continue;
            }

            if ($startDate && $wDate < substr($startDate, 0, 10)) {
                continue;
            }

            if ($endDate && $wDate > substr($endDate, 0, 10)) {
                continue;
            }

            $attStatus = strtoupper((string) ($fields['attendanceStatus'] ?? 'PRESENT'));
            if ($status && strtoupper($status) !== $attStatus) {
                continue;
            }

            $rawApp = strtoupper((string) ($fields['approvalStatus'] ?? $row->status ?? 'PENDING'));
            $approvalStatus = ($rawApp === 'APPROVED') ? 'APPROVED' : 'PENDING';

            $records[] = [
                'recordId' => (string) $row->id,
                'id' => (string) $row->id,
                'attendanceId' => (string) ($fields['attendanceId'] ?? $row->id),
                'businessId' => (string) $row->business_id,
                'outletId' => (string) $row->outlet_id,
                'employeeId' => $empId,
                'workDate' => $fields['workDate'] ?? null,
                'checkInAt' => $fields['checkInAt'] ?? null,
                'checkOutAt' => $fields['checkOutAt'] ?? null,
                'workedMinutes' => (int) ($fields['workedMinutes'] ?? 0),
                'standardShiftMinutes' => (int) ($fields['standardShiftMinutes'] ?? 480),
                'approvedOvertimeMinutes' => (int) ($fields['approvedOvertimeMinutes'] ?? 0),
                'overtimePaise' => (int) ($fields['overtimePaise'] ?? 0),
                'attendanceStatus' => $attStatus,
                'approvalStatus' => $approvalStatus,
                'status' => $approvalStatus,
                'approvedBy' => $fields['approvedBy'] ?? null,
                'approvedAt' => $fields['approvedAt'] ?? null,
                'notes' => $fields['notes'] ?? null,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        }

        return ApiResponse::success($records, 'Attendance records retrieved.');
    }

    public function events(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $employeeId = $request->query('employee_id') ?? $request->query('employeeId');
        $attendanceId = $request->query('attendance_id') ?? $request->query('attendanceId');
        $user = $request->user();
        if (! $businessId || ! $user->canAccessBusiness((string) $businessId)) {
            return ApiResponse::error('A valid, authorized business context is required.', 403, null, 'FORBIDDEN');
        }

        $query = DB::table('staff_attendance_events')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }
        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }
        if ($attendanceId) {
            $query->where('attendance_id', $attendanceId);
        }

        $events = $query->orderBy('occurred_at')->get()->map(function (object $row): array {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            return array_merge($fields, [
                'eventId' => (string) $row->id,
                'recordId' => (string) $row->attendance_id,
                'tenantId' => (string) $row->tenant_id,
                'businessId' => (string) $row->business_id,
                'outletId' => (string) $row->outlet_id,
                'employeeId' => (string) $row->employee_id,
                'eventType' => (string) $row->event_type,
                'occurredAt' => $fields['occurredAt'] ?? $row->occurred_at,
                'workDate' => $fields['workDate'] ?? $row->work_date,
                'immutable' => true,
            ]);
        });

        return ApiResponse::success($events, 'Attendance audit events retrieved.');
    }

    /**
     * Biometric / Kiosk Face Attendance punch (Check-in or Check-out).
     */
    public function punch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'required|string',
            'outletId' => 'nullable|string',
            'employee_id' => 'required|string',
            'employeeId' => 'nullable|string',
            'type' => 'required|string|in:CHECK_IN,CHECK_OUT,check_in,check_out,CHECKIN,CHECKOUT',
            'timestamp' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'livenessScore' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'workTimeZoneId' => 'nullable|string|max:100',
            'attendanceMethod' => 'nullable|string|max:50',
            'kioskDeviceId' => 'nullable|string|max:255',
            'recognitionModelVersion' => 'nullable|string|max:100',
            'livenessCheckVersion' => 'nullable|string|max:100',
            'gpsAccuracy' => 'nullable|numeric|min:0',
            'isMockLocation' => 'nullable|boolean',
            'geofenceDistanceMeters' => 'nullable|numeric|min:0',
            'geofenceRadiusMeters' => 'nullable|numeric|min:0',
            'livenessChallenges' => 'nullable|array',
            'flaggedReasons' => 'nullable|array',
            'verificationStatus' => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('A valid, authorized business context is required to punch attendance.', 403, null, 'FORBIDDEN');
        }

        $outletId = $validated['outlet_id'] ?? $validated['outletId'];
        $employeeId = $validated['employee_id'] ?? $validated['employeeId'];
        $type = strtoupper(str_replace('_', '', $validated['type'])); // CHECKIN or CHECKOUT

        $punchTime = ! empty($validated['timestamp']) ? Carbon::parse($validated['timestamp']) : Carbon::now();
        $punchTimeIso = $punchTime->toIso8601String();
        $todayDate = $punchTime->clone()->startOfDay()->toIso8601String();
        $dateKey = $punchTime->format('Ymd');
        $tenantId = $this->tenantIdForBusiness((string) $businessId, $userId);
        $attendanceId = 'ATT-'.substr(hash('sha256', "{$tenantId}|{$businessId}|{$employeeId}|{$outletId}|{$dateKey}"), 0, 40);

        $outlet = DB::table('outlets')->where('id', $outletId)->where('business_id', $businessId)->where('is_deleted', 0)->exists();
        $employee = DB::table('employees')->where('id', $employeeId)->where('is_deleted', 0)->first();
        $employeePayload = json_decode((string) ($employee->payload ?? null), true) ?: [];
        $employeeFields = $employeePayload['fields'] ?? $employeePayload;
        if (is_array($employeeFields)) {
            $employeeFields = SyncController::decodeFields($employeeFields);
        }
        $empBizIds = (array) ($employeeFields['businessIds'] ?? (isset($employeeFields['businessId']) ? [$employeeFields['businessId']] : []));
        $empOutletIds = (array) ($employeeFields['outletIds'] ?? (isset($employeeFields['outletId']) ? [$employeeFields['outletId']] : []));
        $empOwnerUid = (string) ($employeeFields['ownerUid'] ?? '');
        if (! $outlet || ! $employee ||
            ! in_array($businessId, array_map('strval', $empBizIds), true) ||
            ! in_array($outletId, array_map('strval', $empOutletIds), true) ||
            ($empOwnerUid !== '' && $empOwnerUid !== $tenantId)) {
            return ApiResponse::error('The employee and outlet must be assigned to the authorized business.', 422, null, 'ATTENDANCE_SCOPE_MISMATCH');
        }

        return DB::transaction(function () use ($businessId, $outletId, $employeeId, $type, $punchTime, $punchTimeIso, $todayDate, $attendanceId, $validated, $userId, $tenantId) {
            $existing = DB::table('staff_attendance')
                ->where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->where('id', $attendanceId)
                ->where('is_deleted', 0)
                ->lockForUpdate()
                ->first();

            $nowDateTime = now();
            $nowIso = now()->toIso8601String();
            $eventType = $type === 'CHECKIN' ? 'CHECK_IN' : 'CHECK_OUT';
            $eventId = 'ATTEVT-'.substr(hash('sha256', "{$attendanceId}|{$eventType}"), 0, 40);
            $recordAuditEvent = function () use ($attendanceId, $businessId, $outletId, $employeeId, $eventType, $eventId, $punchTime, $punchTimeIso, $validated, $userId, $tenantId, $nowDateTime, $nowIso): void {
                $eventFields = [
                    'eventId' => $eventId,
                    'recordId' => $attendanceId,
                    'tenantId' => $tenantId,
                    'businessId' => $businessId,
                    'outletId' => $outletId,
                    'employeeId' => $employeeId,
                    'eventType' => $eventType,
                    'occurredAt' => $punchTimeIso,
                    'workDate' => $punchTime->toDateString(),
                    'workTimeZoneId' => $validated['workTimeZoneId'] ?? null,
                    'attendanceMethod' => $validated['attendanceMethod'] ?? 'FACE_KIOSK',
                    'kioskDeviceId' => $validated['kioskDeviceId'] ?? null,
                    'recognitionModelVersion' => $validated['recognitionModelVersion'] ?? null,
                    'livenessCheckVersion' => $validated['livenessCheckVersion'] ?? null,
                    'gpsLatitude' => $validated['latitude'] ?? null,
                    'gpsLongitude' => $validated['longitude'] ?? null,
                    'gpsAccuracy' => $validated['gpsAccuracy'] ?? null,
                    'isMockLocation' => (bool) ($validated['isMockLocation'] ?? false),
                    'geofenceDistanceMeters' => $validated['geofenceDistanceMeters'] ?? null,
                    'geofenceRadiusMeters' => $validated['geofenceRadiusMeters'] ?? null,
                    'livenessChallenges' => $validated['livenessChallenges'] ?? [],
                    'verificationStatus' => $validated['verificationStatus'] ?? 'RECORDED',
                    'flaggedReasons' => $validated['flaggedReasons'] ?? [],
                    'recordedBy' => $userId,
                    'createdAt' => $nowIso,
                    'immutable' => true,
                ];
                DB::table('staff_attendance_events')->insertOrIgnore([
                    'id' => $eventId,
                    'document_path' => "staff_attendance_events/{$eventId}",
                    'attendance_id' => $attendanceId,
                    'tenant_id' => $tenantId,
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'employee_id' => $employeeId,
                    'event_type' => $eventType,
                    'occurred_at' => $punchTime,
                    'work_date' => $punchTime->toDateString(),
                    'work_timezone_id' => $validated['workTimeZoneId'] ?? null,
                    'attendance_method' => $validated['attendanceMethod'] ?? 'FACE_KIOSK',
                    'kiosk_device_id' => $validated['kioskDeviceId'] ?? null,
                    'recognition_model_version' => $validated['recognitionModelVersion'] ?? null,
                    'liveness_check_version' => $validated['livenessCheckVersion'] ?? null,
                    'gps_latitude' => $validated['latitude'] ?? null,
                    'gps_longitude' => $validated['longitude'] ?? null,
                    'gps_accuracy' => $validated['gpsAccuracy'] ?? null,
                    'is_mock_location' => (bool) ($validated['isMockLocation'] ?? false),
                    'geofence_distance_meters' => $validated['geofenceDistanceMeters'] ?? null,
                    'geofence_radius_meters' => $validated['geofenceRadiusMeters'] ?? null,
                    'liveness_challenges' => json_encode($validated['livenessChallenges'] ?? []),
                    'verification_status' => $validated['verificationStatus'] ?? 'RECORDED',
                    'flagged_reasons' => json_encode($validated['flaggedReasons'] ?? []),
                    'recorded_by' => $userId,
                    'immutable' => 1,
                    'status' => 'RECORDED',
                    'is_deleted' => 0,
                    'payload' => json_encode(['_documentId' => $eventId, '_documentPath' => "staff_attendance_events/{$eventId}", 'fields' => $eventFields]),
                    'created_at' => $nowDateTime,
                    'updated_at' => $nowDateTime,
                ]);
            };

            if ($type === 'CHECKIN') {
                if ($existing) {
                    $payload = json_decode($existing->payload, true) ?: [];
                    $fields = $payload['fields'] ?? $payload;
                    $fields['checkInAt'] = $punchTimeIso;
                    $fields['attendanceStatus'] = 'PRESENT';
                    $fields['updatedAt'] = $nowIso;

                    DB::table('staff_attendance')->where('id', $attendanceId)->update([
                        'updated_at' => $nowDateTime,
                        'payload' => json_encode([
                            '_documentId' => $attendanceId,
                            '_documentPath' => "staff_attendance/{$attendanceId}",
                            'fields' => $fields,
                        ]),
                    ]);

                    $recordAuditEvent();

                    return ApiResponse::success($fields, 'Check-in updated successfully.');
                }

                $fields = [
                    'attendanceId' => $attendanceId,
                    'recordId' => $attendanceId,
                    'tenantId' => $tenantId,
                    'businessId' => $businessId,
                    'outletId' => $outletId,
                    'employeeId' => $employeeId,
                    'workDate' => $todayDate,
                    'checkInAt' => $punchTimeIso,
                    'checkOutAt' => null,
                    'workedMinutes' => 0,
                    'standardShiftMinutes' => 480,
                    'approvedOvertimeMinutes' => 0,
                    'overtimePaise' => 0,
                    'attendanceStatus' => 'PRESENT',
                    'approvalStatus' => 'PENDING_APPROVAL',
                    'recordedBy' => $userId,
                    'notes' => $validated['notes'] ?? null,
                    'createdAt' => $nowIso,
                    'updatedAt' => $nowIso,
                ];

                DB::table('staff_attendance')->insert([
                    'id' => $attendanceId,
                    'document_path' => "staff_attendance/{$attendanceId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'PENDING_APPROVAL',
                    'created_at' => $nowDateTime,
                    'updated_at' => $nowDateTime,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        '_documentId' => $attendanceId,
                        '_documentPath' => "staff_attendance/{$attendanceId}",
                        'fields' => $fields,
                    ]),
                ]);

                $recordAuditEvent();

                return ApiResponse::success($fields, 'Check-in recorded successfully.', 201);
            } else {
                // CHECKOUT
                if (! $existing) {
                    // Create entry with check-out
                    $fields = [
                        'attendanceId' => $attendanceId,
                        'recordId' => $attendanceId,
                        'tenantId' => $tenantId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'employeeId' => $employeeId,
                        'workDate' => $todayDate,
                        'checkInAt' => $punchTimeIso,
                        'checkOutAt' => $punchTimeIso,
                        'workedMinutes' => 0,
                        'standardShiftMinutes' => 480,
                        'approvedOvertimeMinutes' => 0,
                        'overtimePaise' => 0,
                        'attendanceStatus' => 'PRESENT',
                        'approvalStatus' => 'PENDING_APPROVAL',
                        'recordedBy' => $userId,
                        'notes' => $validated['notes'] ?? null,
                        'createdAt' => $nowIso,
                        'updatedAt' => $nowIso,
                    ];

                    DB::table('staff_attendance')->insert([
                        'id' => $attendanceId,
                        'document_path' => "staff_attendance/{$attendanceId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'status' => 'PENDING_APPROVAL',
                        'created_at' => $nowDateTime,
                        'updated_at' => $nowDateTime,
                        'is_deleted' => 0,
                        'payload' => json_encode([
                            '_documentId' => $attendanceId,
                            '_documentPath' => "staff_attendance/{$attendanceId}",
                            'fields' => $fields,
                        ]),
                    ]);

                    $recordAuditEvent();

                    return ApiResponse::success($fields, 'Check-out recorded.', 201);
                }

                $payload = json_decode($existing->payload, true) ?: [];
                $fields = $payload['fields'] ?? $payload;

                $checkInAt = ! empty($fields['checkInAt']) ? Carbon::parse($fields['checkInAt']) : $punchTime;
                $workedMinutes = max(0, $punchTime->diffInMinutes($checkInAt));
                $standardShift = (int) ($fields['standardShiftMinutes'] ?? 480);
                $overtimeMinutes = max(0, $workedMinutes - $standardShift);

                $fields['checkOutAt'] = $punchTimeIso;
                $fields['workedMinutes'] = $workedMinutes;
                $fields['approvedOvertimeMinutes'] = $overtimeMinutes;
                $fields['checkOutRecordedBy'] = $userId;
                $fields['updatedAt'] = $nowIso;

                DB::table('staff_attendance')->where('id', $attendanceId)->update([
                    'updated_at' => $nowDateTime,
                    'payload' => json_encode([
                        '_documentId' => $attendanceId,
                        '_documentPath' => "staff_attendance/{$attendanceId}",
                        'fields' => $fields,
                    ]),
                ]);

                $recordAuditEvent();

                return ApiResponse::success($fields, "Check-out recorded. Total worked: {$workedMinutes} minutes.");
            }
        });
    }

    /**
     * Record manual attendance entry.
     */
    public function manual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'required|string',
            'outletId' => 'nullable|string',
            'employee_id' => 'required|string',
            'employeeId' => 'nullable|string',
            'workDate' => 'required|string',
            'attendanceStatus' => 'required|string|in:PRESENT,ABSENT,HALF_DAY,PAID_LEAVE,UNPAID_LEAVE,WEEKLY_OFF,HOLIDAY',
            'checkInAt' => 'nullable|string',
            'checkOutAt' => 'nullable|string',
            'workedMinutes' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('A valid, authorized business context is required to record attendance.', 403, null, 'FORBIDDEN');
        }

        $outletId = $validated['outlet_id'] ?? $validated['outletId'];
        $employeeId = $validated['employee_id'] ?? $validated['employeeId'];
        $wDate = Carbon::parse($validated['workDate']);
        $dateKey = $wDate->format('Ymd');
        $tenantId = $this->tenantIdForBusiness((string) $businessId, $userId);
        $attendanceId = 'ATT-'.substr(hash('sha256', "{$tenantId}|{$businessId}|{$employeeId}|{$outletId}|{$dateKey}"), 0, 40);

        $nowDateTime = now();
        $nowIso = now()->toIso8601String();

        $workedMinutes = (int) ($validated['workedMinutes'] ?? 0);
        if ($workedMinutes === 0 && ! empty($validated['checkInAt']) && ! empty($validated['checkOutAt'])) {
            $in = Carbon::parse($validated['checkInAt']);
            $out = Carbon::parse($validated['checkOutAt']);
            $workedMinutes = max(0, $out->diffInMinutes($in));
        }

        $fields = [
            'attendanceId' => $attendanceId,
            'recordId' => $attendanceId,
            'tenantId' => $tenantId,
            'businessId' => $businessId,
            'outletId' => $outletId,
            'employeeId' => $employeeId,
            'workDate' => $wDate->startOfDay()->toIso8601String(),
            'checkInAt' => $validated['checkInAt'] ?? null,
            'checkOutAt' => $validated['checkOutAt'] ?? null,
            'workedMinutes' => $workedMinutes,
            'standardShiftMinutes' => 480,
            'approvedOvertimeMinutes' => max(0, $workedMinutes - 480),
            'overtimePaise' => 0,
            'attendanceStatus' => strtoupper($validated['attendanceStatus']),
            'approvalStatus' => 'PENDING_APPROVAL',
            'recordedBy' => $userId,
            'notes' => $validated['notes'] ?? null,
            'createdAt' => $nowIso,
            'updatedAt' => $nowIso,
        ];

        DB::table('staff_attendance')->updateOrInsert(
            ['id' => $attendanceId],
            [
                'document_path' => "staff_attendance/{$attendanceId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'PENDING_APPROVAL',
                'created_at' => $nowDateTime,
                'updated_at' => $nowDateTime,
                'is_deleted' => 0,
                'payload' => json_encode([
                    '_documentId' => $attendanceId,
                    '_documentPath' => "staff_attendance/{$attendanceId}",
                    'fields' => $fields,
                ]),
            ]
        );

        return ApiResponse::success($fields, 'Manual attendance recorded successfully.', 201);
    }

    /**
     * Manager approval of staff attendance record.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $userId = (string) $user->id;

        $row = DB::table('staff_attendance')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Attendance record not found.', 404, null, 'NOT_FOUND');
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;
        $businessId = (string) ($request->input('business_id') ?? $request->input('businessId') ?? $request->query('business_id') ?? $request->header('X-Business-Id') ?? '');
        if ($businessId === '') {
            $businessId = (string) $row->business_id;
        } elseif ((string) $row->business_id !== $businessId) {
            return ApiResponse::error('Attendance record not found in this business.', 404, null, 'NOT_FOUND');
        }
        if (! app(EmployeePermissionService::class)->isWithinAssignedScope(
            $user,
            $businessId,
            (string) $row->outlet_id,
            true,
        )) {
            return ApiResponse::error('Attendance record not found.', 404, null, 'NOT_FOUND');
        }

        if (strtoupper((string) ($fields['approvalStatus'] ?? $row->status ?? '')) === 'APPROVED') {
            return ApiResponse::success([
                'recordId' => $id,
                'approvalStatus' => 'APPROVED',
                'approvedBy' => $fields['approvedBy'] ?? $userId,
                'approvedAt' => $fields['approvedAt'] ?? now()->toIso8601String(),
            ], 'Attendance is already approved.');
        }

        $nowIso = now()->toIso8601String();
        $fields['approvalStatus'] = 'APPROVED';
        $fields['approvedBy'] = $userId;
        $fields['approvedAt'] = $nowIso;
        $fields['updatedAt'] = $nowIso;

        $payload['fields'] = $fields;

        DB::table('staff_attendance')->where('id', $id)->update([
            'status' => 'APPROVED',
            'updated_at' => now(),
            'payload' => json_encode($payload),
        ]);

        return ApiResponse::success([
            'recordId' => $id,
            'approvalStatus' => 'APPROVED',
            'approvedBy' => $userId,
            'approvedAt' => $nowIso,
        ], 'Attendance approved successfully.');
    }

    private function tenantIdForBusiness(string $businessId, string $fallback): string
    {
        $row = DB::table('businesses')->where('id', $businessId)->where('is_deleted', 0)->first();
        if (! $row) {
            return $fallback;
        }
        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;

        return (string) ($fields['ownerUid'] ?? $fields['owner_uid'] ?? $row->owner_uid ?? $fallback);
    }
}
