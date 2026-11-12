<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\EmployeePermissionService;
use App\Services\InvoiceCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuotationController extends Controller
{
    public function __construct(
        private readonly InvoiceCreationService $invoiceCreator = new InvoiceCreationService
    ) {}

    /**
     * List saved/converted/cancelled quotations for a business and outlet.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $statusFilter = strtoupper((string) ($request->query('status') ?? 'ALL'));
        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('quotations')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($user && app(EmployeePermissionService::class)->isBillingStaff($user)) {
            $query->where('created_by', (string) $user->id);
        }

        if ($outletId) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                    ->orWhere('outlet_id', '')
                    ->orWhereNull('outlet_id');
            });
        }

        if ($statusFilter !== 'ALL') {
            $query->where('status', $statusFilter);
        }

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate.' 23:59:59');
        }

        $rows = $query->orderBy('created_at', 'desc')->get();
        $data = [];

        foreach ($rows as $row) {
            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            $fields = $payload['fields'] ?? [];
            $quotationNumber = (string) ($row->quotation_number ?? $fields['quotationNumber'] ?? $row->id);
            $customerName = (string) ($row->customer_name ?? $fields['customerName'] ?? '');
            $customerPhone = (string) ($row->customer_phone ?? $fields['customerPhone'] ?? '');

            if ($search !== '') {
                $sLower = strtolower($search);
                $matches = str_contains(strtolower($quotationNumber), $sLower)
                    || str_contains(strtolower($customerName), $sLower)
                    || str_contains(strtolower($customerPhone), $sLower);
                if (! $matches) {
                    continue;
                }
            }

            $data[] = $this->formatQuotationRecord($row, $fields);
        }

        return ApiResponse::success($data, 'Quotations retrieved successfully.');
    }

    /**
     * Show a single quotation by id or quotation number.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $row = DB::table('quotations')
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('quotation_number', $id);
            })
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Quotation not found.', 404, null, 'NOT_FOUND');
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
            return ApiResponse::error('Unauthorized access to this quotation.', 403, null, 'FORBIDDEN');
        }

        if ($user && app(EmployeePermissionService::class)->isBillingStaff($user)) {
            if ((string) ($row->created_by ?? '') !== '' && (string) $row->created_by !== (string) $user->id) {
                return ApiResponse::error('You are not authorized to view quotations from other staff.', 403, null, 'PERMISSION_DENIED');
            }
        }

        $payload = json_decode($row->payload ?? '{}', true) ?: [];
        $fields = $payload['fields'] ?? [];

        return ApiResponse::success($this->formatQuotationRecord($row, $fields), 'Quotation retrieved successfully.');
    }

    /**
     * Save a new quotation without deducting inventory stock or posting customer ledger/payments.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateQuotationPayload($request);
        $businessId = $validated['business_id'];
        $outletId = $validated['outlet_id'];

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness($businessId)) {
            return ApiResponse::error('Unauthorized access to this business.', 403, null, 'FORBIDDEN');
        }

        $requestId = $validated['request_id'] ?? null;
        $quotationId = $requestId ? "QTN_REQ_{$requestId}" : ('QTN-'.strtoupper(Str::random(24)));

        if ($requestId) {
            $existing = DB::table('quotations')->where('id', $quotationId)->first();
            if ($existing) {
                $payload = json_decode($existing->payload ?? '{}', true) ?: [];
                $fields = $payload['fields'] ?? [];

                return ApiResponse::success([
                    'quotationId' => $existing->id,
                    'quotationNumber' => $existing->quotation_number ?? ($fields['quotationNumber'] ?? $existing->id),
                    'existing' => true,
                    'quotation' => $this->formatQuotationRecord($existing, $fields),
                ], 'Quotation retrieved (already saved).');
            }
        }

        $shortCode = $this->invoiceCreator->normalizeShortCode($validated['business_short_code'] ?? 'INV', 'INV');
        $taxMode = $validated['tax_mode'] ?? 'GST';
        $taxStructure = $validated['tax_structure'] ?? 'INTRA_STATE';
        $calc = $this->invoiceCreator->calculateLinesAndTotals(
            $businessId,
            $quotationId,
            $validated['lines'],
            $taxMode,
            $taxStructure,
            (int) ($validated['bill_discount_paise'] ?? 0),
            (float) ($validated['bill_discount_percent'] ?? 0.0)
        );

        $userId = $user?->id ?? 'SYSTEM';
        $nowDt = new \DateTimeImmutable;
        $nowStr = $nowDt->format(\DateTimeInterface::ATOM);
        $dbNow = now();
        $fy = $this->invoiceCreator->financialYearLabel($nowDt);

        return DB::transaction(function () use (
            $quotationId,
            $businessId,
            $outletId,
            $shortCode,
            $fy,
            $validated,
            $taxMode,
            $taxStructure,
            $calc,
            $userId,
            $nowStr,
            $dbNow
        ) {
            $seq = $this->invoiceCreator->nextSequence(
                $businessId,
                $outletId,
                'QUOTATION',
                $fy,
                $nowStr,
                $dbNow
            );
            $seqFormatted = str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
            $quotationNumber = "QTN-{$shortCode}-{$fy}-{$seqFormatted}";

            $fields = $this->buildQuotationFields(
                quotationId: $quotationId,
                quotationNumber: $quotationNumber,
                businessId: $businessId,
                outletId: $outletId,
                shortCode: $shortCode,
                validated: $validated,
                taxMode: $taxMode,
                taxStructure: $taxStructure,
                calc: $calc,
                status: 'SAVED',
                userId: $userId,
                createdAt: $nowStr,
                updatedAt: $nowStr
            );

            DB::table('quotations')->insert([
                'id' => $quotationId,
                'document_path' => "quotations/{$quotationId}",
                'quotation_number' => $quotationNumber,
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'customer_id' => $fields['customerId'],
                'customer_name' => $fields['customerName'],
                'customer_phone' => $fields['customerPhone'],
                'status' => 'SAVED',
                'grand_total_paise' => $calc['grandTotal'],
                'valid_until' => $validated['valid_until'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $userId,
                'is_deleted' => 0,
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'payload' => json_encode(['fields' => $fields]),
            ]);

            $saved = DB::table('quotations')->where('id', $quotationId)->first();

            return ApiResponse::success([
                'quotationId' => $quotationId,
                'quotationNumber' => $quotationNumber,
                'existing' => false,
                'quotation' => $saved ? $this->formatQuotationRecord($saved, $fields) : $fields,
            ], 'Quotation saved successfully.', 201);
        });
    }

    /**
     * Update an unconverted saved quotation.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $this->validateQuotationPayload($request, isUpdate: true);
        $user = $request->user();

        return DB::transaction(function () use ($id, $validated, $user) {
            $row = DB::table('quotations')->where('id', $id)->where('is_deleted', 0)->lockForUpdate()->first();
            if (! $row) {
                return ApiResponse::error('Quotation not found.', 404, null, 'NOT_FOUND');
            }
            if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
                return ApiResponse::error('Unauthorized access to this quotation.', 403, null, 'FORBIDDEN');
            }
            if (strtoupper((string) $row->status) !== 'SAVED') {
                return ApiResponse::error('Only open saved quotations can be updated.', 400, null, 'INVALID_STATUS');
            }

            $existingPayload = json_decode($row->payload ?? '{}', true) ?: [];
            $existingFields = $existingPayload['fields'] ?? [];
            $businessId = (string) ($validated['business_id'] ?? $row->business_id);
            $outletId = (string) ($validated['outlet_id'] ?? $row->outlet_id);
            $shortCode = $this->invoiceCreator->normalizeShortCode(
                $validated['business_short_code'] ?? ($existingFields['businessShortCode'] ?? 'INV'),
                'INV'
            );
            $taxMode = $validated['tax_mode'] ?? ($existingFields['taxMode'] ?? 'GST');
            $taxStructure = $validated['tax_structure'] ?? ($existingFields['taxStructure'] ?? 'INTRA_STATE');
            $calc = $this->invoiceCreator->calculateLinesAndTotals(
                $businessId,
                $row->id,
                $validated['lines'],
                $taxMode,
                $taxStructure,
                (int) ($validated['bill_discount_paise'] ?? 0),
                (float) ($validated['bill_discount_percent'] ?? 0.0)
            );

            $nowStr = (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);
            $dbNow = now();
            $fields = $this->buildQuotationFields(
                quotationId: $row->id,
                quotationNumber: (string) ($row->quotation_number ?? $row->id),
                businessId: $businessId,
                outletId: $outletId,
                shortCode: $shortCode,
                validated: $validated,
                taxMode: $taxMode,
                taxStructure: $taxStructure,
                calc: $calc,
                status: 'SAVED',
                userId: (string) ($row->created_by ?? ($user?->id ?? 'SYSTEM')),
                createdAt: (string) ($existingFields['createdAt'] ?? $nowStr),
                updatedAt: $nowStr
            );

            DB::table('quotations')->where('id', $row->id)->update([
                'customer_id' => $fields['customerId'],
                'customer_name' => $fields['customerName'],
                'customer_phone' => $fields['customerPhone'],
                'grand_total_paise' => $calc['grandTotal'],
                'valid_until' => $validated['valid_until'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'updated_at' => $dbNow,
                'payload' => json_encode(['fields' => $fields]),
            ]);

            $updated = DB::table('quotations')->where('id', $row->id)->first();

            return ApiResponse::success([
                'quotationId' => $row->id,
                'quotationNumber' => (string) ($row->quotation_number ?? $row->id),
                'existing' => false,
                'quotation' => $updated ? $this->formatQuotationRecord($updated, $fields) : $fields,
            ], 'Quotation updated successfully.');
        });
    }

    /**
     * Convert a saved quotation into a real final sales invoice (with stock deduction and ledger posting).
     */
    public function finalize(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => 'nullable|string',
            'business_short_code' => 'nullable|string',
            'customer_id' => 'nullable|string',
            'customer_name' => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'customer_gstin' => 'nullable|string',
            'customer_state_code' => 'nullable|string',
            'tax_mode' => 'nullable|string',
            'tax_structure' => 'nullable|string',
            'cash_paise' => 'nullable|integer|min:0',
            'upi_paise' => 'nullable|integer|min:0',
            'advance_paise' => 'nullable|integer|min:0',
            'due_paise' => 'nullable|integer|min:0',
            'due_date' => 'nullable|string',
            'payment_mode' => 'nullable|string',
            'lines' => 'nullable|array|min:1',
            'bill_discount_paise' => 'nullable|integer|min:0',
            'bill_discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.product_id' => 'nullable|string',
            'lines.*.variant_id' => 'nullable|string',
            'lines.*.product_name' => 'nullable|string',
            'lines.*.variant_name' => 'nullable|string',
            'lines.*.sku' => 'nullable|string',
            'lines.*.quantity' => 'required_with:lines|numeric|min:0.001',
            'lines.*.unit_price_paise' => 'required_with:lines|integer|min:0',
            'lines.*.discount_paise' => 'nullable|integer|min:0',
            'lines.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.gst_rate_bps' => 'nullable|integer|min:0',
            'lines.*.price_includes_tax' => 'nullable|boolean',
            'lines.*.hsn_code' => 'nullable|string',
            'lines.*.unit_id' => 'nullable|string',
            'lines.*.unit' => 'nullable|string',
            'lines.*.unit_symbol' => 'nullable|string',
            'lines.*.unit_display_name' => 'nullable|string',
            'lines.*.conversion_factor' => 'nullable|numeric|min:0.0001',
            'lines.*.sales_unit_conversion_factor' => 'nullable|numeric|min:0.0001',
            'lines.*.track_stock' => 'nullable|boolean',
            'lines.*.mrp' => 'nullable|integer',
            'lines.*.selling_price' => 'nullable|integer',
            'lines.*.batch_id' => 'nullable|string',
            'lines.*.rack_id' => 'nullable|string',
        ]);

        $user = $request->user();
        $requestId = $validated['request_id'] ?? null;

        return DB::transaction(function () use ($id, $validated, $user, $requestId) {
            $row = DB::table('quotations')
                ->where('id', $id)
                ->where('is_deleted', 0)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return ApiResponse::error('Quotation not found.', 404, null, 'NOT_FOUND');
            }

            if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
                return ApiResponse::error('Unauthorized access to this quotation.', 403, null, 'FORBIDDEN');
            }

            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            $fields = $payload['fields'] ?? [];
            $status = strtoupper((string) ($row->status ?? $fields['status'] ?? 'SAVED'));

            if ($status === 'CANCELLED') {
                return ApiResponse::error('Cancelled quotations cannot be converted to a final bill.', 400, null, 'QUOTATION_CANCELLED');
            }

            if ($status === 'CONVERTED' || ! empty($row->converted_invoice_id)) {
                $sameRequest = $requestId && ($fields['finalizationRequestId'] ?? null) === $requestId;
                if ($sameRequest) {
                    return ApiResponse::success([
                        'invoiceId' => (string) $row->converted_invoice_id,
                        'invoiceNumber' => (string) ($row->converted_invoice_number ?? $row->converted_invoice_id),
                        'quotationId' => $row->id,
                        'quotationNumber' => (string) ($row->quotation_number ?? $row->id),
                        'existing' => true,
                    ], 'Quotation already converted to final bill.');
                }

                return ApiResponse::error(
                    "Quotation is already finalized into invoice {$row->converted_invoice_number}.",
                    409,
                    null,
                    'ALREADY_CONVERTED'
                );
            }

            $lines = $validated['lines'] ?? $this->extractLinesFromSavedItems($fields['items'] ?? []);
            if (empty($lines)) {
                return ApiResponse::error('Quotation has no line items to finalize.', 422, null, 'EMPTY_LINES');
            }

            $hasExplicitPayment = $requestId !== null
                || array_key_exists('cash_paise', $validated)
                || array_key_exists('upi_paise', $validated)
                || array_key_exists('advance_paise', $validated)
                || array_key_exists('due_paise', $validated);

            $cashPaise = (int) ($validated['cash_paise'] ?? 0);
            $upiPaise = (int) ($validated['upi_paise'] ?? 0);
            $advancePaise = (int) ($validated['advance_paise'] ?? 0);
            $duePaise = (int) ($validated['due_paise'] ?? 0);
            $enforceAllocation = $hasExplicitPayment && (
                array_key_exists('cash_paise', $validated)
                || array_key_exists('upi_paise', $validated)
                || array_key_exists('advance_paise', $validated)
                || array_key_exists('due_paise', $validated)
            );

            $taxMode = $validated['tax_mode'] ?? ($fields['taxMode'] ?? 'GST');
            $taxStructure = $validated['tax_structure'] ?? ($fields['taxStructure'] ?? 'INTRA_STATE');
            if (! $enforceAllocation) {
                $preview = $this->invoiceCreator->calculateLinesAndTotals(
                    (string) $row->business_id,
                    $row->id,
                    $lines,
                    $taxMode,
                    $taxStructure
                );
                $cashPaise = $preview['grandTotal'];
            }

            $invoiceResult = $this->invoiceCreator->finalizeInvoice([
                'request_id' => $requestId ?: "QTN_FIN_{$row->id}",
                'business_id' => (string) $row->business_id,
                'outlet_id' => (string) ($row->outlet_id ?: ($fields['outletId'] ?? '')),
                'business_short_code' => $validated['business_short_code'] ?? ($fields['businessShortCode'] ?? 'INV'),
                'customer_id' => array_key_exists('customer_id', $validated) ? $validated['customer_id'] : ($row->customer_id ?? ($fields['customerId'] ?? null)),
                'customer_name' => array_key_exists('customer_name', $validated) ? $validated['customer_name'] : ($row->customer_name ?? ($fields['customerName'] ?? null)),
                'customer_phone' => array_key_exists('customer_phone', $validated) ? $validated['customer_phone'] : ($row->customer_phone ?? ($fields['customerPhone'] ?? null)),
                'customer_gstin' => array_key_exists('customer_gstin', $validated) ? $validated['customer_gstin'] : ($fields['customerGstin'] ?? null),
                'customer_state_code' => array_key_exists('customer_state_code', $validated) ? $validated['customer_state_code'] : ($fields['customerStateCode'] ?? null),
                'tax_mode' => $taxMode,
                'tax_structure' => $taxStructure,
                'cash_paise' => $cashPaise,
                'upi_paise' => $upiPaise,
                'advance_paise' => $advancePaise,
                'due_paise' => $duePaise,
                'due_date' => $validated['due_date'] ?? null,
                'lines' => $lines,
                'user_id' => $user?->id ?? 'SYSTEM',
                'quotation_id' => $row->id,
                'quotation_number' => (string) ($row->quotation_number ?? $row->id),
                'enforce_payment_allocation' => true,
            ]);

            $nowStr = (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);
            $dbNow = now();

            $fields['status'] = 'CONVERTED';
            $fields['convertedInvoiceId'] = $invoiceResult['invoiceId'];
            $fields['convertedInvoiceNumber'] = $invoiceResult['invoiceNumber'];
            $fields['convertedAt'] = $nowStr;
            $fields['finalizationRequestId'] = $requestId ?: "QTN_FIN_{$row->id}";
            $fields['updatedAt'] = $nowStr;
            $payload['fields'] = $fields;

            DB::table('quotations')->where('id', $row->id)->update([
                'status' => 'CONVERTED',
                'converted_invoice_id' => $invoiceResult['invoiceId'],
                'converted_invoice_number' => $invoiceResult['invoiceNumber'],
                'converted_at' => $dbNow,
                'updated_at' => $dbNow,
                'payload' => json_encode($payload),
            ]);

            return ApiResponse::success([
                'invoiceId' => $invoiceResult['invoiceId'],
                'invoiceNumber' => $invoiceResult['invoiceNumber'],
                'quotationId' => $row->id,
                'quotationNumber' => (string) ($row->quotation_number ?? $row->id),
                'existing' => (bool) ($invoiceResult['existing'] ?? false),
                'invoice' => $invoiceResult['invoice'] ?? null,
            ], 'Quotation converted to final sales invoice successfully.', 201);
        });
    }

    /**
     * Cancel an unconverted saved quotation.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $row = DB::table('quotations')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Quotation not found.', 404, null, 'NOT_FOUND');
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
            return ApiResponse::error('Unauthorized access to this quotation.', 403, null, 'FORBIDDEN');
        }

        $status = strtoupper((string) $row->status);
        if ($status === 'CONVERTED') {
            return ApiResponse::error('Converted quotations cannot be cancelled. Cancel the linked invoice instead.', 400, null, 'ALREADY_CONVERTED');
        }
        if ($status === 'CANCELLED') {
            return ApiResponse::error('Quotation is already cancelled.', 400, null, 'ALREADY_CANCELLED');
        }

        $nowStr = (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);
        $payload = json_decode($row->payload ?? '{}', true) ?: [];
        $fields = $payload['fields'] ?? [];
        $fields['status'] = 'CANCELLED';
        $fields['cancelReason'] = $validated['reason'] ?? 'Cancelled by user';
        $fields['cancelledAt'] = $nowStr;
        $fields['cancelledBy'] = $user?->id ?? 'SYSTEM';
        $fields['updatedAt'] = $nowStr;
        $payload['fields'] = $fields;

        DB::table('quotations')->where('id', $row->id)->update([
            'status' => 'CANCELLED',
            'updated_at' => now(),
            'payload' => json_encode($payload),
        ]);

        return ApiResponse::success(null, 'Quotation cancelled successfully.');
    }

    private function validateQuotationPayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'request_id' => 'nullable|string',
            'business_id' => $isUpdate ? 'nullable|string' : 'required|string',
            'outlet_id' => $isUpdate ? 'nullable|string' : 'required|string',
            'business_short_code' => 'nullable|string',
            'customer_id' => 'nullable|string',
            'customer_name' => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'customer_gstin' => 'nullable|string',
            'customer_state_code' => 'nullable|string',
            'tax_mode' => 'nullable|string',
            'tax_structure' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'bill_discount_paise' => 'nullable|integer|min:0',
            'bill_discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|string',
            'lines.*.variant_id' => 'nullable|string',
            'lines.*.product_name' => 'nullable|string',
            'lines.*.variant_name' => 'nullable|string',
            'lines.*.sku' => 'nullable|string',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price_paise' => 'required|integer|min:0',
            'lines.*.discount_paise' => 'nullable|integer|min:0',
            'lines.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.gst_rate_bps' => 'nullable|integer|min:0',
            'lines.*.price_includes_tax' => 'nullable|boolean',
            'lines.*.hsn_code' => 'nullable|string',
            'lines.*.unit_id' => 'nullable|string',
            'lines.*.unit' => 'nullable|string',
            'lines.*.unit_symbol' => 'nullable|string',
            'lines.*.unit_display_name' => 'nullable|string',
            'lines.*.conversion_factor' => 'nullable|numeric|min:0.0001',
            'lines.*.sales_unit_conversion_factor' => 'nullable|numeric|min:0.0001',
            'lines.*.track_stock' => 'nullable|boolean',
            'lines.*.mrp' => 'nullable|integer',
            'lines.*.selling_price' => 'nullable|integer',
            'lines.*.batch_id' => 'nullable|string',
            'lines.*.rack_id' => 'nullable|string',
            'lines.*.aggregate_size' => 'nullable|string',
            'lines.*.grade_quality' => 'nullable|string',
            'lines.*.custom_attributes' => 'nullable|array',
            'lines.*.attribute_values' => 'nullable|array',
        ]);
    }

    private function buildQuotationFields(
        string $quotationId,
        string $quotationNumber,
        string $businessId,
        string $outletId,
        string $shortCode,
        array $validated,
        string $taxMode,
        string $taxStructure,
        array $calc,
        string $status,
        string $userId,
        string $createdAt,
        string $updatedAt
    ): array {
        $customerPhone = ! empty($validated['customer_phone'])
            ? preg_replace('/\D/', '', $validated['customer_phone'])
            : null;
        $customerGstin = ! empty($validated['customer_gstin'])
            ? strtoupper(trim($validated['customer_gstin']))
            : null;

        $isInterState = strtoupper($taxStructure) === 'INTER_STATE';
        $taxTotal = (int) $calc['taxTotal'];
        $cgstTotal = $isInterState ? 0 : (int) ($taxTotal / 2);
        $sgstTotal = $isInterState ? 0 : ($taxTotal - $cgstTotal);
        $igstTotal = $isInterState ? $taxTotal : 0;

        $logoSnapshot = BusinessController::resolveActiveLogoSnapshot($businessId);
        $signatureSnapshot = BusinessController::resolveActiveSignatureSnapshot($businessId);

        return array_merge([
            'quotationId' => $quotationId,
            'quotationNumber' => $quotationNumber,
            'businessId' => $businessId,
            'outletId' => $outletId,
            'businessShortCode' => $shortCode,
            'customerId' => $validated['customer_id'] ?? null,
            'customerName' => $validated['customer_name'] ?? null,
            'customerPhone' => $customerPhone,
            'customerGstin' => $customerGstin,
            'customerStateCode' => $validated['customer_state_code'] ?? null,
            'taxMode' => $taxMode,
            'taxStructure' => $taxStructure,
            'subtotalPaise' => $calc['grossTotal'],
            'discountPaise' => $calc['discountTotal'],
            'taxablePaise' => $calc['taxableTotal'],
            'gstAmount' => $taxTotal,
            'cgstPaise' => $cgstTotal,
            'sgstPaise' => $sgstTotal,
            'igstPaise' => $igstTotal,
            'roundOffPaise' => 0,
            'grandTotalPaise' => $calc['grandTotal'],
            'validUntil' => $validated['valid_until'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $status,
            'items' => $calc['lines'],
            'createdByUid' => $userId,
            'createdAt' => $createdAt,
            'updatedAt' => $updatedAt,
        ], $logoSnapshot, $signatureSnapshot);
    }

    private function extractLinesFromSavedItems(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $lines[] = [
                'product_id' => $item['productId'] ?? ($item['product_id'] ?? null),
                'variant_id' => $item['variantId'] ?? ($item['variant_id'] ?? null),
                'product_name' => $item['name'] ?? ($item['product_name'] ?? 'Product'),
                'variant_name' => $item['variantName'] ?? ($item['variant_name'] ?? 'Variant'),
                'sku' => $item['sku'] ?? '',
                'quantity' => $item['quantity'] ?? 1,
                'unit_price_paise' => (int) ($item['unitPricePaise'] ?? ($item['unit_price_paise'] ?? 0)),
                'discount_paise' => (int) ($item['discountPaise'] ?? ($item['discount_paise'] ?? 0)),
                'gst_rate_bps' => (int) ($item['gstRateBps'] ?? ($item['gst_rate_bps'] ?? 0)),
                'price_includes_tax' => (bool) ($item['priceIncludesTax'] ?? ($item['price_includes_tax'] ?? true)),
                'hsn_code' => $item['hsn'] ?? ($item['hsn_code'] ?? ''),
                'unit_id' => $item['unitId'] ?? ($item['unit_id'] ?? null),
                'unit' => $item['unit'] ?? null,
                'unit_symbol' => $item['unitSymbol'] ?? ($item['unit'] ?? null),
                'unit_display_name' => $item['unitDisplayName'] ?? null,
                'sales_unit_conversion_factor' => $item['conversionFactor'] ?? ($item['sales_unit_conversion_factor'] ?? 1.0),
                'track_stock' => array_key_exists('trackStock', $item) ? (bool) $item['trackStock'] : true,
                'mrp' => $item['mrp'] ?? null,
                'selling_price' => $item['sellingPrice'] ?? null,
                'batch_id' => $item['batchId'] ?? ($item['batch_id'] ?? null),
                'rack_id' => $item['rackId'] ?? ($item['rack_id'] ?? null),
            ];
        }

        return $lines;
    }

    private function formatQuotationRecord(object $row, array $fields): array
    {
        $items = $fields['items'] ?? [];
        $taxTotal = (int) ($fields['gstAmount'] ?? 0);
        $grandTotal = (int) ($row->grand_total_paise ?? $fields['grandTotalPaise'] ?? 0);

        return array_merge($fields, [
            'id' => $row->id,
            'quotationId' => $row->id,
            'quotationNumber' => (string) ($row->quotation_number ?? $fields['quotationNumber'] ?? $row->id),
            'businessId' => $row->business_id,
            'outletId' => $row->outlet_id ?? ($fields['outletId'] ?? null),
            'customerId' => $row->customer_id ?? ($fields['customerId'] ?? null),
            'customerName' => $row->customer_name ?? ($fields['customerName'] ?? null),
            'customerPhone' => $row->customer_phone ?? ($fields['customerPhone'] ?? null),
            'status' => strtoupper((string) ($row->status ?? $fields['status'] ?? 'SAVED')),
            'grandTotalPaise' => $grandTotal,
            'gstAmount' => $taxTotal,
            'cgstPaise' => (int) ($fields['cgstPaise'] ?? (int) ($taxTotal / 2)),
            'sgstPaise' => (int) ($fields['sgstPaise'] ?? ($taxTotal - (int) ($taxTotal / 2))),
            'igstPaise' => (int) ($fields['igstPaise'] ?? 0),
            'validUntil' => $row->valid_until ?? ($fields['validUntil'] ?? null),
            'notes' => $row->notes ?? ($fields['notes'] ?? null),
            'convertedInvoiceId' => $row->converted_invoice_id ?? ($fields['convertedInvoiceId'] ?? null),
            'convertedInvoiceNumber' => $row->converted_invoice_number ?? ($fields['convertedInvoiceNumber'] ?? null),
            'convertedAt' => $row->converted_at ?? ($fields['convertedAt'] ?? null),
            'itemsCount' => is_array($items) ? count($items) : 0,
            'items' => $items,
            'createdAt' => $fields['createdAt'] ?? $row->created_at,
            'updatedAt' => $fields['updatedAt'] ?? $row->updated_at,
        ]);
    }
}
