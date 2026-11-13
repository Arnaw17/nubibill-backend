<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\SendFcmNotificationJob;
use App\Services\EmployeePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    /**
     * Get stock balances for an outlet / business.
     */
    public function stock(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $variantId = $request->query('variant_id') ?? $request->query('variantId');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $stockSvc = new \App\Services\InventoryStockService;
        $snapshot = $stockSvc->getConsolidatedStockSnapshot(
            (string) $businessId,
            $outletId ? (string) $outletId : null,
            $variantId ? (string) $variantId : null
        );

        return ApiResponse::success([
            'stockMap' => $snapshot['stockMap'],
            'parentStockMap' => $snapshot['parentStockMap'],
            'parentStockUnitsMap' => $snapshot['parentStockUnitsMap'],
            'valuationMap' => $snapshot['valuationMap'],
            'reconciliationMap' => $snapshot['reconciliationMap'],
            'balances' => $snapshot['balances'],
        ], 'Stock balances retrieved successfully.');
    }

    /**
     * Recalculate & reconcile CFT Sand/Gravel inventory balances from verified transactions.
     */
    public function reconcileCft(Request $request): JsonResponse
    {
        $user = $request->user();
        $businessId = $request->input('business_id') ?? $request->input('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->input('outlet_id') ?? $request->input('outletId') ?? $request->header('X-Outlet-Id');
        $productId = $request->input('product_id') ?? $request->input('productId');
        $apply = $request->has('apply') ? filter_var($request->input('apply'), FILTER_VALIDATE_BOOLEAN) : true;

        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }
        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('Unauthorized business context.', 403, null, 'FORBIDDEN');
        }

        $stockSvc = new \App\Services\InventoryStockService;
        $result = $stockSvc->reconcileCftInventory(
            (string) $businessId,
            $outletId ? (string) $outletId : null,
            $apply,
            $productId ? (string) $productId : null
        );

        return ApiResponse::success($result, 'CFT inventory reconciliation completed.');
    }

    /**
     * Apply a stock movement / adjustment.
     */
    public function recordMovement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'required|string',
            'outletId' => 'nullable|string',
            'variantId' => 'nullable|string',
            'productId' => 'required|string',
            'type' => 'required|string|in:RECEIVE,OPENING_STOCK,ADJUST_IN,ADJUST_OUT,DAMAGE_LOSS,COUNT_CORRECTION,SALE,RETURN,receive,openingStock,adjustIn,adjustOut,damageLoss,countCorrection,sale,returnStock',
            'qtyBase' => 'required|integer|min:0',
            'reason' => 'required|string|max:500',
            'unitCostPaise' => 'nullable|integer|min:0',
            'referenceId' => 'nullable|string|max:100',
            'stockBalanceId' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('A valid, authorized business context is required to record stock movement.', 403, null, 'FORBIDDEN');
        }

        $outletId = $validated['outlet_id'] ?? $validated['outletId'];
        $variantId = $validated['variantId'] ?? $validated['productId'];
        $productId = $validated['productId'];
        $qtyBase = (int) $validated['qtyBase'];
        $reason = trim($validated['reason']);
        $unitCostPaise = isset($validated['unitCostPaise']) ? (int) $validated['unitCostPaise'] : null;
        $referenceId = $validated['referenceId'] ?? 'MANUAL_STOCK_ACTION';

        // Normalize type
        $typeMap = [
            'RECEIVE' => 'RECEIVE',
            'OPENING_STOCK' => 'OPENING_STOCK',
            'ADJUST_IN' => 'ADJUST_IN',
            'ADJUST_OUT' => 'ADJUST_OUT',
            'DAMAGE_LOSS' => 'DAMAGE_LOSS',
            'COUNT_CORRECTION' => 'COUNT_CORRECTION',
            'SALE' => 'SALE',
            'RETURN' => 'RETURN',
            'receive' => 'RECEIVE',
            'openingStock' => 'OPENING_STOCK',
            'adjustIn' => 'ADJUST_IN',
            'adjustOut' => 'ADJUST_OUT',
            'damageLoss' => 'DAMAGE_LOSS',
            'countCorrection' => 'COUNT_CORRECTION',
            'sale' => 'SALE',
            'returnStock' => 'RETURN',
        ];
        $type = $typeMap[$validated['type']] ?? strtoupper($validated['type']);

        $permissionService = app(EmployeePermissionService::class);
        $requiredPermission = $type === 'OPENING_STOCK'
            ? ['inventory.adjust', 'inventory.opening_stock']
            : ['inventory.adjust'];
        if (! $permissionService->isAuthorized($user, $requiredPermission)) {
            return ApiResponse::error('You do not have permission to perform this stock operation.', 403, null, 'PERMISSION_DENIED');
        }

        if (in_array($type, ['RECEIVE', 'OPENING_STOCK'], true) && ($unitCostPaise === null || $unitCostPaise <= 0)) {
            return ApiResponse::error('Enter a valid unit cost for received or opening stock.', 422, null, 'VALIDATION_FAILED');
        }

        if ($qtyBase <= 0 && $type !== 'COUNT_CORRECTION') {
            return ApiResponse::error('Stock quantity must be greater than zero.', 422, null, 'VALIDATION_FAILED');
        }

        return DB::transaction(function () use ($businessId, $outletId, $variantId, $productId, $type, $qtyBase, $reason, $unitCostPaise, $referenceId, $userId) {
            $now = now()->toIso8601String();
            $nowDateTime = now();
            $stockSvc = new \App\Services\InventoryStockService;
            $meta = $productId !== '' ? $stockSvc->resolveProductMeta($businessId, $productId) : [
                'behaviour' => \App\Services\InventoryStockService::BEHAVIOUR_SEPARATE_VARIANT,
                'baseUnitId' => 'UNIT',
            ];
            $invBehaviour = $meta['behaviour'];
            $target = $stockSvc->inspectStockTarget($businessId, $outletId, $productId, $variantId, $invBehaviour);
            $targetVariantId = $target['canonicalVariantId'] ?: $variantId;
            $balanceRow = $target['canonicalRow'];

            // Unique balance ID format
            $balanceId = $balanceRow
                ? (string) $balanceRow->id
                : "{$userId}_{$businessId}_OUTLET_{$outletId}_DIRECT_{$targetVariantId}";

            $currentQty = $target['totalMilli'];
            $currentAvgCost = $target['weightedAvgCostPaise'];
            $currentLastPurchasePrice = 0;

            if ($balanceRow) {
                $payload = json_decode($balanceRow->payload, true) ?: [];
                $fields = $payload['fields'] ?? $payload;
                $currentAvgCost = $currentAvgCost > 0 ? $currentAvgCost : (int) ($fields['avgCostPaise'] ?? $fields['costPrice'] ?? 0);
                $currentLastPurchasePrice = (int) ($fields['lastPurchasePricePaise'] ?? 0);
            }

            $existingMovement = DB::table('stock_movements')
                ->where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->where(function ($q) use ($targetVariantId, $variantId) {
                    $q->where('payload->fields->variantId', $targetVariantId)
                        ->orWhere('payload->fields->variantId', $variantId);
                })
                ->where('payload->fields->referenceId', $referenceId)
                ->where('is_deleted', 0)
                ->first();
            if ($existingMovement) {
                $existingPayload = json_decode($existingMovement->payload, true) ?: [];
                $existingFields = $existingPayload['fields'] ?? $existingPayload;

                return ApiResponse::success([
                    'movementId' => (string) $existingMovement->id,
                    'balanceAfter' => (int) ($existingFields['balanceAfter'] ?? $currentQty),
                    'previousQuantity' => (int) ($existingFields['previousQuantity'] ?? 0),
                    'changedQuantity' => (int) ($existingFields['qtyBase'] ?? 0),
                    'type' => $existingFields['type'] ?? $type,
                    'variantId' => $targetVariantId,
                    'outletId' => $outletId,
                    'avgCostPaise' => (int) ($existingFields['avgCostPaise'] ?? $currentAvgCost),
                ], 'Stock movement was already recorded.');
            }

            if ($type === 'OPENING_STOCK') {
                $purchaseReceiptExists = DB::table('purchase_bill_items')
                    ->where('business_id', $businessId)
                    ->where('outlet_id', $outletId)
                    ->where('is_deleted', 0)
                    ->where('status', 'APPROVED')
                    ->where(function ($q) use ($variantId, $productId, $stockSvc, $invBehaviour) {
                        $q->where('payload->fields->variantId', $variantId);
                        if ($stockSvc->isUnifiedBehaviour($invBehaviour) && $productId !== '') {
                            $q->orWhere('payload->fields->productId', $productId);
                        }
                    })
                    ->exists();
                $priorInitializationExists = DB::table('stock_movements')
                    ->where('business_id', $businessId)
                    ->where('outlet_id', $outletId)
                    ->where(function ($q) use ($variantId, $targetVariantId, $productId, $stockSvc, $invBehaviour) {
                        $q->where('payload->fields->variantId', $variantId)
                            ->orWhere('payload->fields->variantId', $targetVariantId);
                        if ($stockSvc->isUnifiedBehaviour($invBehaviour) && $productId !== '') {
                            $q->orWhere('product_id', $productId);
                        }
                    })
                    ->where('is_deleted', 0)
                    ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.type'))"), [
                        'OPENING_STOCK', 'RECEIVE', 'ADJUST_IN', 'RETURN', 'COUNT_CORRECTION',
                    ])
                    ->exists();
                if ($currentQty > 0 || $purchaseReceiptExists || $priorInitializationExists) {
                    return ApiResponse::error('Opening stock can only be registered before the first stock receipt.', 409, null, 'STOCK_ALREADY_INITIALIZED');
                }
            }

            // Calculate balance after
            $balanceAfter = match ($type) {
                'RECEIVE', 'OPENING_STOCK', 'ADJUST_IN', 'RETURN' => $currentQty + $qtyBase,
                'ADJUST_OUT', 'DAMAGE_LOSS', 'SALE' => max(0, $currentQty - $qtyBase),
                'COUNT_CORRECTION' => $qtyBase,
                default => $currentQty,
            };

            // Calculate weighted average cost if RECEIVE
            $avgCost = $currentAvgCost;
            $lastPurchasePrice = $currentLastPurchasePrice;
            if (in_array($type, ['RECEIVE', 'OPENING_STOCK'], true)) {
                $lastPurchasePrice = $unitCostPaise;
                $totalUnits = $currentQty + $qtyBase;
                if ($totalUnits > 0) {
                    $totalCost = ($currentQty * $currentAvgCost) + ($qtyBase * $unitCostPaise);
                    $avgCost = (int) round($totalCost / $totalUnits);
                } else {
                    $avgCost = $unitCostPaise;
                }
            }

            // Record movement
            $movementId = 'STM-'.strtoupper(Str::random(24));
            $movementFields = [
                'tenantId' => $userId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'productId' => $productId,
                'variantId' => $variantId,
                'type' => $type,
                'qtyBase' => $qtyBase,
                'unitCostPaise' => $unitCostPaise,
                'reason' => $reason,
                'referenceId' => $referenceId,
                'userId' => $userId,
                'createdAt' => $now,
                'balanceAfter' => $balanceAfter,
                'previousQuantity' => $currentQty,
                'immutable' => true,
                'avgCostPaise' => $avgCost,
            ];

            $signedQtyUnits = round(($balanceAfter - $currentQty) / 1000.0, 3);
            $balAfterUnits = round($balanceAfter / 1000.0, 3);
            $movementFields['variantId'] = $targetVariantId;
            $movementFields['inventoryBehaviour'] = $invBehaviour;
            $movInsert = [
                'id' => $movementId,
                'document_path' => "stock_movements/{$movementId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'product_id' => $productId,
                'variant_id' => $targetVariantId,
                'type' => strtolower($type),
                'qty_change' => $signedQtyUnits,
                'balance_after' => $balAfterUnits,
                'reference_type' => strtolower($type),
                'reference_id' => $referenceId ?: $movementId,
                'condition' => $type === 'DAMAGE_LOSS' ? 'damaged' : 'good',
                'note' => $reason,
                'created_by' => $userId,
                'created_at' => $nowDateTime,
                'updated_at' => $nowDateTime,
                'is_deleted' => 0,
                'payload' => json_encode([
                    '_documentId' => $movementId,
                    '_documentPath' => "stock_movements/{$movementId}",
                    'fields' => $movementFields,
                ]),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'inventory_behaviour')) {
                $movInsert['inventory_behaviour'] = $invBehaviour;
            }
            DB::table('stock_movements')->insert($movInsert);

            $totalValueAfter = $avgCost > 0 ? (int) round(($balanceAfter / 1000.0) * $avgCost) : 0;

            // Update or create stock balance
            $balanceFields = [
                'tenantId' => $userId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'productId' => $productId,
                'variantId' => $targetVariantId,
                'inventoryBehaviour' => $invBehaviour,
                'baseUnitId' => $meta['baseUnitId'] ?? null,
                'quantityOnHand' => $balanceAfter,
                'currentStock' => $balanceAfter,
                'stockScale' => 'MILLI',
                'totalValue' => $totalValueAfter,
                'costPrice' => $avgCost,
                'weightedAvgCost' => $avgCost,
                'avgCostPaise' => $avgCost,
                'lastPurchasePricePaise' => $lastPurchasePrice,
                'updatedAt' => $now,
            ];

            if ($balanceRow) {
                $sbUpd = [
                    'updated_at' => $nowDateTime,
                    'payload' => json_encode([
                        '_documentId' => $balanceId,
                        '_documentPath' => "stock_balances/{$balanceId}",
                        'fields' => $balanceFields,
                    ]),
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
                    $sbUpd['quantity_on_hand'] = $balAfterUnits;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'product_id')) {
                    $sbUpd['product_id'] = $productId;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'variant_id')) {
                    $sbUpd['variant_id'] = $targetVariantId;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'inventory_behaviour')) {
                    $sbUpd['inventory_behaviour'] = $invBehaviour;
                }
                DB::table('stock_balances')->where('id', $balanceId)->update($sbUpd);
            } else {
                $sbIns = [
                    'id' => $balanceId,
                    'document_path' => "stock_balances/{$balanceId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'ACTIVE',
                    'created_at' => $nowDateTime,
                    'updated_at' => $nowDateTime,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        '_documentId' => $balanceId,
                        '_documentPath' => "stock_balances/{$balanceId}",
                        'fields' => $balanceFields,
                    ]),
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
                    $sbIns['quantity_on_hand'] = $balAfterUnits;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'product_id')) {
                    $sbIns['product_id'] = $productId;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'variant_id')) {
                    $sbIns['variant_id'] = $targetVariantId;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('stock_balances', 'inventory_behaviour')) {
                    $sbIns['inventory_behaviour'] = $invBehaviour;
                }
                DB::table('stock_balances')->insert($sbIns);
            }

            // Low stock or Damage alert to owner
            try {
                $pName = (string) ($meta['fields']['productName'] ?? $meta['fields']['name'] ?? 'Product');
                $reorder = (float) ($meta['fields']['reorderLevel'] ?? $meta['fields']['reorder_level'] ?? 5);

                if ($type === 'DAMAGE_LOSS') {
                    $lossUnits = $unitService->fromMilliUnits($qtyBase);
                    SendFcmNotificationJob::dispatch(
                        null,
                        null,
                        'Stock Damage / Loss Recorded',
                        "{$lossUnits} units of '{$pName}' written off as damaged. Reason: {$reason}.",
                        [
                            'target_screen' => 'inventory',
                            'type' => 'stock_damage',
                            'product_id' => (string) $productId,
                            'business_id' => (string) $businessId,
                        ],
                        (string) $businessId
                    );
                }

                if (in_array($type, ['ADJUST_OUT', 'DAMAGE_LOSS', 'SALE'], true) && $balAfterUnits <= $reorder) {
                    $isZero = $balAfterUnits <= 0;
                    $alertTitle = $isZero ? 'Out of Stock Alert' : 'Low Stock Alert';
                    $alertBody = $isZero
                        ? "'{$pName}' is now completely out of stock!"
                        : "'{$pName}' is running low: {$balAfterUnits} remaining (Reorder level: {$reorder}).";

                    SendFcmNotificationJob::dispatch(
                        null,
                        null,
                        $alertTitle,
                        $alertBody,
                        [
                            'target_screen' => 'inventory',
                            'type' => $isZero ? 'out_of_stock' : 'low_stock',
                            'product_id' => (string) $productId,
                            'business_id' => (string) $businessId,
                        ],
                        (string) $businessId
                    );
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to dispatch inventory notification: '.$e->getMessage());
            }

            return ApiResponse::success([
                'movementId' => $movementId,
                'balanceAfter' => $balanceAfter,
                'balanceAfterUnits' => $balAfterUnits,
                'previousQuantity' => $currentQty,
                'changedQuantity' => $qtyBase,
                'type' => $type,
                'productId' => $productId,
                'variantId' => $targetVariantId,
                'inventoryBehaviour' => $invBehaviour,
                'outletId' => $outletId,
                'avgCostPaise' => $avgCost,
            ], "Stock movement '{$type}' recorded successfully.", 201);
        });
    }

    /**
     * Get stock movement audit history.
     */
    public function movements(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $variantId = $request->query('variant_id') ?? $request->query('variantId');
        $type = $request->query('type');
        $limit = min(200, max(1, (int) ($request->query('limit') ?? 50)));

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('stock_movements')
            ->where('business_id', $businessId);

        if (\Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'is_deleted')) {
            $query->where('is_deleted', 0);
        }

        if ($outletId && $outletId !== 'ALL') {
            $query->where('outlet_id', $outletId);
        }
        $productFilterId = $request->query('product_id') ?? $request->query('productId');
        if ($productFilterId) {
            $query->where(function ($q) use ($productFilterId) {
                $q->where('product_id', $productFilterId)
                    ->orWhere('payload->fields->productId', $productFilterId);
            });
        } elseif ($variantId) {
            $query->where(function ($q) use ($variantId) {
                $q->where('variant_id', $variantId)
                    ->orWhere('payload->fields->variantId', $variantId)
                    ->orWhere('product_id', $variantId);
            });
        }

        $rows = $query->orderBy('created_at', 'desc')->limit($limit)->get();

        $variantsMap = [];
        try {
            $varRows = DB::table('product_variants')->where('business_id', $businessId)->get();
            foreach ($varRows as $vr) {
                $vp = json_decode($vr->payload ?? '{}', true) ?: [];
                $vf = $vp['fields'] ?? [];
                $pName = $vf['productName'] ?? 'Ace Suprema';
                $vName = $vf['variantName'] ?? $vf['name'] ?? '';
                $displayName = $vName ? "$pName ($vName)" : $pName;
                $variantsMap[$vr->id] = $displayName;
            }
            $prodRows = DB::table('products')->where('business_id', $businessId)->get();
            foreach ($prodRows as $pr) {
                $pp = json_decode($pr->payload ?? '{}', true) ?: [];
                $pf = $pp['fields'] ?? [];
                $pName = $pf['productName'] ?? $pf['name'] ?? $pr->product_name ?? 'Ace Suprema 20L';
                if (! isset($variantsMap[$pr->id])) {
                    $variantsMap[$pr->id] = $pName;
                }
            }
        } catch (\Throwable $e) {}

        $movements = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $vId = $fields['variantId'] ?? $row->variant_id ?? $fields['productId'] ?? $row->product_id ?? null;
            $rowProductId = $fields['productId'] ?? $row->product_id ?? $vId;
            if ($productFilterId && $rowProductId !== $productFilterId) {
                continue;
            }
            if (! $productFilterId && $variantId && $vId !== $variantId) {
                continue;
            }

            $mType = strtoupper((string) ($fields['type'] ?? $row->type ?? 'MOVEMENT'));
            if ($mType === 'SALE_RETURN') {
                $mType = 'RETURN';
            }
            if ($type && strtoupper($type) !== $mType) {
                continue;
            }

            $qty = (float) ($row->qty_change ?? $fields['qty_change'] ?? 0);
            if ($qty == 0 && isset($fields['qtyBase'])) {
                $rawQty = (float) $fields['qtyBase'];
                $qty = abs($rawQty) >= 1000 ? ($rawQty / 1000.0) : $rawQty;
            }
            $bal = (float) ($row->balance_after ?? $fields['balance_after'] ?? 0);
            if ($bal == 0 && isset($fields['balanceAfter'])) {
                $rawBal = (float) $fields['balanceAfter'];
                $bal = $rawBal >= 1000 ? ($rawBal / 1000.0) : $rawBal;
            }

            $reason = (string) ($fields['reason'] ?? $row->note ?? $fields['note'] ?? $row->reference_id ?? '—');

            $movements[] = [
                'movementId' => (string) $row->id,
                'id' => (string) $row->id,
                'businessId' => (string) $row->business_id,
                'outletId' => (string) $row->outlet_id,
                'variantId' => $vId,
                'productId' => $fields['productId'] ?? $row->product_id ?? $vId,
                'productName' => $variantsMap[$vId] ?? $variantsMap[$rowProductId] ?? ($fields['productName'] ?? 'Product'),
                'type' => $mType,
                'inventoryBehaviour' => $fields['inventoryBehaviour'] ?? ($row->inventory_behaviour ?? null),
                'purchaseOptionId' => $fields['purchaseOptionId'] ?? ($row->purchase_option_id ?? null),
                'purchaseOptionName' => $fields['purchaseOptionName'] ?? ($row->purchase_option_name ?? null),
                'conversionFactor' => isset($fields['conversionFactor']) ? (float) $fields['conversionFactor'] : null,
                'effectiveBaseUnitCost' => isset($fields['effectiveBaseUnitCost'])
                    ? (int) $fields['effectiveBaseUnitCost']
                    : (isset($row->effective_base_unit_cost) ? (int) $row->effective_base_unit_cost : null),
                'qtyBase' => (int) round($qty),
                'quantity' => $qty,
                'balanceAfter' => (int) round($bal),
                'balance' => $bal,
                'unitCostPaise' => isset($fields['unitCostPaise']) ? (int) $fields['unitCostPaise'] : null,
                'reason' => $reason,
                'referenceId' => $fields['referenceId'] ?? $row->reference_id ?? null,
                'userId' => $fields['userId'] ?? $row->created_by ?? null,
                'createdAt' => $row->created_at ? date('Y-m-d\TH:i:s\Z', strtotime($row->created_at)) : null,
            ];
        }

        return ApiResponse::success($movements, 'Stock movement history retrieved.');
    }

    /**
     * List inventory batches.
     */
    public function batches(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $variantId = $request->query('variant_id') ?? $request->query('variantId');
        $status = strtoupper((string) ($request->query('status') ?? 'ALL'));

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('inventory_batches')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        if ($status !== 'ALL') {
            $query->where('status', $status);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $batches = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $vId = $fields['variantId'] ?? $fields['productId'] ?? null;
            if ($variantId && $vId !== $variantId) {
                continue;
            }

            $origQty = (float) ($row->quantity_initial ?? $fields['originalQuantity'] ?? ($fields['receivedQty'] ?? 0));
            $availQty = (float) ($row->quantity_available ?? $fields['availableQuantity'] ?? ($fields['availableQty'] ?? 0));
            $batchNo = $row->batch_number ?? $fields['batchNumber'] ?? $fields['internalBatchNo'] ?? $fields['supplierLotNo'] ?? null;
            $purchaseDate = $row->purchase_date ?? $fields['purchaseDate'] ?? null;
            $mfgDate = $row->mfg_date ?? $fields['mfgDate'] ?? $fields['manufacturingDate'] ?? null;
            $expDate = $row->expiry_date ?? $fields['expiryDate'] ?? null;
            $purchaseRate = (float) ($row->purchase_rate ?? $fields['purchaseRate'] ?? $fields['purchasePrice'] ?? 0);
            $isHistorical = (bool) ($row->is_historical ?? $fields['isHistorical'] ?? false);

            $batches[] = [
                'batchId' => (string) $row->id,
                'id' => (string) $row->id,
                'businessId' => (string) $row->business_id,
                'outletId' => (string) $row->outlet_id,
                'variantId' => $vId,
                'productId' => $fields['productId'] ?? $row->product_id ?? $vId,
                'batchNumber' => $batchNo,
                'internalBatchNo' => $batchNo,
                'supplierLotNo' => $fields['supplierLotNo'] ?? $batchNo,
                'barcode' => $fields['barcode'] ?? null,
                'purchaseRate' => $purchaseRate,
                'mrp' => (int) ($fields['mrp'] ?? 0),
                'sellingPrice' => (int) ($fields['sellingPrice'] ?? 0),
                'receivedQty' => $origQty,
                'originalQuantity' => $origQty,
                'availableQty' => $availQty,
                'availableQuantity' => $availQty,
                'soldQuantity' => (float) ($fields['soldQuantity'] ?? max(0, $origQty - $availQty)),
                'purchaseDate' => $purchaseDate,
                'purchaseBillId' => $row->purchase_bill_id ?? ($fields['purchaseBillId'] ?? null),
                'isHistorical' => $isHistorical,
                'is_historical' => $isHistorical ? 1 : 0,
                'status' => (string) ($row->status ?? $fields['status'] ?? ($availQty <= 0 ? 'EXHAUSTED' : 'ACTIVE')),
                'manufacturingDate' => $mfgDate,
                'expiryDate' => $expDate,
                'storageLocationId' => $fields['storageLocationId'] ?? null,
                'rackId' => $fields['rackId'] ?? null,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        }

        return ApiResponse::success($batches, 'Inventory batches retrieved.');
    }

    /**
     * Create an inventory batch.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'required|string',
            'outletId' => 'nullable|string',
            'variantId' => 'required|string',
            'productId' => 'nullable|string',
            'internalBatchNo' => 'nullable|string|max:100',
            'supplierLotNo' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'purchaseRate' => 'required|integer|min:0',
            'mrp' => 'nullable|integer|min:0',
            'sellingPrice' => 'nullable|integer|min:0',
            'receivedQty' => 'required|integer|min:1',
            'manufacturingDate' => 'nullable|string',
            'expiryDate' => 'nullable|string',
            'storageLocationId' => 'nullable|string',
            'rackId' => 'nullable|string',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('A valid, authorized business context is required to record stock movement.', 403, null, 'FORBIDDEN');
        }

        $outletId = $validated['outlet_id'] ?? $validated['outletId'];
        $variantId = $validated['variantId'];
        $productId = $validated['productId'] ?? $variantId;
        $receivedQty = (int) $validated['receivedQty'];
        $now = now()->toIso8601String();
        $nowDateTime = now();

        $batchId = 'BATCH_'.strtoupper(Str::random(24));
        $internalBatchNo = $validated['internalBatchNo'] ?? ('BAT-'.sprintf('%06d', rand(1, 999999)));

        $fields = [
            'batchId' => $batchId,
            'businessId' => $businessId,
            'outletId' => $outletId,
            'tenantId' => $userId,
            'variantId' => $variantId,
            'productId' => $productId,
            'internalBatchNo' => $internalBatchNo,
            'supplierLotNo' => $validated['supplierLotNo'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'purchaseRate' => (int) $validated['purchaseRate'],
            'mrp' => (int) ($validated['mrp'] ?? 0),
            'sellingPrice' => (int) ($validated['sellingPrice'] ?? 0),
            'receivedQty' => $receivedQty,
            'availableQty' => $receivedQty,
            'status' => 'AVAILABLE',
            'manufacturingDate' => $validated['manufacturingDate'] ?? null,
            'expiryDate' => $validated['expiryDate'] ?? null,
            'storageLocationId' => $validated['storageLocationId'] ?? $outletId,
            'rackId' => $validated['rackId'] ?? null,
            'isDeleted' => false,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];

        DB::table('inventory_batches')->insert([
            'id' => $batchId,
            'document_path' => "inventory_batches/{$batchId}",
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'status' => 'AVAILABLE',
            'created_at' => $nowDateTime,
            'updated_at' => $nowDateTime,
            'is_deleted' => 0,
            'payload' => json_encode([
                '_documentId' => $batchId,
                '_documentPath' => "inventory_batches/{$batchId}",
                'fields' => $fields,
            ]),
        ]);

        return ApiResponse::success($fields, "Inventory batch '{$internalBatchNo}' created successfully.", 201);
    }

    /**
     * List batch movements.
     */
    public function batchMovements(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $batchId = $request->query('batch_id') ?? $request->query('batchId');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('inventory_batch_movements')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $movements = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            if ($batchId && ($fields['batchId'] ?? '') !== $batchId) {
                continue;
            }

            $movements[] = [
                'movementId' => (string) $row->id,
                'id' => (string) $row->id,
                'batchId' => $fields['batchId'] ?? '',
                'businessId' => (string) $row->business_id,
                'outletId' => (string) $row->outlet_id,
                'variantId' => $fields['variantId'] ?? '',
                'productId' => $fields['productId'] ?? null,
                'movementType' => $fields['movementType'] ?? '',
                'quantity' => (int) ($fields['quantity'] ?? 0),
                'direction' => $fields['direction'] ?? 'IN',
                'referenceType' => $fields['referenceType'] ?? '',
                'referenceId' => $fields['referenceId'] ?? '',
                'status' => (string) ($row->status ?? 'POSTED'),
                'reason' => $fields['reason'] ?? '',
                'occurredAt' => $fields['occurredAt'] ?? $row->created_at,
                'createdAt' => $row->created_at,
            ];
        }

        return ApiResponse::success($movements, 'Batch movements retrieved.');
    }

    /**
     * Record a batch movement with atomic batch balance update.
     */
    public function recordBatchMovement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'required|string',
            'outletId' => 'nullable|string',
            'batchId' => 'required|string',
            'variantId' => 'required|string',
            'productId' => 'nullable|string',
            'movementType' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'direction' => 'required|string|in:IN,OUT,in,out',
            'referenceType' => 'required|string',
            'referenceId' => 'required|string',
            'referenceLineId' => 'nullable|string',
            'reason' => 'nullable|string',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('A valid, authorized business context is required to record stock movement.', 403, null, 'FORBIDDEN');
        }

        $outletId = $validated['outlet_id'] ?? $validated['outletId'];
        $batchId = $validated['batchId'];
        $variantId = $validated['variantId'];
        $productId = $validated['productId'] ?? $variantId;
        $movementType = strtoupper($validated['movementType']);
        $quantity = (int) $validated['quantity'];
        $direction = strtoupper($validated['direction']);
        $referenceType = $validated['referenceType'];
        $referenceId = $validated['referenceId'];
        $reason = $validated['reason'] ?? 'Manual batch adjustment';

        return DB::transaction(function () use ($businessId, $outletId, $batchId, $variantId, $productId, $movementType, $quantity, $direction, $referenceType, $referenceId, $reason, $validated, $userId) {
            $now = now()->toIso8601String();
            $nowDateTime = now();

            // Lock batch record
            $batchRow = DB::table('inventory_batches')
                ->where('id', $batchId)
                ->where('is_deleted', 0)
                ->lockForUpdate()
                ->first();

            if (! $batchRow) {
                return ApiResponse::error('Inventory batch not found.', 404, null, 'NOT_FOUND');
            }

            $bPayload = json_decode($batchRow->payload, true) ?: [];
            $bFields = $bPayload['fields'] ?? $bPayload;
            $currentAvailable = (int) ($bFields['availableQty'] ?? 0);

            if ($direction === 'OUT' && $currentAvailable < $quantity) {
                return ApiResponse::error("Insufficient batch quantity. Available: {$currentAvailable}, Requested: {$quantity}", 422, null, 'INSUFFICIENT_BATCH_STOCK');
            }

            $newAvailable = $direction === 'IN' ? ($currentAvailable + $quantity) : ($currentAvailable - $quantity);
            $batchStatus = $newAvailable <= 0 ? 'DEPLETED' : 'AVAILABLE';

            $bFields['availableQty'] = $newAvailable;
            $bFields['status'] = $batchStatus;
            $bFields['updatedAt'] = $now;

            DB::table('inventory_batches')->where('id', $batchId)->update([
                'status' => $batchStatus,
                'updated_at' => $nowDateTime,
                'payload' => json_encode([
                    '_documentId' => $batchId,
                    '_documentPath' => "inventory_batches/{$batchId}",
                    'fields' => $bFields,
                ]),
            ]);

            // Create batch movement record
            $movementId = 'BM-'.strtoupper(Str::random(24));
            $movementFields = [
                'movementId' => $movementId,
                'batchId' => $batchId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'variantId' => $variantId,
                'productId' => $productId,
                'movementType' => $movementType,
                'quantity' => $quantity,
                'direction' => $direction,
                'referenceType' => $referenceType,
                'referenceId' => $referenceId,
                'referenceLineId' => $validated['referenceLineId'] ?? null,
                'reason' => $reason,
                'status' => 'POSTED',
                'tenantId' => $userId,
                'createdByUid' => $userId,
                'occurredAt' => $now,
                'createdAt' => $now,
                'updatedAt' => $now,
                'isDeleted' => false,
            ];

            DB::table('inventory_batch_movements')->insert([
                'id' => $movementId,
                'document_path' => "inventory_batch_movements/{$movementId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'POSTED',
                'created_at' => $nowDateTime,
                'updated_at' => $nowDateTime,
                'is_deleted' => 0,
                'payload' => json_encode([
                    '_documentId' => $movementId,
                    '_documentPath' => "inventory_batch_movements/{$movementId}",
                    'fields' => $movementFields,
                ]),
            ]);

            return ApiResponse::success([
                'movementId' => $movementId,
                'batchId' => $batchId,
                'availableQty' => $newAvailable,
                'status' => $batchStatus,
                'direction' => $direction,
                'quantity' => $quantity,
            ], "Batch movement recorded. Remaining available: {$newAvailable}.", 201);
        });
    }
}
