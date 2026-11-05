<?php

namespace App\Services;

use App\Models\HistoricalStockAdjustment;
use App\Models\MigrationBatch;
use App\Models\MigrationError;
use App\Models\MigrationLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class HistoricalDataService
{
    /**
     * Record a historical sales invoice.
     */
    public function recordHistoricalSale(array $data, User $user): array
    {
        $businessId = $data['business_id'];
        $outletId = $data['outlet_id'] ?? null;
        $batchId = $data['batch_id'] ?? null;
        $originalInvoiceNumber = trim((string) $data['original_invoice_number']);
        $originalInvoiceDate = Carbon::parse($data['original_invoice_date'])->toDateString();
        $customerId = $data['customer_id'] ?? null;
        $customerName = $data['customer_name'] ?? 'Walk-in Customer';
        $customerPhone = ! empty($data['customer_phone']) ? preg_replace('/\D/', '', $data['customer_phone']) : null;
        $customerGstin = ! empty($data['customer_gstin']) ? strtoupper(trim($data['customer_gstin'])) : null;
        $customerStateCode = $data['customer_state_code'] ?? null;
        $notes = $data['notes'] ?? 'Historical Sales Record';
        $paymentMode = strtoupper($data['payment_mode'] ?? 'CASH');
        $paymentDate = ! empty($data['payment_date']) ? Carbon::parse($data['payment_date'])->toDateString() : $originalInvoiceDate;
        $impactCurrentStock = (bool) ($data['impact_current_stock'] ?? false);

        // 1. Duplicate invoice prevention
        $existingInvoice = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->where(function ($q) use ($originalInvoiceNumber) {
                $q->where('original_invoice_number', $originalInvoiceNumber)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.originalInvoiceNumber')) = ?", [$originalInvoiceNumber])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.invoiceNumber')) = ?", [$originalInvoiceNumber]);
            })
            ->first();

        if ($existingInvoice) {
            throw new InvalidArgumentException("Duplicate invoice: Invoice number '{$originalInvoiceNumber}' already exists for this business.");
        }

        $nowStr = now()->toIso8601String();
        $dbNow = now();
        $invoiceId = 'HIST-INV-'.strtoupper(Str::random(24));

        // 2. Calculate lines
        $lines = $data['lines'] ?? $data['items'] ?? [];
        if (empty($lines)) {
            throw new InvalidArgumentException('Historical sales invoice must contain at least one line item.');
        }

        $calculatedLines = [];
        $grossTotal = 0;
        $discountTotal = 0;
        $taxableTotal = 0;
        $taxTotal = 0;

        foreach ($lines as $index => $item) {
            $qty = (float) $item['quantity'];
            if ($qty <= 0) {
                throw new InvalidArgumentException("Quantity for item {$index} must be greater than zero.");
            }
            // Support both paise and rupees
            $rawRate = (float) ($item['sale_rate'] ?? $item['rate'] ?? 0);
            if (isset($item['unit_price_paise'])) {
                $unitPricePaise = (int) $item['unit_price_paise'];
            } elseif (isset($item['rate_paise'])) {
                $unitPricePaise = (int) $item['rate_paise'];
            } elseif ($rawRate > 0 && $rawRate < 100000) {
                $unitPricePaise = (int) round($rawRate * 100);
            } else {
                $unitPricePaise = (int) round($rawRate);
            }
            $rawDiscount = (float) ($item['discount'] ?? $item['discount_paise'] ?? 0);
            $discountPaise = isset($item['discount_paise']) ? (int) $item['discount_paise'] : (int) round($rawDiscount * 100);

            $grossPaise = (int) round($qty * $unitPricePaise);
            $discountPaise = min($discountPaise, $grossPaise);
            $netPaise = $grossPaise - $discountPaise;

            $gstRateBps = (int) ($item['gst_percentage'] ?? $item['gst_rate_bps'] ?? 18);
            if ($gstRateBps < 100) {
                $gstRateBps = $gstRateBps * 100; // e.g. 18% -> 1800 bps
            }

            $priceIncludesTax = (bool) ($item['price_includes_tax'] ?? false);
            if ($priceIncludesTax) {
                $taxablePaise = (int) round(($netPaise * 10000) / (10000 + $gstRateBps));
                $taxPaise = $netPaise - $taxablePaise;
            } else {
                $taxablePaise = $netPaise;
                $taxPaise = (int) round(($taxablePaise * $gstRateBps) / 10000);
            }

            $grossTotal += $grossPaise;
            $discountTotal += $discountPaise;
            $taxableTotal += $taxablePaise;
            $taxTotal += $taxPaise;

            $pId = $item['product_id'] ?? 'SYS_PROD_HIST_'.Str::random(8);
            $vId = $item['variant_id'] ?? $pId;

            $calculatedLines[] = [
                'itemId' => "HIST_{$invoiceId}_{$index}",
                'productId' => $pId,
                'variantId' => $vId,
                'name' => $item['product_name'] ?? $item['name'] ?? 'Historical Product',
                'variantName' => $item['variant_name'] ?? 'Default',
                'quantity' => $qty,
                'quantityBase' => $qty,
                'unit' => $item['unit'] ?? $item['unit_id'] ?? 'SYS_UNIT_PCS',
                'unitPricePaise' => $unitPricePaise,
                'discountPaise' => $discountPaise,
                'taxablePaise' => $taxablePaise,
                'taxPaise' => $taxPaise,
                'lineTotalPaise' => $taxablePaise + $taxPaise,
                'gstRateBps' => $gstRateBps,
                'hsn' => $item['hsn_code'] ?? '',
            ];
        }

        $grandTotalPaise = (int) ($data['grand_total'] ?? ($taxableTotal + $taxTotal));
        if ($grandTotalPaise < 1000 && ! isset($data['grand_total_paise']) && $taxableTotal + $taxTotal >= 1000) {
            $grandTotalPaise = (int) round($grandTotalPaise * 100);
        }

        $rawPaid = (float) ($data['amount_received'] ?? $data['paid_amount'] ?? $data['paid_paise'] ?? 0);
        $paidPaise = $rawPaid > 0 && $rawPaid < 1000 && ! isset($data['paid_paise']) && $grandTotalPaise >= 1000 ? (int) round($rawPaid * 100) : (int) round($rawPaid);
        $duePaise = max(0, $grandTotalPaise - $paidPaise);

        $status = $duePaise == 0 ? 'PAID' : ($paidPaise > 0 ? 'PARTIALLY_PAID' : 'PENDING');

        return DB::transaction(function () use (
            $invoiceId,
            $businessId,
            $outletId,
            $batchId,
            $originalInvoiceNumber,
            $originalInvoiceDate,
            $customerId,
            $customerName,
            $customerPhone,
            $customerGstin,
            $customerStateCode,
            $grossTotal,
            $discountTotal,
            $taxableTotal,
            $taxTotal,
            $grandTotalPaise,
            $paidPaise,
            $duePaise,
            $status,
            $paymentMode,
            $paymentDate,
            $notes,
            $calculatedLines,
            $impactCurrentStock,
            $user,
            $nowStr,
            $dbNow
        ) {
            $invoiceFields = [
                'invoiceId' => $invoiceId,
                'invoiceNumber' => $originalInvoiceNumber, // Keep original invoice number
                'originalInvoiceNumber' => $originalInvoiceNumber,
                'originalInvoiceDate' => $originalInvoiceDate,
                'isHistorical' => true,
                'entrySource' => 'historical',
                'migrationBatchId' => $batchId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'customerId' => $customerId,
                'customerName' => $customerName,
                'customerPhone' => $customerPhone,
                'customerGstin' => $customerGstin,
                'customerStateCode' => $customerStateCode,
                'subtotal' => $grossTotal,
                'subtotalPaise' => $grossTotal,
                'discount' => $discountTotal,
                'discountPaise' => $discountTotal,
                'taxableAmount' => $taxableTotal,
                'taxablePaise' => $taxableTotal,
                'gstAmount' => $taxTotal,
                'totalAmount' => $grandTotalPaise,
                'grandTotal' => $grandTotalPaise,
                'grandTotalPaise' => $grandTotalPaise,
                'dueAmount' => $duePaise,
                'duePaise' => $duePaise,
                'paidPaise' => $paidPaise,
                'paidAmount' => $paidPaise,
                'paymentMode' => $paymentMode,
                'paymentStatus' => $status,
                'status' => $status,
                'notes' => $notes,
                'items' => $calculatedLines,
                'createdAt' => Carbon::parse($originalInvoiceDate)->toIso8601String(),
                'enteredAt' => $nowStr,
                'createdByUid' => $user->id,
            ];

            // 1. Insert into invoices table
            DB::table('invoices')->insert([
                'id' => $invoiceId,
                'document_path' => "invoices/{$invoiceId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'invoice_number' => $originalInvoiceNumber,
                'customer_id' => $customerId,
                'total_amount' => $grandTotalPaise >= 1000 ? ($grandTotalPaise / 100.0) : $grandTotalPaise,
                'status' => $status,
                'entry_source' => 'historical',
                'is_historical' => 1,
                'original_invoice_number' => $originalInvoiceNumber,
                'original_invoice_date' => $originalInvoiceDate,
                'migration_batch_id' => $batchId,
                'entered_at' => $dbNow,
                'created_at' => Carbon::parse($originalInvoiceDate),
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $invoiceFields]),
            ]);

            // 2. Insert invoice line items
            foreach ($calculatedLines as $line) {
                DB::table('invoice_items')->insert([
                    'id' => $line['itemId'],
                    'document_path' => "invoice_items/{$line['itemId']}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'invoice_id' => $invoiceId,
                    'product_id' => $line['productId'],
                    'quantity' => $line['quantity'],
                    'returned_qty' => 0,
                    'status' => 'ACTIVE',
                    'created_at' => Carbon::parse($originalInvoiceDate),
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => array_merge($line, [
                        'invoiceId' => $invoiceId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'isHistorical' => true,
                    ])]),
                ]);
            }

            // 3. Customer balance and ledger entries
            if ($customerId) {
                // Lock and update customer balance
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
                    $bFields['duePaise'] = max(0, $curDue + $duePaise);
                    $bFields['updatedAt'] = $nowStr;
                    $bPayload['fields'] = $bFields;

                    DB::table('customer_balances')->where('id', $balRow->id)->update([
                        'due_paise' => $bFields['duePaise'],
                        'updated_at' => $dbNow,
                        'payload' => json_encode($bPayload),
                    ]);
                }

                // Post Invoice Debit for the full invoice amount
                $ledgerId = 'LED-'.strtoupper(Str::random(24));
                DB::table('customer_ledger_entries')->insert([
                    'id' => $ledgerId,
                    'document_path' => "customer_ledger_entries/{$ledgerId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'POSTED',
                    'entry_source' => 'historical',
                    'is_historical' => 1,
                    'migration_batch_id' => $batchId,
                    'original_transaction_date' => $originalInvoiceDate,
                    'created_at' => Carbon::parse($originalInvoiceDate),
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        'fields' => [
                            'transactionId' => $ledgerId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'customerId' => $customerId,
                            'invoiceId' => $invoiceId,
                            'originalInvoiceNumber' => $originalInvoiceNumber,
                            'transactionType' => 'SALES_INVOICE',
                            'amountPaise' => $grandTotalPaise,
                            'debitPaise' => $grandTotalPaise,
                            'creditPaise' => 0,
                            'createdAt' => Carbon::parse($originalInvoiceDate)->toIso8601String(),
                            'transactionDate' => $originalInvoiceDate,
                            'isHistorical' => true,
                            'entrySource' => 'historical',
                            'migrationBatchId' => $batchId,
                            'status' => 'POSTED',
                            'immutable' => true,
                        ],
                    ]),
                ]);

                // If paidNow > 0, post PAYMENT (-Credit)
                if ($paidPaise > 0) {
                    $ledgerPayId = 'LED-'.strtoupper(Str::random(24));
                    DB::table('customer_ledger_entries')->insert([
                        'id' => $ledgerPayId,
                        'document_path' => "customer_ledger_entries/{$ledgerPayId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'status' => 'POSTED',
                        'entry_source' => 'historical',
                        'is_historical' => 1,
                        'migration_batch_id' => $batchId,
                        'original_transaction_date' => $paymentDate,
                        'created_at' => Carbon::parse($paymentDate),
                        'updated_at' => $dbNow,
                        'is_deleted' => 0,
                        'payload' => json_encode([
                            'fields' => [
                                'transactionId' => $ledgerPayId,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'customerId' => $customerId,
                                'invoiceId' => $invoiceId,
                                'originalInvoiceNumber' => $originalInvoiceNumber,
                                'transactionType' => 'PAYMENT',
                                'amountPaise' => $paidPaise,
                                'debitPaise' => 0,
                                'creditPaise' => $paidPaise,
                                'createdAt' => Carbon::parse($paymentDate)->toIso8601String(),
                                'transactionDate' => $paymentDate,
                                'paymentMode' => $paymentMode,
                                'isHistorical' => true,
                                'entrySource' => 'historical',
                                'migrationBatchId' => $batchId,
                                'status' => 'POSTED',
                                'immutable' => true,
                            ],
                        ]),
                    ]);
                }
            }

            // 4. Payment voucher
            if ($paidPaise > 0) {
                $payId = 'PAY-'.strtoupper(Str::random(24));
                DB::table('payments')->insert([
                    'id' => $payId,
                    'document_path' => "payments/{$payId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'COMPLETED',
                    'entry_source' => 'historical',
                    'is_historical' => 1,
                    'migration_batch_id' => $batchId,
                    'original_transaction_date' => $paymentDate,
                    'created_at' => Carbon::parse($paymentDate),
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        'fields' => [
                            'paymentId' => $payId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'customerId' => $customerId,
                            'invoiceId' => $invoiceId,
                            'amount' => $paidPaise,
                            'paymentMethod' => $paymentMode,
                            'isHistorical' => true,
                            'transactionDate' => $paymentDate,
                            'createdAt' => Carbon::parse($paymentDate)->toIso8601String(),
                            'createdBy' => $user->id,
                        ],
                    ]),
                ]);
            }

            // 5. Stock Impact (only if explicitly enabled to avoid double-counting opening stock)
            if ($impactCurrentStock && $outletId) {
                foreach ($calculatedLines as $line) {
                    $vId = $line['variantId'];
                    $pId = $line['productId'];
                    $qty = (float) $line['quantity'];
                    $milliQty = (int) round($qty * 1000);

                    $stockRow = DB::table('stock_balances')
                        ->where('business_id', $businessId)
                        ->where('outlet_id', $outletId)
                        ->where(function ($q) use ($vId, $pId) {
                            $q->where('id', 'LIKE', "%_{$vId}")
                                ->orWhere('payload->fields->variantId', $vId)
                                ->orWhere('payload->fields->productId', $pId);
                        })
                        ->lockForUpdate()
                        ->first();

                    if ($stockRow) {
                        $sbPayload = json_decode($stockRow->payload, true) ?: [];
                        $sbFields = $sbPayload['fields'] ?? [];
                        $currStock = (int) ($sbFields['quantityOnHand'] ?? $sbFields['currentStock'] ?? 0);
                        if ($currStock >= $milliQty || $currStock > 500) {
                            $newStock = max(0, $currStock - $milliQty);
                        } else {
                            $newStock = max(0, $currStock - (int) round($qty));
                        }

                        $sbFields['quantityOnHand'] = $newStock;
                        $sbFields['currentStock'] = $newStock;
                        $sbFields['updatedAt'] = $nowStr;
                        $sbPayload['fields'] = $sbFields;

                        DB::table('stock_balances')->where('id', $stockRow->id)->update([
                            'updated_at' => $dbNow,
                            'payload' => json_encode($sbPayload),
                        ]);

                        $balAfterUnits = $newStock >= 1000 ? ($newStock / 1000.0) : (float) $newStock;
                        $movementId = 'STM-'.strtoupper(Str::random(24));
                        DB::table('stock_movements')->insert([
                            'id' => $movementId,
                            'document_path' => "stock_movements/{$movementId}",
                            'business_id' => $businessId,
                            'outlet_id' => $outletId,
                            'product_id' => $pId,
                            'variant_id' => $vId,
                            'type' => 'sale',
                            'qty_change' => -$qty,
                            'balance_after' => $balAfterUnits,
                            'reference_type' => 'historical_invoice',
                            'reference_id' => $invoiceId,
                            'condition' => 'good',
                            'note' => "Historical Sale {$originalInvoiceNumber}",
                            'entry_source' => 'historical',
                            'is_historical' => 1,
                            'migration_batch_id' => $batchId,
                            'original_transaction_date' => $originalInvoiceDate,
                            'created_by' => $user->id,
                            'created_at' => Carbon::parse($originalInvoiceDate),
                            'updated_at' => $dbNow,
                            'payload' => json_encode(['fields' => [
                                'movementId' => $movementId,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'productId' => $pId,
                                'variantId' => $vId,
                                'type' => 'SALE',
                                'qtyBase' => -$milliQty,
                                'balanceAfter' => $newStock,
                                'isHistorical' => true,
                                'entrySource' => 'historical',
                            ]]),
                        ]);
                    }
                }
            }

            // Log action
            if ($batchId) {
                MigrationLog::create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batchId,
                    'business_id' => $businessId,
                    'user_id' => $user->id,
                    'action' => 'HISTORICAL_SALE_RECORDED',
                    'description' => "Recorded historical sale invoice {$originalInvoiceNumber} for {$grandTotalPaise} paise.",
                ]);
            }

            return [
                'invoiceId' => $invoiceId,
                'invoiceNumber' => $originalInvoiceNumber,
                'grandTotal' => $grandTotalPaise,
                'paidAmount' => $paidPaise,
                'dueAmount' => $duePaise,
                'status' => $status,
                'isHistorical' => true,
            ];
        });
    }

    /**
     * Record a historical purchase invoice with Section 6 current stock availability formula.
     */
    public function recordHistoricalPurchase(array $data, User $user): array
    {
        $businessId = $data['business_id'];
        $outletId = $data['outlet_id'] ?? null;
        $batchId = $data['batch_id'] ?? null;
        $supplierId = $data['supplier_id'];
        $supplierInvoiceNumber = trim((string) ($data['supplier_invoice_number'] ?? $data['original_invoice_number'] ?? ''));
        if (empty($supplierInvoiceNumber)) {
            throw new InvalidArgumentException('Original supplier invoice number is required.');
        }

        $invoiceDate = Carbon::parse($data['invoice_date'] ?? $data['purchase_date'] ?? $data['original_invoice_date'])->toDateString();
        $purchaseDate = Carbon::parse($data['purchase_date'] ?? $invoiceDate)->toDateString();
        $taxType = $data['tax_type'] ?? 'INTRA_STATE';
        $paymentMethod = strtoupper($data['payment_method'] ?? 'CASH');
        $status = strtoupper($data['status'] ?? 'APPROVED');

        // Check duplicate invoice for this supplier & business
        $existingPB = DB::table('purchase_bills')
            ->where('business_id', $businessId)
            ->where('supplier_id', $supplierId)
            ->where('is_deleted', 0)
            ->where(function ($q) use ($supplierInvoiceNumber) {
                $q->where('original_invoice_number', $supplierInvoiceNumber)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.supplierInvoiceNumber')) = ?", [$supplierInvoiceNumber]);
            })
            ->first();

        if ($existingPB) {
            throw new InvalidArgumentException("Duplicate purchase bill: Invoice '{$supplierInvoiceNumber}' already exists for this supplier.");
        }

        // Supplier check
        $supplierRow = DB::table('suppliers')->where('id', $supplierId)->first();
        if (! $supplierRow) {
            throw new InvalidArgumentException("Supplier '{$supplierId}' not found.");
        }
        $sPayload = json_decode($supplierRow->payload, true) ?: [];
        $supplierName = $sPayload['fields']['name'] ?? 'Supplier';

        $items = $data['items'] ?? $data['lines'] ?? [];
        if (empty($items)) {
            throw new InvalidArgumentException('Purchase invoice must contain at least one item.');
        }

        $nowStr = now()->toIso8601String();
        $dbNow = now();
        $purchaseBillId = 'HIST-PB-'.strtoupper(Str::random(24));
        $supplierBusinessId = "{$supplierId}_{$businessId}";

        $lineItems = [];
        $stockAdjustmentPlans = [];
        $subtotal = 0;
        $totalTax = 0;

        foreach ($items as $index => $item) {
            $origQty = (float) ($item['original_quantity'] ?? $item['quantity'] ?? 0);
            if ($origQty <= 0) {
                throw new InvalidArgumentException("Item {$index} original quantity must be greater than zero.");
            }

            $rawRate = (float) ($item['rate'] ?? $item['purchase_rate'] ?? 0);
            if (isset($item['rate_paise'])) {
                $ratePaise = (int) $item['rate_paise'];
            } elseif (isset($item['unit_price_paise'])) {
                $ratePaise = (int) $item['unit_price_paise'];
            } elseif ($rawRate > 0 && $rawRate < 100000) {
                $ratePaise = (int) round($rawRate * 100);
            } else {
                $ratePaise = (int) round($rawRate);
            }

            $gstRateBps = (int) ($item['gst_rate_bps'] ?? $item['gst_percentage'] ?? 18);
            if ($gstRateBps < 100) {
                $gstRateBps = $gstRateBps * 100;
            }

            $lineTaxablePaise = (int) round($origQty * $ratePaise);
            $lineTaxPaise = (int) round(($lineTaxablePaise * $gstRateBps) / 10000);
            $subtotal += $lineTaxablePaise;
            $totalTax += $lineTaxPaise;

            $pId = $item['product_id'] ?? 'SYS_PROD_HIST_'.Str::random(8);
            $vId = $item['variant_id'] ?? $pId;

            // SECTION 6 CALCULATION:
            // 1. Quantity already consumed
            $consumedQty = (float) ($item['consumed_quantity'] ?? $item['quantity_consumed'] ?? 0);
            if ($consumedQty > $origQty) {
                throw new InvalidArgumentException("Item {$index} consumed quantity ({$consumedQty}) cannot exceed original quantity ({$origQty}).");
            }

            // 2. Remaining physical stock
            $remainingPhysicalStock = max(0, $origQty - $consumedQty);

            // 3. Quantity already included in opening inventory
            $includedInOpening = (float) ($item['included_in_opening_stock'] ?? $item['opening_stock_quantity'] ?? 0);
            if ($includedInOpening > $remainingPhysicalStock) {
                throw new InvalidArgumentException("Item {$index} opening stock inclusion ({$includedInOpening}) cannot exceed remaining physical stock ({$remainingPhysicalStock}).");
            }

            // 4. Actual new stock adjustment to add to current inventory
            // Actual new stock adjustment = Remaining physical stock - Quantity already included in opening inventory
            $actualNewStockAdjustment = max(0, $remainingPhysicalStock - $includedInOpening);

            $lineItems[] = [
                'purchaseItemId' => "LINE_{$purchaseBillId}_{$index}",
                'productId' => $pId,
                'variantId' => $vId,
                'productName' => $item['product_name'] ?? 'Product',
                'variantName' => $item['variant_name'] ?? 'Default',
                'quantity' => $origQty, // Original purchased quantity is preserved!
                'rate' => $ratePaise,
                'gstRateBps' => $gstRateBps,
                'taxableAmount' => $lineTaxablePaise,
                'taxAmount' => $lineTaxPaise,
                'consumedQuantity' => $consumedQty,
                'remainingPhysicalStock' => $remainingPhysicalStock,
                'includedInOpeningStock' => $includedInOpening,
                'actualStockAdjustment' => $actualNewStockAdjustment,
            ];

            $stockAdjustmentPlans[] = [
                'productId' => $pId,
                'variantId' => $vId,
                'originalPurchasedQty' => $origQty,
                'consumedQty' => $consumedQty,
                'remainingPhysicalStock' => $remainingPhysicalStock,
                'includedInOpeningStockQty' => $includedInOpening,
                'actualStockAdjustmentQty' => $actualNewStockAdjustment,
                'ratePaise' => $ratePaise,
            ];
        }

        $grandTotalPaise = (int) ($data['grand_total'] ?? ($subtotal + $totalTax));
        if ($grandTotalPaise < 1000 && ! isset($data['grand_total_paise']) && $subtotal + $totalTax >= 1000) {
            $grandTotalPaise = (int) round($grandTotalPaise * 100);
        }

        $rawPaid = (float) ($data['paid_amount'] ?? $data['amount_paid'] ?? 0);
        $paidPaise = $rawPaid > 0 && $rawPaid < 1000 && ! isset($data['paid_paise']) && $grandTotalPaise >= 1000 ? (int) round($rawPaid * 100) : (int) round($rawPaid);
        $outstandingPaise = max(0, $grandTotalPaise - $paidPaise);

        return DB::transaction(function () use (
            $purchaseBillId,
            $businessId,
            $outletId,
            $batchId,
            $supplierId,
            $supplierBusinessId,
            $supplierName,
            $supplierInvoiceNumber,
            $invoiceDate,
            $purchaseDate,
            $taxType,
            $subtotal,
            $totalTax,
            $grandTotalPaise,
            $paidPaise,
            $outstandingPaise,
            $paymentMethod,
            $status,
            $lineItems,
            $stockAdjustmentPlans,
            $user,
            $nowStr,
            $dbNow
        ) {
            $billFields = [
                'purchaseBillId' => $purchaseBillId,
                'purchaseNumber' => $supplierInvoiceNumber,
                'originalInvoiceNumber' => $supplierInvoiceNumber,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'supplierId' => $supplierId,
                'supplierBusinessId' => $supplierBusinessId,
                'supplierName' => $supplierName,
                'supplierInvoiceNumber' => $supplierInvoiceNumber,
                'invoiceDate' => $invoiceDate,
                'purchaseDate' => $purchaseDate,
                'isHistorical' => true,
                'entrySource' => 'historical',
                'migrationBatchId' => $batchId,
                'subtotal' => $subtotal,
                'taxableAmount' => $subtotal,
                'cgstAmount' => (int) floor($totalTax / 2),
                'sgstAmount' => $totalTax - (int) floor($totalTax / 2),
                'igstAmount' => $taxType === 'INTER_STATE' ? $totalTax : 0,
                'grandTotal' => $grandTotalPaise,
                'paidAmount' => $paidPaise,
                'outstandingAmount' => $outstandingPaise,
                'taxType' => $taxType,
                'status' => $status,
                'items' => $lineItems,
                'createdAt' => Carbon::parse($invoiceDate)->toIso8601String(),
                'enteredAt' => $nowStr,
                'createdBy' => $user->id,
            ];

            // 1. Insert into purchase_bills
            DB::table('purchase_bills')->insert([
                'id' => $purchaseBillId,
                'document_path' => "purchase_bills/{$purchaseBillId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'supplier_id' => $supplierId,
                'status' => $status,
                'entry_source' => 'historical',
                'is_historical' => 1,
                'original_invoice_number' => $supplierInvoiceNumber,
                'original_invoice_date' => $invoiceDate,
                'migration_batch_id' => $batchId,
                'entered_at' => $dbNow,
                'created_at' => Carbon::parse($invoiceDate),
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $billFields]),
            ]);

            // 2. Insert line items
            foreach ($lineItems as $item) {
                DB::table('purchase_bill_items')->insert([
                    'id' => $item['purchaseItemId'],
                    'document_path' => "purchase_bill_items/{$item['purchaseItemId']}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'purchase_bill_id' => $purchaseBillId,
                    'product_id' => $item['productId'],
                    'quantity' => $item['quantity'], // Preserves 100% original quantity
                    'status' => $status,
                    'created_at' => Carbon::parse($invoiceDate),
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => array_merge($item, [
                        'purchaseBillId' => $purchaseBillId,
                        'supplierId' => $supplierId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'isHistorical' => true,
                    ])]),
                ]);
            }

            // 3. Supplier Balance and Ledger
            if ($status === 'APPROVED') {
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
                    $bFields['totalPayable'] = (int) ($bFields['totalPayable'] ?? 0) + $outstandingPaise;
                    $bFields['updatedAt'] = $nowStr;
                    $bPayload['fields'] = $bFields;

                    DB::table('supplier_balances')->where('id', $balRow->id)->update([
                        'balance' => $bFields['totalPayable'] >= 1000 ? ($bFields['totalPayable'] / 100.0) : $bFields['totalPayable'],
                        'updated_at' => $dbNow,
                        'payload' => json_encode($bPayload),
                    ]);
                }

                // Add credit entry to supplier ledger
                $ledgerBillId = "HIST_PB_{$purchaseBillId}";
                DB::table('supplier_ledger_entries')->insert([
                    'id' => $ledgerBillId,
                    'document_path' => "supplier_ledger_entries/{$ledgerBillId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'POSTED',
                    'entry_source' => 'historical',
                    'is_historical' => 1,
                    'migration_batch_id' => $batchId,
                    'original_transaction_date' => $invoiceDate,
                    'created_at' => Carbon::parse($invoiceDate),
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => [
                        'ledgerEntryId' => $ledgerBillId,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'supplierBusinessId' => $supplierBusinessId,
                        'supplierId' => $supplierId,
                        'entryType' => 'PURCHASE_BILL',
                        'referenceType' => 'PURCHASE_BILL',
                        'referenceId' => $purchaseBillId,
                        'originalInvoiceNumber' => $supplierInvoiceNumber,
                        'debit' => 0,
                        'credit' => $grandTotalPaise,
                        'transactionDate' => $invoiceDate,
                        'createdAt' => Carbon::parse($invoiceDate)->toIso8601String(),
                        'createdBy' => $user->id,
                        'isHistorical' => true,
                        'entrySource' => 'historical',
                        'isReversal' => false,
                    ]]),
                ]);

                // Record payment if paid
                if ($paidPaise > 0) {
                    $payId = 'PAY-'.strtoupper(Str::random(24));
                    DB::table('payments')->insert([
                        'id' => $payId,
                        'document_path' => "payments/{$payId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'status' => 'COMPLETED',
                        'entry_source' => 'historical',
                        'is_historical' => 1,
                        'migration_batch_id' => $batchId,
                        'original_transaction_date' => $invoiceDate,
                        'created_at' => Carbon::parse($invoiceDate),
                        'updated_at' => $dbNow,
                        'is_deleted' => 0,
                        'payload' => json_encode(['fields' => [
                            'paymentId' => $payId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'supplierId' => $supplierId,
                            'purchaseBillId' => $purchaseBillId,
                            'amount' => $paidPaise,
                            'paymentMethod' => $paymentMethod,
                            'transactionDate' => $invoiceDate,
                            'isHistorical' => true,
                            'createdAt' => Carbon::parse($invoiceDate)->toIso8601String(),
                            'createdBy' => $user->id,
                        ]]),
                    ]);

                    $ledgerPayId = "HIST_PAY_{$payId}";
                    DB::table('supplier_ledger_entries')->insert([
                        'id' => $ledgerPayId,
                        'document_path' => "supplier_ledger_entries/{$ledgerPayId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'status' => 'POSTED',
                        'entry_source' => 'historical',
                        'is_historical' => 1,
                        'migration_batch_id' => $batchId,
                        'original_transaction_date' => $invoiceDate,
                        'created_at' => Carbon::parse($invoiceDate),
                        'updated_at' => $dbNow,
                        'is_deleted' => 0,
                        'payload' => json_encode(['fields' => [
                            'ledgerEntryId' => $ledgerPayId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'supplierBusinessId' => $supplierBusinessId,
                            'supplierId' => $supplierId,
                            'entryType' => 'PAYMENT',
                            'referenceType' => 'PAYMENT',
                            'referenceId' => $payId,
                            'debit' => $paidPaise,
                            'credit' => 0,
                            'transactionDate' => $invoiceDate,
                            'createdAt' => Carbon::parse($invoiceDate)->toIso8601String(),
                            'createdBy' => $user->id,
                            'isHistorical' => true,
                            'isReversal' => false,
                        ]]),
                    ]);
                }

                // 4. Traceable Stock Adjustments (SECTION 6):
                // For each product, record historical_stock_adjustments.
                // If actualStockAdjustmentQty > 0, update current inventory.
                foreach ($stockAdjustmentPlans as $plan) {
                    $adjId = 'HSA-'.strtoupper(Str::random(24));
                    $movementId = null;

                    if ($plan['actualStockAdjustmentQty'] > 0 && $outletId) {
                        $qtyUnits = (float) $plan['actualStockAdjustmentQty'];
                        $milliQty = (int) round($qtyUnits * 1000);
                        $vId = $plan['variantId'];
                        $pId = $plan['productId'];
                        $lineCost = (int) round($qtyUnits * $plan['ratePaise']);

                        $stockRow = DB::table('stock_balances')
                            ->where('business_id', $businessId)
                            ->where('outlet_id', $outletId)
                            ->where(function ($q) use ($vId, $pId) {
                                $q->where('id', 'LIKE', "%_{$vId}")
                                    ->orWhere('payload->fields->variantId', $vId)
                                    ->orWhere('payload->fields->productId', $pId);
                            })
                            ->lockForUpdate()
                            ->first();

                        $currStock = 0;
                        if ($stockRow) {
                            $sbPayload = json_decode($stockRow->payload, true) ?: [];
                            $sbFields = $sbPayload['fields'] ?? [];
                            $currStock = (int) ($sbFields['quantityOnHand'] ?? $sbFields['currentStock'] ?? 0);
                            $newStock = $currStock + $milliQty;
                            $oldVal = (int) ($sbFields['totalValue'] ?? 0);
                            $newVal = $oldVal + $lineCost;

                            $sbFields['quantityOnHand'] = $newStock;
                            $sbFields['currentStock'] = $newStock;
                            $sbFields['totalValue'] = $newVal;
                            $sbFields['averageCost'] = $newStock > 0 ? (int) round(($newVal * 1000) / $newStock) : 0;
                            $sbFields['avgCostPaise'] = $newStock > 0 ? (int) round($newVal / max(1, $newStock / 1000)) : 0;
                            $sbFields['updatedAt'] = $nowStr;
                            $sbPayload['fields'] = $sbFields;

                            DB::table('stock_balances')->where('id', $stockRow->id)->update([
                                'quantity_on_hand' => $newStock >= 1000 ? ($newStock / 1000.0) : $newStock,
                                'updated_at' => $dbNow,
                                'payload' => json_encode($sbPayload),
                            ]);
                        } else {
                            $newStock = $milliQty;
                            $sbFields = [
                                'stockBalanceId' => "HIST_{$businessId}_{$outletId}_{$vId}",
                                'tenantId' => $user->id,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'variantId' => $vId,
                                'productId' => $pId,
                                'quantityOnHand' => $newStock,
                                'currentStock' => $newStock,
                                'totalValue' => $lineCost,
                                'averageCost' => $plan['ratePaise'],
                                'avgCostPaise' => $plan['ratePaise'],
                                'createdAt' => $nowStr,
                                'updatedAt' => $nowStr,
                            ];
                            DB::table('stock_balances')->insert([
                                'id' => "HIST_{$businessId}_{$outletId}_{$vId}",
                                'document_path' => "stock_balances/HIST_{$businessId}_{$outletId}_{$vId}",
                                'business_id' => $businessId,
                                'outlet_id' => $outletId,
                                'quantity_on_hand' => $qtyUnits,
                                'status' => 'ACTIVE',
                                'created_at' => $dbNow,
                                'updated_at' => $dbNow,
                                'is_deleted' => 0,
                                'payload' => json_encode(['fields' => $sbFields]),
                            ]);
                        }

                        $balAfterUnits = $newStock >= 1000 ? ($newStock / 1000.0) : (float) $newStock;
                        $movementId = 'STM-'.strtoupper(Str::random(24));
                        DB::table('stock_movements')->insert([
                            'id' => $movementId,
                            'document_path' => "stock_movements/{$movementId}",
                            'business_id' => $businessId,
                            'outlet_id' => $outletId,
                            'product_id' => $pId,
                            'variant_id' => $vId,
                            'type' => 'purchase',
                            'qty_change' => $qtyUnits,
                            'balance_after' => $balAfterUnits,
                            'reference_type' => 'historical_purchase_bill',
                            'reference_id' => $purchaseBillId,
                            'condition' => 'good',
                            'note' => "Historical Purchase {$supplierInvoiceNumber} stock inward: {$qtyUnits} units (original: {$plan['originalPurchasedQty']}, consumed: {$plan['consumedQty']}, in opening: {$plan['includedInOpeningStockQty']})",
                            'entry_source' => 'historical',
                            'is_historical' => 1,
                            'migration_batch_id' => $batchId,
                            'original_transaction_date' => $invoiceDate,
                            'created_by' => $user->id,
                            'created_at' => Carbon::parse($invoiceDate),
                            'updated_at' => $dbNow,
                            'payload' => json_encode(['fields' => [
                                'movementId' => $movementId,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'productId' => $pId,
                                'variantId' => $vId,
                                'type' => 'PURCHASE',
                                'qtyBase' => $milliQty,
                                'balanceAfter' => $newStock,
                                'isHistorical' => true,
                                'entrySource' => 'historical',
                            ]]),
                        ]);
                    }

                    // Record in historical_stock_adjustments table for full audit provenance
                    HistoricalStockAdjustment::create([
                        'id' => $adjId,
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'batch_id' => $batchId,
                        'purchase_bill_id' => $purchaseBillId,
                        'product_id' => $plan['productId'],
                        'variant_id' => $plan['variantId'],
                        'original_purchased_qty' => $plan['originalPurchasedQty'],
                        'consumed_qty' => $plan['consumedQty'],
                        'remaining_physical_stock' => $plan['remainingPhysicalStock'],
                        'included_in_opening_stock_qty' => $plan['includedInOpeningStockQty'],
                        'actual_stock_adjustment_qty' => $plan['actualStockAdjustmentQty'],
                        'stock_movement_id' => $movementId,
                        'status' => 'POSTED',
                        'payload' => [
                            'fields' => array_merge($plan, [
                                'adjustmentId' => $adjId,
                                'supplierInvoiceNumber' => $supplierInvoiceNumber,
                                'invoiceDate' => $invoiceDate,
                            ]),
                        ],
                    ]);
                }
            }

            // Log action
            if ($batchId) {
                MigrationLog::create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batchId,
                    'business_id' => $businessId,
                    'user_id' => $user->id,
                    'action' => 'HISTORICAL_PURCHASE_RECORDED',
                    'description' => "Recorded historical purchase bill {$supplierInvoiceNumber} for {$grandTotalPaise} paise.",
                ]);
            }

            return [
                'purchaseBillId' => $purchaseBillId,
                'supplierInvoiceNumber' => $supplierInvoiceNumber,
                'grandTotal' => $grandTotalPaise,
                'paidAmount' => $paidPaise,
                'outstandingAmount' => $outstandingPaise,
                'status' => $status,
                'isHistorical' => true,
                'stockAdjustments' => $stockAdjustmentPlans,
            ];
        });
    }

    /**
     * Record Opening Stock baseline.
     */
    public function recordOpeningStock(array $data, User $user): array
    {
        $businessId = $data['business_id'];
        $outletId = $data['outlet_id'];
        $batchId = $data['batch_id'] ?? null;
        $productId = $data['product_id'];
        $variantId = $data['variant_id'] ?? $productId;
        $quantity = (float) $data['quantity'];
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Opening stock quantity must be greater than zero.');
        }

        $rawRate = (float) ($data['valuation_rate'] ?? $data['purchase_rate'] ?? $data['rate'] ?? 0);
        $ratePaise = $rawRate > 0 && $rawRate < 1000 && ! isset($data['rate_paise']) ? (int) round($rawRate * 100) : (int) round($rawRate);
        $openingDate = Carbon::parse($data['opening_date'] ?? now())->toDateString();
        $milliQty = (int) round($quantity * 1000);
        $totalValuationPaise = (int) round($quantity * $ratePaise);

        $nowStr = now()->toIso8601String();
        $dbNow = now();

        return DB::transaction(function () use (
            $businessId,
            $outletId,
            $batchId,
            $productId,
            $variantId,
            $quantity,
            $milliQty,
            $ratePaise,
            $totalValuationPaise,
            $openingDate,
            $user,
            $nowStr,
            $dbNow
        ) {
            $stockBalId = "OPENING_{$businessId}_{$outletId}_{$variantId}";
            $stockRow = DB::table('stock_balances')
                ->where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->where(function ($q) use ($stockBalId, $variantId, $productId) {
                    $q->where('id', $stockBalId)
                        ->orWhere('payload->fields->variantId', $variantId)
                        ->orWhere('payload->fields->productId', $productId);
                })
                ->lockForUpdate()
                ->first();

            $currStock = 0;
            if ($stockRow) {
                $sbPayload = json_decode($stockRow->payload, true) ?: [];
                $sbFields = $sbPayload['fields'] ?? [];
                $currStock = (int) ($sbFields['quantityOnHand'] ?? $sbFields['currentStock'] ?? 0);
                $newStock = $currStock + $milliQty;
                $oldVal = (int) ($sbFields['totalValue'] ?? 0);
                $newVal = $oldVal + $totalValuationPaise;

                $sbFields['quantityOnHand'] = $newStock;
                $sbFields['currentStock'] = $newStock;
                $sbFields['totalValue'] = $newVal;
                $sbFields['averageCost'] = $newStock > 0 ? (int) round(($newVal * 1000) / $newStock) : 0;
                $sbFields['avgCostPaise'] = $newStock > 0 ? (int) round($newVal / max(1, $newStock / 1000)) : 0;
                $sbFields['lastPurchasePricePaise'] = $ratePaise;
                $sbFields['updatedAt'] = $nowStr;
                $sbPayload['fields'] = $sbFields;

                DB::table('stock_balances')->where('id', $stockRow->id)->update([
                    'quantity_on_hand' => $newStock >= 1000 ? ($newStock / 1000.0) : $newStock,
                    'updated_at' => $dbNow,
                    'payload' => json_encode($sbPayload),
                ]);
            } else {
                $newStock = $milliQty;
                $sbFields = [
                    'stockBalanceId' => $stockBalId,
                    'tenantId' => $user->id,
                    'businessId' => $businessId,
                    'outletId' => $outletId,
                    'variantId' => $variantId,
                    'productId' => $productId,
                    'quantityOnHand' => $newStock,
                    'currentStock' => $newStock,
                    'totalValue' => $totalValuationPaise,
                    'averageCost' => $ratePaise,
                    'avgCostPaise' => $ratePaise,
                    'lastPurchasePricePaise' => $ratePaise,
                    'createdAt' => $nowStr,
                    'updatedAt' => $nowStr,
                ];
                DB::table('stock_balances')->insert([
                    'id' => $stockBalId,
                    'document_path' => "stock_balances/{$stockBalId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'quantity_on_hand' => $quantity,
                    'status' => 'ACTIVE',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => $sbFields]),
                ]);
            }

            $balAfterUnits = $newStock >= 1000 ? ($newStock / 1000.0) : (float) $newStock;
            $movementId = 'STM-'.strtoupper(Str::random(24));
            DB::table('stock_movements')->insert([
                'id' => $movementId,
                'document_path' => "stock_movements/{$movementId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'type' => 'OPENING_STOCK',
                'qty_change' => $quantity,
                'balance_after' => $balAfterUnits,
                'reference_type' => 'opening_stock',
                'reference_id' => $stockBalId,
                'condition' => 'good',
                'note' => "Opening physical stock baseline: {$quantity} units @ ₹".($ratePaise / 100.0),
                'entry_source' => 'opening_balance',
                'is_historical' => 1,
                'migration_batch_id' => $batchId,
                'original_transaction_date' => $openingDate,
                'created_by' => $user->id,
                'created_at' => Carbon::parse($openingDate),
                'updated_at' => $dbNow,
                'payload' => json_encode(['fields' => [
                    'movementId' => $movementId,
                    'businessId' => $businessId,
                    'outletId' => $outletId,
                    'productId' => $productId,
                    'variantId' => $variantId,
                    'type' => 'OPENING_STOCK',
                    'qtyBase' => $milliQty,
                    'balanceAfter' => $newStock,
                    'isHistorical' => true,
                    'entrySource' => 'opening_balance',
                    'unitCostPaise' => $ratePaise,
                ]]),
            ]);

            return [
                'stockBalanceId' => $stockBalId,
                'movementId' => $movementId,
                'productId' => $productId,
                'variantId' => $variantId,
                'quantity' => $quantity,
                'currentStock' => $balAfterUnits,
                'totalValue' => $totalValuationPaise,
            ];
        });
    }

    /**
     * Stock Reconciliation Calculation between historical calculated movements and opening inventory.
     */
    public function calculateStockReconciliation(string $businessId, ?string $outletId): array
    {
        $products = DB::table('products')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        $reconciliations = [];

        foreach ($products as $prod) {
            $pId = $prod->id;
            $pPayload = json_decode($prod->payload, true) ?: [];
            $name = $pPayload['fields']['name'] ?? 'Product';

            // 1. Current stock from stock_balances
            $currBal = DB::table('stock_balances')
                ->where('business_id', $businessId)
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->where(function ($q) use ($pId) {
                    $q->where('product_id', $pId)
                        ->orWhere('id', 'LIKE', "%_{$pId}")
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.productId')) = ?", [$pId]);
                })
                ->sum('quantity_on_hand');

            // 2. Opening stock entered
            $openingStockQty = DB::table('stock_movements')
                ->where('business_id', $businessId)
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->where(function ($q) use ($pId) {
                    $q->where('product_id', $pId)->orWhere('variant_id', $pId);
                })
                ->where('type', 'OPENING_STOCK')
                ->sum('qty_change');

            // 3. Historical purchases total
            $histPurchasesQty = DB::table('purchase_bill_items')
                ->join('purchase_bills', 'purchase_bill_items.purchase_bill_id', '=', 'purchase_bills.id')
                ->where('purchase_bills.business_id', $businessId)
                ->when($outletId, fn ($q) => $q->where('purchase_bills.outlet_id', $outletId))
                ->where('purchase_bills.is_historical', 1)
                ->where('purchase_bill_items.product_id', $pId)
                ->sum('purchase_bill_items.quantity');

            // 4. Historical sales total
            $histSalesQty = DB::table('invoice_items')
                ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                ->where('invoices.business_id', $businessId)
                ->when($outletId, fn ($q) => $q->where('invoices.outlet_id', $outletId))
                ->where('invoices.is_historical', 1)
                ->where('invoice_items.product_id', $pId)
                ->sum('invoice_items.quantity');

            // 5. Historical stock adjustments applied
            $adjustmentsApplied = DB::table('historical_stock_adjustments')
                ->where('business_id', $businessId)
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->where('product_id', $pId)
                ->sum('actual_stock_adjustment_qty');

            $calculatedHistoricalClosing = max(0, $histPurchasesQty - $histSalesQty);
            $discrepancy = $currBal - ($openingStockQty + $adjustmentsApplied);

            $reconciliations[] = [
                'productId' => $pId,
                'productName' => $name,
                'openingStockQty' => (float) $openingStockQty,
                'historicalPurchasesQty' => (float) $histPurchasesQty,
                'historicalSalesQty' => (float) $histSalesQty,
                'calculatedHistoricalClosing' => (float) $calculatedHistoricalClosing,
                'adjustmentsAppliedToCurrentStock' => (float) $adjustmentsApplied,
                'currentStockOnHand' => (float) $currBal,
                'reconciliationDiscrepancy' => (float) $discrepancy,
                'isReconciled' => abs($discrepancy) < 0.001,
            ];
        }

        return $reconciliations;
    }

    /**
     * Safely rollback a historical migration batch.
     */
    public function rollbackBatch(string $batchId, string $businessId, User $user): array
    {
        $batch = MigrationBatch::where('id', $batchId)
            ->where('business_id', $businessId)
            ->first();

        if (! $batch) {
            throw new InvalidArgumentException("Migration batch '{$batchId}' not found for this business.");
        }

        if ($batch->status === 'ROLLED_BACK') {
            throw new InvalidArgumentException("Migration batch '{$batchId}' has already been rolled back.");
        }

        return DB::transaction(function () use ($batch, $batchId, $businessId, $user) {
            $nowDb = now();

            // 1. Revert stock adjustments made by this batch
            $stockAdjustments = HistoricalStockAdjustment::where('batch_id', $batchId)
                ->where('business_id', $businessId)
                ->get();

            foreach ($stockAdjustments as $adj) {
                if ($adj->actual_stock_adjustment_qty > 0 && $adj->outlet_id) {
                    $milliQty = (int) round($adj->actual_stock_adjustment_qty * 1000);
                    $stockRow = DB::table('stock_balances')
                        ->where('business_id', $businessId)
                        ->where('outlet_id', $adj->outlet_id)
                        ->where(function ($q) use ($adj) {
                            $q->where('product_id', $adj->product_id)
                                ->orWhere('variant_id', $adj->variant_id)
                                ->orWhere('payload->fields->productId', $adj->product_id)
                                ->orWhere('payload->fields->variantId', $adj->variant_id);
                        })
                        ->lockForUpdate()
                        ->first();

                    if ($stockRow) {
                        $sbPayload = json_decode($stockRow->payload, true) ?: [];
                        $sbFields = $sbPayload['fields'] ?? [];
                        $curr = (int) ($sbFields['quantityOnHand'] ?? $sbFields['currentStock'] ?? 0);
                        $reverted = max(0, $curr - $milliQty);
                        $sbFields['quantityOnHand'] = $reverted;
                        $sbFields['currentStock'] = $reverted;
                        $sbPayload['fields'] = $sbFields;

                        DB::table('stock_balances')->where('id', $stockRow->id)->update([
                            'quantity_on_hand' => $reverted >= 1000 ? ($reverted / 1000.0) : $reverted,
                            'updated_at' => $nowDb,
                            'payload' => json_encode($sbPayload),
                        ]);
                    }
                }
                $adj->update(['status' => 'ROLLED_BACK']);
            }

            // 2. Soft-delete or mark invoices from this batch
            DB::table('invoices')
                ->where('business_id', $businessId)
                ->where('migration_batch_id', $batchId)
                ->update(['is_deleted' => 1, 'status' => 'ROLLED_BACK', 'updated_at' => $nowDb]);

            // 3. Soft-delete or mark purchase bills from this batch
            DB::table('purchase_bills')
                ->where('business_id', $businessId)
                ->where('migration_batch_id', $batchId)
                ->update(['is_deleted' => 1, 'status' => 'ROLLED_BACK', 'updated_at' => $nowDb]);

            // 4. Soft-delete ledger entries
            DB::table('customer_ledger_entries')
                ->where('business_id', $businessId)
                ->where('migration_batch_id', $batchId)
                ->update(['is_deleted' => 1, 'status' => 'REVERSED', 'updated_at' => $nowDb]);

            DB::table('supplier_ledger_entries')
                ->where('business_id', $businessId)
                ->where('migration_batch_id', $batchId)
                ->update(['is_deleted' => 1, 'status' => 'REVERSED', 'updated_at' => $nowDb]);

            // 5. Delete stock movements created by this batch
            DB::table('stock_movements')
                ->where('business_id', $businessId)
                ->where('migration_batch_id', $batchId)
                ->delete();

            // 6. Update batch status
            $batch->update([
                'status' => 'ROLLED_BACK',
                'updated_at' => $nowDb,
            ]);

            // 7. Write migration log
            MigrationLog::create([
                'id' => (string) Str::uuid(),
                'batch_id' => $batchId,
                'business_id' => $businessId,
                'user_id' => $user->id,
                'action' => 'BATCH_ROLLED_BACK',
                'description' => "Migration batch {$batchId} was successfully rolled back and all associated transactions and stock adjustments reverted.",
            ]);

            return [
                'batchId' => $batchId,
                'status' => 'ROLLED_BACK',
                'revertedAdjustmentsCount' => count($stockAdjustments),
            ];
        });
    }
}
