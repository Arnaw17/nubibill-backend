<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\ReturnAudit;
use App\Models\ReturnItem;
use App\Models\ReturnModel;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    /**
     * Create a new sales or purchase return inside a database transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createReturn(array $data, User $user): ReturnModel
    {
        // 1. Idempotency Check
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if (! empty($idempotencyKey)) {
            $existing = ReturnModel::with(['items', 'reason', 'stockMovements', 'ledgerEntries'])
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($data, $user, $idempotencyKey) {
            $now = Carbon::now();
            $nowStr = $now->toIso8601String();
            $dbNow = $now->toDateTimeString();

            $businessId = $data['business_id'] ?? $user->defaultBusinessId() ?? 'default_business';
            $outletId = $data['outlet_id'] ?? null;
            $type = $data['type']; // 'sale_return' or 'purchase_return'
            $itemsData = $data['items'] ?? [];

            if (empty($itemsData)) {
                throw ValidationException::withMessages(['items' => 'A return must contain at least one item.']);
            }

            // 2. Validate Over-Return Rejection against original bill items
            $this->validateReturnableQuantities($type, $itemsData, $data['original_bill_id'] ?? null);

            // 3. Sequential Return Number Generation
            $returnNo = $this->generateReturnNumber($user->id, $businessId, $type);

            // 4. Calculate Tax & Totals
            $partyStateCode = $data['party_state_code'] ?? null;
            $businessStateCode = $data['business_state_code'] ?? null;
            $isInterState = (! empty($partyStateCode) && ! empty($businessStateCode) && $partyStateCode !== $businessStateCode);

            $subtotal = 0.00;
            $cgstTotal = 0.00;
            $sgstTotal = 0.00;
            $igstTotal = 0.00;
            $taxTotal = 0.00;
            $grandTotal = 0.00;

            $calculatedItems = [];
            foreach ($itemsData as $item) {
                $qty = (float) ($item['qty_returned'] ?? 0);
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $discount = (float) ($item['discount_amount'] ?? 0);
                $taxRate = (float) ($item['tax_rate'] ?? 0);
                $condition = $item['condition'] ?? 'resalable';
                // If condition is damaged or expired, restock is false by default unless explicitly specified
                $restock = isset($item['restock']) ? (bool) $item['restock'] : ($condition === 'resalable');

                $taxableAmount = round(max(0, ($qty * $unitPrice) - $discount), 2);

                if ($isInterState) {
                    $cgst = 0.00;
                    $sgst = 0.00;
                    $igst = round($taxableAmount * ($taxRate / 100), 2);
                } else {
                    $cgst = round($taxableAmount * (($taxRate / 2) / 100), 2);
                    $sgst = round($taxableAmount * (($taxRate / 2) / 100), 2);
                    $igst = 0.00;
                }

                $taxAmount = $cgst + $sgst + $igst;
                $lineTotal = $taxableAmount + $taxAmount;

                $subtotal += $taxableAmount;
                $cgstTotal += $cgst;
                $sgstTotal += $sgst;
                $igstTotal += $igst;
                $taxTotal += $taxAmount;
                $grandTotal += $lineTotal;

                $calculatedItems[] = array_merge($item, [
                    'id' => (string) Str::uuid(),
                    'qty_returned' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $discount,
                    'taxable_amount' => $taxableAmount,
                    'tax_rate' => $taxRate,
                    'cgst_amount' => $cgst,
                    'sgst_amount' => $sgst,
                    'igst_amount' => $igst,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'condition' => $condition,
                    'restock' => $restock,
                ]);
            }

            // 5. Create Return Header Record
            $returnId = (string) Str::uuid();
            $refundMode = $data['refund_mode'] ?? 'adjust_to_ledger';

            $returnRecord = ReturnModel::create([
                'id' => $returnId,
                'user_id' => $user->id,
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'return_no' => $returnNo,
                'type' => $type,
                'original_bill_id' => $data['original_bill_id'] ?? null,
                'original_bill_no' => $data['original_bill_no'] ?? null,
                'original_bill_date' => $data['original_bill_date'] ?? null,
                'party_id' => $data['party_id'],
                'party_name' => $data['party_name'] ?? null,
                'party_phone' => $data['party_phone'] ?? null,
                'party_gstin' => $data['party_gstin'] ?? null,
                'party_state_code' => $partyStateCode,
                'business_state_code' => $businessStateCode,
                'return_date' => $data['return_date'] ?? $now->toDateString(),
                'reason_id' => $data['reason_id'] ?? null,
                'reason_note' => $data['reason_note'] ?? null,
                'refund_mode' => $refundMode,
                'idempotency_key' => $idempotencyKey,
                'subtotal' => $subtotal,
                'cgst_total' => $cgstTotal,
                'sgst_total' => $sgstTotal,
                'igst_total' => $igstTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'status' => 'completed',
                'created_by' => $user->id,
            ]);

            // 6. Create Return Items
            foreach ($calculatedItems as $cItem) {
                ReturnItem::create(array_merge($cItem, [
                    'return_id' => $returnId,
                ]));
            }

            // 7. Update Stock and Record Stock Movements
            $this->applyStockEffects($returnRecord, $calculatedItems, $user);

            // 8. Update Party Ledger and Record Ledger Entries
            $this->applyLedgerEffects($returnRecord, $user);

            // 9. Update returned_qty on original bill items
            $this->updateOriginalBillReturnedQuantities($type, $calculatedItems);

            // 10. Audit History Log
            ReturnAudit::create([
                'id' => (string) Str::uuid(),
                'return_id' => $returnId,
                'user_id' => $user->id,
                'action' => 'created',
                'new_values' => $returnRecord->toArray(),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $returnRecord->load(['items', 'reason', 'stockMovements', 'ledgerEntries']);
        });
    }

    /**
     * Cancel an existing return with full compensating stock and ledger reversals.
     *
     * @throws ValidationException
     */
    public function cancelReturn(string $returnId, string $reason, User $user): ReturnModel
    {
        return DB::transaction(function () use ($returnId, $reason, $user) {
            $return = ReturnModel::with(['items', 'stockMovements', 'ledgerEntries'])
                ->where('id', $returnId)
                ->lockForUpdate()
                ->first();

            if (! $return) {
                throw ValidationException::withMessages(['return' => 'Return not found.']);
            }

            if ($return->isCancelled()) {
                throw ValidationException::withMessages(['return' => 'Return is already cancelled.']);
            }

            $oldValues = $return->toArray();
            $now = Carbon::now();

            // 1. Reverse Stock Effects via Compensating Stock Movements
            $this->reverseStockEffects($return, $user);

            // 2. Reverse Ledger Effects via Compensating Ledger Entries
            $this->reverseLedgerEffects($return, $user);

            // 3. Deduct returned_qty from original bill items
            $this->reverseOriginalBillReturnedQuantities($return);

            // 4. Mark Return as Cancelled
            $return->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => $now,
            ]);

            // 5. Audit History Log
            ReturnAudit::create([
                'id' => (string) Str::uuid(),
                'return_id' => $return->id,
                'user_id' => $user->id,
                'action' => 'cancelled',
                'old_values' => $oldValues,
                'new_values' => $return->fresh()->toArray(),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $return->fresh(['items', 'reason', 'stockMovements', 'ledgerEntries']);
        });
    }

    /**
     * Validate that returned quantities do not exceed original purchased/sold quantities.
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws ValidationException
     */
    protected function validateReturnableQuantities(string $type, array $items, ?string $originalBillId): void
    {
        if (empty($originalBillId)) {
            // Standalone returns without original bill are allowed
            return;
        }

        foreach ($items as $item) {
            $originalItemId = $item['original_bill_item_id'] ?? null;
            $qtyToReturn = (float) ($item['qty_returned'] ?? 0);
            $productName = $item['product_name'] ?? 'Item';

            if ($qtyToReturn <= 0) {
                throw ValidationException::withMessages([
                    'items' => "Quantity returned for '{$productName}' must be greater than zero.",
                ]);
            }

            if (! empty($originalItemId)) {
                $originalItem = null;
                if ($type === 'sale_return' && DB::getSchemaBuilder()->hasTable('invoice_items')) {
                    $originalItem = DB::table('invoice_items')->where('id', $originalItemId)->first();
                } elseif ($type === 'purchase_return' && DB::getSchemaBuilder()->hasTable('purchase_bill_items')) {
                    $originalItem = DB::table('purchase_bill_items')->where('id', $originalItemId)->first();
                }

                if ($originalItem) {
                    $soldQty = 0.0;
                    $alreadyReturned = 0.0;

                    if (isset($originalItem->quantity)) {
                        $soldQty = (float) $originalItem->quantity;
                    }
                    if (isset($originalItem->returned_qty)) {
                        $alreadyReturned = (float) $originalItem->returned_qty;
                    }

                    // Fallback to parse JSON payload if present
                    if ($soldQty <= 0 && ! empty($originalItem->payload)) {
                        $payload = json_decode($originalItem->payload, true) ?: [];
                        $fields = $payload['fields'] ?? [];
                        $soldQty = (float) ($fields['quantity'] ?? $fields['orderedQuantity'] ?? 0);
                        $alreadyReturned = (float) ($fields['returnedQty'] ?? 0);
                    }

                    $remainingReturnable = max(0.0, $soldQty - $alreadyReturned);

                    if ($soldQty > 0 && $qtyToReturn > ($remainingReturnable + 0.0001)) {
                        throw ValidationException::withMessages([
                            'items' => "Return quantity for '{$productName}' exceeds returnable quantity. Available: {$remainingReturnable}, Requested: {$qtyToReturn}.",
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Apply stock adjustments and record stock movements.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function applyStockEffects(ReturnModel $return, array $items, User $user): void
    {
        $businessId = $return->business_id;
        $outletId = $return->outlet_id;

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $variantId = $item['variant_id'] ?? null;
            $qty = (float) $item['qty_returned'];
            $condition = $item['condition'] ?? 'resalable';
            $restock = (bool) ($item['restock'] ?? false);

            if ($return->isSaleReturn()) {
                if ($condition === 'resalable' && $restock) {
                    // Restock inventory: +qty (converted to milli-units in adjustStockBalance)
                    $newBalance = $this->adjustStockBalance($businessId, $outletId, $productId, $variantId, $qty);

                    StockMovement::create([
                        'id' => (string) Str::uuid(),
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'type' => 'sale_return',
                        'qty_change' => $qty,
                        'balance_after' => $newBalance / 1000.0,
                        'reference_type' => 'return',
                        'reference_id' => $return->id,
                        'condition' => 'resalable',
                        'note' => "Sales Return {$return->return_no} restocked",
                        'created_by' => $user->id,
                        'payload' => [
                            'fields' => [
                                'type' => 'RETURN',
                                'reason' => "Sales Return {$return->return_no} restocked",
                                'productId' => $variantId ?? $productId,
                                'variantId' => $variantId,
                                'qtyBase' => (int) round($qty * 1000.0),
                                'balanceAfter' => (int) $newBalance,
                                'outletId' => $outletId,
                                'businessId' => $businessId,
                                'referenceId' => $return->id,
                                'createdAt' => now()->toIso8601String(),
                            ],
                        ],
                    ]);
                } else {
                    // Damaged/Expired or no restock: log as wastage without increasing sellable stock
                    $currentBal = $this->getStockBalance($businessId, $outletId, $productId, $variantId);

                    StockMovement::create([
                        'id' => (string) Str::uuid(),
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'type' => 'wastage',
                        'qty_change' => 0.000,
                        'balance_after' => $currentBal / 1000.0,
                        'reference_type' => 'return',
                        'reference_id' => $return->id,
                        'condition' => $condition,
                        'note' => "Wastage/damaged return {$return->return_no} - not restocked ({$condition})",
                        'created_by' => $user->id,
                        'payload' => [
                            'fields' => [
                                'type' => 'DAMAGE_LOSS',
                                'reason' => "Wastage/damaged return {$return->return_no} - not restocked ({$condition})",
                                'productId' => $variantId ?? $productId,
                                'variantId' => $variantId,
                                'qtyBase' => 0,
                                'balanceAfter' => (int) $currentBal,
                                'outletId' => $outletId,
                                'businessId' => $businessId,
                                'referenceId' => $return->id,
                                'createdAt' => now()->toIso8601String(),
                            ],
                        ],
                    ]);
                }
            } elseif ($return->isPurchaseReturn()) {
                // Return to supplier: deduct stock (-qty)
                $newBalance = $this->adjustStockBalance($businessId, $outletId, $productId, $variantId, -$qty);

                StockMovement::create([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'type' => 'purchase_return',
                    'qty_change' => -$qty,
                    'balance_after' => $newBalance / 1000.0,
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'condition' => $condition,
                    'note' => "Purchase Return {$return->return_no} returned to supplier",
                    'created_by' => $user->id,
                    'payload' => [
                        'fields' => [
                            'type' => 'ADJUST_OUT',
                            'reason' => "Purchase Return {$return->return_no} returned to supplier",
                            'productId' => $variantId ?? $productId,
                            'variantId' => $variantId,
                            'qtyBase' => (int) round($qty * 1000.0),
                            'balanceAfter' => (int) $newBalance,
                            'outletId' => $outletId,
                            'businessId' => $businessId,
                            'referenceId' => $return->id,
                            'createdAt' => now()->toIso8601String(),
                        ],
                    ],
                ]);
            }
        }
    }

    /**
     * Apply party ledger entry and customer/supplier balance updates.
     */
    protected function applyLedgerEffects(ReturnModel $return, User $user): void
    {
        $businessId = $return->business_id;
        $partyId = $return->party_id;
        $grandTotal = (float) $return->grand_total;
        $refundMode = $return->refund_mode;
        $now = Carbon::now();

        if ($return->isSaleReturn()) {
            if (in_array($refundMode, ['adjust_to_ledger', 'credit_note'], true)) {
                // Credit Note: reduces customer's pending Udhaar / receivable balance (Credit)
                $balAfter = $this->updateCustomerBalance($businessId, $partyId, -$grandTotal);

                LedgerEntry::create([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'party_type' => 'customer',
                    'party_id' => $partyId,
                    'type' => 'credit',
                    'amount' => $grandTotal,
                    'balance_after' => $balAfter,
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'date' => $return->return_date ?? $now->toDateString(),
                    'note' => "Credit Note {$return->return_no} adjusted to customer ledger",
                    'is_reversal' => false,
                    'created_by' => $user->id,
                ]);
            } else {
                // Cash / UPI / Bank refund payout: record refund payment voucher
                LedgerEntry::create([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'party_type' => 'customer',
                    'party_id' => $partyId,
                    'type' => 'credit',
                    'amount' => $grandTotal,
                    'balance_after' => null,
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'date' => $return->return_date ?? $now->toDateString(),
                    'note' => "Sales Return {$return->return_no} refund payout via ".strtoupper($refundMode),
                    'is_reversal' => false,
                    'created_by' => $user->id,
                ]);
            }
        } elseif ($return->isPurchaseReturn()) {
            if (in_array($refundMode, ['adjust_to_ledger', 'credit_note'], true)) {
                // Debit Note: reduces what we owe supplier (Debit)
                $balAfter = $this->updateSupplierBalance($businessId, $partyId, -$grandTotal);

                LedgerEntry::create([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'party_type' => 'supplier',
                    'party_id' => $partyId,
                    'type' => 'debit',
                    'amount' => $grandTotal,
                    'balance_after' => $balAfter,
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'date' => $return->return_date ?? $now->toDateString(),
                    'note' => "Debit Note {$return->return_no} adjusted to supplier ledger",
                    'is_reversal' => false,
                    'created_by' => $user->id,
                ]);
            } else {
                // Cash / UPI / Bank refund received from supplier
                LedgerEntry::create([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'party_type' => 'supplier',
                    'party_id' => $partyId,
                    'type' => 'debit',
                    'amount' => $grandTotal,
                    'balance_after' => null,
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'date' => $return->return_date ?? $now->toDateString(),
                    'note' => "Purchase Return {$return->return_no} refund received via ".strtoupper($refundMode),
                    'is_reversal' => false,
                    'created_by' => $user->id,
                ]);
            }
        }
    }

    /**
     * Compensating stock reversals on cancellation.
     */
    protected function reverseStockEffects(ReturnModel $return, User $user): void
    {
        $businessId = $return->business_id;
        $outletId = $return->outlet_id;

        foreach ($return->items as $item) {
            $productId = $item->product_id;
            $variantId = $item->variant_id;
            $qty = (float) $item->qty_returned;

            if ($return->isSaleReturn()) {
                if ($item->condition === 'resalable' && $item->restock) {
                    // Deduct the restocked stock (-qty)
                    $newBalance = $this->adjustStockBalance($businessId, $outletId, $productId, $variantId, -$qty);

                    StockMovement::create([
                        'id' => (string) Str::uuid(),
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'type' => 'adjustment',
                        'qty_change' => -$qty,
                        'balance_after' => $newBalance / 1000.0,
                        'reference_type' => 'return',
                        'reference_id' => $return->id,
                        'condition' => 'resalable',
                        'note' => "Cancellation of Sales Return {$return->return_no} (Stock reversed)",
                        'created_by' => $user->id,
                        'payload' => [
                            'fields' => [
                                'type' => 'ADJUST_OUT',
                                'reason' => "Cancellation of Sales Return {$return->return_no} (Stock reversed)",
                                'productId' => $variantId ?? $productId,
                                'variantId' => $variantId,
                                'qtyBase' => (int) round($qty * 1000.0),
                                'balanceAfter' => (int) $newBalance,
                                'outletId' => $outletId,
                                'businessId' => $businessId,
                                'referenceId' => $return->id,
                                'createdAt' => now()->toIso8601String(),
                            ],
                        ],
                    ]);
                }
            } elseif ($return->isPurchaseReturn()) {
                // Re-add stock that was returned to supplier (+qty)
                $newBalance = $this->adjustStockBalance($businessId, $outletId, $productId, $variantId, $qty);

                StockMovement::create([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'type' => 'adjustment',
                    'qty_change' => $qty,
                    'balance_after' => $newBalance / 1000.0,
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'condition' => $item->condition,
                    'note' => "Cancellation of Purchase Return {$return->return_no} (Stock restored)",
                    'created_by' => $user->id,
                    'payload' => [
                        'fields' => [
                            'type' => 'RETURN',
                            'reason' => "Cancellation of Purchase Return {$return->return_no} (Stock restored)",
                            'productId' => $variantId ?? $productId,
                            'variantId' => $variantId,
                            'qtyBase' => (int) round($qty * 1000.0),
                            'balanceAfter' => (int) $newBalance,
                            'outletId' => $outletId,
                            'businessId' => $businessId,
                            'referenceId' => $return->id,
                            'createdAt' => now()->toIso8601String(),
                        ],
                    ],
                ]);
            }
        }
    }

    /**
     * Compensating ledger reversals on cancellation.
     */
    protected function reverseLedgerEffects(ReturnModel $return, User $user): void
    {
        $businessId = $return->business_id;
        $partyId = $return->party_id;
        $grandTotal = (float) $return->grand_total;
        $now = Carbon::now();

        if ($return->isSaleReturn()) {
            if (in_array($return->refund_mode, ['adjust_to_ledger', 'credit_note'], true)) {
                $balAfter = $this->updateCustomerBalance($businessId, $partyId, $grandTotal);

                LedgerEntry::create([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'party_type' => 'customer',
                    'party_id' => $partyId,
                    'type' => 'debit',
                    'amount' => $grandTotal,
                    'balance_after' => $balAfter,
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'date' => $now->toDateString(),
                    'note' => "Reversal of Credit Note {$return->return_no}",
                    'is_reversal' => true,
                    'created_by' => $user->id,
                ]);
            }
        } elseif ($return->isPurchaseReturn()) {
            if (in_array($return->refund_mode, ['adjust_to_ledger', 'credit_note'], true)) {
                $balAfter = $this->updateSupplierBalance($businessId, $partyId, $grandTotal);

                LedgerEntry::create([
                    'id' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'party_type' => 'supplier',
                    'party_id' => $partyId,
                    'type' => 'credit',
                    'amount' => $grandTotal,
                    'balance_after' => $balAfter,
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'date' => $now->toDateString(),
                    'note' => "Reversal of Debit Note {$return->return_no}",
                    'is_reversal' => true,
                    'created_by' => $user->id,
                ]);
            }
        }
    }

    /**
     * Increment returned_qty on original bill items.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function updateOriginalBillReturnedQuantities(string $type, array $items): void
    {
        foreach ($items as $item) {
            $originalItemId = $item['original_bill_item_id'] ?? null;
            $qty = (float) $item['qty_returned'];

            if (! empty($originalItemId)) {
                $tableName = ($type === 'sale_return') ? 'invoice_items' : 'purchase_bill_items';

                if (DB::getSchemaBuilder()->hasTable($tableName)) {
                    $row = DB::table($tableName)->where('id', $originalItemId)->first();
                    if ($row) {
                        $updateData = [];
                        if (DB::getSchemaBuilder()->hasColumn($tableName, 'returned_qty')) {
                            $updateData['returned_qty'] = DB::raw("returned_qty + {$qty}");
                        }

                        if (! empty($row->payload)) {
                            $payload = json_decode($row->payload, true) ?: [];
                            $fields = $payload['fields'] ?? [];
                            $fields['returnedQty'] = ((float) ($fields['returnedQty'] ?? 0)) + $qty;
                            $payload['fields'] = $fields;
                            $updateData['payload'] = json_encode($payload);
                        }

                        if (! empty($updateData)) {
                            DB::table($tableName)->where('id', $originalItemId)->update($updateData);
                        }
                    }
                }
            }
        }
    }

    /**
     * Deduct returned_qty on original bill items when return is cancelled.
     */
    protected function reverseOriginalBillReturnedQuantities(ReturnModel $return): void
    {
        $tableName = ($return->type === 'sale_return') ? 'invoice_items' : 'purchase_bill_items';

        if (! DB::getSchemaBuilder()->hasTable($tableName)) {
            return;
        }

        foreach ($return->items as $item) {
            $originalItemId = $item->original_bill_item_id;
            $qty = (float) $item->qty_returned;

            if (! empty($originalItemId)) {
                $row = DB::table($tableName)->where('id', $originalItemId)->first();
                if ($row) {
                    $updateData = [];
                    if (DB::getSchemaBuilder()->hasColumn($tableName, 'returned_qty')) {
                        $updateData['returned_qty'] = DB::raw("CASE WHEN returned_qty >= {$qty} THEN returned_qty - {$qty} ELSE 0 END");
                    }

                    if (! empty($row->payload)) {
                        $payload = json_decode($row->payload, true) ?: [];
                        $fields = $payload['fields'] ?? [];
                        $fields['returnedQty'] = max(0.0, ((float) ($fields['returnedQty'] ?? 0)) - $qty);
                        $payload['fields'] = $fields;
                        $updateData['payload'] = json_encode($payload);
                    }

                    if (! empty($updateData)) {
                        DB::table($tableName)->where('id', $originalItemId)->update($updateData);
                    }
                }
            }
        }
    }

    /**
     * Adjust stock balance row in database and return updated balance in milli-units (thousandths).
     */
    protected function adjustStockBalance(string $businessId, ?string $outletId, string $productId, ?string $variantId, float $qtyChange): float
    {
        if (! DB::getSchemaBuilder()->hasTable('stock_balances')) {
            return $qtyChange * 1000.0;
        }

        // In NubiBilling, stock_balances stores thousandths of a unit (1 unit = 1000 milli-units).
        $milliDelta = (int) round($qtyChange * 1000.0);

        $query = DB::table('stock_balances')
            ->where('business_id', $businessId);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $row = $query->where(function ($q) use ($productId, $variantId) {
            if ($variantId) {
                $q->where('id', 'LIKE', "%_{$variantId}")
                    ->orWhere('payload->fields->variantId', $variantId);
            }
            $q->orWhere('payload->fields->productId', $productId);
        })->lockForUpdate()->first();

        if ($row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];
            $curStock = (float) ($fields['quantityOnHand'] ?? $fields['currentStock'] ?? 0.0);
            if ($curStock > 0 && $curStock < 500) {
                $curStock = $curStock * 1000.0;
            } elseif ($curStock >= 1000) {
                $rem = fmod($curStock, 1000.0);
                if ($rem > 0 && $rem < 50) {
                    $curStock = (floor($curStock / 1000.0) + $rem) * 1000.0;
                }
            }
            $newStock = max(0.0, $curStock + $milliDelta);

            $fields['quantityOnHand'] = (int) round($newStock);
            $fields['currentStock'] = (int) round($newStock);
            $fields['updatedAt'] = Carbon::now()->toIso8601String();
            $payload['fields'] = $fields;

            DB::table('stock_balances')->where('id', $row->id)->update([
                'updated_at' => Carbon::now()->toDateTimeString(),
                'payload' => json_encode($payload),
            ]);

            return $newStock;
        }

        // If no stock balance row exists yet, create one in milli-units
        $newStock = max(0.0, (float) $milliDelta);
        $vId = $variantId ?? $productId;
        $now = Carbon::now();
        $balanceId = "{$businessId}_OUTLET_{$outletId}_DIRECT_{$vId}";

        $balanceFields = [
            'tenantId' => $businessId,
            'businessId' => $businessId,
            'outletId' => $outletId,
            'productId' => $productId,
            'variantId' => $vId,
            'quantityOnHand' => (int) round($newStock),
            'currentStock' => (int) round($newStock),
            'avgCostPaise' => 0,
            'lastPurchasePricePaise' => 0,
            'updatedAt' => $now->toIso8601String(),
        ];

        $insertData = [
            'id' => $balanceId,
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'status' => 'ACTIVE',
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
            'is_deleted' => 0,
            'payload' => json_encode([
                '_documentId' => $balanceId,
                '_documentPath' => "stock_balances/{$balanceId}",
                'fields' => $balanceFields,
            ]),
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'document_path')) {
            $insertData['document_path'] = "stock_balances/{$balanceId}";
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'product_id')) {
            $insertData['product_id'] = $productId;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'variant_id')) {
            $insertData['variant_id'] = $vId;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
            $insertData['quantity_on_hand'] = (int) round($newStock);
        }

        DB::table('stock_balances')->insert($insertData);

        return $newStock;
    }

    /**
     * Get current stock balance for a product/variant.
     */
    protected function getStockBalance(string $businessId, ?string $outletId, string $productId, ?string $variantId): float
    {
        if (! DB::getSchemaBuilder()->hasTable('stock_balances')) {
            return 0.0;
        }

        $query = DB::table('stock_balances')
            ->where('business_id', $businessId);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $row = $query->where(function ($q) use ($productId, $variantId) {
            if ($variantId) {
                $q->where('id', 'LIKE', "%_{$variantId}")
                    ->orWhere('payload->fields->variantId', $variantId);
            }
            $q->orWhere('payload->fields->productId', $productId);
        })->first();

        if ($row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];

            return (float) ($fields['quantityOnHand'] ?? $fields['currentStock'] ?? 0.0);
        }

        return 0.0;
    }

    /**
     * Update customer balance record and return balance after.
     */
    protected function updateCustomerBalance(string $businessId, string $customerId, float $amountChange): ?float
    {
        if (! DB::getSchemaBuilder()->hasTable('customer_balances')) {
            return null;
        }

        $balRow = DB::table('customer_balances')
            ->where('business_id', $businessId)
            ->where(function ($q) use ($customerId) {
                $q->where('id', $customerId)
                    ->orWhere('payload->fields->customerId', $customerId);
            })
            ->lockForUpdate()
            ->first();

        if ($balRow) {
            $payload = json_decode($balRow->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];
            $curDue = ((float) ($fields['duePaise'] ?? 0)) / 100.0;
            $newDue = max(0.0, $curDue + $amountChange);

            $fields['duePaise'] = (int) round($newDue * 100);
            $fields['updatedAt'] = Carbon::now()->toIso8601String();
            $payload['fields'] = $fields;

            DB::table('customer_balances')->where('id', $balRow->id)->update([
                'updated_at' => Carbon::now()->toDateTimeString(),
                'payload' => json_encode($payload),
            ]);

            return $newDue;
        }

        return null;
    }

    /**
     * Update supplier balance record and return balance after.
     */
    protected function updateSupplierBalance(string $businessId, string $supplierId, float $amountChange): ?float
    {
        if (! DB::getSchemaBuilder()->hasTable('supplier_balances')) {
            return null;
        }

        $balRow = DB::table('supplier_balances')
            ->where('business_id', $businessId)
            ->where(function ($q) use ($supplierId) {
                $q->where('id', $supplierId)
                    ->orWhere('payload->fields->supplierId', $supplierId);
            })
            ->lockForUpdate()
            ->first();

        if ($balRow) {
            $payload = json_decode($balRow->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];
            $curBal = ((float) ($fields['balance'] ?? $fields['payableAmount'] ?? 0));
            $newBal = max(0.0, $curBal + $amountChange);

            $fields['balance'] = $newBal;
            $fields['payableAmount'] = $newBal;
            $fields['updatedAt'] = Carbon::now()->toIso8601String();
            $payload['fields'] = $fields;

            DB::table('supplier_balances')->where('id', $balRow->id)->update([
                'updated_at' => Carbon::now()->toDateTimeString(),
                'payload' => json_encode($payload),
            ]);

            return $newBal;
        }

        return null;
    }

    /**
     * Generate consecutive return number per user and type (e.g. SR-0001 or PR-0001).
     */
    protected function generateReturnNumber(string $userId, string $businessId, string $type): string
    {
        $prefix = ($type === 'sale_return') ? 'SR' : 'PR';

        $count = ReturnModel::where('user_id', $userId)
            ->where('type', $type)
            ->count();

        $nextSeq = $count + 1;

        return sprintf('%s-%04d', $prefix, $nextSeq);
    }
}
