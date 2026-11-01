<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialTransactionController extends Controller
{
    /**
     * List financial transactions / cash drawer ledger.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $type = $request->query('transaction_type') ?? $request->query('type');
        $paymentMode = $request->query('payment_mode') ?? $request->query('paymentMode');
        $startDate = $request->query('start_date') ?? $request->query('startDate');
        $endDate = $request->query('end_date') ?? $request->query('endDate');
        $limit = min((int) ($request->query('limit', 100)), 500);

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('financial_transactions')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId && $outletId !== 'ALL') {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        if ($type && $type !== 'ALL') {
            $query->where('transaction_type', strtoupper($type));
        }

        if ($paymentMode && $paymentMode !== 'ALL') {
            $query->where('payment_mode', strtoupper($paymentMode));
        }

        if ($startDate) {
            $query->where('transaction_date', '>=', date('Y-m-d 00:00:00', strtotime($startDate)));
        }

        if ($endDate) {
            $query->where('transaction_date', '<=', date('Y-m-d 23:59:59', strtotime($endDate)));
        }

        $rows = $query->orderBy('transaction_date', 'desc')->limit($limit)->get();

        $data = $rows->map(function ($row) {
            return [
                'id' => (string) $row->id,
                'businessId' => (string) $row->business_id,
                'outletId' => $row->outlet_id ? (string) $row->outlet_id : null,
                'tenantId' => $row->tenant_id ? (string) $row->tenant_id : null,
                'transactionType' => (string) $row->transaction_type,
                'category' => $row->category ? (string) $row->category : null,
                'amountPaise' => (int) $row->amount_paise,
                'amountRupees' => round((int) $row->amount_paise / 100, 2),
                'paymentMode' => (string) $row->payment_mode,
                'referenceId' => $row->reference_id ? (string) $row->reference_id : null,
                'referenceType' => $row->reference_type ? (string) $row->reference_type : null,
                'description' => $row->description ? (string) $row->description : null,
                'transactionDate' => $row->transaction_date ? date('Y-m-d\TH:i:s\Z', strtotime($row->transaction_date)) : null,
                'balanceAfterPaise' => $row->balance_after_paise !== null ? (int) $row->balance_after_paise : null,
                'status' => (string) $row->status,
                'recordedBy' => $row->recorded_by ? (string) $row->recorded_by : null,
                'createdAt' => $row->created_at ? date('Y-m-d\TH:i:s\Z', strtotime($row->created_at)) : null,
            ];
        });

        return ApiResponse::success($data, 'Financial transactions retrieved successfully.');
    }

    /**
     * Store manual financial entry or cash drawer adjustment.
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
            'transaction_type' => 'required|string', // INCOME, EXPENSE, TRANSFER, ADJUSTMENT
            'category' => 'nullable|string|max:100',
            'amount_paise' => 'required|integer|min:1',
            'payment_mode' => 'nullable|string',
            'paymentMode' => 'nullable|string',
            'description' => 'nullable|string',
            'transaction_date' => 'nullable|string',
            'recorded_by' => 'nullable|string',
        ]);

        $user = $request->user();
        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('A valid, authorized business context is required to create a transaction.', 403, null, 'FORBIDDEN');
        }

        $id = 'FT-'.strtoupper(Str::random(10));
        $now = now();
        $date = $validated['transaction_date'] ?? now()->toIso8601String();
        $paymentMode = strtoupper((string) ($validated['payment_mode'] ?? $validated['paymentMode'] ?? 'CASH'));
        $type = strtoupper((string) $validated['transaction_type']);

        $payload = [
            'id' => $id,
            'businessId' => $businessId,
            'outletId' => $validated['outlet_id'] ?? $validated['outletId'] ?? null,
            'tenantId' => $validated['tenant_id'] ?? $validated['tenantId'] ?? null,
            'transactionType' => $type,
            'category' => $validated['category'] ?? null,
            'amountPaise' => (int) $validated['amount_paise'],
            'paymentMode' => $paymentMode,
            'description' => $validated['description'] ?? null,
            'transactionDate' => $date,
            'status' => 'COMPLETED',
            'recordedBy' => $validated['recorded_by'] ?? null,
            'createdAt' => $now->toIso8601String(),
        ];

        DB::table('financial_transactions')->insert([
            'id' => $id,
            'business_id' => $businessId,
            'outlet_id' => $payload['outletId'],
            'tenant_id' => $payload['tenantId'],
            'transaction_type' => $type,
            'category' => $payload['category'],
            'amount_paise' => $payload['amountPaise'],
            'payment_mode' => $paymentMode,
            'description' => $payload['description'],
            'transaction_date' => date('Y-m-d H:i:s', strtotime($date)),
            'recorded_by' => $payload['recordedBy'],
            'status' => 'COMPLETED',
            'document_path' => "businesses/{$businessId}/financial_transactions/{$id}",
            'is_deleted' => 0,
            'payload' => json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ApiResponse::success($payload, 'Financial transaction recorded.', 201);
    }

    /**
     * Cash drawer and accounts balance summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        // Aggregate transactions by payment mode and type
        $query = DB::table('financial_transactions')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->where('status', 'COMPLETED');

        if ($outletId && $outletId !== 'ALL') {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        $allTx = $query->get();

        $cashInPaise = 0;
        $cashOutPaise = 0;
        $onlineInPaise = 0;
        $onlineOutPaise = 0;

        foreach ($allTx as $tx) {
            $amt = (int) $tx->amount_paise;
            $isCash = strtoupper((string) $tx->payment_mode) === 'CASH';
            $isIncome = in_array(strtoupper((string) $tx->transaction_type), ['INCOME', 'CUSTOMER_PAYMENT']);
            $isExpense = in_array(strtoupper((string) $tx->transaction_type), ['EXPENSE', 'SUPPLIER_PAYMENT', 'STAFF_ADVANCE']);

            if ($isCash) {
                if ($isIncome) {
                    $cashInPaise += $amt;
                }
                if ($isExpense) {
                    $cashOutPaise += $amt;
                }
            } else {
                if ($isIncome) {
                    $onlineInPaise += $amt;
                }
                if ($isExpense) {
                    $onlineOutPaise += $amt;
                }
            }
        }

        // Receivables from customer_balances
        $receivablesQuery = DB::table('customer_balances')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        $totalCustomerDuePaise = 0;
        foreach ($receivablesQuery->get() as $cRow) {
            $p = json_decode($cRow->payload, true) ?: [];
            $totalCustomerDuePaise += (int) ($p['fields']['duePaise'] ?? 0);
        }

        // Payables from supplier_balances
        $payablesQuery = DB::table('supplier_balances')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        $totalSupplierDuePaise = 0;
        foreach ($payablesQuery->get() as $sRow) {
            $p = json_decode($sRow->payload, true) ?: [];
            $totalSupplierDuePaise += (int) ($p['fields']['totalPayable'] ?? 0);
        }

        $data = [
            'cashInPaise' => $cashInPaise,
            'cashOutPaise' => $cashOutPaise,
            'cashInDrawerPaise' => max(0, $cashInPaise - $cashOutPaise),
            'cashInDrawerRupees' => round(max(0, $cashInPaise - $cashOutPaise) / 100, 2),
            'onlineInPaise' => $onlineInPaise,
            'onlineOutPaise' => $onlineOutPaise,
            'netOnlineBalancePaise' => $onlineInPaise - $onlineOutPaise,
            'totalReceivablesPaise' => $totalCustomerDuePaise,
            'totalReceivablesRupees' => round($totalCustomerDuePaise / 100, 2),
            'totalPayablesPaise' => $totalSupplierDuePaise,
            'totalPayablesRupees' => round($totalSupplierDuePaise / 100, 2),
        ];

        return ApiResponse::success($data, 'Financial summary calculated successfully.');
    }
}
