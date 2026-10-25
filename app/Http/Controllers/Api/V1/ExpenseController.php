<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    protected array $defaultCategories = [
        'Rent & Lease',
        'Salaries & Wages',
        'Electricity & Utilities',
        'Freight & Delivery',
        'Repairs & Maintenance',
        'Tea & Refreshments',
        'Packaging & Materials',
        'Office & Stationery',
        'Marketing & Ads',
        'Taxes & Bank Fees',
        'Miscellaneous',
    ];

    /**
     * List expenses with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $category = $request->query('category');
        $paymentMode = $request->query('payment_mode') ?? $request->query('paymentMode');
        $status = strtoupper((string) ($request->query('status') ?? 'ACTIVE'));
        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));
        $startDate = $request->query('start_date') ?? $request->query('startDate');
        $endDate = $request->query('end_date') ?? $request->query('endDate');

        if (!$businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        if (!$businessId) {
            return ApiResponse::success([], 'No active business selected.');
        }

        $query = DB::table('expenses')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId && $outletId !== 'ALL') {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        if ($category && $category !== 'ALL') {
            $query->where('category', $category);
        }

        if ($paymentMode && $paymentMode !== 'ALL') {
            $query->where('payment_mode', strtoupper($paymentMode));
        }

        if ($status && $status !== 'ALL') {
            $query->where('status', $status);
        }

        if ($startDate) {
            $query->where('expense_date', '>=', date('Y-m-d 00:00:00', strtotime($startDate)));
        }

        if ($endDate) {
            $query->where('expense_date', '<=', date('Y-m-d 23:59:59', strtotime($endDate)));
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('payee_name', 'LIKE', "%{$search}%")
                  ->orWhere('reference_number', 'LIKE', "%{$search}%")
                  ->orWhere('notes', 'LIKE', "%{$search}%");
            });
        }

        $rows = $query->orderBy('expense_date', 'desc')->get();

        $data = $rows->map(function ($row) {
            return $this->formatExpense($row);
        });

        return ApiResponse::success($data, 'Expenses retrieved successfully.');
    }

    /**
     * Get single expense.
     */
    public function show(string $id): JsonResponse
    {
        $row = DB::table('expenses')->where('id', $id)->first();
        if (!$row) {
            return ApiResponse::error('Expense not found.', 404);
        }

        return ApiResponse::success($this->formatExpense($row), 'Expense retrieved successfully.');
    }

    /**
     * Create an expense.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'outletId' => 'nullable|string',
            'tenant_id' => 'nullable|string',
            'tenantId' => 'nullable|string',
            'title' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'amount_paise' => 'nullable|integer|min:1',
            'amountPaise' => 'nullable|integer|min:1',
            'expense_date' => 'nullable|string',
            'expenseDate' => 'nullable|string',
            'payment_mode' => 'nullable|string',
            'paymentMode' => 'nullable|string',
            'payee_name' => 'nullable|string|max:255',
            'payeeName' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'referenceNumber' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'status' => 'nullable|string',
            'created_by' => 'nullable|string',
            'createdBy' => 'nullable|string',
        ]);

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (!$businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : 'BUS-001';
        }

        $outletId = $validated['outlet_id'] ?? $validated['outletId'] ?? null;
        $tenantId = $validated['tenant_id'] ?? $validated['tenantId'] ?? null;
        $amountPaise = (int) ($validated['amount_paise'] ?? $validated['amountPaise'] ?? 0);
        $expenseDate = $validated['expense_date'] ?? $validated['expenseDate'] ?? now()->toIso8601String();
        $paymentMode = strtoupper((string) ($validated['payment_mode'] ?? $validated['paymentMode'] ?? 'CASH'));
        $payeeName = $validated['payee_name'] ?? $validated['payeeName'] ?? null;
        $refNumber = $validated['reference_number'] ?? $validated['referenceNumber'] ?? null;
        $notes = $validated['notes'] ?? null;
        $createdBy = $validated['created_by'] ?? $validated['createdBy'] ?? null;

        $id = 'EXP-' . strtoupper(Str::random(10));
        $now = now();

        $payload = [
            'expenseId' => $id,
            'businessId' => $businessId,
            'outletId' => $outletId,
            'tenantId' => $tenantId,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'amountPaise' => $amountPaise,
            'expenseDate' => $expenseDate,
            'paymentMode' => $paymentMode,
            'payeeName' => $payeeName,
            'referenceNumber' => $refNumber,
            'notes' => $notes,
            'status' => 'ACTIVE',
            'createdAt' => $now->toIso8601String(),
            'createdBy' => $createdBy,
        ];

        DB::table('expenses')->insert([
            'id' => $id,
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'tenant_id' => $tenantId,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'amount_paise' => $amountPaise,
            'expense_date' => date('Y-m-d H:i:s', strtotime($expenseDate)),
            'payment_mode' => $paymentMode,
            'payee_name' => $payeeName,
            'reference_number' => $refNumber,
            'notes' => $notes,
            'status' => 'ACTIVE',
            'created_by' => $createdBy,
            'document_path' => "businesses/{$businessId}/expenses/{$id}",
            'is_deleted' => 0,
            'payload' => json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Record in financial_transactions as EXPENSE
        DB::table('financial_transactions')->insert([
            'id' => 'FIN-' . $id,
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'tenant_id' => $tenantId,
            'transaction_type' => 'EXPENSE',
            'category' => $validated['category'],
            'amount_paise' => $amountPaise,
            'payment_mode' => $paymentMode,
            'reference_id' => $id,
            'reference_type' => 'EXPENSE',
            'description' => $validated['title'] . ($payeeName ? " (Payee: {$payeeName})" : ''),
            'transaction_date' => date('Y-m-d H:i:s', strtotime($expenseDate)),
            'recorded_by' => $createdBy,
            'status' => 'COMPLETED',
            'document_path' => "businesses/{$businessId}/financial_transactions/FIN-{$id}",
            'is_deleted' => 0,
            'payload' => json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ApiResponse::success($payload, 'Expense recorded successfully.', 201);
    }

    /**
     * Update an expense.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $row = DB::table('expenses')->where('id', $id)->first();
        if (!$row) {
            return ApiResponse::error('Expense not found.', 404);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:100',
            'amount_paise' => 'nullable|integer|min:1',
            'amountPaise' => 'nullable|integer|min:1',
            'expense_date' => 'nullable|string',
            'expenseDate' => 'nullable|string',
            'payment_mode' => 'nullable|string',
            'paymentMode' => 'nullable|string',
            'payee_name' => 'nullable|string|max:255',
            'payeeName' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'referenceNumber' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        $updates = ['updated_at' => now()];

        if (isset($validated['title'])) $updates['title'] = $validated['title'];
        if (isset($validated['category'])) $updates['category'] = $validated['category'];
        if (isset($validated['amount_paise']) || isset($validated['amountPaise'])) {
            $updates['amount_paise'] = (int) ($validated['amount_paise'] ?? $validated['amountPaise']);
        }
        if (isset($validated['expense_date']) || isset($validated['expenseDate'])) {
            $d = $validated['expense_date'] ?? $validated['expenseDate'];
            $updates['expense_date'] = date('Y-m-d H:i:s', strtotime($d));
        }
        if (isset($validated['payment_mode']) || isset($validated['paymentMode'])) {
            $updates['payment_mode'] = strtoupper((string) ($validated['payment_mode'] ?? $validated['paymentMode']));
        }
        if (isset($validated['payee_name']) || isset($validated['payeeName'])) {
            $updates['payee_name'] = $validated['payee_name'] ?? $validated['payeeName'];
        }
        if (isset($validated['reference_number']) || isset($validated['referenceNumber'])) {
            $updates['reference_number'] = $validated['reference_number'] ?? $validated['referenceNumber'];
        }
        if (isset($validated['notes'])) $updates['notes'] = $validated['notes'];
        if (isset($validated['status'])) $updates['status'] = strtoupper((string) $validated['status']);

        DB::table('expenses')->where('id', $id)->update($updates);

        $fresh = DB::table('expenses')->where('id', $id)->first();
        return ApiResponse::success($this->formatExpense($fresh), 'Expense updated successfully.');
    }

    /**
     * Void or delete expense.
     */
    public function destroy(string $id): JsonResponse
    {
        $row = DB::table('expenses')->where('id', $id)->first();
        if (!$row) {
            return ApiResponse::error('Expense not found.', 404);
        }

        DB::table('expenses')->where('id', $id)->update([
            'status' => 'VOID',
            'is_deleted' => 1,
            'updated_at' => now(),
        ]);

        // Also void financial transaction
        DB::table('financial_transactions')->where('reference_id', $id)->update([
            'status' => 'VOID',
            'is_deleted' => 1,
            'updated_at' => now(),
        ]);

        return ApiResponse::success(null, 'Expense voided successfully.');
    }

    /**
     * List categories.
     */
    public function categories(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId');
        $custom = [];
        if ($businessId) {
            $custom = DB::table('expenses')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->distinct()
                ->pluck('category')
                ->toArray();
        }

        $all = array_values(array_unique(array_merge($this->defaultCategories, $custom)));
        return ApiResponse::success($all, 'Expense categories retrieved.');
    }

    protected function formatExpense($row): array
    {
        $payload = json_decode($row->payload ?? '{}', true) ?: [];

        return [
            'id' => (string) $row->id,
            'expenseId' => (string) $row->id,
            'businessId' => (string) $row->business_id,
            'outletId' => $row->outlet_id ? (string) $row->outlet_id : null,
            'tenantId' => $row->tenant_id ? (string) $row->tenant_id : null,
            'title' => (string) $row->title,
            'category' => (string) $row->category,
            'amountPaise' => (int) $row->amount_paise,
            'amountRupees' => round((int) $row->amount_paise / 100, 2),
            'expenseDate' => $row->expense_date ? date('Y-m-d\TH:i:s\Z', strtotime($row->expense_date)) : null,
            'paymentMode' => (string) $row->payment_mode,
            'payeeName' => $row->payee_name ? (string) $row->payee_name : null,
            'referenceNumber' => $row->reference_number ? (string) $row->reference_number : null,
            'notes' => $row->notes ? (string) $row->notes : null,
            'status' => (string) $row->status,
            'isActive' => strtoupper((string) $row->status) === 'ACTIVE',
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at ? date('Y-m-d\TH:i:s\Z', strtotime($row->created_at)) : null,
        ];
    }
}
