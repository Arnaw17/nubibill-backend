<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\SendFcmNotificationJob;
use App\Services\EmployeePermissionService;
use App\Services\InventoryStockService;
use App\Services\InvoiceCreationService;
use App\Services\UnitConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly UnitConversionService $unitService = new UnitConversionService,
        private readonly InvoiceCreationService $invoiceCreator = new InvoiceCreationService
    ) {}

    /**
     * Helper to compute Indian Financial Year label (e.g. 2026-04 starts FY 26).
     */
    private function financialYearLabel(\DateTimeInterface $date): string
    {
        $m = (int) $date->format('n');
        $y = (int) $date->format('Y');
        $fyYear = $m >= 4 ? $y : $y - 1;

        return str_pad((string) ($fyYear % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * List sales invoices for a business and outlet with search and status filters.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $billId = $request->query('bill_id') ?? $request->query('invoice_number');
        $customerId = $request->query('customer_id') ?? $request->query('customerId');
        $phone = $request->query('phone') ?? $request->query('customer_phone');
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

        $query = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($user && app(EmployeePermissionService::class)->isBillingStaff($user)) {
            $query->where(function ($q) use ($user) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.createdByUid')) = ?", [$user->id])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.creatorUid')) = ?", [$user->id])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.created_by')) = ?", [$user->id]);
            });
        }

        if ($outletId) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                    ->orWhere('outlet_id', '')
                    ->orWhereNull('outlet_id');
            });
        }

        if ($billId) {
            $query->where(function ($q) use ($billId) {
                $q->where('id', $billId)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.invoiceNumber')) = ?", [$billId]);
            });
        }

        if ($customerId) {
            $query->where(function ($q) use ($customerId) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.customerId')) = ?", [$customerId]);
            });
        }

        if ($phone) {
            $cleanPhone = preg_replace('/\D/', '', $phone);
            $query->where(function ($q) use ($cleanPhone) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.customerPhone')) = ?", [$cleanPhone]);
            });
        }

        if ($statusFilter !== 'ALL') {
            if ($statusFilter === 'PENDING') {
                $query->where(function ($q) {
                    $q->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.dueAmount')) AS SIGNED) > 0")
                        ->orWhere('status', 'PENDING');
                })->where('status', '!=', 'CANCELLED');
            } elseif ($statusFilter === 'HISTORICAL') {
                $query->where(function ($q) {
                    $q->where('is_historical', 1)
                        ->orWhere('entry_source', 'historical')
                        ->orWhere('id', 'LIKE', 'HIST-%')
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.isHistorical')) = 'true'")
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.entrySource')) = 'historical'");
                });
            } else {
                $query->where(function ($q) use ($statusFilter) {
                    $q->where('status', $statusFilter)
                        ->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.status'))) = ?", [$statusFilter])
                        ->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.paymentStatus'))) = ?", [$statusFilter]);
                });
            }
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
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];

            $invoiceNumber = (string) ($fields['invoiceNumber'] ?? $fields['invoice_number'] ?? $row->id);
            $customerName = (string) ($fields['customerName'] ?? $fields['customer_name'] ?? '');
            $customerPhone = (string) ($fields['customerPhone'] ?? $fields['customer_phone'] ?? '');

            if ($search !== '') {
                $sLower = strtolower($search);
                $matches = str_contains(strtolower($invoiceNumber), $sLower)
                    || str_contains(strtolower($customerName), $sLower)
                    || str_contains(strtolower($customerPhone), $sLower);
                if (! $matches) {
                    continue;
                }
            }

            $dueAmount = (int) ($fields['dueAmount'] ?? $fields['duePaise'] ?? 0);
            $paidPaise = (int) ($fields['paidPaise'] ?? $fields['paidAmount'] ?? 0);
            $totalAmount = (int) ($fields['totalAmount'] ?? $fields['grandTotal'] ?? $fields['subtotal'] ?? 0);
            $status = (string) ($fields['status'] ?? $row->status ?? 'PAID');
            $paymentStatus = (string) ($fields['paymentStatus'] ?? ($dueAmount == 0 ? 'PAID' : 'PARTIALLY_PAID'));

            $subtotal = (int) ($fields['subtotal'] ?? $fields['subtotalPaise'] ?? 0);
            $discount = (int) ($fields['discount'] ?? $fields['discountAmount'] ?? $fields['discountPaise'] ?? 0);
            $gstAmount = (int) ($fields['gstAmount'] ?? 0);
            $taxableAmount = (int) ($fields['taxableAmount'] ?? $fields['taxablePaise'] ?? ($subtotal - $discount));

            $itemData = array_merge($fields, [
                'invoiceId' => $row->id,
                'id' => $row->id,
                'invoiceNumber' => $invoiceNumber,
                'businessId' => $row->business_id,
                'outletId' => $fields['outletId'] ?? $row->outlet_id,
                'customerId' => $fields['customerId'] ?? null,
                'customerName' => $customerName ?: null,
                'customerPhone' => $customerPhone ?: null,
                'customerGstin' => $fields['customerGstin'] ?? null,
                'customerStateCode' => $fields['customerStateCode'] ?? null,
                'subtotal' => $subtotal,
                'subtotalPaise' => $subtotal,
                'discount' => $discount,
                'discountPaise' => $discount,
                'gstAmount' => $gstAmount,
                'taxableAmount' => $taxableAmount,
                'taxablePaise' => $taxableAmount,
                'totalAmount' => $totalAmount,
                'grandTotal' => $totalAmount,
                'grandTotalPaise' => $totalAmount,
                'dueAmount' => $dueAmount,
                'duePaise' => $dueAmount,
                'paidPaise' => $paidPaise,
                'paidAmount' => $paidPaise,
                'dueDate' => $fields['dueDate'] ?? null,
                'paymentMode' => $fields['paymentMode'] ?? 'CASH',
                'paymentStatus' => $paymentStatus,
                'status' => $status,
                'isHistorical' => (bool) ($fields['isHistorical'] ?? ($row->is_historical == 1) ?? false),
                'is_historical' => (int) ($row->is_historical ?? ($fields['isHistorical'] ? 1 : 0)),
                'entrySource' => (string) ($fields['entrySource'] ?? $row->entry_source ?? 'normal'),
                'itemsCount' => count($fields['items'] ?? []),
                'items' => $fields['items'] ?? [],
                'createdAt' => $fields['createdAt'] ?? $row->created_at,
            ]);

            $data[] = $itemData;
        }

        return ApiResponse::success($data, 'Sales invoices retrieved successfully.');
    }

    /**
     * Show single sales invoice details.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $row = DB::table('invoices')
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.invoiceId')) = ?", [$id])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.invoiceNumber')) = ?", [$id]);
            })
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Invoice not found.', 404, null, 'NOT_FOUND');
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
            return ApiResponse::error('Unauthorized access to this invoice.', 403, null, 'FORBIDDEN');
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];

        if ($user && app(EmployeePermissionService::class)->isBillingStaff($user)) {
            $createdByUid = (string) ($fields['createdByUid'] ?? $fields['creatorUid'] ?? $fields['created_by'] ?? '');
            if ($createdByUid !== '' && $createdByUid !== (string) $user->id) {
                return ApiResponse::error('You are not authorized to view sales from other counters/staff.', 403, null, 'PERMISSION_DENIED');
            }
        }

        // Check if items are in items table or embedded in payload
        $items = $fields['items'] ?? [];
        if (empty($items)) {
            $itemRows = DB::table('invoice_items')
                ->where('business_id', $row->business_id)
                ->where(function ($q) use ($id, $row) {
                    $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.invoiceId')) = ?", [$row->id])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.invoiceId')) = ?", [$id]);
                })
                ->get();

            foreach ($itemRows as $iRow) {
                $iPayload = json_decode($iRow->payload, true) ?: [];
                $items[] = $iPayload['fields'] ?? [];
            }
        }

        $dueAmount = (int) ($fields['dueAmount'] ?? $fields['duePaise'] ?? 0);
        $paidPaise = (int) ($fields['paidPaise'] ?? $fields['paidAmount'] ?? 0);
        $totalAmount = (int) ($fields['totalAmount'] ?? $fields['grandTotal'] ?? $fields['grandTotalPaise'] ?? $fields['subtotal'] ?? 0);
        $subtotal = (int) ($fields['subtotal'] ?? $fields['subtotalPaise'] ?? 0);
        $discount = (int) ($fields['discount'] ?? $fields['discountAmount'] ?? $fields['discountPaise'] ?? 0);
        $gstAmount = (int) ($fields['gstAmount'] ?? $fields['taxPaise'] ?? 0);
        $taxable = (int) ($fields['taxableAmount'] ?? $fields['taxablePaise'] ?? max(0, $subtotal - $discount));

        $data = [
            'invoiceId' => $row->id,
            'id' => $row->id,
            'invoiceNumber' => $fields['invoiceNumber'] ?? $row->id,
            'businessId' => $row->business_id,
            'outletId' => $fields['outletId'] ?? $row->outlet_id,
            'customerId' => $fields['customerId'] ?? null,
            'customerName' => $fields['customerName'] ?? null,
            'customerPhone' => $fields['customerPhone'] ?? null,
            'customerGstin' => $fields['customerGstin'] ?? null,
            'customerStateCode' => $fields['customerStateCode'] ?? null,
            'subtotal' => $subtotal,
            'subtotalPaise' => $subtotal,
            'discount' => $discount,
            'discountPaise' => $discount,
            'taxableAmount' => $taxable,
            'taxablePaise' => $taxable,
            'gstAmount' => $gstAmount,
            'taxPaise' => $gstAmount,
            'cgstPaise' => (int) ($fields['cgstPaise'] ?? ($gstAmount > 0 ? (int) ($gstAmount / 2) : 0)),
            'sgstPaise' => (int) ($fields['sgstPaise'] ?? ($gstAmount > 0 ? (int) ($gstAmount / 2) : 0)),
            'igstPaise' => (int) ($fields['igstPaise'] ?? 0),
            'roundOffPaise' => (int) ($fields['roundOffPaise'] ?? 0),
            'totalAmount' => $totalAmount,
            'grandTotal' => $totalAmount,
            'grandTotalPaise' => $totalAmount,
            'dueAmount' => $dueAmount,
            'duePaise' => $dueAmount,
            'paidPaise' => $paidPaise,
            'paidAmount' => $paidPaise,
            'dueDate' => $fields['dueDate'] ?? null,
            'paymentMode' => $fields['paymentMode'] ?? 'CASH',
            'paymentStatus' => $fields['paymentStatus'] ?? ($dueAmount == 0 ? 'PAID' : 'PARTIALLY_PAID'),
            'status' => $fields['status'] ?? $row->status ?? 'PAID',
            'items' => $items,
            'hasBusinessLogoSnapshot' => (bool) ($fields['hasBusinessLogoSnapshot'] ?? false),
            'businessLogoUrl' => $fields['businessLogoUrl'] ?? null,
            'businessLogoPath' => $fields['businessLogoPath'] ?? null,
            'businessLogoVersion' => $fields['businessLogoVersion'] ?? null,
            'businessLogoHash' => $fields['businessLogoHash'] ?? null,
            'hasBusinessSignatureSnapshot' => (bool) ($fields['hasBusinessSignatureSnapshot'] ?? false),
            'businessSignatureEnabled' => (bool) ($fields['businessSignatureEnabled'] ?? false),
            'businessSignatureUrl' => $fields['businessSignatureUrl'] ?? null,
            'businessSignaturePath' => $fields['businessSignaturePath'] ?? null,
            'businessSignatureVersion' => $fields['businessSignatureVersion'] ?? null,
            'businessSignatureHash' => $fields['businessSignatureHash'] ?? null,
            'businessSignatoryName' => $fields['businessSignatoryName'] ?? null,
            'businessSignatoryDesignation' => $fields['businessSignatoryDesignation'] ?? null,
            'createdAt' => $fields['createdAt'] ?? $row->created_at,
            'updatedAt' => $fields['updatedAt'] ?? $row->updated_at,
        ];

        return ApiResponse::success($data, 'Invoice retrieved successfully.');
    }

    /**
     * Finalize / create a new sales invoice.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => 'nullable|string',
            'business_id' => 'required|string',
            'outlet_id' => 'required|string',
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
            'bill_discount_paise' => 'nullable|integer|min:0',
            'bill_discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|string',
            'lines.*.variant_id' => 'nullable|string',
            'lines.*.product_name' => 'nullable|string',
            'lines.*.variant_name' => 'nullable|string',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price_paise' => 'required|integer|min:0',
            'lines.*.discount_paise' => 'nullable|integer|min:0',
            'lines.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.gst_rate_bps' => 'nullable|integer',
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

        $result = $this->invoiceCreator->finalizeInvoice(array_merge($validated, [
            'user_id' => $request->user()?->id ?? 'SYSTEM',
        ]));

        if (! empty($result['existing'])) {
            return ApiResponse::success([
                'invoiceId' => $result['invoiceId'],
                'invoiceNumber' => $result['invoiceNumber'],
                'existing' => true,
            ], 'Invoice retrieved (already processed).');
        }

        $invoiceNo = (string) ($result['invoiceNumber'] ?? $result['invoiceId'] ?? 'New Bill');
        $customerLabel = trim((string) ($validated['customer_name'] ?? 'Walk-in Customer'));
        SendFcmNotificationJob::dispatch(
            null,
            null,
            'New Sales Invoice Created',
            "Invoice {$invoiceNo} created for {$customerLabel}.",
            [
                'target_screen' => 'invoice',
                'type' => 'invoice_created',
                'invoice_id' => (string) ($result['invoiceId'] ?? ''),
                'invoice_number' => $invoiceNo,
                'business_id' => (string) $validated['business_id'],
            ],
            (string) $validated['business_id']
        );

        $lowStockAlerts = $result['lowStockAlerts'] ?? [];
        if (! empty($lowStockAlerts)) {
            if (count($lowStockAlerts) === 1) {
                $alert = $lowStockAlerts[0];
                $alertTitle = $alert['isOutOfStock'] ? 'Out of Stock Alert' : 'Low Stock Alert';
                $alertBody = $alert['isOutOfStock']
                    ? "'{$alert['productName']}' is now completely out of stock!"
                    : "'{$alert['productName']}' is running low: {$alert['remainingStock']} {$alert['unit']} remaining (Reorder level: {$alert['reorderLevel']}).";
                SendFcmNotificationJob::dispatch(
                    null,
                    null,
                    $alertTitle,
                    $alertBody,
                    [
                        'target_screen' => 'inventory',
                        'type' => $alert['isOutOfStock'] ? 'out_of_stock' : 'low_stock',
                        'product_id' => (string) ($alert['productId'] ?? ''),
                        'product_name' => (string) ($alert['productName'] ?? ''),
                        'business_id' => (string) $validated['business_id'],
                    ],
                    (string) $validated['business_id']
                );
            } else {
                $count = count($lowStockAlerts);
                $names = implode(', ', array_slice(array_column($lowStockAlerts, 'productName'), 0, 3));
                if ($count > 3) {
                    $names .= ' and '.($count - 3).' more';
                }
                SendFcmNotificationJob::dispatch(
                    null,
                    null,
                    'Low Stock Alert',
                    "{$count} products ({$names}) reached low stock after billing {$invoiceNo}.",
                    [
                        'target_screen' => 'inventory',
                        'type' => 'low_stock_summary',
                        'business_id' => (string) $validated['business_id'],
                        'count' => (string) $count,
                    ],
                    (string) $validated['business_id']
                );
            }
        }

        return ApiResponse::success($result, 'Sales invoice created successfully.', 201);
    }

    /**
     * Settle / pay dues on an invoice.
     */
    public function settle(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount_paise' => 'required|integer|min:1',
            'payment_mode' => 'nullable|string|in:CASH,UPI,MIXED,BANK_TRANSFER,CARD',
            'next_due_date' => 'nullable|string',
            'notes' => 'nullable|string|max:500',
        ]);

        $amountPaise = (int) $validated['amount_paise'];
        $paymentMode = $validated['payment_mode'] ?? 'CASH';
        $nextDueDate = $validated['next_due_date'] ?? null;
        $notes = $validated['notes'] ?? null;
        $userId = $request->user()?->id ?? 'SYSTEM';
        $nowStr = now()->toIso8601String();
        $dbNow = now();

        $invoice = DB::table('invoices')->where('id', $id)->first();
        if (! $invoice) {
            return ApiResponse::error('Invoice not found.', 'NOT_FOUND', 404);
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $invoice->business_id)) {
            return ApiResponse::error('Unauthorized access to this invoice.', 'FORBIDDEN', 403);
        }

        return DB::transaction(function () use (
            $id,
            $invoice,
            $amountPaise,
            $paymentMode,
            $nextDueDate,
            $notes,
            $userId,
            $nowStr,
            $dbNow
        ) {
            $payload = json_decode($invoice->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];

            $due = (int) ($fields['dueAmount'] ?? $fields['duePaise'] ?? 0);
            if ($due <= 0) {
                return ApiResponse::error('This invoice is already settled.', 'ALREADY_PAID', 400);
            }

            $applied = min($amountPaise, $due);
            $remaining = $due - $applied;
            $newPaid = ((int) ($fields['paidPaise'] ?? 0)) + $applied;
            $newStatus = $remaining == 0 ? 'PAID' : 'PARTIALLY_PAID';

            $fields['dueAmount'] = $remaining;
            $fields['duePaise'] = $remaining;
            $fields['paidPaise'] = $newPaid;
            $fields['paidAmount'] = $newPaid;
            $fields['status'] = $newStatus;
            $fields['paymentStatus'] = $newStatus;
            $fields['dueDate'] = $remaining == 0 ? null : $nextDueDate;
            $fields['updatedAt'] = $nowStr;
            $payload['fields'] = $fields;

            DB::table('invoices')->where('id', $id)->update([
                'updated_at' => $dbNow,
                'status' => $newStatus,
                'payload' => json_encode($payload),
            ]);

            $customerId = $fields['customerId'] ?? null;
            if ($customerId) {
                // Adjust customer balances
                $balRow = DB::table('customer_balances')
                    ->where('business_id', $invoice->business_id)
                    ->where(function ($q) use ($customerId) {
                        $q->where('id', $customerId)
                            ->orWhere('payload->fields->customerId', $customerId);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($balRow) {
                    $bPayload = json_decode($balRow->payload, true) ?: [];
                    $bFields = $bPayload['fields'] ?? [];
                    $curDue = (int) ($bFields['duePaise'] ?? 0);
                    $bFields['duePaise'] = max(0, $curDue - $applied);
                    $bFields['updatedAt'] = $nowStr;
                    $bPayload['fields'] = $bFields;

                    DB::table('customer_balances')->where('id', $balRow->id)->update([
                        'updated_at' => $dbNow,
                        'payload' => json_encode($bPayload),
                    ]);
                }

                // Add payment to customer_ledger_entries
                $ledgerId = 'LED-'.strtoupper(Str::random(24));
                DB::table('customer_ledger_entries')->insert([
                    'id' => $ledgerId,
                    'document_path' => "customer_ledger_entries/{$ledgerId}",
                    'business_id' => $invoice->business_id,
                    'outlet_id' => $invoice->outlet_id,
                    'status' => 'POSTED',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        'fields' => [
                            'transactionId' => $ledgerId,
                            'businessId' => $invoice->business_id,
                            'outletId' => $invoice->outlet_id,
                            'customerId' => $customerId,
                            'invoiceId' => $id,
                            'transactionType' => 'PAYMENT',
                            'amountPaise' => $applied,
                            'debitPaise' => 0,
                            'creditPaise' => $applied,
                            'createdAt' => $nowStr,
                            'createdBy' => $userId,
                            'status' => 'POSTED',
                            'notes' => $notes,
                        ],
                    ]),
                ]);
            }

            // Create payment voucher
            $payId = 'PAY-'.strtoupper(Str::random(24));
            DB::table('payments')->insert([
                'id' => $payId,
                'document_path' => "payments/{$payId}",
                'business_id' => $invoice->business_id,
                'outlet_id' => $invoice->outlet_id,
                'status' => 'COMPLETED',
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode([
                    'fields' => [
                        'paymentId' => $payId,
                        'businessId' => $invoice->business_id,
                        'outletId' => $invoice->outlet_id,
                        'customerId' => $customerId,
                        'invoiceId' => $id,
                        'amount' => $applied,
                        'paymentMethod' => $paymentMode,
                        'notes' => $notes,
                        'createdAt' => $nowStr,
                        'createdBy' => $userId,
                    ],
                ]),
            ]);

            $invoiceNo = (string) ($fields['invoiceNumber'] ?? $id);
            $paidRupees = number_format($applied / 100, 2);
            SendFcmNotificationJob::dispatch(
                null,
                null,
                'Invoice Payment Received',
                "Payment of INR {$paidRupees} recorded for invoice {$invoiceNo} ({$newStatus}).",
                [
                    'target_screen' => 'invoice',
                    'type' => 'invoice_settled',
                    'invoice_id' => (string) $id,
                    'invoice_number' => $invoiceNo,
                    'business_id' => (string) $invoice->business_id,
                    'status' => $newStatus,
                ],
                (string) $invoice->business_id
            )->afterCommit();

            return ApiResponse::success([
                'invoiceId' => $id,
                'remainingDuePaise' => $remaining,
                'status' => $newStatus,
            ], 'Payment applied to invoice successfully.');
        });
    }

    /**
     * Cancel an invoice and reverse ledger / dues.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $reason = $validated['reason'] ?? 'Cancelled by user';
        $userId = $request->user()?->id ?? 'SYSTEM';
        $nowStr = now()->toIso8601String();
        $dbNow = now();

        $invoice = DB::table('invoices')->where('id', $id)->first();
        if (! $invoice) {
            return ApiResponse::error('Invoice not found.', 'NOT_FOUND', 404);
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $invoice->business_id)) {
            return ApiResponse::error('Unauthorized access to this invoice.', 'FORBIDDEN', 403);
        }

        return DB::transaction(function () use (
            $id,
            $invoice,
            $reason,
            $userId,
            $nowStr,
            $dbNow
        ) {
            $payload = json_decode($invoice->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];

            if (($fields['status'] ?? '') === 'CANCELLED') {
                return ApiResponse::error('Invoice is already cancelled.', 'ALREADY_CANCELLED', 400);
            }

            $dueAmount = (int) ($fields['dueAmount'] ?? $fields['duePaise'] ?? 0);
            $customerId = $fields['customerId'] ?? null;

            $fields['status'] = 'CANCELLED';
            $fields['paymentStatus'] = 'CANCELLED';
            $fields['cancelReason'] = $reason;
            $fields['cancelledAt'] = $nowStr;
            $fields['cancelledBy'] = $userId;
            $payload['fields'] = $fields;

            DB::table('invoices')->where('id', $id)->update([
                'status' => 'CANCELLED',
                'updated_at' => $dbNow,
                'payload' => json_encode($payload),
            ]);

            // Reverse stock deducted during sale for this invoice
            $saleMovements = DB::table('stock_movements')
                ->where('business_id', $invoice->business_id)
                ->where('reference_type', 'invoice')
                ->where('reference_id', $id)
                ->where('type', 'sale')
                ->get();
            $stockSvc = new InventoryStockService($this->unitService);
            foreach ($saleMovements as $mov) {
                $mp = json_decode($mov->payload ?? '{}', true) ?: [];
                $mf = $mp['fields'] ?? [];
                $milliRestore = abs((int) ($mf['qtyBase'] ?? round(((float) abs($mov->qty_change)) * 1000)));
                if ($milliRestore <= 0) {
                    continue;
                }
                $mProdId = (string) ($mf['productId'] ?? $mov->product_id ?? '');
                $mVarId = (string) ($mf['variantId'] ?? $mov->variant_id ?? '');
                $mOutletId = (string) ($mov->outlet_id ?? $invoice->outlet_id ?? '');
                $mMeta = $mProdId !== '' ? $stockSvc->resolveProductMeta((string) $invoice->business_id, $mProdId) : ['behaviour' => 'SEPARATE_VARIANT'];
                $target = $stockSvc->inspectStockTarget((string) $invoice->business_id, $mOutletId, $mProdId, $mVarId, $mMeta['behaviour']);
                $row = $target['canonicalRow'];
                if ($row) {
                    $newMilli = $target['totalMilli'] + $milliRestore;
                    $newUnits = $this->unitService->fromMilliUnits($newMilli);
                    $sbPayload = json_decode($row->payload ?? '{}', true) ?: [];
                    $sbFields = $sbPayload['fields'] ?? [];
                    $sbFields['quantityOnHand'] = $newMilli;
                    $sbFields['currentStock'] = $newMilli;
                    $sbFields['stockScale'] = 'MILLI';
                    $sbFields['updatedAt'] = $nowStr;
                    $sbPayload['fields'] = $sbFields;
                    $sbUp = ['updated_at' => $dbNow, 'payload' => json_encode($sbPayload)];
                    if (Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
                        $sbUp['quantity_on_hand'] = $newUnits;
                    }
                    DB::table('stock_balances')->where('id', $row->id)->update($sbUp);

                    $revId = 'STM-'.strtoupper(Str::random(24));
                    DB::table('stock_movements')->insert([
                        'id' => $revId,
                        'document_path' => "stock_movements/{$revId}",
                        'business_id' => $invoice->business_id,
                        'outlet_id' => $mOutletId,
                        'product_id' => $mProdId ?: $target['canonicalVariantId'],
                        'variant_id' => $target['canonicalVariantId'],
                        'type' => 'return',
                        'qty_change' => $this->unitService->fromMilliUnits($milliRestore),
                        'balance_after' => $newUnits,
                        'reference_type' => 'invoice_cancellation',
                        'reference_id' => $id,
                        'condition' => 'good',
                        'note' => "Invoice {$id} cancelled: {$reason}",
                        'created_by' => $userId,
                        'created_at' => $dbNow,
                        'updated_at' => $dbNow,
                        'payload' => json_encode(['fields' => [
                            'movementId' => $revId,
                            'businessId' => $invoice->business_id,
                            'outletId' => $mOutletId,
                            'productId' => $mProdId ?: $target['canonicalVariantId'],
                            'variantId' => $target['canonicalVariantId'],
                            'inventoryBehaviour' => $mMeta['behaviour'],
                            'type' => 'RETURN',
                            'qtyBase' => $milliRestore,
                            'quantity' => $this->unitService->fromMilliUnits($milliRestore),
                            'balanceAfter' => $newMilli,
                            'balanceAfterUnits' => $newUnits,
                            'reason' => "Invoice {$id} cancelled: {$reason}",
                            'referenceId' => $id,
                            'userId' => $userId,
                            'createdAt' => $nowStr,
                        ]]),
                    ]);
                }
            }

            // Revert customer due balance if credit
            if ($customerId && $dueAmount > 0) {
                $balRow = DB::table('customer_balances')
                    ->where('business_id', $invoice->business_id)
                    ->where(function ($q) use ($customerId) {
                        $q->where('id', $customerId)
                            ->orWhere('payload->fields->customerId', $customerId);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($balRow) {
                    $bPayload = json_decode($balRow->payload, true) ?: [];
                    $bFields = $bPayload['fields'] ?? [];
                    $bFields['duePaise'] = max(0, ((int) ($bFields['duePaise'] ?? 0)) - $dueAmount);
                    $bFields['updatedAt'] = $nowStr;
                    $bPayload['fields'] = $bFields;

                    DB::table('customer_balances')->where('id', $balRow->id)->update([
                        'updated_at' => $dbNow,
                        'payload' => json_encode($bPayload),
                    ]);
                }

                // Add cancellation ledger entry
                $ledgerId = 'LED-'.strtoupper(Str::random(24));
                DB::table('customer_ledger_entries')->insert([
                    'id' => $ledgerId,
                    'document_path' => "customer_ledger_entries/{$ledgerId}",
                    'business_id' => $invoice->business_id,
                    'outlet_id' => $invoice->outlet_id,
                    'status' => 'POSTED',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        'fields' => [
                            'transactionId' => $ledgerId,
                            'businessId' => $invoice->business_id,
                            'outletId' => $invoice->outlet_id,
                            'customerId' => $customerId,
                            'invoiceId' => $id,
                            'transactionType' => 'ADJUSTMENT',
                            'entryType' => 'CANCELLATION_REVERSAL',
                            'amountPaise' => $dueAmount,
                            'debitPaise' => 0,
                            'creditPaise' => $dueAmount,
                            'createdAt' => $nowStr,
                            'createdBy' => $userId,
                            'status' => 'POSTED',
                            'notes' => "Invoice $id cancelled: $reason",
                        ],
                    ]),
                ]);
            }

            $invNumber = (string) ($fields['invoiceNumber'] ?? $id);
            $totalPaise = (int) ($fields['totalAmount'] ?? $fields['totalPaise'] ?? 0);
            $totalRupees = number_format($totalPaise / 100, 2);
            $staffName = 'Staff';
            if ($userId && $userId !== 'SYSTEM') {
                $userRow = DB::table('users')->where('id', $userId)->first();
                $staffName = $userRow ? ($userRow->name ?: $userRow->email) : 'Staff';
            }

            SendFcmNotificationJob::dispatch(
                null,
                null,
                'Invoice Cancelled',
                "Invoice {$invNumber} (INR {$totalRupees}) was cancelled by {$staffName}. Reason: {$reason}.",
                [
                    'target_screen' => 'invoice',
                    'type' => 'invoice_cancelled',
                    'invoice_id' => (string) $id,
                    'invoice_number' => $invNumber,
                    'business_id' => (string) $invoice->business_id,
                    'amount_paise' => (string) $totalPaise,
                    'cancel_reason' => (string) $reason,
                    'cancelled_by' => $staffName,
                ],
                (string) $invoice->business_id
            );

            return ApiResponse::success(null, 'Invoice cancelled successfully.');
        });
    }

    /**
     * Update due date on an invoice.
     */
    public function updateDueDate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'due_date' => 'required|string',
        ]);

        $invoice = DB::table('invoices')->where('id', $id)->first();
        if (! $invoice) {
            return ApiResponse::error('Invoice not found.', 'NOT_FOUND', 404);
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $invoice->business_id)) {
            return ApiResponse::error('Unauthorized access to this invoice.', 'FORBIDDEN', 403);
        }

        $payload = json_decode($invoice->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        $fields['dueDate'] = $validated['due_date'];
        $fields['updatedAt'] = now()->toIso8601String();
        $payload['fields'] = $fields;

        DB::table('invoices')->where('id', $id)->update([
            'updated_at' => now(),
            'payload' => json_encode($payload),
        ]);

        return ApiResponse::success(null, 'Due date updated successfully.');
    }
}
