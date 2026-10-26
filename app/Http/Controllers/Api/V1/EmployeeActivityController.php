<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeActivityController extends Controller
{
    /**
     * List employee activity trail / audit logs with multi-table aggregation.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $employeeId = $request->query('employee_id') ?? $request->query('employeeId');
        $module = $this->normalizeModule((string) ($request->query('module') ?? 'ALL'));
        $action = strtoupper(trim((string) ($request->query('action') ?? 'ALL')));
        $search = strtolower(trim((string) ($request->query('search') ?? $request->query('q') ?? '')));
        $startDate = $request->query('start_date') ?? $request->query('startDate') ?? $request->query('from');
        $endDate = $request->query('end_date') ?? $request->query('endDate') ?? $request->query('to');
        $limit = min(max((int) ($request->query('limit') ?? $request->query('per_page') ?? 100), 1), 500);
        $offset = max((int) ($request->query('offset') ?? (((int) $request->query('page', 1) - 1) * $limit)), 0);

        if (! $businessId) {
            return ApiResponse::error('A business is required to view employee activity.', 422);
        }
        $accessError = $this->businessAccessError($request, (string) $businessId);
        if ($accessError) {
            return $accessError;
        }

        // Cache employee and outlet names for rapid enrichment
        $employeesMap = [];
        $employeeRows = DB::table('employees')->where('is_deleted', 0)->get();
        foreach ($employeeRows as $er) {
            $p = json_decode($er->payload, true) ?: [];
            $f = $p['fields'] ?? $p;
            $empId = (string) ($f['employeeId'] ?? $er->id);
            $employeesMap[$empId] = [
                'name' => $f['name'] ?? $er->name ?? 'Staff Member',
                'code' => $f['employeeCode'] ?? $er->employee_code ?? 'EMP',
                'role' => $f['roleName'] ?? 'Staff',
            ];
            // Also map by uid if present
            if (! empty($f['uid'])) {
                $employeesMap[$f['uid']] = $employeesMap[$empId];
            }
        }

        $outletsMap = [];
        $outletRows = DB::table('outlets')->where('is_deleted', 0)->get();
        foreach ($outletRows as $or) {
            $p = json_decode($or->payload, true) ?: [];
            $f = $p['fields'] ?? $p;
            $oId = (string) ($f['outletId'] ?? $or->id);
            $outletsMap[$oId] = $f['name'] ?? $f['outletName'] ?? $or->name ?? 'Outlet';
        }

        $activities = [];

        // 1. Fetch from audit_logs table
        $auditQuery = DB::table('audit_logs')
            ->where('is_deleted', 0);

        if ($businessId) {
            $auditQuery->where(function ($q) use ($businessId) {
                $q->where('business_id', $businessId)->orWhereNull('business_id');
            });
        }
        if ($outletId && $outletId !== 'ALL') {
            $auditQuery->where('outlet_id', $outletId);
        }

        $auditRows = $auditQuery->orderBy('created_at', 'desc')->limit(500)->get();
        foreach ($auditRows as $row) {
            $p = json_decode($row->payload, true) ?: [];
            $f = $p['fields'] ?? $p;
            $activityBusinessId = (string) ($row->business_id ?? $f['businessId'] ?? '');
            if ($activityBusinessId !== (string) $businessId) {
                continue;
            }

            $empId = (string) ($f['employeeId'] ?? $f['uid'] ?? $f['performedBy'] ?? $f['userId'] ?? '');
            $empInfo = $employeesMap[$empId] ?? [
                'name' => $f['employeeName'] ?? $f['performedByName'] ?? 'Staff Member',
                'code' => 'EMP',
                'role' => 'Staff',
            ];

            $actModule = strtoupper((string) ($f['module'] ?? 'SYSTEM'));
            $actAction = strtoupper((string) ($f['action'] ?? 'OPERATE'));
            $desc = (string) ($f['description'] ?? 'Operation performed');
            $refId = (string) ($f['referenceId'] ?? $row->id);
            $outId = (string) ($row->outlet_id ?? $f['outletId'] ?? '');

            $activities[] = [
                'id' => (string) $row->id,
                'logId' => (string) ($f['logId'] ?? $row->id),
                'employeeId' => $empId,
                'employeeName' => $empInfo['name'],
                'employeeCode' => $empInfo['code'],
                'role' => $empInfo['role'],
                'action' => $actAction,
                'actionLabel' => $this->formatActionLabel($actAction),
                'module' => $actModule,
                'moduleLabel' => $this->formatModuleLabel($actModule),
                'description' => $desc,
                'referenceId' => $refId,
                'outletId' => $outId,
                'outletName' => $outletsMap[$outId] ?? 'Main Branch',
                'businessId' => $activityBusinessId,
                'status' => strtoupper((string) ($row->status ?? $f['status'] ?? 'SUCCESS')),
                'details' => $f['details'] ?? $f['metadata'] ?? [],
                'createdAt' => Carbon::parse($row->created_at)->toIso8601String(),
                'timestamp' => Carbon::parse($row->created_at)->timestamp,
            ];
        }

        // 2. Synthesize sales/billing activities from invoices
        $invoiceQuery = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        if ($outletId && $outletId !== 'ALL') {
            $invoiceQuery->where('outlet_id', $outletId);
        }
        $invoiceRows = $invoiceQuery->orderBy('created_at', 'desc')->limit(150)->get();
        foreach ($invoiceRows as $inv) {
            $p = json_decode($inv->payload, true) ?: [];
            $f = $p['fields'] ?? $p;

            $empId = (string) ($f['createdBy'] ?? $f['employeeId'] ?? $f['cashierId'] ?? '');
            $empInfo = $employeesMap[$empId] ?? [
                'name' => $f['cashierName'] ?? $f['createdByName'] ?? 'Billing Staff',
                'code' => 'BILLING',
                'role' => 'Billing Staff',
            ];
            $invNum = $f['invoiceNumber'] ?? $inv->id;
            $amt = (int) (
                $f['totalAmountPaise']
                    ?? $f['grandTotalPaise']
                    ?? $f['totalAmount']
                    ?? $f['grandTotal']
                    ?? $f['total']
                    ?? $inv->total_amount
                    ?? 0
            );
            $amtRupees = number_format($amt / 100, 2);
            $custName = $f['customerName'] ?? 'Walk-in Customer';
            $outId = (string) ($inv->outlet_id ?? $f['outletId'] ?? '');

            $activities[] = [
                'id' => 'ACT-INV-'.$inv->id,
                'logId' => 'INV-'.$inv->id,
                'employeeId' => $empId,
                'employeeName' => $empInfo['name'],
                'employeeCode' => $empInfo['code'],
                'role' => $empInfo['role'],
                'action' => 'CREATE_SALE',
                'actionLabel' => 'New POS Sale',
                'module' => 'SALES',
                'moduleLabel' => 'Sales & Billing',
                'description' => "Generated Invoice #$invNum for $custName (₹$amtRupees)",
                'referenceId' => (string) $invNum,
                'outletId' => $outId,
                'outletName' => $outletsMap[$outId] ?? 'Main Branch',
                'businessId' => $businessId,
                'status' => 'SUCCESS',
                'details' => [
                    'invoiceNumber' => $invNum,
                    'customer' => $custName,
                    'amount' => '₹'.$amtRupees,
                    'paymentMode' => $f['paymentMode'] ?? 'CASH',
                ],
                'createdAt' => Carbon::parse($inv->created_at)->toIso8601String(),
                'timestamp' => Carbon::parse($inv->created_at)->timestamp,
            ];
        }

        // 3. Synthesize customer payments & khata collections
        $paymentQuery = DB::table('payments')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        if ($outletId && $outletId !== 'ALL') {
            $paymentQuery->where('outlet_id', $outletId);
        }
        $paymentRows = $paymentQuery->orderBy('created_at', 'desc')->limit(100)->get();
        foreach ($paymentRows as $pay) {
            $p = json_decode($pay->payload, true) ?: [];
            $f = $p['fields'] ?? $p;

            $empId = (string) ($f['receivedBy'] ?? $f['createdBy'] ?? $f['employeeId'] ?? '');
            $empInfo = $employeesMap[$empId] ?? [
                'name' => $f['receivedByName'] ?? 'Cashier',
                'code' => 'CASHIER',
                'role' => 'Accountant',
            ];
            $amt = (int) (
                $f['amountPaise']
                    ?? $f['amount_paise']
                    ?? $f['amount']
                    ?? $f['credit']
                    ?? $f['paidAmount']
                    ?? (isset($pay->amount) ? $pay->amount : 0)
            );
            $amtRupees = number_format($amt / 100, 2);
            $mode = $f['paymentMode'] ?? $f['paymentMethod'] ?? $f['payment_mode'] ?? 'CASH';
            $custName = $f['customerName'] ?? 'Customer';
            $outId = (string) ($pay->outlet_id ?? $f['outletId'] ?? '');

            $activities[] = [
                'id' => 'ACT-PAY-'.$pay->id,
                'logId' => 'PAY-'.$pay->id,
                'employeeId' => $empId,
                'employeeName' => $empInfo['name'],
                'employeeCode' => $empInfo['code'],
                'role' => $empInfo['role'],
                'action' => 'COLLECT_PAYMENT',
                'actionLabel' => 'Payment Received',
                'module' => 'PAYMENTS',
                'moduleLabel' => 'Payments & Khata',
                'description' => "Collected payment of ₹$amtRupees via $mode from $custName",
                'referenceId' => (string) ($f['receiptNumber'] ?? $pay->id),
                'outletId' => $outId,
                'outletName' => $outletsMap[$outId] ?? 'Main Branch',
                'businessId' => $businessId,
                'status' => 'SUCCESS',
                'details' => [
                    'amount' => '₹'.$amtRupees,
                    'mode' => $mode,
                    'customer' => $custName,
                ],
                'createdAt' => Carbon::parse($pay->created_at)->toIso8601String(),
                'timestamp' => Carbon::parse($pay->created_at)->timestamp,
            ];
        }

        // 4. Synthesize attendance actions (check-ins, check-outs)
        $attQuery = DB::table('staff_attendance')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        if ($outletId && $outletId !== 'ALL') {
            $attQuery->where('outlet_id', $outletId);
        }
        $attRows = $attQuery->orderBy('created_at', 'desc')->limit(100)->get();
        foreach ($attRows as $att) {
            $p = json_decode($att->payload, true) ?: [];
            $f = $p['fields'] ?? $p;

            $empId = (string) ($f['employeeId'] ?? $att->employee_id ?? '');
            $empInfo = $employeesMap[$empId] ?? [
                'name' => $f['employeeName'] ?? 'Staff',
                'code' => 'STAFF',
                'role' => 'Employee',
            ];
            $outId = (string) ($att->outlet_id ?? $f['outletId'] ?? '');

            if (! empty($f['checkInAt'])) {
                $checkInTime = Carbon::parse($f['checkInAt'])->format('h:i A');
                $activities[] = [
                    'id' => 'ACT-ATT-IN-'.$att->id,
                    'logId' => 'ATT-IN-'.$att->id,
                    'employeeId' => $empId,
                    'employeeName' => $empInfo['name'],
                    'employeeCode' => $empInfo['code'],
                    'role' => $empInfo['role'],
                    'action' => 'CLOCK_IN',
                    'actionLabel' => 'Shift Clock In',
                    'module' => 'ATTENDANCE',
                    'moduleLabel' => 'Attendance & Shifts',
                    'description' => "Punched in for work shift at $checkInTime",
                    'referenceId' => (string) $att->id,
                    'outletId' => $outId,
                    'outletName' => $outletsMap[$outId] ?? 'Main Branch',
                    'businessId' => $businessId,
                    'status' => 'SUCCESS',
                    'details' => [
                        'date' => $f['workDate'] ?? Carbon::parse($f['checkInAt'])->toDateString(),
                        'time' => $checkInTime,
                    ],
                    'createdAt' => Carbon::parse($f['checkInAt'])->toIso8601String(),
                    'timestamp' => Carbon::parse($f['checkInAt'])->timestamp,
                ];
            }

            if (! empty($f['checkOutAt'])) {
                $checkOutTime = Carbon::parse($f['checkOutAt'])->format('h:i A');
                $mins = (int) ($f['workedMinutes'] ?? 0);
                $hrs = round($mins / 60, 1);
                $activities[] = [
                    'id' => 'ACT-ATT-OUT-'.$att->id,
                    'logId' => 'ATT-OUT-'.$att->id,
                    'employeeId' => $empId,
                    'employeeName' => $empInfo['name'],
                    'employeeCode' => $empInfo['code'],
                    'role' => $empInfo['role'],
                    'action' => 'CLOCK_OUT',
                    'actionLabel' => 'Shift Clock Out',
                    'module' => 'ATTENDANCE',
                    'moduleLabel' => 'Attendance & Shifts',
                    'description' => "Completed work shift at $checkOutTime (Duration: {$hrs}h)",
                    'referenceId' => (string) $att->id,
                    'outletId' => $outId,
                    'outletName' => $outletsMap[$outId] ?? 'Main Branch',
                    'businessId' => $businessId,
                    'status' => 'SUCCESS',
                    'details' => [
                        'workedMinutes' => $mins,
                        'time' => $checkOutTime,
                    ],
                    'createdAt' => Carbon::parse($f['checkOutAt'])->toIso8601String(),
                    'timestamp' => Carbon::parse($f['checkOutAt'])->timestamp,
                ];
            }
        }

        // 5. Synthesize staff advances and payroll settlements
        $khataQuery = DB::table('staff_khata_transactions')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        if ($outletId && $outletId !== 'ALL') {
            $khataQuery->where('outlet_id', $outletId);
        }
        $khataRows = $khataQuery->orderBy('created_at', 'desc')->limit(60)->get();
        foreach ($khataRows as $khata) {
            $p = json_decode($khata->payload, true) ?: [];
            $f = $p['fields'] ?? $p;

            $empId = (string) ($f['employeeId'] ?? '');
            $empInfo = $employeesMap[$empId] ?? [
                'name' => 'Staff',
                'code' => 'STAFF',
                'role' => 'Employee',
            ];
            $type = strtoupper((string) ($f['transactionType'] ?? 'ADVANCE'));
            $amt = (int) ($f['amountPaise'] ?? 0);
            $amtRupees = number_format($amt / 100, 2);
            $outId = (string) ($khata->outlet_id ?? $f['outletId'] ?? '');

            $activities[] = [
                'id' => 'ACT-KHT-'.$khata->id,
                'logId' => 'KHT-'.$khata->id,
                'employeeId' => $empId,
                'employeeName' => $empInfo['name'],
                'employeeCode' => $empInfo['code'],
                'role' => $empInfo['role'],
                'action' => $type === 'ADVANCE' ? 'DISBURSE_ADVANCE' : 'SETTLE_SALARY',
                'actionLabel' => $type === 'ADVANCE' ? 'Staff Advance (Peshgi)' : 'Salary Settlement',
                'module' => 'PAYROLL',
                'moduleLabel' => 'Salary & Khata',
                'description' => ($type === 'ADVANCE' ? "Disbursed advance of ₹$amtRupees" : "Processed salary payment of ₹$amtRupees")." to {$empInfo['name']}",
                'referenceId' => (string) $khata->id,
                'outletId' => $outId,
                'outletName' => $outletsMap[$outId] ?? 'Main Branch',
                'businessId' => $businessId,
                'status' => 'SUCCESS',
                'details' => [
                    'amount' => '₹'.$amtRupees,
                    'type' => $type,
                    'paymentMode' => $f['paymentMode'] ?? 'CASH',
                ],
                'createdAt' => Carbon::parse($khata->created_at)->toIso8601String(),
                'timestamp' => Carbon::parse($khata->created_at)->timestamp,
            ];
        }

        // Apply In-Memory Filtering and Searching across merged activities
        $filtered = array_filter($activities, function ($item) use ($employeeId, $module, $action, $search, $startDate, $endDate) {
            if ($employeeId && $employeeId !== 'ALL' && $item['employeeId'] !== $employeeId) {
                return false;
            }
            if ($module !== 'ALL' && $this->normalizeModule((string) $item['module']) !== $module) {
                return false;
            }
            if ($action !== 'ALL' && $item['action'] !== $action) {
                return false;
            }
            if ($startDate) {
                $itemDate = Carbon::parse($item['createdAt'])->toDateString();
                if ($itemDate < Carbon::parse($startDate)->toDateString()) {
                    return false;
                }
            }
            if ($endDate) {
                $itemDate = Carbon::parse($item['createdAt'])->toDateString();
                if ($itemDate > Carbon::parse($endDate)->toDateString()) {
                    return false;
                }
            }
            if ($search !== '') {
                $haystack = strtolower(
                    $item['description'].' '.
                    $item['employeeName'].' '.
                    $item['employeeCode'].' '.
                    $item['actionLabel'].' '.
                    $item['referenceId'].' '.
                    $item['outletName']
                );
                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            return true;
        });

        // Sort by timestamp descending
        usort($filtered, function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        $total = count($filtered);
        $paginated = array_slice($filtered, $offset, $limit);

        return ApiResponse::success([
            'activities' => array_values($paginated),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ], 'Employee activity trail retrieved successfully.');
    }

    /**
     * Store an explicit activity audit log.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|max:100',
            'module' => 'required|string|max:100',
            'description' => 'required|string|max:500',
            'business_id' => 'required|string',
            'outlet_id' => 'nullable|string',
            'employee_id' => 'nullable|string',
            'employee_name' => 'nullable|string',
            'reference_id' => 'nullable|string',
            'details' => 'nullable|array',
            'status' => 'nullable|string',
        ]);

        $user = $request->user();
        $businessId = $validated['business_id'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            return ApiResponse::error('A business is required to record employee activity.', 422);
        }
        $accessError = $this->businessAccessError($request, (string) $businessId);
        if ($accessError) {
            return $accessError;
        }

        $logId = 'ACT-'.strtoupper(Str::random(10));
        $now = Carbon::now();

        $payload = [
            'fields' => [
                'logId' => $logId,
                'action' => strtoupper($validated['action']),
                'module' => strtoupper($validated['module']),
                'description' => $validated['description'],
                'employeeId' => $validated['employee_id'] ?? (string) $user->id,
                'employeeName' => $validated['employee_name'] ?? $user->name ?? 'User',
                'referenceId' => $validated['reference_id'] ?? null,
                'outletId' => $validated['outlet_id'] ?? $request->header('X-Outlet-Id'),
                'businessId' => $businessId,
                'details' => $validated['details'] ?? [],
                'status' => strtoupper($validated['status'] ?? 'SUCCESS'),
                'ipAddress' => $request->ip(),
                'userAgent' => $request->userAgent(),
                'createdAt' => $now->toIso8601String(),
            ],
            '_documentId' => $logId,
            '_documentPath' => "audit_logs/$logId",
        ];

        DB::table('audit_logs')->insert([
            'id' => $logId,
            'document_path' => "audit_logs/$logId",
            'business_id' => $businessId,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'status' => strtoupper($validated['status'] ?? 'SUCCESS'),
            'payload' => json_encode($payload),
            'is_deleted' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ApiResponse::success([
            'id' => $logId,
            'action' => $validated['action'],
            'createdAt' => $now->toIso8601String(),
        ], 'Activity logged successfully.', 201);
    }

    /**
     * Get aggregated activity statistics for the dashboard ribbon.
     */
    public function stats(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        if (! $businessId) {
            return ApiResponse::error('A business is required to view employee activity statistics.', 422);
        }
        $accessError = $this->businessAccessError($request, (string) $businessId);
        if ($accessError) {
            return $accessError;
        }

        $today = Carbon::today()->toDateString();

        $todayInvoices = DB::table('invoices')
            ->where('business_id', $businessId)
            ->whereDate('created_at', $today)
            ->where('is_deleted', 0)
            ->count();

        $todayPayments = DB::table('payments')
            ->where('business_id', $businessId)
            ->whereDate('created_at', $today)
            ->where('is_deleted', 0)
            ->count();

        $todayAttendance = DB::table('staff_attendance')
            ->where('business_id', $businessId)
            ->whereDate('created_at', $today)
            ->where('is_deleted', 0)
            ->count();

        $todayAuditLogs = DB::table('audit_logs')
            ->where('business_id', $businessId)
            ->whereDate('created_at', $today)
            ->where('is_deleted', 0)
            ->count();

        $totalOperations = DB::table('invoices')->where('business_id', $businessId)->count()
            + DB::table('payments')->where('business_id', $businessId)->count()
            + DB::table('staff_attendance')->where('business_id', $businessId)->count()
            + DB::table('audit_logs')->where('business_id', $businessId)->count();

        $todayTotal = $todayInvoices + $todayPayments + $todayAttendance + $todayAuditLogs;

        // Count distinct active employees today
        $activeStaffRows = DB::table('staff_attendance')
            ->where('business_id', $businessId)
            ->whereDate('created_at', $today)
            ->where('is_deleted', 0)
            ->get();
        $distinctEmpIds = [];
        foreach ($activeStaffRows as $r) {
            $p = json_decode($r->payload, true) ?: [];
            $emp = $p['fields']['employeeId'] ?? $p['employeeId'] ?? null;
            if ($emp) {
                $distinctEmpIds[$emp] = true;
            }
        }
        $activeEmployeesToday = count($distinctEmpIds);

        return ApiResponse::success([
            'total_activities' => $totalOperations,
            'today_activities' => $todayTotal,
            'active_staff_today' => max($activeEmployeesToday, 1),
            'top_module' => 'pos_billing',
            'top_module_count' => $todayInvoices,
            'totalOperations' => $totalOperations,
            'todayOperations' => $todayTotal,
            'activeEmployeesToday' => max($activeEmployeesToday, 1),
            'topModule' => 'Sales & Billing',
            'moduleBreakdown' => [
                'SALES' => $todayInvoices,
                'PAYMENTS' => $todayPayments,
                'ATTENDANCE' => $todayAttendance,
                'OTHER' => $todayAuditLogs,
            ],
        ], 'Activity statistics computed.');
    }

    private function formatActionLabel(string $action): string
    {
        return match (strtoupper($action)) {
            'CREATE_SALE', 'CREATE_INVOICE' => 'New POS Sale',
            'COLLECT_PAYMENT' => 'Collected Payment',
            'CLOCK_IN' => 'Shift Clock In',
            'CLOCK_OUT' => 'Shift Clock Out',
            'DISBURSE_ADVANCE' => 'Advance (Peshgi)',
            'SETTLE_SALARY' => 'Salary Settled',
            'UPDATE_STOCK', 'ADJUST_STOCK' => 'Stock Adjustment',
            'CREATE_CUSTOMER' => 'Added Customer',
            'UPDATE_CUSTOMER' => 'Updated Customer',
            'CREATE_SUPPLIER' => 'Added Supplier',
            'EXPENSE_ENTRY' => 'Expense Recorded',
            default => ucwords(strtolower(str_replace('_', ' ', $action))),
        };
    }

    private function normalizeModule(string $module): string
    {
        $module = strtoupper(str_replace(['-', ' '], '_', trim($module)));

        return match ($module) {
            'POS_BILLING', 'BILLING', 'INVOICE' => 'SALES',
            'STAFF_ATTENDANCE' => 'ATTENDANCE',
            'STAFF_PAYROLL', 'SALARY' => 'PAYROLL',
            'STOCK' => 'INVENTORY',
            'SUPPLIER' => 'SUPPLIERS',
            'PURCHASE' => 'PURCHASES',
            'CUSTOMER' => 'CUSTOMERS',
            'EXPENSE' => 'EXPENSES',
            'PAYMENT' => 'PAYMENTS',
            default => $module,
        };
    }

    private function formatModuleLabel(string $module): string
    {
        return match (strtoupper($module)) {
            'SALES' => 'Sales & Billing',
            'PAYMENTS' => 'Payments & Khata',
            'ATTENDANCE' => 'Attendance & Shifts',
            'PAYROLL' => 'Salary & Khata',
            'INVENTORY' => 'Inventory & Stock',
            'CUSTOMERS' => 'Customer CRM',
            'PURCHASES' => 'Purchases & Vendors',
            'EXPENSES' => 'Expenses',
            default => 'General Admin',
        };
    }

    private function businessAccessError(Request $request, string $businessId): ?JsonResponse
    {
        $business = DB::table('businesses')
            ->where('is_deleted', 0)
            ->where(function ($query) use ($businessId) {
                $query->where('id', $businessId)->orWhere('business_id', $businessId);
            })
            ->first();
        if (! $business) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode($business->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;
        $ownerUid = (string) ($fields['ownerUid'] ?? $business->owner_uid ?? '');
        if (! $request->user()->isAdmin() && $ownerUid !== (string) $request->user()->id) {
            return ApiResponse::error('Unauthorized access to employee activity for this business.', 403);
        }

        return null;
    }
}
