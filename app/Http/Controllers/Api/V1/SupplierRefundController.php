<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierRefundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id')
            ?? $request->query('businessId')
            ?? $request->header('X-Business-Id');
        $supplierBusinessId = $request->query('supplier_business_id') ?? $request->query('supplierBusinessId');
        $user = $request->user();
        if (! $businessId || ! $user->canAccessBusiness((string) $businessId)) {
            return ApiResponse::error('A valid, authorized business context is required.', 403, null, 'FORBIDDEN');
        }

        $query = DB::table('supplier_refunds')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        if ($supplierBusinessId) {
            $query->where('supplier_business_id', $supplierBusinessId);
        }

        return ApiResponse::success($query->orderByDesc('refund_date')->get()->map(fn (object $row): array => $this->refundResponse($row)), 'Supplier refunds retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'businessId' => $request->input('businessId', $request->input('business_id')),
            'outletId' => $request->input('outletId', $request->input('outlet_id')),
            'supplierId' => $request->input('supplierId', $request->input('supplier_id')),
            'supplierBusinessId' => $request->input('supplierBusinessId', $request->input('supplier_business_id')),
            'refundDate' => $request->input('refundDate', $request->input('refund_date')),
            'paymentMode' => $request->input('paymentMode', $request->input('payment_mode')),
            'referenceNo' => $request->input('referenceNo', $request->input('reference_no')),
            'requestId' => $request->input('requestId', $request->input('request_id')),
        ]);
        $validated = $request->validate([
            'businessId' => 'nullable|string|max:100',
            'outletId' => 'required|string|max:255',
            'supplierId' => 'required|string|max:255',
            'supplierBusinessId' => 'required|string|max:100',
            'amount' => 'required|integer|min:1',
            'refundDate' => 'required|date',
            'paymentMode' => 'required|string|in:CASH,UPI,BANK_TRANSFER,CHEQUE,CARD',
            'referenceNo' => 'nullable|string|max:255',
            'reason' => 'required|string|max:65535',
            'notes' => 'nullable|string|max:65535',
            'requestId' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $businessId = $validated['businessId'] ?? $request->header('X-Business-Id');
        $user = $request->user();
        if (! $businessId || ! $user->canAccessBusiness((string) $businessId)) {
            return ApiResponse::error('A valid, authorized business context is required.', 403, null, 'FORBIDDEN');
        }

        $supplierBusinessId = $validated['supplierBusinessId'];
        $tenantId = $this->tenantIdForBusiness((string) $businessId, (string) $user->id);
        $balance = DB::table('supplier_balances')->where('id', $supplierBusinessId)->first();
        if (! $balance || $balance->business_id !== $businessId) {
            return ApiResponse::error('The supplier is not linked to this business.', 422, null, 'SUPPLIER_BUSINESS_MISMATCH');
        }
        $relation = DB::table('supplier_businesses')->where('id', $supplierBusinessId)->where('business_id', $businessId)->where('is_deleted', 0)->first();
        $relationPayload = json_decode((string) ($relation->payload ?? null), true) ?: [];
        $relationFields = $relationPayload['fields'] ?? $relationPayload;
        if (! $relation || ($relationFields['supplierId'] ?? $relation->supplier_id ?? null) !== $validated['supplierId'] ||
            ($relationFields['businessId'] ?? $relation->business_id) !== $businessId ||
            (isset($relationFields['tenantId']) && $relationFields['tenantId'] !== $tenantId) ||
            strtoupper((string) ($relationFields['status'] ?? $relation->status)) !== 'ACTIVE') {
            return ApiResponse::error('The supplier is not actively linked to this business.', 422, null, 'SUPPLIER_BUSINESS_MISMATCH');
        }

        $outlet = DB::table('outlets')->where('id', $validated['outletId'])->where('business_id', $businessId)->where('is_deleted', 0)->exists();
        if (! $outlet) {
            return ApiResponse::error('The selected outlet is not part of this business.', 422, null, 'OUTLET_BUSINESS_MISMATCH');
        }

        $requestId = trim($validated['requestId']);
        $refundId = 'SREF-'.$requestId;
        $financialTransactionId = 'FIN-'.$refundId;
        $ledgerEntryId = 'SUPPLIER_REFUND_'.$refundId;
        $refundDate = Carbon::parse($validated['refundDate'])->utc();
        $now = now();

        $result = DB::transaction(function () use ($balance, $businessId, $financialTransactionId, $ledgerEntryId, $now, $refundDate, $refundId, $requestId, $supplierBusinessId, $tenantId, $user, $validated): array {
            $balanceRow = DB::table('supplier_balances')->where('id', $balance->id)->lockForUpdate()->first();
            $existing = DB::table('supplier_refunds')->where('id', $refundId)->first();
            if ($existing) {
                if ($existing->tenant_id !== $tenantId || $existing->business_id !== $businessId ||
                    $existing->request_id !== $requestId || (int) $existing->amount_paise !== $validated['amount'] ||
                    $existing->supplier_business_id !== $supplierBusinessId ||
                    $existing->payment_mode !== $validated['paymentMode'] ||
                    Carbon::parse($existing->refund_date)->ne($refundDate) ||
                    $existing->reason !== trim($validated['reason']) ||
                    $existing->reference_no !== (trim((string) ($validated['referenceNo'] ?? '')) ?: null) ||
                    $existing->notes !== (trim((string) ($validated['notes'] ?? '')) ?: null)) {
                    return ['conflict' => true];
                }

                return ['refund' => $this->refundResponse($existing), 'created' => false];
            }

            if (strlen($financialTransactionId) > 255 || strlen($refundId) > 255 || strlen($ledgerEntryId) > 255) {
                throw ValidationException::withMessages(['requestId' => ['The request ID is too long to create linked transaction records.']]);
            }

            $balancePayload = json_decode((string) $balanceRow->payload, true) ?: [];
            $balanceFields = $balancePayload['fields'] ?? $balancePayload;
            if (($balanceFields['supplierBusinessId'] ?? $balanceRow->id) !== $supplierBusinessId ||
                ($balanceFields['supplierId'] ?? $balanceRow->supplier_id) !== $validated['supplierId'] ||
                ($balanceFields['businessId'] ?? $balanceRow->business_id) !== $businessId) {
                return ['conflict' => true];
            }
            $advance = (int) ($balanceFields['unallocatedAdvance'] ?? 0);
            if ($validated['amount'] > $advance) {
                throw ValidationException::withMessages(['amount' => ['Refund amount exceeds the available supplier advance.']]);
            }

            $createdAt = $now->utc()->toIso8601String();
            $refundFields = [
                'refundId' => $refundId,
                'tenantId' => $tenantId,
                'businessId' => (string) $businessId,
                'outletId' => $validated['outletId'],
                'supplierId' => $validated['supplierId'],
                'supplierBusinessId' => $supplierBusinessId,
                'amount' => $validated['amount'],
                'refundDate' => $refundDate->toIso8601String(),
                'paymentMode' => $validated['paymentMode'],
                'referenceNo' => trim((string) ($validated['referenceNo'] ?? '')) ?: null,
                'reason' => trim($validated['reason']),
                'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
                'status' => 'POSTED',
                'financialTransactionId' => $financialTransactionId,
                'createdByUid' => (string) $user->id,
                'createdAt' => $createdAt,
                'requestId' => $requestId,
            ];
            $refundPayload = ['_documentId' => $refundId, '_documentPath' => "supplier_refunds/{$refundId}", 'fields' => $refundFields];
            DB::table('supplier_refunds')->insert([
                'id' => $refundId,
                'document_path' => "supplier_refunds/{$refundId}",
                'tenant_id' => $tenantId,
                'business_id' => $businessId,
                'outlet_id' => $validated['outletId'],
                'supplier_id' => $validated['supplierId'],
                'supplier_business_id' => $supplierBusinessId,
                'amount_paise' => $validated['amount'],
                'refund_date' => $refundDate,
                'payment_mode' => $validated['paymentMode'],
                'reference_no' => $refundFields['referenceNo'],
                'reason' => $refundFields['reason'],
                'notes' => $refundFields['notes'],
                'status' => 'POSTED',
                'financial_transaction_id' => $financialTransactionId,
                'created_by_uid' => (string) $user->id,
                'request_id' => $requestId,
                'is_deleted' => 0,
                'payload' => json_encode($refundPayload),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $financialFields = [
                'transactionId' => $financialTransactionId,
                'tenantId' => $tenantId,
                'businessId' => (string) $businessId,
                'outletId' => $validated['outletId'],
                'direction' => 'IN',
                'transactionType' => 'SUPPLIER_REFUND',
                'partyType' => 'SUPPLIER',
                'partyId' => $validated['supplierId'],
                'amount' => $validated['amount'],
                'paymentMode' => $validated['paymentMode'],
                'referenceType' => 'SUPPLIER_REFUND',
                'referenceId' => $refundId,
                'transactionDate' => $refundDate->toIso8601String(),
                'referenceNo' => $refundFields['referenceNo'],
                'notes' => $refundFields['notes'],
                'createdByUid' => (string) $user->id,
                'createdAt' => $createdAt,
                'status' => 'POSTED',
            ];
            DB::table('financial_transactions')->insert([
                'id' => $financialTransactionId,
                'business_id' => $businessId,
                'outlet_id' => $validated['outletId'],
                'tenant_id' => $tenantId,
                'transaction_type' => 'SUPPLIER_REFUND',
                'category' => 'SUPPLIER_REFUND',
                'amount_paise' => $validated['amount'],
                'payment_mode' => $validated['paymentMode'],
                'reference_id' => $refundId,
                'reference_type' => 'SUPPLIER_REFUND',
                'description' => $refundFields['reason'],
                'transaction_date' => $refundDate,
                'recorded_by' => (string) $user->id,
                'status' => 'POSTED',
                'document_path' => "financial_transactions/{$financialTransactionId}",
                'is_deleted' => 0,
                'payload' => json_encode(['_documentId' => $financialTransactionId, '_documentPath' => "financial_transactions/{$financialTransactionId}", 'fields' => $financialFields]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $balanceFields['unallocatedAdvance'] = $advance - $validated['amount'];
            $balanceFields['lastLedgerEntryId'] = $ledgerEntryId;
            $balanceFields['updatedAt'] = $createdAt;
            $balancePayload['fields'] = $balanceFields;
            DB::table('supplier_balances')->where('id', $balanceRow->id)->update([
                'payload' => json_encode($balancePayload),
                'updated_at' => $now,
            ]);

            $ledgerFields = [
                'ledgerEntryId' => $ledgerEntryId,
                'tenantId' => $tenantId,
                'businessId' => (string) $businessId,
                'outletId' => $validated['outletId'],
                'supplierBusinessId' => $supplierBusinessId,
                'supplierId' => $validated['supplierId'],
                'entryType' => 'SUPPLIER_REFUND',
                'referenceType' => 'SUPPLIER_REFUND',
                'referenceId' => $refundId,
                'refundId' => $refundId,
                'financialTransactionId' => $financialTransactionId,
                'amount' => $validated['amount'],
                'debit' => 0,
                'credit' => 0,
                'paymentMode' => $validated['paymentMode'],
                'referenceNo' => $refundFields['referenceNo'],
                'reason' => $refundFields['reason'],
                'isReversal' => false,
                'reversalOfId' => null,
                'transactionDate' => $refundDate->toIso8601String(),
                'createdBy' => (string) $user->id,
                'createdAt' => $createdAt,
            ];
            DB::table('supplier_ledger_entries')->insert([
                'id' => $ledgerEntryId,
                'document_path' => "supplier_ledger_entries/{$ledgerEntryId}",
                'business_id' => $businessId,
                'outlet_id' => $validated['outletId'],
                'status' => 'POSTED',
                'is_deleted' => 0,
                'payload' => json_encode(['_documentId' => $ledgerEntryId, '_documentPath' => "supplier_ledger_entries/{$ledgerEntryId}", 'fields' => $ledgerFields]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['refund' => $refundFields, 'created' => true];
        });

        if ($result['conflict'] ?? false) {
            return ApiResponse::error('This request ID was already used for different refund details.', 409, null, 'REFUND_REQUEST_CONFLICT');
        }

        return ApiResponse::success($result['refund'], $result['created'] ? 'Supplier refund recorded.' : 'Supplier refund already recorded.', $result['created'] ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function refundResponse(object $row): array
    {
        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;

        return array_merge($fields, [
            'refundId' => (string) $row->id,
            'tenantId' => (string) $row->tenant_id,
            'businessId' => (string) $row->business_id,
            'outletId' => (string) $row->outlet_id,
            'supplierId' => (string) $row->supplier_id,
            'supplierBusinessId' => (string) $row->supplier_business_id,
            'amount' => (int) $row->amount_paise,
            'refundDate' => $fields['refundDate'] ?? $row->refund_date,
            'paymentMode' => (string) $row->payment_mode,
            'status' => (string) $row->status,
            'financialTransactionId' => (string) $row->financial_transaction_id,
            'requestId' => (string) $row->request_id,
        ]);
    }

    private function tenantIdForBusiness(string $businessId, string $fallback): string
    {
        $business = DB::table('businesses')->where('id', $businessId)->first();
        $payload = json_decode((string) ($business->payload ?? null), true) ?: [];
        $fields = $payload['fields'] ?? $payload;

        return (string) ($fields['ownerUid'] ?? $fields['tenantId'] ?? $business->tenant_id ?? $fallback);
    }
}
