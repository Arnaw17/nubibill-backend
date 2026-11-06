<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MigrationBatch;
use App\Models\MigrationLog;
use App\Services\HistoricalDataService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class HistoricalDataController extends Controller
{
    public function __construct(
        protected HistoricalDataService $historicalService
    ) {}

    /**
     * Resolve and validate current business ID.
     */
    private function resolveBusinessId(Request $request): ?string
    {
        $businessId = $request->input('business_id') ?? $request->query('business_id') ?? $request->header('X-Business-Id');
        $user = $request->user();
        if (! $businessId && $user) {
            $businessId = $user->defaultBusinessId();
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return null;
        }

        return (string) $businessId;
    }

    /**
     * Get or set historical cutoff date for the business.
     */
    public function getCutoffDate(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access to this business.', 403, null, 'FORBIDDEN');
        }

        $setting = DB::table('business_settings')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->first();

        $cutoffDate = null;
        if ($setting) {
            $p = json_decode($setting->payload, true) ?: [];
            $cutoffDate = $p['fields']['historicalCutoffDate'] ?? $p['fields']['cutoffDate'] ?? null;
        }

        return ApiResponse::success([
            'businessId' => $businessId,
            'historicalCutoffDate' => $cutoffDate,
        ], 'Historical cutoff date retrieved.');
    }

    public function setCutoffDate(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access to this business.', 403, null, 'FORBIDDEN');
        }

        $validated = $request->validate([
            'cutoff_date' => 'required|date',
        ]);

        $cutoffDate = Carbon::parse($validated['cutoff_date'])->toDateString();
        $nowStr = now()->toIso8601String();
        $dbNow = now();

        $setting = DB::table('business_settings')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->first();

        if ($setting) {
            $p = json_decode($setting->payload, true) ?: [];
            $fields = $p['fields'] ?? [];
            $fields['historicalCutoffDate'] = $cutoffDate;
            $fields['updatedAt'] = $nowStr;
            $p['fields'] = $fields;

            DB::table('business_settings')->where('id', $setting->id)->update([
                'updated_at' => $dbNow,
                'payload' => json_encode($p),
            ]);
        } else {
            $settingId = "SETTING_{$businessId}";
            DB::table('business_settings')->insert([
                'id' => $settingId,
                'document_path' => "business_settings/{$settingId}",
                'business_id' => $businessId,
                'status' => 'ACTIVE',
                'is_deleted' => 0,
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'payload' => json_encode([
                    'fields' => [
                        'businessId' => $businessId,
                        'historicalCutoffDate' => $cutoffDate,
                        'createdAt' => $nowStr,
                        'updatedAt' => $nowStr,
                    ],
                ]),
            ]);
        }

        return ApiResponse::success([
            'businessId' => $businessId,
            'historicalCutoffDate' => $cutoffDate,
        ], 'Historical cutoff date saved successfully.');
    }

    /**
     * Create Migration Batch.
     */
    public function createBatch(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $validated = $request->validate([
            'source_software' => 'required|string|max:100',
            'cutoff_date' => 'nullable|date',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'outlet_id' => 'nullable|string',
        ]);

        $batchId = 'BATCH-'.strtoupper(Str::random(16));
        $batch = MigrationBatch::create([
            'id' => $batchId,
            'business_id' => $businessId,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'source_software' => $validated['source_software'],
            'cutoff_date' => $validated['cutoff_date'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'status' => 'DRAFT',
            'created_by' => $request->user()?->id,
        ]);

        MigrationLog::create([
            'id' => (string) Str::uuid(),
            'batch_id' => $batchId,
            'business_id' => $businessId,
            'user_id' => $request->user()?->id,
            'action' => 'BATCH_CREATED',
            'description' => "Created migration batch from {$validated['source_software']}.",
        ]);

        return ApiResponse::success($batch, 'Migration batch created successfully.');
    }

    /**
     * List Migration Batches.
     */
    public function listBatches(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $batches = MigrationBatch::where('business_id', $businessId)
            ->withCount(['stockAdjustments', 'logs', 'errors'])
            ->orderBy('created_at', 'desc')
            ->get();

        return ApiResponse::success($batches, 'Migration batches retrieved.');
    }

    /**
     * Approve Migration Batch.
     */
    public function approveBatch(Request $request, string $id): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $batch = MigrationBatch::where('id', $id)->where('business_id', $businessId)->first();
        if (! $batch) {
            return ApiResponse::error('Batch not found.', 404);
        }

        $batch->update([
            'status' => 'APPROVED',
            'approved_by' => $request->user()?->id,
        ]);

        MigrationLog::create([
            'id' => (string) Str::uuid(),
            'batch_id' => $batch->id,
            'business_id' => $businessId,
            'user_id' => $request->user()?->id,
            'action' => 'BATCH_APPROVED',
            'description' => "Batch {$batch->id} approved.",
        ]);

        return ApiResponse::success($batch, 'Migration batch approved.');
    }

    /**
     * Rollback Migration Batch.
     */
    public function rollbackBatch(Request $request, string $id): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        try {
            $result = $this->historicalService->rollbackBatch($id, $businessId, $request->user());

            return ApiResponse::success($result, 'Migration batch rolled back successfully.');
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Check stock availability and batches on a historical date.
     */
    public function stockAvailability(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $validated = $request->validate([
            'product_id' => 'required|string',
            'date' => 'required|date',
            'outlet_id' => 'nullable|string',
        ]);

        try {
            $data = $this->historicalService->getStockAvailabilityOnDate(
                $businessId,
                $validated['outlet_id'] ?? null,
                $validated['product_id'],
                $validated['date']
            );

            return ApiResponse::success($data, 'Historical stock availability retrieved.');
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Record Historical Sale ("Old Sale Entry").
     */
    public function storeSale(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $validated = $request->validate([
            'original_invoice_number' => 'required|string|max:100',
            'original_invoice_date' => 'required|date',
            'customer_id' => 'nullable|string',
            'customer_name' => 'nullable|string|max:200',
            'customer_phone' => 'nullable|string|max:20',
            'customer_gstin' => 'nullable|string|max:20',
            'customer_state_code' => 'nullable|string|max:10',
            'outlet_id' => 'nullable|string',
            'batch_id' => 'nullable|string',
            'notes' => 'nullable|string|max:500',
            'payment_mode' => 'nullable|string|in:CASH,UPI,CARD,CHEQUE,BANK_TRANSFER,CREDIT',
            'payment_date' => 'nullable|date',
            'amount_received' => 'nullable|numeric|min:0',
            'amount_received_paise' => 'nullable|integer|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'paid_paise' => 'nullable|integer|min:0',
            'grand_total' => 'nullable|numeric|min:0',
            'grand_total_paise' => 'nullable|integer|min:0',
            'impact_current_stock' => 'nullable|boolean',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|string',
            'lines.*.variant_id' => 'nullable|string',
            'lines.*.product_name' => 'nullable|string',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit' => 'nullable|string',
            'lines.*.sale_rate' => 'required|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'lines.*.gst_percentage' => 'nullable|numeric|min:0',
            'lines.*.hsn_code' => 'nullable|string',
        ]);

        $validated['business_id'] = $businessId;

        try {
            $result = $this->historicalService->recordHistoricalSale($validated, $request->user());

            return ApiResponse::success($result, 'Historical sale recorded successfully.');
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Record Historical Purchase ("Old Purchase Entry" with Section 6 Stock Logic).
     */
    public function storePurchase(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $validated = $request->validate([
            'supplier_id' => 'required|string',
            'supplier_invoice_number' => 'required|string|max:100',
            'invoice_date' => 'required|date',
            'purchase_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'outlet_id' => 'nullable|string',
            'batch_id' => 'nullable|string',
            'tax_type' => 'nullable|string|in:INTRA_STATE,INTER_STATE',
            'payment_method' => 'nullable|string',
            'paid_amount' => 'nullable|numeric|min:0',
            'paid_paise' => 'nullable|integer|min:0',
            'paid_amount_paise' => 'nullable|integer|min:0',
            'grand_total' => 'nullable|numeric|min:0',
            'grand_total_paise' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:APPROVED,DRAFT',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|string',
            'items.*.variant_id' => 'nullable|string',
            'items.*.product_name' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.gst_rate_bps' => 'nullable|numeric|min:0',
            'items.*.consumed_quantity' => 'nullable|numeric|min:0',
            'items.*.included_in_opening_stock' => 'nullable|numeric|min:0',
        ]);

        $validated['business_id'] = $businessId;

        try {
            $result = $this->historicalService->recordHistoricalPurchase($validated, $request->user());

            return ApiResponse::success($result, 'Historical purchase recorded successfully.');
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Record Opening Stock baseline.
     */
    public function storeOpeningStock(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $validated = $request->validate([
            'product_id' => 'required|string',
            'variant_id' => 'nullable|string',
            'outlet_id' => 'required|string',
            'quantity' => 'required|numeric|min:0.001',
            'valuation_rate' => 'required|numeric|min:0',
            'opening_date' => 'nullable|date',
            'batch_id' => 'nullable|string',
        ]);

        $validated['business_id'] = $businessId;

        try {
            $result = $this->historicalService->recordOpeningStock($validated, $request->user());

            return ApiResponse::success($result, 'Opening stock registered successfully.');
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Get Stock Reconciliation.
     */
    public function stockReconciliation(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $outletId = $request->query('outlet_id');
        $reconciliation = $this->historicalService->calculateStockReconciliation($businessId, $outletId);

        return ApiResponse::success($reconciliation, 'Stock reconciliation report calculated.');
    }

    /**
     * Historical Sales Report.
     */
    public function salesReport(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $scope = strtolower($request->query('record_scope', 'old_only')); // old_only, new_only, all
        $outletId = $request->query('outlet_id');
        $customerId = $request->query('customer_id');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($scope === 'old_only') {
            $query->where('is_historical', 1);
        } elseif ($scope === 'new_only') {
            $query->where('is_historical', 0);
        }

        if ($outletId && $outletId !== 'ALL') {
            $query->where('outlet_id', $outletId);
        }
        if ($customerId) {
            $query->where('customer_id', $customerId);
        }
        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $data = [];
        $totalSales = 0;
        $totalDue = 0;

        foreach ($rows as $r) {
            $p = json_decode($r->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $tot = (float) ($f['totalAmount'] ?? $f['grandTotal'] ?? $r->total_amount ?? 0);
            $due = (float) ($f['dueAmount'] ?? $f['duePaise'] ?? 0);
            $totalSales += $tot;
            $totalDue += $due;

            $data[] = [
                'id' => $r->id,
                'invoiceNumber' => $f['invoiceNumber'] ?? $r->invoice_number,
                'originalInvoiceNumber' => $f['originalInvoiceNumber'] ?? $r->original_invoice_number,
                'isHistorical' => (bool) $r->is_historical,
                'entrySource' => $r->entry_source,
                'customerId' => $f['customerId'] ?? $r->customer_id,
                'customerName' => $f['customerName'] ?? 'Walk-in',
                'totalAmount' => $tot,
                'paidAmount' => (float) ($f['paidAmount'] ?? 0),
                'dueAmount' => $due,
                'status' => $r->status,
                'transactionDate' => $r->original_invoice_date ?? Carbon::parse($r->created_at)->toDateString(),
                'createdAt' => $r->created_at,
            ];
        }

        return ApiResponse::success([
            'scope' => $scope,
            'count' => count($data),
            'totalSales' => $totalSales,
            'totalDue' => $totalDue,
            'records' => $data,
        ], 'Historical sales report retrieved.');
    }

    /**
     * Historical Purchase Report.
     */
    public function purchaseReport(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $scope = strtolower($request->query('record_scope', 'old_only'));
        $outletId = $request->query('outlet_id');
        $supplierId = $request->query('supplier_id');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = DB::table('purchase_bills')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($scope === 'old_only') {
            $query->where('is_historical', 1);
        } elseif ($scope === 'new_only') {
            $query->where('is_historical', 0);
        }

        if ($outletId && $outletId !== 'ALL') {
            $query->where('outlet_id', $outletId);
        }
        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }
        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ]);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $data = [];
        $totalPurchases = 0;
        $totalOutstanding = 0;

        foreach ($rows as $r) {
            $p = json_decode($r->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $tot = (float) ($f['grandTotal'] ?? 0);
            $out = (float) ($f['outstandingAmount'] ?? 0);
            $totalPurchases += $tot;
            $totalOutstanding += $out;

            $data[] = [
                'id' => $r->id,
                'supplierInvoiceNumber' => $f['supplierInvoiceNumber'] ?? $r->original_invoice_number,
                'isHistorical' => (bool) $r->is_historical,
                'entrySource' => $r->entry_source,
                'supplierId' => $f['supplierId'] ?? $r->supplier_id,
                'supplierName' => $f['supplierName'] ?? 'Supplier',
                'grandTotal' => $tot,
                'paidAmount' => (float) ($f['paidAmount'] ?? 0),
                'outstandingAmount' => $out,
                'status' => $r->status,
                'transactionDate' => $r->original_invoice_date ?? Carbon::parse($r->created_at)->toDateString(),
                'createdAt' => $r->created_at,
            ];
        }

        return ApiResponse::success([
            'scope' => $scope,
            'count' => count($data),
            'totalPurchases' => $totalPurchases,
            'totalOutstanding' => $totalOutstanding,
            'records' => $data,
        ], 'Historical purchase report retrieved.');
    }

    /**
     * Historical Customer Ledger.
     */
    public function customerLedgerReport(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $customerId = $request->query('customer_id');
        $scope = strtolower($request->query('record_scope', 'all'));

        $query = DB::table('customer_ledger_entries')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($customerId) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.customerId')) = ?", [$customerId]);
        }

        if ($scope === 'old_only') {
            $query->where('is_historical', 1);
        } elseif ($scope === 'new_only') {
            $query->where('is_historical', 0);
        }

        $rows = $query->orderBy('created_at', 'asc')->get();

        $runningBalance = 0;
        $entries = [];

        foreach ($rows as $r) {
            $p = json_decode($r->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $dr = (int) ($f['debitPaise'] ?? 0);
            $cr = (int) ($f['creditPaise'] ?? 0);
            $runningBalance += ($dr - $cr);

            $entries[] = [
                'id' => $r->id,
                'customerId' => $f['customerId'] ?? null,
                'transactionType' => $f['transactionType'] ?? 'LEDGER',
                'originalInvoiceNumber' => $f['originalInvoiceNumber'] ?? null,
                'debitPaise' => $dr,
                'creditPaise' => $cr,
                'runningBalancePaise' => $runningBalance,
                'isHistorical' => (bool) $r->is_historical,
                'entrySource' => $r->entry_source,
                'transactionDate' => $r->original_transaction_date ?? Carbon::parse($r->created_at)->toDateString(),
            ];
        }

        return ApiResponse::success([
            'scope' => $scope,
            'customerId' => $customerId,
            'closingBalancePaise' => $runningBalance,
            'entries' => $entries,
        ], 'Customer ledger retrieved.');
    }

    /**
     * Historical Supplier Ledger.
     */
    public function supplierLedgerReport(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $supplierId = $request->query('supplier_id');
        $scope = strtolower($request->query('record_scope', 'all'));

        $query = DB::table('supplier_ledger_entries')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($supplierId) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.supplierId')) = ?", [$supplierId]);
        }

        if ($scope === 'old_only') {
            $query->where('is_historical', 1);
        } elseif ($scope === 'new_only') {
            $query->where('is_historical', 0);
        }

        $rows = $query->orderBy('created_at', 'asc')->get();

        $runningPayable = 0;
        $entries = [];

        foreach ($rows as $r) {
            $p = json_decode($r->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $cr = (int) ($f['credit'] ?? 0); // Credit adds to payable
            $dr = (int) ($f['debit'] ?? 0);  // Debit pays down payable
            $runningPayable += ($cr - $dr);

            $entries[] = [
                'id' => $r->id,
                'supplierId' => $f['supplierId'] ?? null,
                'entryType' => $f['entryType'] ?? 'LEDGER',
                'originalInvoiceNumber' => $f['originalInvoiceNumber'] ?? null,
                'debit' => $dr,
                'credit' => $cr,
                'runningPayable' => $runningPayable,
                'isHistorical' => (bool) $r->is_historical,
                'entrySource' => $r->entry_source,
                'transactionDate' => $r->original_transaction_date ?? Carbon::parse($r->created_at)->toDateString(),
            ];
        }

        return ApiResponse::success([
            'scope' => $scope,
            'supplierId' => $supplierId,
            'closingPayable' => $runningPayable,
            'entries' => $entries,
        ], 'Supplier ledger retrieved.');
    }

    /**
     * Historical Stock Movements Report.
     */
    public function stockMovementsReport(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized access.', 403, null, 'FORBIDDEN');
        }

        $scope = strtolower($request->query('record_scope', 'all'));
        $outletId = $request->query('outlet_id');
        $productId = $request->query('product_id');

        $query = DB::table('stock_movements')
            ->where('business_id', $businessId);

        if (Schema::hasColumn('stock_movements', 'is_deleted')) {
            $query->where('is_deleted', 0);
        }

        if (Schema::hasColumn('stock_movements', 'is_historical')) {
            if ($scope === 'old_only') {
                $query->where('is_historical', 1);
            } elseif ($scope === 'new_only') {
                $query->where('is_historical', 0);
            }
        }

        if ($outletId && $outletId !== 'ALL') {
            $query->where('outlet_id', $outletId);
        }
        if ($productId) {
            $query->where(function ($q) use ($productId) {
                $q->where('product_id', $productId)->orWhere('variant_id', $productId);
            });
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        // Pre-fetch product names for readability
        $prodMap = [];
        $pIds = $rows->pluck('product_id')->filter()->unique()->values()->all();
        if (! empty($pIds)) {
            $prods = DB::table('products')->whereIn('id', $pIds)->get(['id', 'payload']);
            foreach ($prods as $p) {
                $pPayload = json_decode($p->payload, true) ?: [];
                $prodMap[$p->id] = $pPayload['fields']['name'] ?? $p->id;
            }
        }

        $entries = [];
        foreach ($rows as $r) {
            $transDate = $r->original_transaction_date ?? Carbon::parse($r->created_at)->toDateString();
            $pName = $prodMap[$r->product_id] ?? $r->product_id;
            $entries[] = [
                'id' => $r->id,
                'productId' => $r->product_id,
                'product_id' => $r->product_id,
                'productName' => $pName,
                'product_name' => $pName,
                'variantId' => $r->variant_id,
                'variant_id' => $r->variant_id,
                'type' => $r->type,
                'qtyChange' => (float) $r->qty_change,
                'qty_change' => (float) $r->qty_change,
                'balanceAfter' => (float) $r->balance_after,
                'balance_after' => (float) $r->balance_after,
                'referenceType' => $r->reference_type,
                'reference_type' => $r->reference_type,
                'referenceId' => $r->reference_id,
                'reference_id' => $r->reference_id,
                'note' => $r->note,
                'isHistorical' => (bool) ($r->is_historical ?? false),
                'is_historical' => (bool) ($r->is_historical ?? false),
                'entrySource' => $r->entry_source ?? 'new',
                'entry_source' => $r->entry_source ?? 'new',
                'transactionDate' => $transDate,
                'original_transaction_date' => $transDate,
                'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
            ];
        }

        return ApiResponse::success([
            'scope' => $scope,
            'count' => count($entries),
            'records' => $entries,
            'movements' => $entries,
        ], 'Stock movements report retrieved.');
    }
}
