<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PurchaseController extends Controller
{
    /**
     * List purchase bills for business and outlet.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $supplierId = $request->query('supplier_id') ?? $request->query('supplierId');
        $statusFilter = strtoupper((string) ($request->query('status') ?? 'ALL'));
        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));

        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        if (! $businessId) {
            return ApiResponse::success([], 'No active business selected.');
        }

        $query = DB::table('purchase_bills')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                    ->orWhere('outlet_id', '')
                    ->orWhereNull('outlet_id');
            });
        }

        if ($supplierId) {
            $query->where(function ($q) use ($supplierId) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.supplierId')) = ?", [$supplierId]);
            });
        }

        if ($statusFilter !== 'ALL') {
            $query->where(function ($q) use ($statusFilter) {
                $q->where('status', $statusFilter)
                    ->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.status'))) = ?", [$statusFilter]);
            });
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $data = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];

            $purchaseNumber = (string) ($fields['purchaseNumber'] ?? '');
            $supplierName = (string) ($fields['supplierName'] ?? '');
            $supplierInvoiceNumber = (string) ($fields['supplierInvoiceNumber'] ?? '');

            if ($search !== '') {
                $sLower = strtolower($search);
                $matches = str_contains(strtolower($purchaseNumber), $sLower)
                    || str_contains(strtolower($supplierName), $sLower)
                    || str_contains(strtolower($supplierInvoiceNumber), $sLower);
                if (! $matches) {
                    continue;
                }
            }

            $data[] = [
                'purchaseBillId' => $row->id,
                'id' => $row->id,
                'purchaseNumber' => $purchaseNumber,
                'businessId' => $row->business_id,
                'outletId' => $fields['outletId'] ?? $row->outlet_id,
                'supplierId' => $fields['supplierId'] ?? '',
                'supplierName' => $supplierName,
                'supplierInvoiceNumber' => $supplierInvoiceNumber,
                'invoiceDate' => $fields['invoiceDate'] ?? $row->created_at,
                'purchaseDate' => $fields['purchaseDate'] ?? $row->created_at,
                'dueDate' => $fields['dueDate'] ?? null,
                'purchaseType' => $fields['purchaseType'] ?? 'DIRECT',
                'paymentStatus' => $fields['paymentStatus'] ?? 'UNPAID',
                'subtotal' => (int) ($fields['subtotal'] ?? 0),
                'taxableAmount' => (int) ($fields['taxableAmount'] ?? 0),
                'cgstAmount' => (int) ($fields['cgstAmount'] ?? 0),
                'sgstAmount' => (int) ($fields['sgstAmount'] ?? 0),
                'igstAmount' => (int) ($fields['igstAmount'] ?? 0),
                'roundOff' => (int) ($fields['roundOff'] ?? 0),
                'grandTotal' => (int) ($fields['grandTotal'] ?? 0),
                'paidAmount' => (int) ($fields['paidAmount'] ?? 0),
                'outstandingAmount' => (int) ($fields['outstandingAmount'] ?? 0),
                'taxType' => $fields['taxType'] ?? 'INTRA_STATE',
                'status' => $fields['status'] ?? $row->status ?? 'APPROVED',
                'itemsCount' => count($fields['items'] ?? []),
                'items' => $fields['items'] ?? [],
                'createdAt' => $row->created_at,
            ];
        }

        return ApiResponse::success($data, 'Purchase bills retrieved successfully.');
    }

    /**
     * Show single purchase bill with line items.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $row = DB::table('purchase_bills')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Purchase bill not found.', 'NOT_FOUND', 404);
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];

        // Check if items are in items table or embedded in payload
        $items = $fields['items'] ?? [];
        if (empty($items)) {
            $itemRows = DB::table('purchase_bill_items')
                ->where('business_id', $row->business_id)
                ->where(function ($q) use ($id) {
                    $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.purchaseBillId')) = ?", [$id])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.purchaseId')) = ?", [$id]);
                })
                ->get();

            foreach ($itemRows as $iRow) {
                $iPayload = json_decode($iRow->payload, true) ?: [];
                $items[] = $iPayload['fields'] ?? [];
            }
        }

        $data = [
            'purchaseBillId' => $row->id,
            'id' => $row->id,
            'purchaseNumber' => $fields['purchaseNumber'] ?? '',
            'businessId' => $row->business_id,
            'outletId' => $fields['outletId'] ?? $row->outlet_id,
            'supplierId' => $fields['supplierId'] ?? '',
            'supplierName' => $fields['supplierName'] ?? '',
            'supplierInvoiceNumber' => $fields['supplierInvoiceNumber'] ?? '',
            'invoiceDate' => $fields['invoiceDate'] ?? $row->created_at,
            'purchaseDate' => $fields['purchaseDate'] ?? $row->created_at,
            'dueDate' => $fields['dueDate'] ?? null,
            'purchaseType' => $fields['purchaseType'] ?? 'DIRECT',
            'paymentStatus' => $fields['paymentStatus'] ?? 'UNPAID',
            'subtotal' => (int) ($fields['subtotal'] ?? 0),
            'taxableAmount' => (int) ($fields['taxableAmount'] ?? 0),
            'cgstAmount' => (int) ($fields['cgstAmount'] ?? 0),
            'sgstAmount' => (int) ($fields['sgstAmount'] ?? 0),
            'igstAmount' => (int) ($fields['igstAmount'] ?? 0),
            'roundOff' => (int) ($fields['roundOff'] ?? 0),
            'grandTotal' => (int) ($fields['grandTotal'] ?? 0),
            'paidAmount' => (int) ($fields['paidAmount'] ?? 0),
            'outstandingAmount' => (int) ($fields['outstandingAmount'] ?? 0),
            'taxType' => $fields['taxType'] ?? 'INTRA_STATE',
            'status' => $fields['status'] ?? $row->status ?? 'APPROVED',
            'items' => $items,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];

        return ApiResponse::success($data, 'Purchase bill details retrieved successfully.');
    }

    /**
     * Create / record a purchase bill with line items.
     */
    public function store(Request $request): JsonResponse
    {
        $rawItems = $request->input('items', []);
        $normalizedItems = [];
        foreach ($rawItems as $ri) {
            $normalizedItems[] = [
                'product_id' => $ri['product_id'] ?? $ri['productId'] ?? null,
                'variant_id' => $ri['variant_id'] ?? $ri['variantId'] ?? '',
                'product_name' => $ri['product_name'] ?? $ri['productName'] ?? '',
                'variant_name' => $ri['variant_name'] ?? $ri['variantName'] ?? '',
                'quantity' => $ri['quantity'] ?? $ri['qty'] ?? 1,
                'unit_id' => $ri['unit_id'] ?? $ri['unitId'] ?? 'SYS_UNIT_PCS',
                'rate' => $ri['rate'] ?? 0,
                'gst_rate_bps' => $ri['gst_rate_bps'] ?? $ri['gstRateBps'] ?? 0,
                'selling_price' => $ri['selling_price'] ?? $ri['sellingPrice'] ?? 0,
                'mrp' => $ri['mrp'] ?? 0,
                'barcode' => $ri['barcode'] ?? $ri['barcodeSnapshot'] ?? null,
            ];
        }

        $request->merge([
            'business_id' => $request->input('business_id') ?? $request->input('businessId') ?? $request->header('X-Business-Id'),
            'outlet_id' => $request->input('outlet_id') ?? $request->input('outletId') ?? $request->header('X-Outlet-Id'),
            'supplier_id' => $request->input('supplier_id') ?? $request->input('supplierId'),
            'supplier_invoice_number' => $request->input('supplier_invoice_number') ?? $request->input('supplierInvoiceNumber'),
            'invoice_date' => $request->input('invoice_date') ?? $request->input('invoiceDate'),
            'purchase_date' => $request->input('purchase_date') ?? $request->input('purchaseDate'),
            'due_date' => $request->input('due_date') ?? $request->input('dueDate'),
            'place_of_supply_state_code' => $request->input('place_of_supply_state_code') ?? $request->input('placeOfSupplyStateCode'),
            'charges_amount' => $request->input('charges_amount') ?? $request->input('chargesAmount'),
            'discount_amount' => $request->input('discount_amount') ?? $request->input('discountAmount'),
            'paid_amount' => $request->input('paid_amount') ?? $request->input('paidAmount'),
            'payment_method' => $request->input('payment_method') ?? $request->input('paymentMethod'),
            'items' => $normalizedItems,
        ]);

        $validated = $request->validate([
            'business_id' => 'required|string',
            'outlet_id' => 'required|string',
            'supplier_id' => 'required|string',
            'supplier_invoice_number' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|string',
            'purchase_date' => 'nullable|string',
            'due_date' => 'nullable|string',
            'tax_type' => 'nullable|string|in:INTRA_STATE,INTER_STATE',
            'place_of_supply_state_code' => 'nullable|string|max:10',
            'charges_amount' => 'nullable|integer|min:0',
            'discount_amount' => 'nullable|integer|min:0',
            'paid_amount' => 'nullable|integer|min:0',
            'payment_method' => 'nullable|string',
            'status' => 'nullable|string|in:APPROVED,DRAFT',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|string',
            'items.*.variant_id' => 'required|string',
            'items.*.product_name' => 'nullable|string',
            'items.*.variant_name' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_id' => 'nullable|string',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.gst_rate_bps' => 'nullable|integer|min:0',
            'items.*.selling_price' => 'nullable|integer|min:0',
            'items.*.mrp' => 'nullable|integer|min:0',
            'items.*.barcode' => 'nullable|string',
        ]);

        $businessId = $validated['business_id'];
        $outletId = $validated['outlet_id'];
        $supplierId = $validated['supplier_id'];
        $supplierInvoiceNumber = $validated['supplier_invoice_number'] ?? '';
        $taxType = $validated['tax_type'] ?? 'INTRA_STATE';
        $chargesAmount = (int) ($validated['charges_amount'] ?? 0);
        $discountAmount = (int) ($validated['discount_amount'] ?? 0);
        $paidAmount = (int) ($validated['paid_amount'] ?? 0);
        $paymentMethod = $validated['payment_method'] ?? 'CASH';
        $status = strtoupper($validated['status'] ?? 'APPROVED');
        $userId = $request->user()?->id ?? 'SYSTEM';

        $now = now()->toIso8601String();
        $dbNow = now();
        $invoiceDate = $validated['invoice_date'] ?? $now;
        $purchaseDate = $validated['purchase_date'] ?? $invoiceDate;
        $dueDate = $validated['due_date'] ?? $purchaseDate;

        // Fetch supplier
        $supplierRow = DB::table('suppliers')->where('id', $supplierId)->first();
        if (! $supplierRow) {
            return ApiResponse::error('Supplier not found.', 'NOT_FOUND', 404);
        }
        $sPayload = json_decode($supplierRow->payload, true) ?: [];
        $supplierName = $sPayload['fields']['name'] ?? 'Supplier';

        // Calculate totals from line items
        $lineItems = [];
        $subtotal = 0;
        $totalTax = 0;

        foreach ($validated['items'] as $index => $item) {
            $qty = (float) $item['quantity'];
            $rate = (int) $item['rate'];
            $gstRateBps = (int) ($item['gst_rate_bps'] ?? 1800);

            $taxableAmount = (int) round(($qty * $rate) / 1000); // 1000 conversion if millieunits, or direct paise
            if ($taxableAmount == 0 && $qty > 0 && $rate > 0) {
                $taxableAmount = (int) round($qty * $rate);
            }

            $taxAmount = (int) round(($taxableAmount * $gstRateBps) / 10000);
            $subtotal += $taxableAmount;
            $totalTax += $taxAmount;

            $pId = $item['product_id'] ?? null;
            if (! $pId && ! empty($item['variant_id'])) {
                $vRow = DB::table('product_variants')->where('id', $item['variant_id'])->first();
                if ($vRow) {
                    $vp = json_decode($vRow->payload, true) ?: [];
                    $pId = $vp['fields']['productId'] ?? '';
                }
            }

            $lineItems[] = [
                'purchaseItemId' => 'LINE_' . Str::random(16) . "_{$index}",
                'productId' => $pId,
                'variantId' => $item['variant_id'],
                'productName' => $item['product_name'] ?? 'Product',
                'variantName' => $item['variant_name'] ?? 'Default',
                'quantity' => $qty,
                'baseQuantity' => $qty,
                'unitId' => $item['unit_id'] ?? 'SYS_UNIT_PCS',
                'rate' => $rate,
                'gstRateBps' => $gstRateBps,
                'taxableAmount' => $taxableAmount,
                'taxAmount' => $taxAmount,
                'sellingPrice' => (int) ($item['selling_price'] ?? 0),
                'mrp' => (int) ($item['mrp'] ?? 0),
                'barcode' => $item['barcode'] ?? null,
                'barcodeSnapshot' => $item['barcode'] ?? null,
                'storageType' => 'OUTLET',
                'storageLocationId' => $outletId,
            ];
        }

        $taxableTotal = max(0, $subtotal - $discountAmount);
        $cgst = 0;
        $sgst = 0;
        $igst = 0;

        if ($taxType === 'INTRA_STATE') {
            $cgst = (int) floor($totalTax / 2);
            $sgst = $totalTax - $cgst;
        } else {
            $igst = $totalTax;
        }

        $grandTotal = $taxableTotal + $totalTax + $chargesAmount;
        $outstandingAmount = max(0, $grandTotal - $paidAmount);
        $paymentStatus = $paidAmount >= $grandTotal ? 'PAID' : ($paidAmount > 0 ? 'PARTIAL' : 'UNPAID');

        $billCount = DB::table('purchase_bills')->where('business_id', $businessId)->count() + 1;
        $purchaseNumber = 'PUR-' . str_pad((string) $billCount, 6, '0', STR_PAD_LEFT);
        $purchaseBillId = 'PB-' . strtoupper(Str::random(32));
        $supplierBusinessId = "{$supplierId}_{$businessId}";

        $billFields = [
            'purchaseBillId' => $purchaseBillId,
            'purchaseNumber' => $purchaseNumber,
            'businessId' => $businessId,
            'tenantId' => $userId,
            'outletId' => $outletId,
            'supplierId' => $supplierId,
            'supplierBusinessId' => $supplierBusinessId,
            'supplierName' => $supplierName,
            'supplierInvoiceNumber' => $supplierInvoiceNumber,
            'invoiceDate' => $invoiceDate,
            'purchaseDate' => $purchaseDate,
            'dueDate' => $dueDate,
            'purchaseType' => 'DIRECT',
            'paymentStatus' => $paymentStatus,
            'subtotal' => $subtotal,
            'taxableAmount' => $taxableTotal,
            'chargesAmount' => $chargesAmount,
            'discountAmount' => $discountAmount,
            'cgstAmount' => $cgst,
            'sgstAmount' => $sgst,
            'igstAmount' => $igst,
            'roundOff' => 0,
            'grandTotal' => $grandTotal,
            'paidAmount' => $paidAmount,
            'outstandingAmount' => $outstandingAmount,
            'taxType' => $taxType,
            'status' => $status,
            'items' => $lineItems,
            'createdAt' => $now,
            'createdBy' => $userId,
            'updatedAt' => $now,
            'updatedBy' => $userId,
        ];

        DB::transaction(function () use (
            $purchaseBillId,
            $businessId,
            $outletId,
            $supplierId,
            $supplierBusinessId,
            $billFields,
            $lineItems,
            $status,
            $grandTotal,
            $paidAmount,
            $outstandingAmount,
            $paymentMethod,
            $now,
            $dbNow,
            $userId
        ) {
            // 1. Insert into purchase_bills
            DB::table('purchase_bills')->insert([
                'id' => $purchaseBillId,
                'document_path' => "purchase_bills/{$purchaseBillId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => $status,
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $billFields]),
            ]);

            // 2. Insert line items
            foreach ($lineItems as $item) {
                $itemId = $item['purchaseItemId'];
                DB::table('purchase_bill_items')->insert([
                    'id' => $itemId,
                    'document_path' => "purchase_bill_items/{$itemId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => $status,
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => array_merge($item, [
                        'purchaseBillId' => $purchaseBillId,
                        'supplierId' => $supplierId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'tenantId' => $userId,
                    ])]),
                ]);
            }

            // 3. If APPROVED, post to supplier balances & ledger
            if ($status === 'APPROVED') {
                // Update supplier balance
                $balRow = DB::table('supplier_balances')
                    ->where('business_id', $businessId)
                    ->where(function ($q) use ($supplierId, $supplierBusinessId) {
                        $q->where('id', $supplierBusinessId)
                            ->orWhere('payload->fields->supplierId', $supplierId);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($balRow) {
                    $bPayload = json_decode($balRow->payload, true) ?: [];
                    $bFields = $bPayload['fields'] ?? [];
                    $bFields['totalPayable'] = (int) ($bFields['totalPayable'] ?? 0) + $outstandingAmount;
                    $bFields['updatedAt'] = $now;
                    $bPayload['fields'] = $bFields;

                    DB::table('supplier_balances')->where('id', $balRow->id)->update([
                        'updated_at' => $dbNow,
                        'payload' => json_encode($bPayload),
                    ]);
                }

                // Add bill entry to supplier_ledger_entries (credit adds to payable)
                $ledgerBillId = "PURCHASE_BILL_{$purchaseBillId}_1";
                DB::table('supplier_ledger_entries')->insert([
                    'id' => $ledgerBillId,
                    'document_path' => "supplier_ledger_entries/{$ledgerBillId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'POSTED',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => [
                        'ledgerEntryId' => $ledgerBillId,
                        'tenantId' => $userId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'supplierBusinessId' => $supplierBusinessId,
                        'supplierId' => $supplierId,
                        'entryType' => 'PURCHASE_BILL',
                        'referenceType' => 'PURCHASE_BILL',
                        'referenceId' => $purchaseBillId,
                        'debit' => 0,
                        'credit' => $grandTotal,
                        'transactionDate' => $now,
                        'createdAt' => $now,
                        'createdBy' => $userId,
                        'isReversal' => false,
                    ]]),
                ]);

                // If paid amount, record payment and payment ledger entry
                if ($paidAmount > 0) {
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
                        'payload' => json_encode(['fields' => [
                            'paymentId' => $payId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'supplierId' => $supplierId,
                            'purchaseBillId' => $purchaseBillId,
                            'amount' => $paidAmount,
                            'paymentMethod' => $paymentMethod,
                            'createdAt' => $now,
                            'createdBy' => $userId,
                        ]]),
                    ]);

                    $ledgerPayId = "SUPPLIER_PAYMENT_{$payId}";
                    DB::table('supplier_ledger_entries')->insert([
                        'id' => $ledgerPayId,
                        'document_path' => "supplier_ledger_entries/{$ledgerPayId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'status' => 'POSTED',
                        'created_at' => $dbNow,
                        'updated_at' => $dbNow,
                        'is_deleted' => 0,
                        'payload' => json_encode(['fields' => [
                            'ledgerEntryId' => $ledgerPayId,
                            'tenantId' => $userId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'supplierBusinessId' => $supplierBusinessId,
                            'supplierId' => $supplierId,
                            'entryType' => 'PAYMENT',
                            'referenceType' => 'PAYMENT',
                            'referenceId' => $payId,
                            'debit' => $paidAmount,
                            'credit' => 0,
                            'transactionDate' => $now,
                            'createdAt' => $now,
                            'createdBy' => $userId,
                            'isReversal' => false,
                        ]]),
                    ]);
                }

                // Create auto Goods Receipt
                $grnId = "AUTO_GRN_{$purchaseBillId}";
                DB::table('goods_receipts')->insert([
                    'id' => $grnId,
                    'document_path' => "goods_receipts/{$grnId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'APPROVED',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => [
                        'goodsReceiptId' => $grnId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'supplierId' => $supplierId,
                        'purchaseBillId' => $purchaseBillId,
                        'source' => 'AUTO_FROM_DIRECT_BILL',
                        'status' => 'APPROVED',
                        'receivedAt' => $now,
                        'createdAt' => $now,
                        'createdBy' => $userId,
                    ]]),
                ]);

                // Update stock balances and outlet placements
                foreach ($lineItems as $item) {
                    $vId = $item['variantId'] ?? $item['variant_id'] ?? '';
                    $pId = $item['productId'] ?? $item['product_id'] ?? '';
                    $qty = (int) ($item['quantity'] ?? 0);
                    $rate = (int) ($item['rate'] ?? 0);
                    $lineCost = (int) ($item['taxableAmount'] ?? 0) + (int) ($item['taxAmount'] ?? 0);
                    if ($lineCost <= 0 && $qty > 0 && $rate > 0) {
                        $lineCost = (int) round(($qty * $rate) / 1000);
                    }
                    if (! $vId || $qty <= 0) continue;

                    $stockBalId = "{$userId}_{$outletId}_{$vId}";
                    $stockRow = DB::table('stock_balances')
                        ->where('business_id', $businessId)
                        ->where('outlet_id', $outletId)
                        ->where(function ($q) use ($stockBalId, $vId) {
                            $q->where('id', $stockBalId)
                              ->orWhere('payload->fields->variantId', $vId);
                        })
                        ->lockForUpdate()
                        ->first();

                    if ($stockRow) {
                        $sbPayload = json_decode($stockRow->payload, true) ?: [];
                        $sbFields = $sbPayload['fields'] ?? [];
                        $currStock = (int) ($sbFields['quantityOnHand'] ?? $sbFields['currentStock'] ?? 0);
                        $newStock = $currStock + $qty;
                        $oldVal = (int) ($sbFields['totalValue'] ?? 0);
                        $newVal = $oldVal + $lineCost;
                        $sbFields['quantityOnHand'] = $newStock;
                        $sbFields['currentStock'] = $newStock;
                        $sbFields['totalValue'] = $newVal;
                        $sbFields['averageCost'] = $newStock > 0 ? (int) round(($newVal * 1000) / $newStock) : 0;
                        $sbFields['avgCostPaise'] = $newStock > 0 ? (int) round($newVal / max(1, $newStock / 1000)) : 0;
                        $sbFields['lastPurchasePricePaise'] = $rate;
                        $sbFields['updatedAt'] = $now;
                        $sbPayload['fields'] = $sbFields;

                        DB::table('stock_balances')->where('id', $stockRow->id)->update([
                            'updated_at' => $dbNow,
                            'payload' => json_encode($sbPayload),
                        ]);
                    } else {
                        $sbFields = [
                            'stockBalanceId' => $stockBalId,
                            'tenantId' => $userId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'variantId' => $vId,
                            'productId' => $pId,
                            'quantityOnHand' => $qty,
                            'currentStock' => $qty,
                            'totalValue' => $lineCost,
                            'averageCost' => $qty > 0 ? (int) round(($lineCost * 1000) / $qty) : 0,
                            'avgCostPaise' => $qty > 0 ? (int) round($lineCost / max(1, $qty / 1000)) : 0,
                            'lastPurchasePricePaise' => $rate,
                            'createdAt' => $now,
                            'updatedAt' => $now,
                        ];
                        DB::table('stock_balances')->insert([
                            'id' => $stockBalId,
                            'document_path' => "stock_balances/{$stockBalId}",
                            'business_id' => $businessId,
                            'outlet_id' => $outletId,
                            'status' => 'ACTIVE',
                            'created_at' => $dbNow,
                            'updated_at' => $dbNow,
                            'is_deleted' => 0,
                            'payload' => json_encode(['fields' => $sbFields]),
                        ]);
                    }

                    // Auto-reflect purchase selling price, MRP, and rate to variant and product
                    if (!empty($vId)) {
                        $vRow = DB::table('product_variants')
                            ->where('id', $vId)
                            ->orWhere('payload->fields->variantId', $vId)
                            ->first();
                        if ($vRow) {
                            $vPayload = json_decode($vRow->payload, true) ?: [];
                            $vFields = $vPayload['fields'] ?? [];
                            if (!empty($item['sellingPrice']) && (int) $item['sellingPrice'] > 0) {
                                $vFields['sellingPrice'] = (int) $item['sellingPrice'];
                            }
                            if (!empty($item['mrp']) && (int) $item['mrp'] > 0) {
                                $vFields['mrp'] = (int) $item['mrp'];
                            }
                            if ($rate > 0) {
                                $vFields['purchaseRate'] = $rate;
                            }
                            if (!empty($item['barcode'])) {
                                $vFields['barcode'] = $item['barcode'];
                            }
                            $vFields['updatedAt'] = $now;
                            $vPayload['fields'] = $vFields;
                            DB::table('product_variants')->where('id', $vRow->id)->update([
                                'updated_at' => $dbNow,
                                'payload' => json_encode($vPayload),
                            ]);
                        }
                    }

                    if (!empty($pId)) {
                        $pRow = DB::table('products')
                            ->where('id', $pId)
                            ->orWhere('payload->fields->productId', $pId)
                            ->first();
                        if ($pRow) {
                            $pPayload = json_decode($pRow->payload, true) ?: [];
                            $pFields = $pPayload['fields'] ?? [];
                            if (!empty($item['sellingPrice']) && (int) $item['sellingPrice'] > 0) {
                                $pFields['sellingPrice'] = (int) $item['sellingPrice'];
                            }
                            if (!empty($item['mrp']) && (int) $item['mrp'] > 0) {
                                $pFields['mrp'] = (int) $item['mrp'];
                            }
                            if ($rate > 0) {
                                $pFields['purchaseRate'] = $rate;
                            }
                            $pFields['updatedAt'] = $now;
                            $pPayload['fields'] = $pFields;
                            DB::table('products')->where('id', $pRow->id)->update([
                                'updated_at' => $dbNow,
                                'payload' => json_encode($pPayload),
                            ]);
                        }
                    }

                    // Ensure placement in outlet_products
                    if ($pId) {
                        $outletProd = DB::table('outlet_products')
                            ->where('business_id', $businessId)
                            ->where('outlet_id', $outletId)
                            ->where('payload->fields->productId', $pId)
                            ->first();

                        if ($outletProd) {
                            $opPayload = json_decode($outletProd->payload, true) ?: [];
                            $opFields = $opPayload['fields'] ?? [];
                            $vIds = $opFields['variantIds'] ?? [];
                            if (! in_array($vId, $vIds)) {
                                $vIds[] = $vId;
                                $opFields['variantIds'] = $vIds;
                                $opFields['updatedAt'] = $now;
                                $opPayload['fields'] = $opFields;
                                DB::table('outlet_products')->where('id', $outletProd->id)->update([
                                    'updated_at' => $dbNow,
                                    'payload' => json_encode($opPayload),
                                ]);
                            }
                        } else {
                            $opId = "OP-{$pId}-{$outletId}";
                            $opFields = [
                                'outletProductId' => $opId,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'productId' => $pId,
                                'variantIds' => [$vId],
                                'status' => 'ACTIVE',
                                'isActive' => true,
                                'createdAt' => $now,
                                'updatedAt' => $now,
                            ];
                            DB::table('outlet_products')->insert([
                                'id' => $opId,
                                'document_path' => "outlet_products/{$opId}",
                                'business_id' => $businessId,
                                'outlet_id' => $outletId,
                                'status' => 'ACTIVE',
                                'created_at' => $dbNow,
                                'updated_at' => $dbNow,
                                'is_deleted' => 0,
                                'payload' => json_encode(['fields' => $opFields]),
                            ]);
                        }
                    }
                }
            }
        });

        return ApiResponse::success([
            'purchaseBill' => $billFields,
        ], 'Purchase bill created successfully.', 201);
    }

    /**
     * Approve a draft purchase bill.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $row = DB::table('purchase_bills')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Purchase bill not found.', 404);
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        $currentStatus = strtoupper($fields['status'] ?? $row->status ?? 'DRAFT');

        if ($currentStatus === 'APPROVED') {
            return ApiResponse::success([
                'purchaseBill' => $fields,
            ], 'Purchase bill is already approved.');
        }

        $businessId = $row->business_id;
        $outletId = $fields['outletId'] ?? $row->outlet_id;
        $supplierId = $fields['supplierId'] ?? '';
        $supplierBusinessId = $fields['supplierBusinessId'] ?? "{$supplierId}_{$businessId}";
        $grandTotal = (int) ($fields['grandTotal'] ?? 0);
        $reqPaid = $request->input('paid_amount') ?? $request->input('paidAmount');
        if ($reqPaid !== null) {
            $paidAmount = min($grandTotal, max(0, (int) $reqPaid));
        } else {
            $paidAmount = min($grandTotal, (int) ($fields['paidAmount'] ?? 0));
        }
        $outstandingAmount = max(0, $grandTotal - $paidAmount);
        $paymentMethod = $request->input('payment_method') ?? $request->input('paymentMethod') ?? ($fields['paymentMethod'] ?? 'CASH');
        $paymentRef = $request->input('reference') ?? $request->input('payment_reference') ?? '';
        $userId = $request->user()?->id ?? 'SYSTEM';
        $now = now()->toIso8601String();
        $dbNow = now();

        $lineItems = $fields['items'] ?? [];

        DB::transaction(function () use (
            $id,
            $businessId,
            $outletId,
            $supplierId,
            $supplierBusinessId,
            &$fields,
            $lineItems,
            $grandTotal,
            $paidAmount,
            $outstandingAmount,
            $paymentMethod,
            $paymentRef,
            $now,
            $dbNow,
            $userId
        ) {
            $fields['status'] = 'APPROVED';
            $fields['paidAmount'] = $paidAmount;
            $fields['outstandingAmount'] = $outstandingAmount;
            $fields['paymentStatus'] = $paidAmount >= $grandTotal ? 'PAID' : ($paidAmount > 0 ? 'PARTIAL' : 'UNPAID');
            $fields['paymentMethod'] = $paymentMethod;
            $fields['updatedAt'] = $now;
            $fields['updatedBy'] = $userId;

            DB::table('purchase_bills')->where('id', $id)->update([
                'status' => 'APPROVED',
                'updated_at' => $dbNow,
                'payload' => json_encode(['fields' => $fields]),
            ]);

            DB::table('purchase_bill_items')
                ->where('business_id', $businessId)
                ->where('payload->fields->purchaseBillId', $id)
                ->update([
                    'status' => 'APPROVED',
                    'updated_at' => $dbNow,
                ]);

            // Update supplier balance
            $balRow = DB::table('supplier_balances')
                ->where('business_id', $businessId)
                ->where(function ($q) use ($supplierId, $supplierBusinessId) {
                    $q->where('id', $supplierBusinessId)
                        ->orWhere('payload->fields->supplierId', $supplierId);
                })
                ->lockForUpdate()
                ->first();

            if ($balRow) {
                $bPayload = json_decode($balRow->payload, true) ?: [];
                $bFields = $bPayload['fields'] ?? [];
                $bFields['totalPayable'] = (int) ($bFields['totalPayable'] ?? 0) + $outstandingAmount;
                $bFields['updatedAt'] = $now;
                $bPayload['fields'] = $bFields;

                DB::table('supplier_balances')->where('id', $balRow->id)->update([
                    'updated_at' => $dbNow,
                    'payload' => json_encode($bPayload),
                ]);
            }

            // Ledger entry
            $ledgerBillId = "PURCHASE_BILL_{$id}_1";
            DB::table('supplier_ledger_entries')->insert([
                'id' => $ledgerBillId,
                'document_path' => "supplier_ledger_entries/{$ledgerBillId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'POSTED',
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => [
                    'ledgerEntryId' => $ledgerBillId,
                    'tenantId' => $userId,
                    'businessId' => $businessId,
                    'outletId' => $outletId,
                    'supplierBusinessId' => $supplierBusinessId,
                    'supplierId' => $supplierId,
                    'entryType' => 'PURCHASE_BILL',
                    'referenceType' => 'PURCHASE_BILL',
                    'referenceId' => $id,
                    'debit' => 0,
                    'credit' => $grandTotal,
                    'transactionDate' => $now,
                    'createdAt' => $now,
                    'createdBy' => $userId,
                    'isReversal' => false,
                ]]),
            ]);

            // GRN
            $grnId = "AUTO_GRN_{$id}";
            DB::table('goods_receipts')->insert([
                'id' => $grnId,
                'document_path' => "goods_receipts/{$grnId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'APPROVED',
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => [
                    'goodsReceiptId' => $grnId,
                    'businessId' => $businessId,
                    'outletId' => $outletId,
                    'supplierId' => $supplierId,
                    'purchaseBillId' => $id,
                    'source' => 'AUTO_FROM_DIRECT_BILL',
                    'status' => 'APPROVED',
                    'receivedAt' => $now,
                    'createdAt' => $now,
                    'createdBy' => $userId,
                ]]),
            ]);

            // If paid amount, record payment and payment ledger entry
            if ($paidAmount > 0) {
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
                    'payload' => json_encode(['fields' => [
                        'paymentId' => $payId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'supplierId' => $supplierId,
                        'purchaseBillId' => $id,
                        'amount' => $paidAmount,
                        'paymentMethod' => $paymentMethod,
                        'referenceNumber' => $paymentRef,
                        'createdAt' => $now,
                        'createdBy' => $userId,
                    ]]),
                ]);

                $ledgerPayId = "SUPPLIER_PAYMENT_{$payId}";
                DB::table('supplier_ledger_entries')->insert([
                    'id' => $ledgerPayId,
                    'document_path' => "supplier_ledger_entries/{$ledgerPayId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'POSTED',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => [
                        'ledgerEntryId' => $ledgerPayId,
                        'tenantId' => $userId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'supplierBusinessId' => $supplierBusinessId,
                        'supplierId' => $supplierId,
                        'entryType' => 'PAYMENT',
                        'referenceType' => 'PURCHASE_PAYMENT',
                        'referenceId' => $payId,
                        'purchaseBillId' => $id,
                        'debit' => $paidAmount,
                        'credit' => 0,
                        'transactionDate' => $now,
                        'createdAt' => $now,
                        'createdBy' => $userId,
                        'isReversal' => false,
                    ]]),
                ]);
            }

            // Stock balances
            foreach ($lineItems as $item) {
                $vId = $item['variantId'] ?? $item['variant_id'] ?? '';
                $pId = $item['productId'] ?? $item['product_id'] ?? '';
                $qty = (int) ($item['quantity'] ?? 0);
                $rate = (int) ($item['rate'] ?? 0);
                $lineCost = (int) ($item['taxableAmount'] ?? 0) + (int) ($item['taxAmount'] ?? 0);
                if ($lineCost <= 0 && $qty > 0 && $rate > 0) {
                    $lineCost = (int) round(($qty * $rate) / 1000);
                }
                if (! $vId || $qty <= 0) continue;

                $stockBalId = "{$userId}_{$outletId}_{$vId}";
                $stockRow = DB::table('stock_balances')
                    ->where('business_id', $businessId)
                    ->where('outlet_id', $outletId)
                    ->where(function ($q) use ($stockBalId, $vId) {
                        $q->where('id', $stockBalId)
                          ->orWhere('payload->fields->variantId', $vId);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($stockRow) {
                    $sbPayload = json_decode($stockRow->payload, true) ?: [];
                    $sbFields = $sbPayload['fields'] ?? [];
                    $currStock = (int) ($sbFields['quantityOnHand'] ?? $sbFields['currentStock'] ?? 0);
                    $newStock = $currStock + $qty;
                    $oldVal = (int) ($sbFields['totalValue'] ?? 0);
                    $newVal = $oldVal + $lineCost;
                    $sbFields['quantityOnHand'] = $newStock;
                    $sbFields['currentStock'] = $newStock;
                    $sbFields['totalValue'] = $newVal;
                    $sbFields['averageCost'] = $newStock > 0 ? (int) round(($newVal * 1000) / $newStock) : 0;
                    $sbFields['avgCostPaise'] = $newStock > 0 ? (int) round($newVal / max(1, $newStock / 1000)) : 0;
                    $sbFields['lastPurchasePricePaise'] = $rate;
                    $sbFields['updatedAt'] = $now;
                    $sbPayload['fields'] = $sbFields;

                    DB::table('stock_balances')->where('id', $stockRow->id)->update([
                        'updated_at' => $dbNow,
                        'payload' => json_encode($sbPayload),
                    ]);
                } else {
                    $sbFields = [
                        'stockBalanceId' => $stockBalId,
                        'tenantId' => $userId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'variantId' => $vId,
                        'productId' => $pId,
                        'quantityOnHand' => $qty,
                        'currentStock' => $qty,
                        'totalValue' => $lineCost,
                        'averageCost' => $qty > 0 ? (int) round(($lineCost * 1000) / $qty) : 0,
                        'avgCostPaise' => $qty > 0 ? (int) round($lineCost / max(1, $qty / 1000)) : 0,
                        'lastPurchasePricePaise' => $rate,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ];
                    DB::table('stock_balances')->insert([
                        'id' => $stockBalId,
                        'document_path' => "stock_balances/{$stockBalId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'status' => 'ACTIVE',
                        'created_at' => $dbNow,
                        'updated_at' => $dbNow,
                        'is_deleted' => 0,
                        'payload' => json_encode(['fields' => $sbFields]),
                    ]);
                }

                // Auto-reflect purchase selling price, MRP, and rate to variant and product
                if (!empty($vId)) {
                    $vRow = DB::table('product_variants')
                        ->where('id', $vId)
                        ->orWhere('payload->fields->variantId', $vId)
                        ->first();
                    if ($vRow) {
                        $vPayload = json_decode($vRow->payload, true) ?: [];
                        $vFields = $vPayload['fields'] ?? [];
                        if (!empty($item['sellingPrice']) && (int) $item['sellingPrice'] > 0) {
                            $vFields['sellingPrice'] = (int) $item['sellingPrice'];
                        }
                        if (!empty($item['mrp']) && (int) $item['mrp'] > 0) {
                            $vFields['mrp'] = (int) $item['mrp'];
                        }
                        if ($rate > 0) {
                            $vFields['purchaseRate'] = $rate;
                        }
                        if (!empty($item['barcode'])) {
                            $vFields['barcode'] = $item['barcode'];
                        }
                        $vFields['updatedAt'] = $now;
                        $vPayload['fields'] = $vFields;
                        DB::table('product_variants')->where('id', $vRow->id)->update([
                            'updated_at' => $dbNow,
                            'payload' => json_encode($vPayload),
                        ]);
                    }
                }

                if (!empty($pId)) {
                    $pRow = DB::table('products')
                        ->where('id', $pId)
                        ->orWhere('payload->fields->productId', $pId)
                        ->first();
                    if ($pRow) {
                        $pPayload = json_decode($pRow->payload, true) ?: [];
                        $pFields = $pPayload['fields'] ?? [];
                        if (!empty($item['sellingPrice']) && (int) $item['sellingPrice'] > 0) {
                            $pFields['sellingPrice'] = (int) $item['sellingPrice'];
                        }
                        if (!empty($item['mrp']) && (int) $item['mrp'] > 0) {
                            $pFields['mrp'] = (int) $item['mrp'];
                        }
                        if ($rate > 0) {
                            $pFields['purchaseRate'] = $rate;
                        }
                        $pFields['updatedAt'] = $now;
                        $pPayload['fields'] = $pFields;
                        DB::table('products')->where('id', $pRow->id)->update([
                            'updated_at' => $dbNow,
                            'payload' => json_encode($pPayload),
                        ]);
                    }
                }
            }
        });

        return ApiResponse::success([
            'purchaseBill' => $fields,
        ], 'Purchase bill approved successfully.');
    }
}
