<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
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

        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        if (! $businessId) {
            return ApiResponse::success([], 'No active business selected.');
        }

        $query = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

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
            $query->where('created_at', '<=', $endDate . ' 23:59:59');
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
            return ApiResponse::error('Invoice not found.', 'NOT_FOUND', 404);
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];

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
        $totalAmount = (int) ($fields['totalAmount'] ?? $fields['grandTotal'] ?? $fields['subtotal'] ?? 0);

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
            'subtotal' => (int) ($fields['subtotal'] ?? 0),
            'discount' => (int) ($fields['discount'] ?? $fields['discountAmount'] ?? 0),
            'gstAmount' => (int) ($fields['gstAmount'] ?? 0),
            'totalAmount' => $totalAmount,
            'dueAmount' => $dueAmount,
            'duePaise' => $dueAmount,
            'paidPaise' => $paidPaise,
            'dueDate' => $fields['dueDate'] ?? null,
            'paymentMode' => $fields['paymentMode'] ?? 'CASH',
            'paymentStatus' => $fields['paymentStatus'] ?? ($dueAmount == 0 ? 'PAID' : 'PARTIALLY_PAID'),
            'status' => $fields['status'] ?? $row->status ?? 'PAID',
            'items' => $items,
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
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|string',
            'lines.*.variant_id' => 'nullable|string',
            'lines.*.product_name' => 'nullable|string',
            'lines.*.variant_name' => 'nullable|string',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price_paise' => 'required|integer|min:0',
            'lines.*.discount_paise' => 'nullable|integer',
            'lines.*.gst_rate_bps' => 'nullable|integer',
            'lines.*.price_includes_tax' => 'nullable|boolean',
            'lines.*.hsn_code' => 'nullable|string',
            'lines.*.unit_id' => 'nullable|string',
            'lines.*.mrp' => 'nullable|integer',
            'lines.*.selling_price' => 'nullable|integer',
        ]);

        $requestId = $validated['request_id'] ?? null;
        $invoiceId = $requestId ? "REQ_{$requestId}" : ('INV-' . strtoupper(Str::random(24)));
        $businessId = $validated['business_id'];
        $outletId = $validated['outlet_id'];
        $shortCode = strtoupper($validated['business_short_code'] ?? 'INV');
        if (! preg_match('/^[A-Z0-9]+$/', $shortCode)) {
            $shortCode = 'INV';
        }

        $userId = $request->user()?->id ?? 'SYSTEM';
        $nowDt = new \DateTimeImmutable();
        $nowStr = $nowDt->format(\DateTimeInterface::ATOM);
        $dbNow = now();
        $fy = $this->financialYearLabel($nowDt);

        // 1. Idempotency check
        if ($requestId) {
            $existing = DB::table('invoices')->where('id', $invoiceId)->first();
            if ($existing) {
                $payload = json_decode($existing->payload, true) ?: [];
                $fields = $payload['fields'] ?? [];
                return ApiResponse::success([
                    'invoiceId' => $invoiceId,
                    'invoiceNumber' => $fields['invoiceNumber'] ?? $existing->id,
                    'existing' => true,
                ], 'Invoice retrieved (already processed).');
            }
        }

        $customerId = $validated['customer_id'] ?? null;
        $customerName = $validated['customer_name'] ?? null;
        $customerPhone = ! empty($validated['customer_phone']) ? preg_replace('/\D/', '', $validated['customer_phone']) : null;
        $customerGstin = ! empty($validated['customer_gstin']) ? strtoupper(trim($validated['customer_gstin'])) : null;
        $customerStateCode = $validated['customer_state_code'] ?? null;

        $cashPaise = (int) ($validated['cash_paise'] ?? 0);
        $upiPaise = (int) ($validated['upi_paise'] ?? 0);
        $advancePaise = (int) ($validated['advance_paise'] ?? 0);
        $duePaise = (int) ($validated['due_paise'] ?? 0);
        $dueDate = $validated['due_date'] ?? null;

        // Calculate line items
        $calculatedLines = [];
        $grossTotal = 0;
        $discountTotal = 0;
        $taxableTotal = 0;
        $taxTotal = 0;

        foreach ($validated['lines'] as $index => $item) {
            $qty = (int) $item['quantity'];
            $unitPrice = (int) $item['unit_price_paise'];
            $discount = (int) ($item['discount_paise'] ?? 0);
            $gross = $qty * $unitPrice;
            $discount = min($discount, $gross);
            $net = $gross - $discount;

            $gstRateBps = (int) ($item['gst_rate_bps'] ?? 1800);
            $priceIncludesTax = (bool) ($item['price_includes_tax'] ?? true);

            if ($priceIncludesTax) {
                $taxable = (int) round(($net * 10000) / (10000 + $gstRateBps));
                $tax = $net - $taxable;
            } else {
                $taxable = $net;
                $tax = (int) round(($taxable * $gstRateBps) / 10000);
            }

            $grossTotal += $gross;
            $discountTotal += $discount;
            $taxableTotal += $taxable;
            $taxTotal += $tax;

            $calculatedLines[] = [
                'itemId' => "REQ_{$invoiceId}_{$index}",
                'productId' => $item['product_id'],
                'variantId' => $item['variant_id'],
                'name' => $item['product_name'] ?? 'Product',
                'variantName' => $item['variant_name'] ?? 'Variant',
                'quantity' => $qty,
                'quantityBase' => $qty,
                'unit' => $item['unit_id'] ?? 'SYS_UNIT_PCS',
                'unitPricePaise' => $unitPrice,
                'discountPaise' => $discount,
                'taxablePaise' => $taxable,
                'taxPaise' => $tax,
                'lineTotalPaise' => $taxable + $tax,
                'gstRateBps' => $gstRateBps,
                'hsn' => $item['hsn_code'] ?? '',
                'sellingPrice' => $item['selling_price'] ?? $unitPrice,
                'mrp' => $item['mrp'] ?? $unitPrice,
                'conversionFactor' => 1,
            ];
        }

        $grandTotal = $taxableTotal + $taxTotal;
        $totalPaidOnBill = $cashPaise + $upiPaise + $advancePaise;
        $status = $duePaise == 0 ? 'PAID' : ($totalPaidOnBill > 0 ? 'PARTIALLY_PAID' : 'PENDING');

        // Document Sequence Generation inside transaction
        return DB::transaction(function () use (
            $invoiceId,
            $businessId,
            $outletId,
            $shortCode,
            $fy,
            $customerId,
            $customerName,
            $customerPhone,
            $customerGstin,
            $customerStateCode,
            $cashPaise,
            $upiPaise,
            $advancePaise,
            $duePaise,
            $dueDate,
            $grossTotal,
            $discountTotal,
            $taxableTotal,
            $taxTotal,
            $grandTotal,
            $status,
            $calculatedLines,
            $nowStr,
            $dbNow,
            $userId,
            $nowDt
        ) {
            // Sequence update with lock
            $sequenceId = "{$businessId}_{$fy}_SALES_INVOICE";
            $seqRow = DB::table('document_sequences')
                ->where('id', $sequenceId)
                ->lockForUpdate()
                ->first();

            $nextSequence = 1;
            if ($seqRow) {
                $payload = json_decode($seqRow->payload, true) ?: [];
                $fields = $payload['fields'] ?? [];
                $nextSequence = ((int) ($fields['sequence'] ?? 0)) + 1;
                $fields['sequence'] = $nextSequence;
                $fields['updatedAt'] = $nowStr;
                $payload['fields'] = $fields;

                DB::table('document_sequences')->where('id', $sequenceId)->update([
                    'updated_at' => $dbNow,
                    'payload' => json_encode($payload),
                ]);
            } else {
                DB::table('document_sequences')->insert([
                    'id' => $sequenceId,
                    'document_path' => "document_sequences/{$sequenceId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'ACTIVE',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        'fields' => [
                            'sequence' => 1,
                            'businessId' => $businessId,
                            'sequenceType' => 'SALES_INVOICE',
                            'financialYear' => $fy,
                            'updatedAt' => $nowStr,
                        ],
                    ]),
                ]);
            }

            $seqFormatted = str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
            $invoiceNumber = "{$shortCode}-{$fy}-{$seqFormatted}";

            $invoiceFields = [
                'invoiceId' => $invoiceId,
                'invoiceNumber' => $invoiceNumber,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'customerId' => $customerId,
                'customerName' => $customerName,
                'customerPhone' => $customerPhone,
                'customerGstin' => $customerGstin,
                'customerStateCode' => $customerStateCode,
                'subtotal' => $grossTotal,
                'discount' => $discountTotal,
                'discountAmount' => $discountTotal,
                'taxableAmount' => $taxableTotal,
                'gstAmount' => $taxTotal,
                'totalAmount' => $grandTotal,
                'grandTotal' => $grandTotal,
                'dueAmount' => $duePaise,
                'duePaise' => $duePaise,
                'paidPaise' => $cashPaise + $upiPaise,
                'paidAmount' => $cashPaise + $upiPaise,
                'dueDate' => $dueDate,
                'paymentMode' => $cashPaise > 0 && $upiPaise > 0 ? 'MIXED' : ($cashPaise > 0 ? 'CASH' : ($upiPaise > 0 ? 'UPI' : 'CREDIT')),
                'paymentStatus' => $status,
                'status' => $status,
                'items' => $calculatedLines,
                'createdAt' => $nowStr,
                'createdByUid' => $userId,
                'updatedAt' => $nowStr,
            ];

            // 1. Insert into invoices
            DB::table('invoices')->insert([
                'id' => $invoiceId,
                'document_path' => "invoices/{$invoiceId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => $status,
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $invoiceFields]),
            ]);

            // 2. Insert into invoice_items
            foreach ($calculatedLines as $line) {
                DB::table('invoice_items')->insert([
                    'id' => $line['itemId'],
                    'document_path' => "invoice_items/{$line['itemId']}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'ACTIVE',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        'fields' => array_merge($line, [
                            'invoiceId' => $invoiceId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                        ]),
                    ]),
                ]);
            }

            // 3. Customer Ledger and Balance updates
            if ($customerId) {
                $balRow = DB::table('customer_balances')
                    ->where('business_id', $businessId)
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
                    $curAdv = (int) ($bFields['advancePaise'] ?? 0);

                    $bFields['duePaise'] = max(0, $curDue + $duePaise);
                    $bFields['advancePaise'] = max(0, $curAdv - $advancePaise);
                    $bFields['updatedAt'] = $nowStr;
                    $bPayload['fields'] = $bFields;

                    DB::table('customer_balances')->where('id', $balRow->id)->update([
                        'updated_at' => $dbNow,
                        'payload' => json_encode($bPayload),
                    ]);
                }

                // If duePaise > 0, post CREDIT_SALE (+Dr)
                if ($duePaise > 0) {
                    $ledgerId = 'LED-' . strtoupper(Str::random(24));
                    DB::table('customer_ledger_entries')->insert([
                        'id' => $ledgerId,
                        'document_path' => "customer_ledger_entries/{$ledgerId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'status' => 'POSTED',
                        'created_at' => $dbNow,
                        'updated_at' => $dbNow,
                        'is_deleted' => 0,
                        'payload' => json_encode([
                            'fields' => [
                                'transactionId' => $ledgerId,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'customerId' => $customerId,
                                'invoiceId' => $invoiceId,
                                'transactionType' => 'CREDIT_SALE',
                                'amountPaise' => $duePaise,
                                'debitPaise' => $duePaise,
                                'creditPaise' => 0,
                                'advanceCreditPaise' => 0,
                                'createdAt' => $nowStr,
                                'createdBy' => $userId,
                                'status' => 'POSTED',
                                'immutable' => true,
                            ],
                        ]),
                    ]);
                }

                // If cash or upi paid, post PAYMENT (-Cr)
                $paidNow = $cashPaise + $upiPaise;
                if ($paidNow > 0) {
                    $ledgerPayId = 'LED-' . strtoupper(Str::random(24));
                    DB::table('customer_ledger_entries')->insert([
                        'id' => $ledgerPayId,
                        'document_path' => "customer_ledger_entries/{$ledgerPayId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'status' => 'POSTED',
                        'created_at' => $dbNow,
                        'updated_at' => $dbNow,
                        'is_deleted' => 0,
                        'payload' => json_encode([
                            'fields' => [
                                'transactionId' => $ledgerPayId,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'customerId' => $customerId,
                                'invoiceId' => $invoiceId,
                                'transactionType' => 'PAYMENT',
                                'amountPaise' => $paidNow,
                                'debitPaise' => 0,
                                'creditPaise' => $paidNow,
                                'advanceCreditPaise' => 0,
                                'createdAt' => $nowStr,
                                'createdBy' => $userId,
                                'status' => 'POSTED',
                                'immutable' => true,
                            ],
                        ]),
                    ]);
                }
            }

            // 4. Record payment voucher
            $paidTotal = $cashPaise + $upiPaise;
            if ($paidTotal > 0) {
                $payId = 'PAY-' . strtoupper(Str::random(24));
                DB::table('payments')->insert([
                    'id' => $payId,
                    'document_path' => "payments/{$payId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'COMPLETED',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        'fields' => [
                            'paymentId' => $payId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'customerId' => $customerId,
                            'invoiceId' => $invoiceId,
                            'amount' => $paidTotal,
                            'cashAmount' => $cashPaise,
                            'upiAmount' => $upiPaise,
                            'paymentMethod' => $cashPaise > 0 && $upiPaise > 0 ? 'MIXED' : ($cashPaise > 0 ? 'CASH' : 'UPI'),
                            'createdAt' => $nowStr,
                            'createdBy' => $userId,
                        ],
                    ]),
                ]);
            }

            // 5. Update stock balances: deduct sold quantity
            foreach ($calculatedLines as $line) {
                $vId = $line['variantId'] ?? '';
                $pId = $line['productId'] ?? '';
                $qty = (int) ($line['quantity'] ?? 0);
                if ($qty <= 0) continue;

                $stockRow = DB::table('stock_balances')
                    ->where('business_id', $businessId)
                    ->where('outlet_id', $outletId)
                    ->where(function ($q) use ($vId, $pId) {
                        if ($vId) {
                            $q->where('id', 'LIKE', "%_{$vId}")
                              ->orWhere('payload->fields->variantId', $vId);
                        }
                        if ($pId) {
                            $q->orWhere('payload->fields->productId', $pId);
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                if ($stockRow) {
                    $sbPayload = json_decode($stockRow->payload, true) ?: [];
                    $sbFields = $sbPayload['fields'] ?? [];
                    $currStock = (int) ($sbFields['quantityOnHand'] ?? $sbFields['currentStock'] ?? 0);
                    $milliQty = $qty * 1000;

                    // Support both thousandths (30000 = 30 units) and raw units
                    if ($currStock >= $milliQty || $currStock > 500) {
                        $newStock = max(0, $currStock - $milliQty);
                    } else {
                        $newStock = max(0, $currStock - $qty);
                    }

                    $oldVal = (int) ($sbFields['totalValue'] ?? 0);
                    $newVal = $currStock > 0 ? max(0, (int) round($oldVal * ($newStock / $currStock))) : 0;

                    $sbFields['quantityOnHand'] = $newStock;
                    $sbFields['currentStock'] = $newStock;
                    $sbFields['totalValue'] = $newVal;
                    $sbFields['updatedAt'] = $nowStr;
                    $sbPayload['fields'] = $sbFields;

                    DB::table('stock_balances')->where('id', $stockRow->id)->update([
                        'updated_at' => $dbNow,
                        'payload' => json_encode($sbPayload),
                    ]);
                }
            }

            return ApiResponse::success([
                'invoiceId' => $invoiceId,
                'invoiceNumber' => $invoiceNumber,
                'existing' => false,
                'invoice' => $invoiceFields,
            ], 'Sales invoice created successfully.', 201);
        });
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
                $ledgerId = 'LED-' . strtoupper(Str::random(24));
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
            $payId = 'PAY-' . strtoupper(Str::random(24));
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
                $ledgerId = 'LED-' . strtoupper(Str::random(24));
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
