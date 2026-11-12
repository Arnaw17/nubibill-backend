<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\UnitConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(private readonly UnitConversionService $unitService = new UnitConversionService) {}
    public static function extractInt($val, int $default = 0): int
    {
        if ($val === null) return $default;
        if (is_int($val)) return $val;
        if (is_numeric($val)) return (int) $val;
        if (is_array($val)) {
            if (isset($val['integerValue'])) return (int) $val['integerValue'];
            if (isset($val['doubleValue'])) return (int) round((float) $val['doubleValue']);
            if (isset($val['stringValue'])) return (int) $val['stringValue'];
        }
        return $default;
    }

    public static function extractFloat($val, float $default = 0.0): float
    {
        if ($val === null) return $default;
        if (is_float($val) || is_int($val)) return (float) $val;
        if (is_numeric($val)) return (float) $val;
        if (is_array($val)) {
            if (isset($val['doubleValue'])) return (float) $val['doubleValue'];
            if (isset($val['integerValue'])) return (float) $val['integerValue'];
            if (isset($val['stringValue'])) return (float) $val['stringValue'];
        }
        return $default;
    }

    public static function extractString($val, string $default = ''): string
    {
        if ($val === null) return $default;
        if (is_string($val)) return $val;
        if (is_array($val)) {
            if (isset($val['stringValue'])) return (string) $val['stringValue'];
            if (isset($val['integerValue'])) return (string) $val['integerValue'];
        }
        return (string) $val;
    }

    /**
     * List product IDs that have ever been placed at an outlet.
     */
    public function outletProductIds(Request $request, string $outletId): JsonResponse
    {
        $businessId = $request->query('business_id')
            ?? $request->query('businessId')
            ?? $request->header('X-Business-Id');
        $user = $request->user();

        if (! $businessId) {
            $outlet = DB::table('outlets')->where('id', $outletId)->first();
            $businessId = $outlet?->business_id;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness((string) $businessId))) {
            return ApiResponse::success(['productIds' => []], 'No active business selected or unauthorized.');
        }

        $rows = DB::table('outlet_products')
            ->where('business_id', $businessId)
            ->where('outlet_id', $outletId)
            ->get(['payload']);

        $productIds = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $productId = $payload['fields']['productId'] ?? null;
            if (is_string($productId) && $productId !== '') {
                $productIds[$productId] = true;
            }
        }

        return ApiResponse::success(['productIds' => array_keys($productIds)]);
    }

    /**
     * List products with their variants for a business / outlet.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user ? (string) $user->id : (string) ($request->header('X-User-Id') ?? 'USR-OWNER-001');

        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));
        $categoryId = $request->query('category_id') ?? $request->query('categoryId');
        $brandId = $request->query('brand_id') ?? $request->query('brandId');
        $statusFilter = strtoupper((string) ($request->query('status') ?? 'ACTIVE'));

        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        // 1. If outletId is provided, get list of product IDs placed in this outlet
        $outletProductIds = null;
        if ($outletId) {
            $placementRows = DB::table('outlet_products')
                ->where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->where('is_deleted', 0)
                ->get();

            $outletProductIds = [];
            foreach ($placementRows as $pRow) {
                $pPayload = json_decode($pRow->payload, true) ?: [];
                $pFields = $pPayload['fields'] ?? [];
                $prodId = $pFields['productId'] ?? null;
                if ($prodId) {
                    $outletProductIds[$prodId] = true;
                }
            }
        }

        // 2. Fetch products for this business
        $query = DB::table('products')
            ->where(function ($businessQuery) use ($businessId, $search) {
                $businessQuery->where('business_id', $businessId);
                if ($search !== '') {
                    $businessQuery->orWhereNotNull('master_product_id');
                }
            })
            ->where('is_deleted', 0);

        if ($search !== '') {
            $searchPattern = '%'.$search.'%';
            $query->where(function ($searchQuery) use ($searchPattern) {
                foreach (['product_name', 'brand_name', 'category_name', 'master_product_id'] as $column) {
                    $searchQuery->orWhere($column, 'like', $searchPattern);
                }

                foreach (['productName', 'name', 'productCode', 'brand', 'categoryName', 'category', 'subcategory'] as $field) {
                    $searchQuery->orWhere('payload->fields->'.$field, 'like', $searchPattern);
                }
            });
        }

        if ($statusFilter !== 'ALL') {
            $query->where('status', $statusFilter);
        }

        $productRows = $query->get();

        // 3. Fetch variants for this business
        $variantRows = DB::table('product_variants')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        $variantsByProduct = [];
        foreach ($variantRows as $vRow) {
            $vPayload = json_decode($vRow->payload, true) ?: [];
            $vFields = $vPayload['fields'] ?? [];
            $vProdId = (string) ($vFields['productId'] ?? '');
            if (! $vProdId) {
                continue;
            }

            $vId = (string) ($vFields['variantId'] ?? $vRow->id);

            $vSellingPrice = self::extractInt($vFields['sellingPrice'] ?? null, 0);
            $vPurchaseRate = self::extractInt($vFields['purchaseRate'] ?? null, 0);
            $vMrp = self::extractInt($vFields['mrp'] ?? null, 0);

            if ($vSellingPrice === 0 || $vPurchaseRate === 0) {
                $latestPbItem = DB::table('purchase_bill_items')
                    ->where('business_id', $businessId)
                    ->where(function ($q) use ($vId) {
                        $q->where('id', 'like', "%{$vId}%")
                            ->orWhere('payload->fields->variantId', $vId);
                    })
                    ->orderBy('created_at', 'desc')
                    ->first();
                if ($latestPbItem) {
                    $pbiPayload = json_decode($latestPbItem->payload, true) ?: [];
                    $pbiFields = $pbiPayload['fields'] ?? [];
                    if ($vSellingPrice === 0 && ! empty($pbiFields['sellingPrice'])) {
                        $vSellingPrice = self::extractInt($pbiFields['sellingPrice']);
                    }
                    if ($vMrp === 0 && ! empty($pbiFields['mrp'])) {
                        $vMrp = self::extractInt($pbiFields['mrp']);
                    }
                    if ($vPurchaseRate === 0 && ! empty($pbiFields['rate'])) {
                        $vPurchaseRate = self::extractInt($pbiFields['rate']);
                    }
                }
            }

            $vUnitResolved = $this->unitService->resolveVariantUnitConfig($vFields, [], $businessId);

            $variantsByProduct[$vProdId][] = [
                'variantId' => $vId,
                'productId' => $vProdId,
                'businessId' => $businessId,
                'tenantId' => (string) ($vFields['tenantId'] ?? ''),
                'name' => (string) ($vFields['variantName'] ?? $vFields['name'] ?? $vFields['displayName'] ?? 'Standard Variant'),
                'displayName' => (string) ($vFields['displayName'] ?? $vFields['name'] ?? ''),
                'sku' => $vFields['sku'] ?? $vFields['variantCode'] ?? null,
                'barcode' => $vFields['barcode'] ?? null,
                'hsnCode' => $vFields['hsnCode'] ?? null,
                'baseUnitId' => $vUnitResolved['baseUnitId'],
                'packUnitId' => $vFields['packUnitId'] ?? null,
                'commercialUnitId' => $vFields['commercialUnitId'] ?? $vUnitResolved['defaultSalesUnitId'],
                'commercialUnitName' => $vFields['commercialUnitName'] ?? $vUnitResolved['unitDisplayName'],
                'baseQuantityPerCommercialUnit' => self::extractFloat($vFields['baseQuantityPerCommercialUnit'] ?? null, $vUnitResolved['salesConversionFactor']),
                'purchaseUnitId' => $vUnitResolved['purchaseUnitId'],
                'purchaseConversionFactor' => $vUnitResolved['purchaseConversionFactor'],
                'defaultSalesUnitId' => $vUnitResolved['defaultSalesUnitId'],
                'salesConversionFactor' => $vUnitResolved['salesConversionFactor'],
                'purchasePricePerUnit' => $vUnitResolved['purchasePricePerUnit'] > 0 ? $vUnitResolved['purchasePricePerUnit'] : $vPurchaseRate,
                'sellingPricePerUnit' => $vUnitResolved['sellingPricePerUnit'] > 0 ? $vUnitResolved['sellingPricePerUnit'] : $vSellingPrice,
                'allowFractionalQuantity' => $vUnitResolved['allowFractionalQuantity'],
                'allowManualUnitOverride' => $vUnitResolved['allowManualUnitOverride'],
                'unitDisplayName' => $vUnitResolved['unitDisplayName'],
                'unitSymbol' => $vUnitResolved['unitSymbol'],
                'packSize' => $vFields['packSize'] ?? ($vFields['attributeValues']['packSize'] ?? null),
                'purchaseRate' => $vPurchaseRate,
                'sellingPrice' => $vSellingPrice,
                'mrp' => $vMrp,
                'defaultGstRateBps' => self::extractInt($vFields['defaultGstRateBps'] ?? $vFields['defaultGstRate'] ?? null, 0),
                'reorderLevel' => self::extractInt($vFields['reorderLevel'] ?? $vFields['reorder_level'] ?? null, 0),
                'rackId' => $vFields['rackId'] ?? $vFields['rack'] ?? ($vFields['attributeValues']['rack'] ?? null),
                'status' => (string) ($vFields['status'] ?? 'ACTIVE'),
                'isActive' => (($vFields['status'] ?? 'ACTIVE') === 'ACTIVE'),
                'quantitySlabs' => $vFields['quantitySlabs'] ?? $vFields['quantity_slabs'] ?? [],
                'attributeValues' => ! empty($vFields['attributeValues']) ? $vFields['attributeValues'] : (object) [],
            ];
        }

        // 4. Assemble products response
        $products = [];
        $searchLower = strtolower($search);

        foreach ($productRows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];

            $prodId = (string) ($fields['productId'] ?? $row->id);

            // Filter by outlet placement if specified
            if ($outletProductIds !== null) {
                if (! isset($outletProductIds[$prodId])) {
                    continue;
                }
            }

            $name = (string) ($fields['productName'] ?? $fields['name'] ?? $row->product_name ?? '');
            $code = (string) ($fields['productCode'] ?? '');
            $brand = (string) ($fields['brand'] ?? $row->brand_name ?? '');
            $cat = (string) ($fields['categoryName'] ?? $fields['category'] ?? $row->category_name ?? '');
            $subcat = (string) ($fields['subcategory'] ?? $row->subcategory_name ?? '');

            // Apply category filter
            if ($categoryId && ($fields['categoryId'] ?? '') !== $categoryId) {
                continue;
            }

            // Apply brand filter
            if ($brandId && ($fields['brandId'] ?? '') !== $brandId) {
                continue;
            }

            // Apply search filter
            if ($searchLower !== '') {
                $matches = str_contains(strtolower($name), $searchLower)
                    || str_contains(strtolower($code), $searchLower)
                    || str_contains(strtolower($brand), $searchLower)
                    || str_contains(strtolower($cat), $searchLower)
                    || str_contains(strtolower($subcat), $searchLower)
                    || str_contains(strtolower((string) ($row->master_product_id ?? '')), $searchLower);

                if (! $matches) {
                    continue;
                }
            }

            $prodVariants = $variantsByProduct[$prodId] ?? [];
            $parentHsn = $fields['hsnCode'] ?? null;
            $parentGst = (int) ($fields['defaultGstRateBps'] ?? 0);
            $parentAttrs = $fields['attributeValues'] ?? [];
            if (is_string($parentAttrs)) {
                $parentAttrs = json_decode($parentAttrs, true) ?: [];
            } elseif (is_object($parentAttrs)) {
                $parentAttrs = (array) $parentAttrs;
            }
            $parentBaseUnit = $fields['baseUnitId'] ?? ($parentAttrs['baseUnit'] ?? ($parentAttrs['soldAs'] ?? null));
            $parentSoldAs = $parentAttrs['soldAs'] ?? ($parentAttrs['commercialUnit'] ?? null);

            foreach ($prodVariants as &$pv) {
                if (empty($pv['productName'])) {
                    $pv['productName'] = $name;
                }
                if (empty($pv['brand'])) {
                    $pv['brand'] = $brand;
                }
                if (empty($pv['category'])) {
                    $pv['category'] = $cat;
                }
                if (empty($pv['hsnCode']) && ! empty($parentHsn)) {
                    $pv['hsnCode'] = $parentHsn;
                }
                if (empty($pv['defaultGstRateBps']) && $parentGst > 0) {
                    $pv['defaultGstRateBps'] = $parentGst;
                }
                if (empty($pv['reorderLevel']) && ! empty($fields['reorderLevel'])) {
                    $pv['reorderLevel'] = self::extractInt($fields['reorderLevel'], 0);
                }
                if ((empty($pv['baseUnitId']) || strtoupper($pv['baseUnitId']) === 'UNIT') && ! empty($parentBaseUnit)) {
                    $pv['baseUnitId'] = $parentBaseUnit;
                }
                if (empty($pv['commercialUnitName'])) {
                    $vAttrs = $pv['attributeValues'] ?? [];
                    if (is_string($vAttrs)) {
                        $vAttrs = json_decode($vAttrs, true) ?: [];
                    } elseif (is_object($vAttrs)) {
                        $vAttrs = (array) $vAttrs;
                    }
                    $attrSoldAs = $vAttrs['soldAs'] ?? ($vAttrs['commercialUnit'] ?? $parentSoldAs);
                    if (! empty($attrSoldAs)) {
                        $pv['commercialUnitName'] = $attrSoldAs;
                    }
                }
                $mergedResolved = $this->unitService->resolveVariantUnitConfig($pv, $fields, $businessId);
                $pv['baseUnitId'] = $mergedResolved['baseUnitId'];
                $pv['purchaseUnitId'] = $mergedResolved['purchaseUnitId'];
                $pv['purchaseConversionFactor'] = $mergedResolved['purchaseConversionFactor'];
                $pv['defaultSalesUnitId'] = $mergedResolved['defaultSalesUnitId'];
                $pv['salesConversionFactor'] = $mergedResolved['salesConversionFactor'];
                $pv['allowFractionalQuantity'] = $mergedResolved['allowFractionalQuantity'];
                $pv['allowManualUnitOverride'] = $mergedResolved['allowManualUnitOverride'];
                $pv['unitDisplayName'] = $mergedResolved['unitDisplayName'];
                $pv['unitSymbol'] = $mergedResolved['unitSymbol'];
                $pv['purchasePricePerUnit'] = $mergedResolved['purchasePricePerUnit'];
                $pv['sellingPricePerUnit'] = $mergedResolved['sellingPricePerUnit'];
            }
            unset($pv);

            $productLevelUnits = ! empty($prodVariants)
                ? $prodVariants[0]
                : $this->unitService->resolveVariantUnitConfig([], $fields, $businessId);

            $stockSvc = $this->stockService();
            $rawInvBehaviour = $fields['inventoryBehaviour'] ?? ($row->inventory_behaviour ?? null);
            $invBehaviour = $stockSvc->normalizeBehaviour($rawInvBehaviour, $fields);
            if (($rawInvBehaviour === null || $rawInvBehaviour === '') && $stockSvc->isSandOrGravelCftProduct($fields, $prodVariants[0] ?? null)) {
                $hasOpts = ! empty($purchaseOptionsByProduct[$prodId] ?? []);
                if ($hasOpts || count($prodVariants) > 1) {
                    $invBehaviour = \App\Services\InventoryStockService::BEHAVIOUR_UNIFIED_PARENT;
                }
            }

            $products[] = [
                'productId' => $prodId,
                'masterProductId' => $row->master_product_id ?? null,
                'businessId' => $businessId,
                'tenantId' => (string) ($fields['tenantId'] ?? ''),
                'name' => $name,
                'productName' => $name,
                'productCode' => $code,
                'brand' => $brand,
                'brandId' => $fields['brandId'] ?? null,
                'category' => $cat,
                'categoryId' => $fields['categoryId'] ?? null,
                'subcategory' => $subcat,
                'subcategoryId' => $fields['subcategoryId'] ?? null,
                'description' => $fields['description'] ?? $row->description ?? null,
                'productImage' => $fields['productImage'] ?? $fields['imageUrl'] ?? $row->image_url ?? null,
                'defaultGstRateBps' => (int) ($fields['defaultGstRateBps'] ?? 0),
                'hsnCode' => $fields['hsnCode'] ?? null,
                'inventoryBehaviour' => $invBehaviour,
                'defaultPurchaseOptionId' => $fields['defaultPurchaseOptionId'] ?? ($row->default_purchase_option_id ?? null),
                'allowMultiplePurchaseOptions' => array_key_exists('allowMultiplePurchaseOptions', $fields)
                    ? (bool) $fields['allowMultiplePurchaseOptions']
                    : (bool) ($row->allow_multiple_purchase_options ?? true),
                'allowMultipleSalesOptions' => array_key_exists('allowMultipleSalesOptions', $fields)
                    ? (bool) $fields['allowMultipleSalesOptions']
                    : (bool) ($row->allow_multiple_sales_options ?? false),
                'trackStock' => array_key_exists('trackStock', $fields)
                    ? (bool) $fields['trackStock']
                    : (bool) ($row->track_stock ?? true),
                'baseUnitId' => $fields['baseUnitId'] ?? $productLevelUnits['baseUnitId'],
                'purchaseUnitId' => $fields['purchaseUnitId'] ?? $productLevelUnits['purchaseUnitId'],
                'purchaseConversionFactor' => $fields['purchaseConversionFactor'] ?? $productLevelUnits['purchaseConversionFactor'],
                'defaultSalesUnitId' => $fields['defaultSalesUnitId'] ?? $productLevelUnits['defaultSalesUnitId'],
                'salesConversionFactor' => $fields['salesConversionFactor'] ?? $productLevelUnits['salesConversionFactor'],
                'allowFractionalQuantity' => $fields['allowFractionalQuantity'] ?? $productLevelUnits['allowFractionalQuantity'],
                'allowManualUnitOverride' => $fields['allowManualUnitOverride'] ?? $productLevelUnits['allowManualUnitOverride'],
                'unitDisplayName' => $fields['unitDisplayName'] ?? $productLevelUnits['unitDisplayName'],
                'unitSymbol' => $fields['unitSymbol'] ?? $productLevelUnits['unitSymbol'],
                'status' => (string) ($fields['status'] ?? 'ACTIVE'),
                'isActive' => (($fields['status'] ?? 'ACTIVE') === 'ACTIVE'),
                'quantitySlabs' => $fields['quantitySlabs'] ?? $fields['quantity_slabs'] ?? [],
                'attributeValues' => ! empty($fields['attributeValues']) ? $fields['attributeValues'] : (object) [],
                'variants' => $prodVariants,
                'createdAt' => $fields['createdAt'] ?? $row->created_at,
                'updatedAt' => $fields['updatedAt'] ?? $row->updated_at,
            ];
        }

        $optionsByProduct = $this->stockService()->getPurchaseOptionsByProductIds(
            $businessId,
            array_column($products, 'productId')
        );
        foreach ($products as &$prodItem) {
            $prodItem['purchaseOptions'] = $optionsByProduct[$prodItem['productId']] ?? [];
        }
        unset($prodItem);

        return ApiResponse::success($products, 'Products retrieved successfully.');
    }

    /**
     * Get single product by ID with its variants.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $row = DB::table('products')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Product not found.', 404);
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        $businessId = (string) ($fields['businessId'] ?? $row->business_id ?? '');

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness($businessId)) {
            return ApiResponse::error('Unauthorized access to this product.', 403, null, 'FORBIDDEN');
        }

        // Fetch variants
        $variantRows = DB::table('product_variants')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        $variants = [];
        $parentAttrs = $fields['attributeValues'] ?? [];
        if (is_string($parentAttrs)) {
            $parentAttrs = json_decode($parentAttrs, true) ?: [];
        } elseif (is_object($parentAttrs)) {
            $parentAttrs = (array) $parentAttrs;
        }
        $parentBaseUnit = $fields['baseUnitId'] ?? ($parentAttrs['baseUnit'] ?? ($parentAttrs['soldAs'] ?? null));
        $parentSoldAs = $parentAttrs['soldAs'] ?? ($parentAttrs['commercialUnit'] ?? null);

        foreach ($variantRows as $vRow) {
            $vPayload = json_decode($vRow->payload, true) ?: [];
            $vFields = $vPayload['fields'] ?? [];
            if (($vFields['productId'] ?? '') === $id) {
                $vAttrs = $vFields['attributeValues'] ?? [];
                if (is_string($vAttrs)) {
                    $vAttrs = json_decode($vAttrs, true) ?: [];
                } elseif (is_object($vAttrs)) {
                    $vAttrs = (array) $vAttrs;
                }

                $vUnit = (string) ($vFields['baseUnitId'] ?? $vFields['unitId'] ?? '');
                if ((empty($vUnit) || strtoupper($vUnit) === 'UNIT') && ! empty($parentBaseUnit)) {
                    $vUnit = $parentBaseUnit;
                } elseif (empty($vUnit)) {
                    $vUnit = 'UNIT';
                }

                $vCommUnitName = $vFields['commercialUnitName'] ?? null;
                if (empty($vCommUnitName)) {
                    $vCommUnitName = $vAttrs['soldAs'] ?? ($vAttrs['commercialUnit'] ?? $parentSoldAs);
                }

                $vSellingPrice = self::extractInt($vFields['sellingPrice'] ?? null, 0);
                $vPurchaseRate = self::extractInt($vFields['purchaseRate'] ?? null, 0);
                $vMrp = self::extractInt($vFields['mrp'] ?? null, 0);
                $singleVid = (string) ($vFields['variantId'] ?? $vRow->id);

                if ($vSellingPrice === 0 || $vPurchaseRate === 0) {
                    $latestPbItem = DB::table('purchase_bill_items')
                        ->where('business_id', $businessId)
                        ->where(function ($q) use ($singleVid) {
                            $q->where('id', 'like', "%{$singleVid}%")
                                ->orWhere('payload->fields->variantId', $singleVid);
                        })
                        ->orderBy('created_at', 'desc')
                        ->first();
                    if ($latestPbItem) {
                        $pbiPayload = json_decode($latestPbItem->payload, true) ?: [];
                        $pbiFields = $pbiPayload['fields'] ?? [];
                        if ($vSellingPrice === 0 && ! empty($pbiFields['sellingPrice'])) {
                            $vSellingPrice = self::extractInt($pbiFields['sellingPrice']);
                        }
                        if ($vMrp === 0 && ! empty($pbiFields['mrp'])) {
                            $vMrp = self::extractInt($pbiFields['mrp']);
                        }
                        if ($vPurchaseRate === 0 && ! empty($pbiFields['rate'])) {
                            $vPurchaseRate = self::extractInt($pbiFields['rate']);
                        }
                    }
                }

                $baseVariantRow = [
                    'variantId' => $singleVid,
                    'productId' => $id,
                    'businessId' => $businessId,
                    'name' => (string) ($vFields['variantName'] ?? $vFields['name'] ?? 'Standard Variant'),
                    'displayName' => (string) ($vFields['displayName'] ?? $vFields['name'] ?? ''),
                    'productName' => (string) ($fields['productName'] ?? $fields['name'] ?? $row->name ?? ''),
                    'brand' => (string) ($fields['brand'] ?? ''),
                    'category' => (string) ($fields['categoryName'] ?? $fields['category'] ?? ''),
                    'sku' => $vFields['sku'] ?? $vFields['variantCode'] ?? null,
                    'barcode' => $vFields['barcode'] ?? null,
                    'hsnCode' => $vFields['hsnCode'] ?? $fields['hsnCode'] ?? null,
                    'baseUnitId' => $vUnit,
                    'packUnitId' => $vFields['packUnitId'] ?? null,
                    'commercialUnitId' => $vFields['commercialUnitId'] ?? null,
                    'commercialUnitName' => $vCommUnitName,
                    'baseQuantityPerCommercialUnit' => self::extractFloat($vFields['baseQuantityPerCommercialUnit'] ?? null, 1.0),
                    'packSize' => $vFields['packSize'] ?? ($vAttrs['packSize'] ?? null),
                    'purchaseRate' => $vPurchaseRate,
                    'sellingPrice' => $vSellingPrice,
                    'mrp' => $vMrp,
                    'defaultGstRateBps' => self::extractInt($vFields['defaultGstRateBps'] ?? $fields['defaultGstRateBps'] ?? null, 0),
                    'reorderLevel' => self::extractInt($vFields['reorderLevel'] ?? $vFields['reorder_level'] ?? $fields['reorderLevel'] ?? null, 0),
                    'status' => (string) ($vFields['status'] ?? 'ACTIVE'),
                    'isActive' => (($vFields['status'] ?? 'ACTIVE') === 'ACTIVE'),
                    'quantitySlabs' => $vFields['quantitySlabs'] ?? $vFields['quantity_slabs'] ?? ($fields['quantitySlabs'] ?? $fields['quantity_slabs'] ?? []),
                    'attributeValues' => ! empty($vAttrs) ? $vAttrs : (object) [],
                ];
                $vResolved = $this->unitService->resolveVariantUnitConfig(array_merge($vFields, $baseVariantRow), $fields, $businessId);
                $variants[] = array_merge($baseVariantRow, [
                    'baseUnitId' => $vResolved['baseUnitId'],
                    'commercialUnitId' => $vFields['commercialUnitId'] ?? $vResolved['defaultSalesUnitId'],
                    'commercialUnitName' => $vCommUnitName ?: $vResolved['unitDisplayName'],
                    'baseQuantityPerCommercialUnit' => self::extractFloat($vFields['baseQuantityPerCommercialUnit'] ?? null, $vResolved['salesConversionFactor']),
                    'purchaseUnitId' => $vResolved['purchaseUnitId'],
                    'purchaseConversionFactor' => $vResolved['purchaseConversionFactor'],
                    'defaultSalesUnitId' => $vResolved['defaultSalesUnitId'],
                    'salesConversionFactor' => $vResolved['salesConversionFactor'],
                    'purchasePricePerUnit' => $vResolved['purchasePricePerUnit'] > 0 ? $vResolved['purchasePricePerUnit'] : $vPurchaseRate,
                    'sellingPricePerUnit' => $vResolved['sellingPricePerUnit'] > 0 ? $vResolved['sellingPricePerUnit'] : $vSellingPrice,
                    'allowFractionalQuantity' => $vResolved['allowFractionalQuantity'],
                    'allowManualUnitOverride' => $vResolved['allowManualUnitOverride'],
                    'unitDisplayName' => $vResolved['unitDisplayName'],
                    'unitSymbol' => $vResolved['unitSymbol'],
                ]);
            }
        }

        $productUnitSummary = ! empty($variants)
            ? $variants[0]
            : $this->unitService->resolveVariantUnitConfig([], $fields, $businessId);

        $stockSvc = $this->stockService();
        $invBehaviour = $stockSvc->normalizeBehaviour(
            $fields['inventoryBehaviour'] ?? ($row->inventory_behaviour ?? null),
            $fields
        );
        $purchaseOptions = ($stockSvc->getPurchaseOptionsByProductIds($businessId, [$id]))[$id] ?? [];

        $product = [
            'productId' => $id,
            'businessId' => $businessId,
            'tenantId' => (string) ($fields['tenantId'] ?? ''),
            'name' => (string) ($fields['productName'] ?? $fields['name'] ?? ''),
            'productName' => (string) ($fields['productName'] ?? $fields['name'] ?? ''),
            'productCode' => $fields['productCode'] ?? null,
            'brand' => $fields['brand'] ?? null,
            'brandId' => $fields['brandId'] ?? null,
            'category' => $fields['categoryName'] ?? $fields['category'] ?? null,
            'categoryId' => $fields['categoryId'] ?? null,
            'subcategory' => $fields['subcategory'] ?? null,
            'subcategoryId' => $fields['subcategoryId'] ?? null,
            'description' => $fields['description'] ?? null,
            'productImage' => $fields['productImage'] ?? $fields['imageUrl'] ?? null,
            'defaultGstRateBps' => (int) ($fields['defaultGstRateBps'] ?? 0),
            'hsnCode' => $fields['hsnCode'] ?? null,
            'inventoryBehaviour' => $invBehaviour,
            'defaultPurchaseOptionId' => $fields['defaultPurchaseOptionId'] ?? ($row->default_purchase_option_id ?? null),
            'allowMultiplePurchaseOptions' => array_key_exists('allowMultiplePurchaseOptions', $fields)
                ? (bool) $fields['allowMultiplePurchaseOptions']
                : (bool) ($row->allow_multiple_purchase_options ?? true),
            'allowMultipleSalesOptions' => array_key_exists('allowMultipleSalesOptions', $fields)
                ? (bool) $fields['allowMultipleSalesOptions']
                : (bool) ($row->allow_multiple_sales_options ?? false),
            'trackStock' => array_key_exists('trackStock', $fields)
                ? (bool) $fields['trackStock']
                : (bool) ($row->track_stock ?? true),
            'baseUnitId' => $fields['baseUnitId'] ?? $productUnitSummary['baseUnitId'],
            'purchaseUnitId' => $fields['purchaseUnitId'] ?? $productUnitSummary['purchaseUnitId'],
            'purchaseConversionFactor' => $fields['purchaseConversionFactor'] ?? $productUnitSummary['purchaseConversionFactor'],
            'defaultSalesUnitId' => $fields['defaultSalesUnitId'] ?? $productUnitSummary['defaultSalesUnitId'],
            'salesConversionFactor' => $fields['salesConversionFactor'] ?? $productUnitSummary['salesConversionFactor'],
            'allowFractionalQuantity' => $fields['allowFractionalQuantity'] ?? $productUnitSummary['allowFractionalQuantity'],
            'allowManualUnitOverride' => $fields['allowManualUnitOverride'] ?? $productUnitSummary['allowManualUnitOverride'],
            'unitDisplayName' => $fields['unitDisplayName'] ?? $productUnitSummary['unitDisplayName'],
            'unitSymbol' => $fields['unitSymbol'] ?? $productUnitSummary['unitSymbol'],
            'status' => (string) ($fields['status'] ?? 'ACTIVE'),
            'isActive' => (($fields['status'] ?? 'ACTIVE') === 'ACTIVE'),
            'quantitySlabs' => $fields['quantitySlabs'] ?? $fields['quantity_slabs'] ?? [],
            'attributeValues' => ! empty($fields['attributeValues']) ? $fields['attributeValues'] : (object) [],
            'variants' => $variants,
            'purchaseOptions' => $purchaseOptions,
            'createdAt' => $fields['createdAt'] ?? $row->created_at,
            'updatedAt' => $fields['updatedAt'] ?? $row->updated_at,
        ];

        return ApiResponse::success($product, 'Product retrieved successfully.');
    }

    /**
     * Create a new product and its variants atomically.
     */
    /** Assign permanent internal barcodes to active variants missing one. */
    public function generateMissingBarcodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'required|string',
            'outlet_id' => 'nullable|string',
        ]);

        $user = $request->user();
        $businessId = (string) $validated['business_id'];
        $outletId = $validated['outlet_id'] ?? null;
        $business = DB::table('businesses')
            ->where('id', $businessId)
            ->where('is_deleted', 0)
            ->first();
        if (! $business) {
            return ApiResponse::error('Business not found.', 404, null, 'BUSINESS_NOT_FOUND');
        }

        $businessPayload = json_decode((string) $business->payload, true) ?: [];
        $businessFields = $businessPayload['fields'] ?? $businessPayload;
        $ownerUid = (string) ($businessFields['ownerUid'] ?? $business->owner_uid ?? '');
        $isStaff = strtoupper((string) $user->role) === 'STAFF';
        if (! $user->isAdmin() && ! $isStaff && $ownerUid !== (string) $user->id) {
            return ApiResponse::error('Unauthorized access to this business.', 403, null, 'PERMISSION_DENIED');
        }

        if ($outletId !== null) {
            $outletExists = DB::table('outlets')
                ->where('id', $outletId)
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->exists();
            if (! $outletExists) {
                return ApiResponse::error('The selected outlet does not belong to this business.', 422, null, 'OUTLET_SCOPE_MISMATCH');
            }
        }

        $generated = DB::transaction(function () use ($businessId, $outletId, $ownerUid): int {
            $products = DB::table('products')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->get(['id', 'status', 'payload'])
                ->keyBy('id');
            $variants = DB::table('product_variants')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->lockForUpdate()
                ->get();

            $usedNumbers = [];
            $missing = [];
            foreach ($variants as $variant) {
                $payload = json_decode((string) $variant->payload, true) ?: [];
                $fields = $payload['fields'] ?? $payload;
                $barcode = trim((string) ($fields['barcode'] ?? ''));
                if (preg_match('/^\d{12}$/', $barcode)) {
                    $usedNumbers[] = (int) $barcode;
                }

                $productId = (string) ($fields['productId'] ?? '');
                $product = $products->get($productId);
                if (! $product || strtoupper((string) $product->status) !== 'ACTIVE') {
                    continue;
                }
                $productPayload = json_decode((string) $product->payload, true) ?: [];
                $productFields = $productPayload['fields'] ?? $productPayload;
                if (strtoupper((string) ($productFields['status'] ?? $product->status ?? 'ACTIVE')) !== 'ACTIVE' ||
                    strtoupper((string) ($fields['status'] ?? $variant->status ?? 'ACTIVE')) !== 'ACTIVE' ||
                    $barcode !== '') {
                    continue;
                }
                $missing[] = [$variant, $payload, $fields];
            }

            if ($missing === []) {
                return 0;
            }

            $sequenceId = "{$ownerUid}_{$businessId}_INTERNAL_BARCODE";
            $highestUsed = $usedNumbers === [] ? 700000000000 : max($usedNumbers);
            $initialNext = max(700000000001, $highestUsed + 1);
            $now = now();
            $nowIso = $now->toIso8601String();
            DB::table('document_sequences')->insertOrIgnore([
                'id' => $sequenceId,
                'document_path' => "document_sequences/{$sequenceId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => [
                    'tenantId' => $ownerUid,
                    'businessId' => $businessId,
                    'sequenceType' => 'INTERNAL_BARCODE',
                    'nextNumber' => $initialNext,
                    'padding' => 12,
                    'createdAt' => $nowIso,
                ]]),
            ]);
            $sequence = DB::table('document_sequences')
                ->where('id', $sequenceId)
                ->lockForUpdate()
                ->first();
            $sequencePayload = json_decode((string) ($sequence->payload ?? ''), true) ?: [];
            $sequenceFields = $sequencePayload['fields'] ?? $sequencePayload;
            $nextNumber = max(
                $initialNext,
                (int) ($sequenceFields['nextNumber'] ?? $sequenceFields['sequence'] ?? $initialNext),
            );
            if ($nextNumber + count($missing) - 1 > 999999999999) {
                throw new \RuntimeException('The internal barcode range is exhausted.');
            }

            foreach ($missing as [$variant, $payload, $fields]) {
                while (in_array($nextNumber, $usedNumbers, true)) {
                    $nextNumber++;
                }
                $barcode = str_pad((string) $nextNumber, 12, '0', STR_PAD_LEFT);
                $usedNumbers[] = $nextNumber++;
                $fields['barcode'] = $barcode;
                $fields['updatedAt'] = $nowIso;
                $payload['fields'] = $fields;
                DB::table('product_variants')->where('id', $variant->id)->update([
                    'payload' => json_encode($payload),
                    'updated_at' => $now,
                ]);
            }

            $sequenceFields['tenantId'] = $ownerUid;
            $sequenceFields['businessId'] = $businessId;
            $sequenceFields['sequenceType'] = 'INTERNAL_BARCODE';
            $sequenceFields['nextNumber'] = $nextNumber;
            $sequenceFields['padding'] = 12;
            $sequenceFields['updatedAt'] = $nowIso;
            $sequencePayload['fields'] = $sequenceFields;
            DB::table('document_sequences')->where('id', $sequenceId)->update([
                'updated_at' => $now,
                'payload' => json_encode($sequencePayload),
            ]);

            return count($missing);
        });

        return ApiResponse::success(
            ['count' => $generated],
            $generated > 0 ? "{$generated} missing barcode(s) assigned." : 'All active variants already have barcodes.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->mergeMultipartPayload($request);
        $user = $request->user();
        $userId = $user ? (string) $user->id : (string) ($request->header('X-User-Id') ?? 'USR-OWNER-001');

        $request->validate([
            'business_id' => ['sometimes', 'required', 'string'],
            'businessId' => ['sometimes', 'required', 'string'],
            'outlet_id' => ['nullable', 'string'],
            'outletId' => ['nullable', 'string'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'productName' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:64'],
            'productCode' => ['nullable', 'string', 'max:64'],
            'brand_id' => ['nullable', 'string'],
            'brandId' => ['nullable', 'string'],
            'brand' => ['nullable', 'string'],
            'category_id' => ['nullable', 'string'],
            'categoryId' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'subcategoryId' => ['nullable', 'string'],
            'subcategory' => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:1000'],
            'product_image' => ['nullable', 'string'],
            'productImage' => ['nullable', 'string'],
            'product_image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'default_gst_rate_bps' => ['nullable', 'integer'],
            'defaultGstRateBps' => ['nullable', 'integer'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'reorderLevel' => ['nullable', 'integer', 'min:0'],
            'hsn_code' => ['nullable', 'string'],
            'hsnCode' => ['nullable', 'string'],
            'variants' => ['nullable', 'array'],
            'quantity_slabs' => ['nullable', 'array'],
            'quantitySlabs' => ['nullable', 'array'],
        ]);

        $businessId = trim((string) ($request->input('business_id') ?? $request->input('businessId') ?? ''));
        $outletId = $request->input('outlet_id') ?? $request->input('outletId');
        $name = trim((string) ($request->input('name') ?? $request->input('productName') ?? ''));
        $productCode = trim((string) ($request->input('product_code') ?? $request->input('productCode') ?? 'PRD-'.strtoupper(Str::random(6))));
        $brand = $request->input('brand');
        $brandId = $request->input('brand_id') ?? $request->input('brandId');
        $category = $request->input('category');
        $categoryId = $request->input('category_id') ?? $request->input('categoryId');
        $subcategory = $request->input('subcategory');
        $subcategoryId = $request->input('subcategoryId');
        $description = $request->input('description');
        $productImage = $request->input('product_image') ?? $request->input('productImage');
        if ($request->hasFile('product_image_file')) {
            $imagePath = $request->file('product_image_file')->storePublicly('products', 'public');
            $productImage = Storage::disk('public')->url($imagePath);
        }
        $defaultGstRateBps = (int) ($request->input('default_gst_rate_bps') ?? $request->input('defaultGstRateBps') ?? 0);
        $productReorderLevel = (int) ($request->input('reorder_level') ?? $request->input('reorderLevel') ?? 0);
        $hsnCode = $request->input('hsn_code') ?? $request->input('hsnCode');
        $attributeValues = (array) ($request->input('attribute_values') ?? $request->input('attributeValues') ?? []);
        $quantitySlabs = (array) ($request->input('quantity_slabs') ?? $request->input('quantitySlabs') ?? []);

        $productId = 'PROD-'.strtoupper(Str::random(24));
        $now = now()->toISOString();
        $nowDb = now();

        $productFields = [
            'productId' => $productId,
            'businessId' => $businessId,
            'tenantId' => $userId,
            'name' => $name,
            'productName' => $name,
            'productCode' => $productCode,
            'brand' => $brand,
            'brandId' => $brandId,
            'category' => $category,
            'categoryName' => $category,
            'categoryId' => $categoryId,
            'subcategory' => $subcategory,
            'subcategoryId' => $subcategoryId,
            'description' => $description,
            'productImage' => $productImage,
            'imageUrl' => $productImage,
            'defaultGstRateBps' => $defaultGstRateBps,
            'reorderLevel' => $productReorderLevel,
            'hsnCode' => $hsnCode,
            'attributeValues' => $attributeValues,
            'quantitySlabs' => $quantitySlabs,
            'status' => 'ACTIVE',
            'createdAt' => $now,
            'updatedAt' => $now,
            'createdBy' => $userId,
            'updatedBy' => $userId,
        ];

        // Process variants input or default variant
        $inputVariants = $request->input('variants');
        $variantRecords = [];
        $defaultBaseUnit = (string) (
            $attributeValues['baseUnit']
            ?? $request->input('base_unit_id')
            ?? $request->input('baseUnitId')
            ?? $attributeValues['soldAs']
            ?? 'UNIT'
        );
        $defaultCommUnitName = $attributeValues['soldAs'] ?? ($attributeValues['commercialUnit'] ?? null);

        if (empty($inputVariants) || ! is_array($inputVariants)) {
            $inputVariants = [
                [
                    'name' => 'Standard',
                    'selling_price' => (int) ($request->input('selling_price') ?? $request->input('sellingPrice') ?? 0),
                    'mrp' => (int) ($request->input('mrp') ?? 0),
                    'purchase_rate' => (int) ($request->input('purchase_rate') ?? $request->input('purchaseRate') ?? 0),
                    'base_unit_id' => $defaultBaseUnit,
                    'barcode' => $request->input('barcode'),
                ],
            ];
        }

        foreach ($inputVariants as $v) {
            $variantId = 'VAR-'.strtoupper(Str::random(24));
            $vName = trim((string) ($v['name'] ?? $v['variantName'] ?? $v['displayName'] ?? 'Standard'));
            $vSelling = (int) ($v['selling_price'] ?? $v['sellingPrice'] ?? 0);
            $vMrp = (int) ($v['mrp'] ?? 0);
            $vPurchase = (int) ($v['purchase_rate'] ?? $v['purchaseRate'] ?? 0);
            $vUnit = (string) ($v['base_unit_id'] ?? $v['baseUnitId'] ?? $v['unitId'] ?? '');
            if (empty($vUnit) || strtoupper($vUnit) === 'UNIT') {
                $vUnit = $defaultBaseUnit;
            }
            $vCommUnitName = $v['commercial_unit_name'] ?? $v['commercialUnitName'] ?? $defaultCommUnitName;
            $vBarcode = $v['barcode'] ?? null;
            $vSku = $v['sku'] ?? 'SKU-'.strtoupper(Str::random(6));

            $vAttrs = (array) ($v['attributeValues'] ?? $v['attribute_values'] ?? []);
            $vUnitResolved = $this->unitService->resolveVariantUnitConfig(
                array_merge($v, [
                    'baseUnitId' => $vUnit,
                    'commercialUnitId' => $v['commercialUnitId'] ?? $v['commercial_unit_id'] ?? $request->input('defaultSalesUnitId') ?? $request->input('default_sales_unit_id') ?? null,
                    'commercialUnitName' => $vCommUnitName,
                    'baseQuantityPerCommercialUnit' => (float) ($v['baseQuantityPerCommercialUnit'] ?? $v['salesConversionFactor'] ?? $v['sales_conversion_factor'] ?? 1),
                    'purchaseUnitId' => $v['purchaseUnitId'] ?? $v['purchase_unit_id'] ?? $request->input('purchaseUnitId') ?? $request->input('purchase_unit_id') ?? null,
                    'purchaseConversionFactor' => $v['purchaseConversionFactor'] ?? $v['purchase_conversion_factor'] ?? $v['purchaseUnitConversionFactor'] ?? $request->input('purchaseConversionFactor') ?? $request->input('purchase_conversion_factor') ?? null,
                    'defaultSalesUnitId' => $v['defaultSalesUnitId'] ?? $v['default_sales_unit_id'] ?? $v['salesUnitId'] ?? $request->input('defaultSalesUnitId') ?? $request->input('default_sales_unit_id') ?? null,
                    'salesConversionFactor' => $v['salesConversionFactor'] ?? $v['sales_conversion_factor'] ?? null,
                    'purchasePricePerUnit' => $v['purchasePricePerUnit'] ?? $v['purchase_price_per_unit'] ?? $vPurchase,
                    'sellingPricePerUnit' => $v['sellingPricePerUnit'] ?? $v['selling_price_per_unit'] ?? $vSelling,
                    'allowFractionalQuantity' => $v['allowFractionalQuantity'] ?? $v['allow_fractional_quantity'] ?? $request->input('allowFractionalQuantity') ?? $request->input('allow_fractional_quantity') ?? null,
                    'allowManualUnitOverride' => $v['allowManualUnitOverride'] ?? $v['allow_manual_unit_override'] ?? $request->input('allowManualUnitOverride') ?? $request->input('allow_manual_unit_override') ?? null,
                    'unitDisplayName' => $v['unitDisplayName'] ?? $v['unit_display_name'] ?? $request->input('unitDisplayName') ?? $request->input('unit_display_name') ?? null,
                    'unitSymbol' => $v['unitSymbol'] ?? $v['unit_symbol'] ?? $request->input('unitSymbol') ?? $request->input('unit_symbol') ?? null,
                    'attributeValues' => $vAttrs,
                ]),
                $productFields,
                $businessId
            );

            $vFields = [
                'variantId' => $variantId,
                'productId' => $productId,
                'businessId' => $businessId,
                'tenantId' => $userId,
                'name' => $vName,
                'variantName' => $vName,
                'productName' => $name,
                'displayName' => $vName,
                'sku' => $vSku,
                'barcode' => $vBarcode,
                'baseUnitId' => $vUnitResolved['baseUnitId'],
                'packUnitId' => $v['packUnitId'] ?? $v['pack_unit_id'] ?? $vUnitResolved['purchaseUnitId'],
                'commercialUnitId' => $v['commercialUnitId'] ?? $v['commercial_unit_id'] ?? $vUnitResolved['defaultSalesUnitId'],
                'commercialUnitName' => $vCommUnitName ?: $vUnitResolved['unitDisplayName'],
                'baseQuantityPerCommercialUnit' => $vUnitResolved['salesConversionFactor'],
                'purchaseUnitId' => $vUnitResolved['purchaseUnitId'],
                'purchaseConversionFactor' => $vUnitResolved['purchaseConversionFactor'],
                'defaultSalesUnitId' => $vUnitResolved['defaultSalesUnitId'],
                'salesConversionFactor' => $vUnitResolved['salesConversionFactor'],
                'purchasePricePerUnit' => $vUnitResolved['purchasePricePerUnit'] > 0 ? $vUnitResolved['purchasePricePerUnit'] : $vPurchase,
                'sellingPricePerUnit' => $vUnitResolved['sellingPricePerUnit'] > 0 ? $vUnitResolved['sellingPricePerUnit'] : $vSelling,
                'allowFractionalQuantity' => $vUnitResolved['allowFractionalQuantity'],
                'allowManualUnitOverride' => $vUnitResolved['allowManualUnitOverride'],
                'unitDisplayName' => $vUnitResolved['unitDisplayName'],
                'unitSymbol' => $vUnitResolved['unitSymbol'],
                'packSize' => $v['packSize'] ?? ($v['attributeValues']['packSize'] ?? null),
                'purchaseRate' => $vPurchase,
                'sellingPrice' => $vSelling,
                'mrp' => $vMrp,
                'defaultGstRateBps' => $defaultGstRateBps,
                'reorderLevel' => (int) ($v['reorder_level'] ?? $v['reorderLevel'] ?? $productReorderLevel),
                'quantitySlabs' => $v['quantitySlabs'] ?? $v['quantity_slabs'] ?? $quantitySlabs,
                'attributeValues' => $vAttrs,
                'status' => 'ACTIVE',
                'createdAt' => $now,
                'updatedAt' => $now,
                'createdBy' => $userId,
                'updatedBy' => $userId,
            ];

            $variantRecords[] = [
                'id' => $variantId,
                'document_path' => "product_variants/$variantId",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'ACTIVE',
                'is_deleted' => 0,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
                'payload' => json_encode([
                    '_documentId' => $variantId,
                    '_documentPath' => "product_variants/$variantId",
                    'fields' => $vFields,
                ]),
                'raw_fields' => $vFields,
            ];
        }

        $productFields['inventoryBehaviour'] = $this->stockService()->normalizeBehaviour(
            $request->input('inventoryBehaviour') ?? $request->input('inventory_behaviour'),
            $request->all()
        );
        $productFields['defaultPurchaseOptionId'] = $request->input('defaultPurchaseOptionId') ?? $request->input('default_purchase_option_id');
        $productFields['allowMultiplePurchaseOptions'] = $request->has('allowMultiplePurchaseOptions')
            ? (bool) $request->input('allowMultiplePurchaseOptions')
            : ($request->has('allow_multiple_purchase_options') ? (bool) $request->input('allow_multiple_purchase_options') : true);
        $productFields['allowMultipleSalesOptions'] = $request->has('allowMultipleSalesOptions')
            ? (bool) $request->input('allowMultipleSalesOptions')
            : ($request->has('allow_multiple_sales_options') ? (bool) $request->input('allow_multiple_sales_options') : false);
        $productFields['trackStock'] = $request->has('trackStock')
            ? (bool) $request->input('trackStock')
            : ($request->has('track_stock') ? (bool) $request->input('track_stock') : true);

        $createdPurchaseOptions = [];
        $rawPurchaseOptions = (array) ($request->input('purchaseOptions') ?? $request->input('purchase_options') ?? []);

        DB::transaction(function () use (
            $productId,
            $businessId,
            $outletId,
            $nowDb,
            $productFields,
            $variantRecords,
            $userId,
            $rawPurchaseOptions,
            &$createdPurchaseOptions
        ) {
            // 1. Insert product
            $prodRowData = [
                'id' => $productId,
                'document_path' => "products/$productId",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'tenant_id' => $userId,
                'status' => 'ACTIVE',
                'is_deleted' => 0,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
                'payload' => json_encode([
                    '_documentId' => $productId,
                    '_documentPath' => "products/$productId",
                    'fields' => $productFields,
                ]),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'inventory_behaviour')) {
                $prodRowData['inventory_behaviour'] = $productFields['inventoryBehaviour'];
                $prodRowData['default_purchase_option_id'] = $productFields['defaultPurchaseOptionId'];
                $prodRowData['allow_multiple_purchase_options'] = $productFields['allowMultiplePurchaseOptions'] ? 1 : 0;
                $prodRowData['allow_multiple_sales_options'] = $productFields['allowMultipleSalesOptions'] ? 1 : 0;
                $prodRowData['track_stock'] = $productFields['trackStock'] ? 1 : 0;
            }
            DB::table('products')->insert($prodRowData);

            // 2. Insert variants
            foreach ($variantRecords as $vRecord) {
                DB::table('product_variants')->insert([
                    'id' => $vRecord['id'],
                    'document_path' => $vRecord['document_path'],
                    'business_id' => $vRecord['business_id'],
                    'outlet_id' => $vRecord['outlet_id'],
                    'tenant_id' => $userId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $vRecord['created_at'],
                    'updated_at' => $vRecord['updated_at'],
                    'payload' => $vRecord['payload'],
                ]);
            }

            // 2b. Insert purchase/sales options
            if (! empty($rawPurchaseOptions)) {
                $createdPurchaseOptions = $this->syncPurchaseOptionsForProduct(
                    $businessId,
                    $outletId,
                    $userId,
                    $productId,
                    $rawPurchaseOptions,
                    $productFields,
                    $nowDb,
                    $nowDb->toISOString()
                );
            }

            // 3. Insert outlet placement if outlet is specified
            if ($outletId) {
                $opId = 'OP_'.strtolower(Str::random(16));
                DB::table('outlet_products')->insert([
                    'id' => $opId,
                    'document_path' => "outlet_products/$opId",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'tenant_id' => $userId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $nowDb,
                    'updated_at' => $nowDb,
                    'payload' => json_encode([
                        '_documentId' => $opId,
                        '_documentPath' => "outlet_products/$opId",
                        'fields' => [
                            'outletProductId' => $opId,
                            'productId' => $productId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'tenantId' => $userId,
                            'status' => 'ACTIVE',
                            'isActive' => true,
                            'placementSource' => 'NEW_PRODUCT',
                            'createdAt' => $nowDb->toISOString(),
                            'updatedAt' => $nowDb->toISOString(),
                        ],
                    ]),
                ]);
            }
        });

        Log::info("Product created: {$productId} ('{$name}') with ".count($variantRecords).' variant(s)');

        $productFields['variants'] = array_map(fn ($r) => $r['raw_fields'], $variantRecords);
        $productFields['purchaseOptions'] = $createdPurchaseOptions;

        return ApiResponse::success([
            'product' => $productFields,
        ], 'Product created successfully.', 201);
    }

    /**
     * Update an existing product.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->mergeMultipartPayload($request);
        $user = $request->user();
        $prodRow = DB::table('products')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $prodRow) {
            return ApiResponse::error('Product not found.', 404);
        }

        if ($user && ! $user->canAccessBusiness((string) $prodRow->business_id)) {
            return ApiResponse::error('Unauthorized access to this product.', 403, null, 'FORBIDDEN');
        }

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'productName' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:64'],
            'productCode' => ['nullable', 'string', 'max:64'],
            'brand' => ['nullable', 'string'],
            'brandId' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'categoryId' => ['nullable', 'string'],
            'subcategoryId' => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:1000'],
            'product_image' => ['nullable', 'string'],
            'productImage' => ['nullable', 'string'],
            'product_image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE,active,inactive'],
            'default_gst_rate_bps' => ['nullable', 'integer'],
            'hsn_code' => ['nullable', 'string'],
            'quantity_slabs' => ['nullable', 'array'],
            'quantitySlabs' => ['nullable', 'array'],
            'variants' => ['nullable', 'array'],
            'attributeValues' => ['nullable', 'array'],
        ]);

        $payload = json_decode($prodRow->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        $now = now();
        $nowIso = $now->toISOString();

        if ($request->has('name') || $request->has('productName')) {
            $fields['productName'] = trim((string) ($request->input('name') ?? $request->input('productName')));
            $fields['name'] = $fields['productName'];
        }
        if ($request->has('product_code') || $request->has('productCode')) {
            $fields['productCode'] = trim((string) ($request->input('product_code') ?? $request->input('productCode')));
        }
        if ($request->has('brand') || $request->has('brandId')) {
            $fields['brand'] = $request->input('brand') ?? ($fields['brand'] ?? null);
            $fields['brandId'] = $request->input('brandId') ?? ($fields['brandId'] ?? null);
        }
        if ($request->has('category') || $request->has('categoryId')) {
            $fields['category'] = $request->input('category') ?? ($fields['category'] ?? null);
            $fields['categoryId'] = $request->input('categoryId') ?? ($fields['categoryId'] ?? null);
        }
        if ($request->has('subcategoryId') || $request->has('subcategory')) {
            $fields['subcategoryId'] = $request->input('subcategoryId') ?? ($fields['subcategoryId'] ?? null);
            $fields['subcategory'] = $request->input('subcategory') ?? ($fields['subcategory'] ?? null);
        }
        if ($request->has('description')) {
            $fields['description'] = $request->input('description');
        }
        if ($request->has('product_image') || $request->has('productImage')) {
            $imageUrl = $request->input('product_image') ?? $request->input('productImage');
            $fields['productImage'] = $imageUrl;
            $fields['imageUrl'] = $imageUrl;
        }
        if ($request->hasFile('product_image_file')) {
            $imagePath = $request->file('product_image_file')->storePublicly('products', 'public');
            $imageUrl = Storage::disk('public')->url($imagePath);
            $fields['productImage'] = $imageUrl;
            $fields['imageUrl'] = $imageUrl;
        }
        if ($request->has('default_gst_rate_bps')) {
            $fields['defaultGstRateBps'] = (int) $request->input('default_gst_rate_bps');
        }
        if ($request->has('hsn_code')) {
            $fields['hsnCode'] = $request->input('hsn_code');
        }
        if ($request->has('quantitySlabs') || $request->has('quantity_slabs')) {
            $fields['quantitySlabs'] = (array) ($request->input('quantitySlabs') ?? $request->input('quantity_slabs') ?? []);
        }
        if ($request->has('attributeValues')) {
            $fields['attributeValues'] = (array) $request->input('attributeValues');
        }
        foreach ([
            'baseUnitId' => ['baseUnitId', 'base_unit_id'],
            'purchaseUnitId' => ['purchaseUnitId', 'purchase_unit_id'],
            'purchaseConversionFactor' => ['purchaseConversionFactor', 'purchase_conversion_factor'],
            'defaultSalesUnitId' => ['defaultSalesUnitId', 'default_sales_unit_id'],
            'salesConversionFactor' => ['salesConversionFactor', 'sales_conversion_factor'],
            'purchasePricePerUnit' => ['purchasePricePerUnit', 'purchase_price_per_unit'],
            'sellingPricePerUnit' => ['sellingPricePerUnit', 'selling_price_per_unit'],
            'allowFractionalQuantity' => ['allowFractionalQuantity', 'allow_fractional_quantity'],
            'allowManualUnitOverride' => ['allowManualUnitOverride', 'allow_manual_unit_override'],
            'unitDisplayName' => ['unitDisplayName', 'unit_display_name'],
            'unitSymbol' => ['unitSymbol', 'unit_symbol'],
            'inventoryBehaviour' => ['inventoryBehaviour', 'inventory_behaviour'],
            'defaultPurchaseOptionId' => ['defaultPurchaseOptionId', 'default_purchase_option_id'],
            'allowMultiplePurchaseOptions' => ['allowMultiplePurchaseOptions', 'allow_multiple_purchase_options'],
            'allowMultipleSalesOptions' => ['allowMultipleSalesOptions', 'allow_multiple_sales_options'],
            'trackStock' => ['trackStock', 'track_stock'],
            'reorderLevel' => ['reorderLevel', 'reorder_level'],
        ] as $canonKey => $aliases) {
            foreach ($aliases as $alias) {
                if ($request->has($alias)) {
                    $fields[$canonKey] = $request->input($alias);
                    break;
                }
            }
        }
        $fields['inventoryBehaviour'] = $this->stockService()->normalizeBehaviour(
            $fields['inventoryBehaviour'] ?? null,
            $fields
        );
        if ($request->has('status')) {
            $status = strtoupper((string) $request->input('status'));
            $fields['status'] = $status;
            $fields['isActive'] = ($status === 'ACTIVE');
        }
        $fields['updatedAt'] = $nowIso;
        $payload['fields'] = $fields;

        $businessId = (string) $prodRow->business_id;
        $userId = $user ? (string) $user->id : (string) ($prodRow->tenant_id ?? '');

        DB::transaction(function () use ($id, $payload, $now, $nowIso, $fields, $request, $prodRow, $businessId, $userId): void {
            $prodUpdate = [
                'status' => $fields['status'] ?? 'ACTIVE',
                'updated_at' => $now,
                'payload' => json_encode($payload),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'inventory_behaviour')) {
                $prodUpdate['inventory_behaviour'] = $fields['inventoryBehaviour'];
                $prodUpdate['default_purchase_option_id'] = $fields['defaultPurchaseOptionId'] ?? null;
                $prodUpdate['allow_multiple_purchase_options'] = ! empty($fields['allowMultiplePurchaseOptions'] ?? true) ? 1 : 0;
                $prodUpdate['allow_multiple_sales_options'] = ! empty($fields['allowMultipleSalesOptions'] ?? false) ? 1 : 0;
                $prodUpdate['track_stock'] = array_key_exists('trackStock', $fields) ? (! empty($fields['trackStock']) ? 1 : 0) : 1;
            }
            DB::table('products')->where('id', $id)->update($prodUpdate);

            if ($request->has('purchaseOptions') || $request->has('purchase_options')) {
                $rawOptions = (array) ($request->input('purchaseOptions') ?? $request->input('purchase_options') ?? []);
                $this->syncPurchaseOptionsForProduct(
                    $businessId,
                    $prodRow->outlet_id ?? null,
                    $userId ?: (string) ($prodRow->tenant_id ?? ''),
                    $id,
                    $rawOptions,
                    $fields,
                    $now,
                    $nowIso
                );
            }

            if ($request->has('variants')) {
                $inputVariants = (array) $request->input('variants', []);
                $existingVariants = DB::table('product_variants')
                    ->where('is_deleted', 0)
                    ->where(function ($q) use ($id, $businessId) {
                        $q->where(function ($sub) use ($id) {
                            $sub->where('payload->fields->productId', $id)
                                ->orWhere('id', 'like', "%{$id}%");
                        });
                        if (!empty($businessId)) {
                            $q->where(function ($bSub) use ($businessId) {
                                $bSub->where('business_id', $businessId)
                                     ->orWhereNull('business_id');
                            });
                        }
                    })
                    ->get()
                    ->keyBy('id');

                $processedVariantIds = [];

                foreach ($inputVariants as $idx => $v) {
                    if (!is_array($v)) {
                        continue;
                    }
                    $vId = $v['variantId'] ?? $v['id'] ?? null;
                    if ($vId && isset($existingVariants[$vId])) {
                        // Existing variant - UPDATE
                        $existingRow = $existingVariants[$vId];
                        $vPayload = json_decode($existingRow->payload, true) ?: [];
                        $vFields = $vPayload['fields'] ?? [];

                        $vFields['productId'] = $id;
                        $vFields['businessId'] = $businessId;
                        $vFields['productName'] = $fields['productName'] ?? $prodRow->name ?? ($vFields['productName'] ?? '');

                        $vName = trim((string) ($v['name'] ?? $v['variantName'] ?? $v['displayName'] ?? ''));
                        if ($vName !== '') {
                            $vFields['name'] = $vName;
                            $vFields['variantName'] = $vName;
                            $vFields['displayName'] = $vName;
                        }

                        if (array_key_exists('barcode', $v)) {
                            $vFields['barcode'] = !empty($v['barcode']) ? $v['barcode'] : null;
                        }
                        if (!empty($v['sku'])) {
                            $vFields['sku'] = $v['sku'];
                        }
                        if (isset($v['baseUnitId']) || isset($v['unitId']) || isset($v['base_unit_id'])) {
                            $requestedUnitRaw = (string) ($v['baseUnitId'] ?? $v['unitId'] ?? $v['base_unit_id']);
                            $prevResolvedUnit = $this->unitService->resolveUnit(
                                (string) ($vFields['baseUnitId'] ?? $vFields['unitId'] ?? 'UNIT'),
                                $businessId
                            );
                            $nextResolvedUnit = $this->unitService->resolveUnit($requestedUnitRaw, $businessId);
                            $allowUnitChangeWithStock = $request->boolean('allow_unit_change_with_stock')
                                || ! empty($v['allowUnitChangeWithStock'])
                                || ! empty($v['unitConversionAdjustment']);

                            if ($prevResolvedUnit['id'] !== $nextResolvedUnit['id'] && ! $allowUnitChangeWithStock && Schema::hasTable('stock_balances')) {
                                $stockSvc = $this->stockService();
                                $stockRows = DB::table('stock_balances')
                                    ->where('business_id', $businessId)
                                    ->where('is_deleted', 0)
                                    ->where(function ($q) use ($vId) {
                                        $q->where('variant_id', $vId)
                                            ->orWhere('payload', 'like', '%"variantId":"'.$vId.'"%');
                                    })
                                    ->get();
                                $totalStockMilli = 0;
                                foreach ($stockRows as $sbRow) {
                                    $totalStockMilli += $stockSvc->parseBalanceMilli($sbRow);
                                }
                                if (abs($totalStockMilli) > 0) {
                                    throw ValidationException::withMessages([
                                        'baseUnitId' => "Cannot change measurement unit from {$prevResolvedUnit['symbol']} to {$nextResolvedUnit['symbol']} while variant has existing stock. Please convert or adjust stock to zero first.",
                                    ]);
                                }
                            }

                            $vFields['baseUnitId'] = $requestedUnitRaw;
                        }
                        if (array_key_exists('packUnitId', $v) || array_key_exists('pack_unit_id', $v)) {
                            $vFields['packUnitId'] = $v['packUnitId'] ?? $v['pack_unit_id'] ?? null;
                        }
                        if (array_key_exists('commercialUnitId', $v) || array_key_exists('commercial_unit_id', $v)) {
                            $vFields['commercialUnitId'] = $v['commercialUnitId'] ?? $v['commercial_unit_id'] ?? null;
                        }
                        if (array_key_exists('commercialUnitName', $v) || array_key_exists('commercial_unit_name', $v)) {
                            $vFields['commercialUnitName'] = $v['commercialUnitName'] ?? $v['commercial_unit_name'] ?? null;
                        }
                        if (isset($v['baseQuantityPerCommercialUnit'])) {
                            $vFields['baseQuantityPerCommercialUnit'] = (float) $v['baseQuantityPerCommercialUnit'];
                        }
                        if (array_key_exists('packSize', $v) || isset($v['attributeValues']['packSize'])) {
                            $vFields['packSize'] = $v['packSize'] ?? ($v['attributeValues']['packSize'] ?? null);
                        }
                        if (isset($v['sellingPrice']) || isset($v['selling_price'])) {
                            $vFields['sellingPrice'] = (int) ($v['sellingPrice'] ?? $v['selling_price']);
                        }
                        if (isset($v['purchaseRate']) || isset($v['purchase_rate'])) {
                            $vFields['purchaseRate'] = (int) ($v['purchaseRate'] ?? $v['purchase_rate']);
                        }
                        if (isset($v['mrp'])) {
                            $vFields['mrp'] = (int) $v['mrp'];
                        }
                        if (isset($v['defaultGstRateBps']) || isset($v['default_gst_rate_bps'])) {
                            $vFields['defaultGstRateBps'] = (int) ($v['defaultGstRateBps'] ?? $v['default_gst_rate_bps']);
                        }
                        if (isset($v['reorderLevel']) || isset($v['reorder_level'])) {
                            $vFields['reorderLevel'] = (int) ($v['reorderLevel'] ?? $v['reorder_level']);
                        } elseif (isset($fields['reorderLevel']) && ! isset($vFields['reorderLevel'])) {
                            $vFields['reorderLevel'] = (int) $fields['reorderLevel'];
                        }
                        if (isset($v['quantitySlabs']) || isset($v['quantity_slabs'])) {
                            $vFields['quantitySlabs'] = (array) ($v['quantitySlabs'] ?? $v['quantity_slabs']);
                        } elseif (isset($fields['quantitySlabs'])) {
                            $vFields['quantitySlabs'] = $fields['quantitySlabs'];
                        }
                        if (isset($v['attributeValues']) || isset($v['attribute_values'])) {
                            $vFields['attributeValues'] = (array) ($v['attributeValues'] ?? $v['attribute_values']);
                        }
                        foreach ([
                            'purchaseUnitId' => ['purchaseUnitId', 'purchase_unit_id'],
                            'purchaseConversionFactor' => ['purchaseConversionFactor', 'purchase_conversion_factor', 'purchaseUnitConversionFactor'],
                            'defaultSalesUnitId' => ['defaultSalesUnitId', 'default_sales_unit_id', 'salesUnitId'],
                            'salesConversionFactor' => ['salesConversionFactor', 'sales_conversion_factor'],
                            'purchasePricePerUnit' => ['purchasePricePerUnit', 'purchase_price_per_unit'],
                            'sellingPricePerUnit' => ['sellingPricePerUnit', 'selling_price_per_unit'],
                            'allowFractionalQuantity' => ['allowFractionalQuantity', 'allow_fractional_quantity'],
                            'allowManualUnitOverride' => ['allowManualUnitOverride', 'allow_manual_unit_override'],
                            'unitDisplayName' => ['unitDisplayName', 'unit_display_name'],
                            'unitSymbol' => ['unitSymbol', 'unit_symbol'],
                        ] as $canonKey => $aliases) {
                            foreach ($aliases as $alias) {
                                if (array_key_exists($alias, $v)) {
                                    $vFields[$canonKey] = $v[$alias];
                                    break;
                                }
                            }
                        }
                        $resolvedUpd = $this->unitService->resolveVariantUnitConfig($vFields, $fields, $businessId);
                        $vFields = array_merge($vFields, $resolvedUpd);
                        $vStatus = strtoupper((string) ($v['status'] ?? ($vFields['status'] ?? 'ACTIVE')));
                        $vFields['status'] = $vStatus;
                        $vFields['updatedAt'] = $nowIso;
                        $vFields['updatedBy'] = $userId ?: ($vFields['updatedBy'] ?? null);

                        $vPayload['fields'] = $vFields;

                        DB::table('product_variants')->where('id', $vId)->update([
                            'status' => $vStatus,
                            'updated_at' => $now,
                            'payload' => json_encode($vPayload),
                        ]);

                        $processedVariantIds[] = $vId;
                    } else {
                        // New variant - INSERT
                        $variantId = 'VAR-'.strtoupper(Str::random(24));
                        $vAttrs = (array) ($v['attributeValues'] ?? $v['attribute_values'] ?? []);
                        $vName = trim((string) ($v['name'] ?? $v['variantName'] ?? $v['displayName'] ?? ''));
                        if ($vName === '') {
                            $parts = array_filter([
                                $vAttrs['shade'] ?? null,
                                $vAttrs['colourFamily'] ?? null,
                                isset($v['packSize']) || isset($vAttrs['packSize']) ? (($v['packSize'] ?? $vAttrs['packSize']).' '.($v['packUnitId'] ?? '')) : null,
                            ]);
                            $vName = !empty($parts) ? implode(' - ', $parts) : ('Variant ' . ($idx + 1));
                        }

                        $vSelling = (int) ($v['selling_price'] ?? $v['sellingPrice'] ?? ($fields['sellingPrice'] ?? 0));
                        $vMrp = (int) ($v['mrp'] ?? ($fields['mrp'] ?? 0));
                        $vPurchase = (int) ($v['purchase_rate'] ?? $v['purchaseRate'] ?? ($fields['purchaseRate'] ?? 0));
                        $vUnit = (string) ($v['base_unit_id'] ?? $v['baseUnitId'] ?? $v['unitId'] ?? ($fields['baseUnitId'] ?? 'UNIT'));
                        $vCommUnitName = $v['commercial_unit_name'] ?? $v['commercialUnitName'] ?? null;
                        $vBarcode = $v['barcode'] ?? null;
                        $vSku = !empty($v['sku']) ? $v['sku'] : ('SKU-'.strtoupper(Str::random(6)));
                        $vGst = isset($v['defaultGstRateBps']) ? (int) $v['defaultGstRateBps'] : ($fields['defaultGstRateBps'] ?? 0);
                        $vSlabs = $v['quantitySlabs'] ?? $v['quantity_slabs'] ?? ($fields['quantitySlabs'] ?? []);

                        $newVBase = [
                            'variantId' => $variantId,
                            'productId' => $id,
                            'businessId' => $businessId,
                            'tenantId' => $userId ?: $prodRow->tenant_id,
                            'name' => $vName,
                            'variantName' => $vName,
                            'productName' => $fields['productName'] ?? $prodRow->name ?? '',
                            'displayName' => $vName,
                            'sku' => $vSku,
                            'barcode' => $vBarcode,
                            'baseUnitId' => $vUnit,
                            'packUnitId' => $v['packUnitId'] ?? $v['pack_unit_id'] ?? null,
                            'commercialUnitId' => $v['commercialUnitId'] ?? $v['commercial_unit_id'] ?? null,
                            'commercialUnitName' => $vCommUnitName,
                            'baseQuantityPerCommercialUnit' => (float) ($v['baseQuantityPerCommercialUnit'] ?? $v['salesConversionFactor'] ?? 1),
                            'purchaseUnitId' => $v['purchaseUnitId'] ?? $v['purchase_unit_id'] ?? null,
                            'purchaseConversionFactor' => $v['purchaseConversionFactor'] ?? $v['purchase_conversion_factor'] ?? null,
                            'defaultSalesUnitId' => $v['defaultSalesUnitId'] ?? $v['default_sales_unit_id'] ?? null,
                            'salesConversionFactor' => $v['salesConversionFactor'] ?? $v['sales_conversion_factor'] ?? null,
                            'purchasePricePerUnit' => $v['purchasePricePerUnit'] ?? $v['purchase_price_per_unit'] ?? $vPurchase,
                            'sellingPricePerUnit' => $v['sellingPricePerUnit'] ?? $v['selling_price_per_unit'] ?? $vSelling,
                            'allowFractionalQuantity' => $v['allowFractionalQuantity'] ?? $v['allow_fractional_quantity'] ?? null,
                            'allowManualUnitOverride' => $v['allowManualUnitOverride'] ?? $v['allow_manual_unit_override'] ?? null,
                            'unitDisplayName' => $v['unitDisplayName'] ?? $v['unit_display_name'] ?? null,
                            'unitSymbol' => $v['unitSymbol'] ?? $v['unit_symbol'] ?? null,
                            'packSize' => $v['packSize'] ?? ($vAttrs['packSize'] ?? null),
                            'purchaseRate' => $vPurchase,
                            'sellingPrice' => $vSelling,
                            'mrp' => $vMrp,
                            'defaultGstRateBps' => $vGst,
                            'reorderLevel' => (int) ($v['reorderLevel'] ?? $v['reorder_level'] ?? ($fields['reorderLevel'] ?? 0)),
                            'quantitySlabs' => $vSlabs,
                            'attributeValues' => $vAttrs,
                            'status' => 'ACTIVE',
                            'createdAt' => $nowIso,
                            'updatedAt' => $nowIso,
                            'createdBy' => $userId ?: $prodRow->tenant_id,
                            'updatedBy' => $userId ?: $prodRow->tenant_id,
                        ];
                        $newVResolved = $this->unitService->resolveVariantUnitConfig($newVBase, $fields, $businessId);
                        $vFields = array_merge($newVBase, $newVResolved, [
                            'commercialUnitId' => $newVBase['commercialUnitId'] ?? $newVResolved['defaultSalesUnitId'],
                            'commercialUnitName' => $vCommUnitName ?: $newVResolved['unitDisplayName'],
                        ]);

                        DB::table('product_variants')->insert([
                            'id' => $variantId,
                            'document_path' => "product_variants/$variantId",
                            'business_id' => $businessId,
                            'outlet_id' => $prodRow->outlet_id,
                            'tenant_id' => $userId ?: $prodRow->tenant_id,
                            'status' => 'ACTIVE',
                            'is_deleted' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                            'payload' => json_encode([
                                '_documentId' => $variantId,
                                '_documentPath' => "product_variants/$variantId",
                                'fields' => $vFields,
                            ]),
                        ]);

                        $processedVariantIds[] = $variantId;
                    }
                }

                // Soft-delete removed variants
                foreach ($existingVariants as $oldId => $oldRow) {
                    if (!in_array($oldId, $processedVariantIds, true)) {
                        DB::table('product_variants')->where('id', $oldId)->update([
                            'is_deleted' => 1,
                            'status' => 'INACTIVE',
                            'updated_at' => $now,
                        ]);
                    }
                }
            } elseif ($request->has('quantitySlabs') || $request->has('quantity_slabs')) {
                $slabs = (array) ($request->input('quantitySlabs') ?? $request->input('quantity_slabs') ?? []);
                $vRows = DB::table('product_variants')
                    ->where(function ($q) use ($id) {
                        $q->where('payload->fields->productId', $id)
                            ->orWhere('id', 'like', "%{$id}%");
                    })
                    ->where('is_deleted', 0)
                    ->get();
                foreach ($vRows as $vRow) {
                    $vPayload = json_decode($vRow->payload, true) ?: [];
                    $vPayload['fields']['quantitySlabs'] = $slabs;
                    DB::table('product_variants')->where('id', $vRow->id)->update([
                        'payload' => json_encode($vPayload),
                        'updated_at' => $now,
                    ]);
                }
            }
        });

        $activeVariants = DB::table('product_variants')
            ->where('is_deleted', 0)
            ->where(function ($q) use ($id, $businessId) {
                $q->where(function ($sub) use ($id) {
                    $sub->where('payload->fields->productId', $id)
                        ->orWhere('id', 'like', "%{$id}%");
                });
                if (!empty($businessId)) {
                    $q->where(function ($bSub) use ($businessId) {
                        $bSub->where('business_id', $businessId)
                             ->orWhereNull('business_id');
                    });
                }
            })
            ->get();

        $assembledVariants = [];
        foreach ($activeVariants as $vRow) {
            $vPayload = json_decode($vRow->payload, true) ?: [];
            $vFields = $vPayload['fields'] ?? [];
            $vAttrs = $vFields['attributeValues'] ?? [];
            $vAttrsMap = !empty($vAttrs) ? (array) $vAttrs : [];

            $vUnit = (string) ($vFields['baseUnitId'] ?? $vFields['unitId'] ?? $fields['baseUnitId'] ?? 'UNIT');
            $vCommUnitName = $vFields['commercialUnitName'] ?? null;
            $vSellingPrice = self::extractInt($vFields['sellingPrice'] ?? $vFields['selling_price'] ?? null, 0);
            $vMrp = self::extractInt($vFields['mrp'] ?? null, 0);
            $vPurchaseRate = self::extractInt($vFields['purchaseRate'] ?? $vFields['purchase_rate'] ?? null, 0);

            $assembledVariants[] = [
                'id' => $vRow->id,
                'variantId' => $vFields['variantId'] ?? $vRow->id,
                'productId' => $id,
                'businessId' => (string) $vRow->business_id,
                'tenantId' => (string) ($vFields['tenantId'] ?? ''),
                'name' => (string) ($vFields['variantName'] ?? $vFields['name'] ?? 'Standard Variant'),
                'variantName' => (string) ($vFields['variantName'] ?? $vFields['name'] ?? 'Standard Variant'),
                'displayName' => (string) ($vFields['displayName'] ?? $vFields['name'] ?? ''),
                'productName' => (string) ($fields['productName'] ?? $fields['name'] ?? $prodRow->name ?? ''),
                'brand' => (string) ($fields['brand'] ?? ''),
                'category' => (string) ($fields['categoryName'] ?? $fields['category'] ?? ''),
                'sku' => $vFields['sku'] ?? $vFields['variantCode'] ?? null,
                'barcode' => $vFields['barcode'] ?? null,
                'hsnCode' => $vFields['hsnCode'] ?? $fields['hsnCode'] ?? null,
                'baseUnitId' => $vUnit,
                'packUnitId' => $vFields['packUnitId'] ?? null,
                'commercialUnitId' => $vFields['commercialUnitId'] ?? null,
                'commercialUnitName' => $vCommUnitName,
                'baseQuantityPerCommercialUnit' => self::extractFloat($vFields['baseQuantityPerCommercialUnit'] ?? null, 1.0),
                'packSize' => $vFields['packSize'] ?? ($vAttrsMap['packSize'] ?? null),
                'purchaseRate' => $vPurchaseRate,
                'sellingPrice' => $vSellingPrice,
                'mrp' => $vMrp,
                'defaultGstRateBps' => self::extractInt($vFields['defaultGstRateBps'] ?? $fields['defaultGstRateBps'] ?? null, 0),
                'status' => (string) ($vFields['status'] ?? 'ACTIVE'),
                'isActive' => (($vFields['status'] ?? 'ACTIVE') === 'ACTIVE'),
                'quantitySlabs' => $vFields['quantitySlabs'] ?? $vFields['quantity_slabs'] ?? ($fields['quantitySlabs'] ?? []),
                'attributeValues' => !empty($vAttrsMap) ? $vAttrsMap : (object) [],
            ];
        }
        $fields['variants'] = $assembledVariants;
        $optionsByProduct = $this->stockService()->getPurchaseOptionsByProductIds($businessId, [$id]);
        $fields['purchaseOptions'] = $optionsByProduct[$id] ?? [];

        return ApiResponse::success($fields, 'Product updated successfully.');
    }

    /**
     * Soft delete product and its variants.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $prodRow = DB::table('products')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $prodRow) {
            return ApiResponse::error('Product not found.', 404);
        }

        if ($user && ! $user->canAccessBusiness((string) $prodRow->business_id)) {
            return ApiResponse::error('Unauthorized to delete this product.', 403, null, 'FORBIDDEN');
        }

        $nowDb = now();

        DB::transaction(function () use ($id, $nowDb) {
            DB::table('products')
                ->where('id', $id)
                ->update([
                    'is_deleted' => 1,
                    'status' => 'INACTIVE',
                    'updated_at' => $nowDb,
                ]);

            DB::table('product_variants')
                ->where('payload->fields->productId', $id)
                ->update([
                    'is_deleted' => 1,
                    'status' => 'INACTIVE',
                    'updated_at' => $nowDb,
                ]);

            DB::table('outlet_products')
                ->where('payload->fields->productId', $id)
                ->update([
                    'is_deleted' => 1,
                    'status' => 'INACTIVE',
                    'updated_at' => $nowDb,
                ]);
        });

        return ApiResponse::success(null, 'Product deleted successfully.');
    }

    /**
     * Remove products from an outlet.
     */
    public function removeFromOutlet(Request $request, string $outletId): JsonResponse
    {
        $productIds = (array) ($request->input('productIds') ?? $request->input('product_ids') ?? [$request->input('productId')]);
        $productIds = array_values(array_filter($productIds));

        if (empty($productIds)) {
            return ApiResponse::error('No product IDs provided.', 422);
        }

        $nowDb = now();
        $businessId = $request->input('business_id') ?? $request->input('businessId') ?? $request->header('X-Business-Id');

        $count = DB::transaction(function () use ($outletId, $productIds, $businessId, $nowDb) {
            $query = DB::table('outlet_products')
                ->where('outlet_id', $outletId)
                ->whereIn('payload->fields->productId', $productIds);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }

            $rows = $query->get();
            $updated = 0;

            foreach ($rows as $row) {
                $payload = json_decode($row->payload, true) ?: [];
                $fields = $payload['fields'] ?? [];
                $fields['status'] = 'INACTIVE';
                $fields['isActive'] = false;
                $fields['updatedAt'] = $nowDb->toISOString();
                $payload['fields'] = $fields;

                DB::table('outlet_products')
                    ->where('id', $row->id)
                    ->update([
                        'is_deleted' => 1,
                        'status' => 'INACTIVE',
                        'updated_at' => $nowDb,
                        'payload' => json_encode($payload),
                    ]);
                $updated++;
            }

            return $updated;
        });

        return ApiResponse::success(['count' => $count], "Removed {$count} product(s) from outlet successfully.");
    }

    /**
     * Assign products to an outlet.
     */
    public function assignToOutlet(Request $request, string $outletId): JsonResponse
    {
        $user = $request->user();
        $userId = $user ? (string) $user->id : (string) ($request->header('X-User-Id') ?? 'USR-OWNER-001');

        $productIds = (array) ($request->input('productIds') ?? $request->input('product_ids') ?? [$request->input('productId')]);
        $productIds = array_values(array_filter($productIds));

        if (empty($productIds)) {
            return ApiResponse::error('No product IDs provided.', 422);
        }

        $businessId = $request->input('business_id') ?? $request->input('businessId') ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $firstOutlet = DB::table('outlets')->where('id', $outletId)->first();
            $businessId = $firstOutlet ? $firstOutlet->business_id : null;
        }

        if (! $businessId) {
            return ApiResponse::error('Business ID is required.', 422);
        }

        $nowDb = now();

        $count = DB::transaction(function () use ($outletId, $productIds, $businessId, $userId, $nowDb) {
            $updated = 0;

            foreach ($productIds as $prodId) {
                $existing = DB::table('outlet_products')
                    ->where('business_id', $businessId)
                    ->where('outlet_id', $outletId)
                    ->where('payload->fields->productId', $prodId)
                    ->first();

                if ($existing) {
                    $payload = json_decode($existing->payload, true) ?: [];
                    $fields = $payload['fields'] ?? [];
                    $fields['status'] = 'ACTIVE';
                    $fields['isActive'] = true;
                    $fields['updatedAt'] = $nowDb->toISOString();
                    $payload['fields'] = $fields;

                    DB::table('outlet_products')
                        ->where('id', $existing->id)
                        ->update([
                            'is_deleted' => 0,
                            'status' => 'ACTIVE',
                            'updated_at' => $nowDb,
                            'payload' => json_encode($payload),
                        ]);
                    $updated++;
                } else {
                    $opId = 'OP_'.strtolower(Str::random(16));
                    DB::table('outlet_products')->insert([
                        'id' => $opId,
                        'document_path' => "outlet_products/$opId",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'status' => 'ACTIVE',
                        'is_deleted' => 0,
                        'created_at' => $nowDb,
                        'updated_at' => $nowDb,
                        'payload' => json_encode([
                            '_documentId' => $opId,
                            '_documentPath' => "outlet_products/$opId",
                            'fields' => [
                                'outletProductId' => $opId,
                                'productId' => $prodId,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'tenantId' => $userId,
                                'status' => 'ACTIVE',
                                'isActive' => true,
                                'placementSource' => 'EXISTING_PRODUCT',
                                'createdAt' => $nowDb->toISOString(),
                                'updatedAt' => $nowDb->toISOString(),
                            ],
                        ]),
                    ]);
                    $updated++;
                }
            }

            return $updated;
        });

        return ApiResponse::success(['count' => $count], "Assigned {$count} product(s) to outlet successfully.");
    }

    /**
     * Update status of products and their variants.
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $productIds = (array) ($request->input('productIds') ?? $request->input('product_ids') ?? [$request->input('productId')]);
        $productIds = array_values(array_filter($productIds));
        $status = strtoupper((string) ($request->input('status') ?? 'ACTIVE'));

        if (empty($productIds)) {
            return ApiResponse::error('No product IDs provided.', 422);
        }

        if (! in_array($status, ['ACTIVE', 'INACTIVE', 'DELETED'])) {
            return ApiResponse::error('Invalid product status.', 422);
        }

        $user = $request->user();
        if ($user && ! $user->isAdmin()) {
            $accessibleBusinessIds = $user->accessibleBusinessIds();
            $invalidCount = DB::table('products')
                ->whereIn('id', $productIds)
                ->whereNotIn('business_id', $accessibleBusinessIds)
                ->count();
            if ($invalidCount > 0) {
                return ApiResponse::error('Unauthorized access to one or more selected products.', 403, null, 'FORBIDDEN');
            }
        }

        $nowDb = now();
        $isDeleted = ($status === 'DELETED') ? 1 : 0;
        $activeStatus = ($status === 'DELETED') ? 'INACTIVE' : $status;

        DB::transaction(function () use ($productIds, $activeStatus, $isDeleted, $nowDb) {
            foreach ($productIds as $prodId) {
                // Update product row
                $prodRow = DB::table('products')->where('id', $prodId)->first();
                if ($prodRow) {
                    $payload = json_decode($prodRow->payload, true) ?: [];
                    $fields = $payload['fields'] ?? [];
                    $fields['status'] = $activeStatus;
                    $fields['isActive'] = ($activeStatus === 'ACTIVE');
                    $fields['updatedAt'] = $nowDb->toISOString();
                    $payload['fields'] = $fields;

                    DB::table('products')->where('id', $prodId)->update([
                        'status' => $activeStatus,
                        'is_deleted' => $isDeleted,
                        'updated_at' => $nowDb,
                        'payload' => json_encode($payload),
                    ]);
                }

                // Update product variants
                $variantRows = DB::table('product_variants')
                    ->where('payload->fields->productId', $prodId)
                    ->get();

                foreach ($variantRows as $vRow) {
                    $vPayload = json_decode($vRow->payload, true) ?: [];
                    $vFields = $vPayload['fields'] ?? [];
                    $vFields['status'] = $activeStatus;
                    $vFields['isActive'] = ($activeStatus === 'ACTIVE');
                    $vFields['updatedAt'] = $nowDb->toISOString();
                    $vPayload['fields'] = $vFields;

                    DB::table('product_variants')->where('id', $vRow->id)->update([
                        'status' => $activeStatus,
                        'is_deleted' => $isDeleted,
                        'updated_at' => $nowDb,
                        'payload' => json_encode($vPayload),
                    ]);
                }

                // If deleted, also mark outlet placements as deleted
                if ($isDeleted) {
                    DB::table('outlet_products')
                        ->where('payload->fields->productId', $prodId)
                        ->update([
                            'is_deleted' => 1,
                            'status' => 'INACTIVE',
                            'updated_at' => $nowDb,
                        ]);
                }
            }
        });

        return ApiResponse::success(['count' => count($productIds)], 'Product status updated successfully.');
    }

    /**
     * Master data lookups: brands, categories, subcategories, units, hsn codes, pack sizes.
     */
    public function masters(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');

        // Brands
        $brandRows = DB::table('brands')
            ->where('is_deleted', 0)
            ->get();
        $brands = [];
        foreach ($brandRows as $bRow) {
            $p = json_decode($bRow->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $bBusId = $f['businessId'] ?? $bRow->business_id;
            if ($bBusId && $businessId && $bBusId !== $businessId) {
                continue;
            }
            $brands[] = [
                'id' => (string) ($f['brandId'] ?? $bRow->id),
                'name' => (string) ($f['name'] ?? $bRow->id),
                'status' => $f['status'] ?? 'ACTIVE',
            ];
        }

        // Categories
        $catRows = DB::table('product_categories')
            ->where('is_deleted', 0)
            ->get();
        $categories = [];
        foreach ($catRows as $cRow) {
            $p = json_decode($cRow->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $categories[] = [
                'id' => (string) ($f['categoryId'] ?? $cRow->id),
                'name' => (string) ($f['name'] ?? $cRow->id),
                'code' => $f['code'] ?? null,
                'status' => $f['status'] ?? 'ACTIVE',
            ];
        }

        // Subcategories
        $subcatRows = DB::table('product_subcategories')
            ->where('is_deleted', 0)
            ->get();
        $subcategories = [];
        foreach ($subcatRows as $scRow) {
            $p = json_decode($scRow->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $subcategories[] = [
                'id' => (string) ($f['subcategoryId'] ?? $scRow->id),
                'categoryId' => (string) ($f['categoryId'] ?? ''),
                'name' => (string) ($f['name'] ?? $scRow->id),
                'status' => $f['status'] ?? 'ACTIVE',
            ];
        }

        // Units (merge central DB units with standard reusable units)
        $unitRows = DB::table('units')
            ->where('is_deleted', 0)
            ->get();
        $units = [];
        $seenUnitIds = [];
        foreach ($unitRows as $uRow) {
            $p = json_decode($uRow->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $uId = (string) ($f['unitId'] ?? $uRow->id);
            $seenUnitIds[$uId] = true;
            $precision = (int) ($uRow->decimal_precision ?? $f['precision'] ?? 0);
            $name = (string) ($uRow->name ?? $f['name'] ?? $uId);
            $symbol = (string) ($uRow->symbol ?? $f['symbol'] ?? $name);
            $units[] = [
                'id' => $uId,
                'code' => (string) ($uRow->code ?? $f['code'] ?? strtolower($symbol)),
                'name' => $name,
                'displayName' => (string) ($uRow->display_name ?? $f['displayName'] ?? $name),
                'symbol' => $symbol,
                'allowFractional' => isset($uRow->allow_fractional)
                    ? (bool) $uRow->allow_fractional
                    : (bool) ($f['allowFractional'] ?? ($precision > 0)),
                'precision' => $precision,
                'measurementDimension' => $uRow->measurement_dimension ?? $f['measurementDimension'] ?? null,
            ];
        }
        foreach ($this->unitService->standardUnitsForApi() as $stdUnit) {
            if (! isset($seenUnitIds[$stdUnit['id']])) {
                $units[] = $stdUnit;
            }
        }

        // HSN Codes
        $hsnRows = DB::table('hsn_codes')
            ->where('is_deleted', 0)
            ->get();
        $hsnCodes = [];
        foreach ($hsnRows as $hRow) {
            $p = json_decode($hRow->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $hsnCodes[] = [
                'id' => (string) ($f['hsnCodeId'] ?? $hRow->id),
                'hsnCode' => (string) ($f['hsnCode'] ?? $hRow->id),
                'description' => $f['description'] ?? null,
                'gstRateBps' => (int) ($f['gstRateBps'] ?? $f['defaultGstRateBps'] ?? 0),
            ];
        }

        // Pack sizes
        $packSizeRows = DB::table('pack_sizes')
            ->where('is_deleted', 0)
            ->get();
        $packSizes = [];
        foreach ($packSizeRows as $psRow) {
            $p = json_decode($psRow->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $packSizes[] = [
                'id' => (string) ($f['packSizeId'] ?? $psRow->id),
                'size' => (float) ($f['size'] ?? 1),
                'unitId' => (string) ($f['unitId'] ?? ''),
            ];
        }

        return ApiResponse::success([
            'brands' => $brands,
            'categories' => $categories,
            'subcategories' => $subcategories,
            'units' => $units,
            'hsnCodes' => $hsnCodes,
            'packSizes' => $packSizes,
        ], 'Product master values retrieved successfully.');
    }

    /**
     * Fast search against Central Product Master verified catalogue.
     */
    public function searchCentral(Request $request): JsonResponse
    {
        $q = trim((string) ($request->query('query') ?? $request->query('q') ?? ''));
        if (strlen($q) < 2) {
            return ApiResponse::success([], 'Query too short.');
        }

        $rows = DB::table('central_product_master_verified')
            ->where('is_deleted', 0)
            ->where(function ($sub) use ($q) {
                $sub->where('payload->fields->productName', 'LIKE', "%{$q}%")
                    ->orWhere('payload->fields->brandName', 'LIKE', "%{$q}%")
                    ->orWhere('payload->fields->categoryName', 'LIKE', "%{$q}%")
                    ->orWhere('payload->fields->subcategoryName', 'LIKE', "%{$q}%")
                    ->orWhere('payload->fields->masterProductId', 'LIKE', "%{$q}%")
                    ->orWhere('id', 'LIKE', "%{$q}%")
                    ->orWhere('payload->fields->normalizedProductName', 'LIKE', '%'.strtolower($q).'%');
            })
            ->limit(30)
            ->get();

        $results = [];
        foreach ($rows as $row) {
            $p = json_decode($row->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $results[] = [
                'masterProductId' => (string) ($f['masterProductId'] ?? $row->id),
                'productName' => (string) ($f['productName'] ?? ''),
                'normalizedProductName' => (string) ($f['normalizedProductName'] ?? strtolower($f['productName'] ?? '')),
                'brandId' => (string) ($f['brandId'] ?? ''),
                'brandName' => (string) ($f['brandName'] ?? ''),
                'categoryId' => (string) ($f['categoryId'] ?? ''),
                'categoryName' => (string) ($f['categoryName'] ?? ''),
                'subcategoryId' => (string) ($f['subcategoryId'] ?? ''),
                'subcategoryName' => (string) ($f['subcategoryName'] ?? ''),
                'businessTypeId' => (string) ($f['businessTypeId'] ?? 'BT-HARDWARE'),
                'businessTypeName' => (string) ($f['businessTypeName'] ?? 'Hardware'),
                'businessSubTypeId' => (string) ($f['businessSubTypeId'] ?? 'BST-PAINTS'),
                'businessSubTypeName' => (string) ($f['businessSubTypeName'] ?? 'Paints'),
                'imageUrl' => $f['imageUrl'] ?? null,
                'description' => $f['description'] ?? null,
                'packSizes' => $f['packSizes'] ?? null,
                'coverage' => $f['coverage'] ?? null,
                'finish' => $f['finish'] ?? null,
                'colour' => $f['colour'] ?? null,
                'colourCode' => $f['colourCode'] ?? null,
                'dryingRecoatTime' => $f['dryingRecoatTime'] ?? null,
                'warranty' => $f['warranty'] ?? null,
                'dilution' => $f['dilution'] ?? null,
                'recommendedCoats' => $f['recommendedCoats'] ?? null,
                'benefits' => $f['benefits'] ?? null,
                'technicalDatasheetUrl' => $f['technicalDatasheetUrl'] ?? null,
                'productUrl' => $f['productUrl'] ?? null,
                'searchTokens' => $f['searchTokens'] ?? [],
                'isActive' => (bool) ($f['isActive'] ?? true),
                'status' => (string) ($f['status'] ?? 'ACTIVE'),
                'scopeType' => (string) ($f['scopeType'] ?? 'SYSTEM'),
            ];
        }

        return ApiResponse::success($results, 'Central product master search results.');
    }

    /**
     * Create a new brand, category, subcategory, or unit master record.
     */
    public function storeMaster(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'code' => ['nullable', 'string', 'max:64'],
            'category_id' => ['nullable', 'string'],
            'categoryId' => ['nullable', 'string'],
            'business_id' => ['nullable', 'string'],
            'businessId' => ['nullable', 'string'],
        ]);

        $type = strtoupper(trim((string) $request->input('type')));
        $name = trim((string) $request->input('name'));
        $code = trim((string) ($request->input('code') ?? ''));
        $categoryId = $request->input('category_id') ?? $request->input('categoryId');
        $businessId = $request->input('business_id') ?? $request->input('businessId');
        $user = $request->user();
        $isAdmin = $user && $user->isAdmin();
        $scopeType = $isAdmin ? 'SYSTEM' : 'BUSINESS';
        if (! $isAdmin) {
            $businessId = $businessId ?? ($user ? $user->defaultBusinessId() : null);
            if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
                return ApiResponse::error('A valid business context is required to create a master item.', 403, null, 'FORBIDDEN');
            }
        }

        $now = now();
        $isoNow = $now->toISOString();

        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        $slug = trim($slug, '_');
        if (empty($slug)) {
            $slug = 'ITEM_'.strtoupper(Str::random(6));
        }

        switch ($type) {
            case 'BRAND':
                $id = ($scopeType === 'SYSTEM' ? 'SYS_BRAND_' : 'BUS_BRAND_').substr($slug, 0, 40).'_'.strtoupper(Str::random(6));
                $fields = [
                    'brandId' => $id,
                    'name' => $name,
                    'status' => 'ACTIVE',
                    'scopeType' => $scopeType,
                    'businessId' => $businessId,
                    'createdAt' => $isoNow,
                    'updatedAt' => $isoNow,
                ];
                DB::table('brands')->insert([
                    'id' => $id,
                    'document_path' => "brands/$id",
                    'business_id' => $businessId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $id,
                        '_documentPath' => "brands/$id",
                        'fields' => $fields,
                    ]),
                ]);

                return ApiResponse::success([
                    'id' => $id,
                    'name' => $name,
                    'type' => 'BRAND',
                    'status' => 'ACTIVE',
                ], 'Brand created successfully.', 201);

            case 'CATEGORY':
                $id = ($scopeType === 'SYSTEM' ? 'SYS_CATEGORY_' : 'BUS_CATEGORY_').substr($slug, 0, 40).'_'.strtoupper(Str::random(6));
                $fields = [
                    'categoryId' => $id,
                    'name' => $name,
                    'code' => $code ?: null,
                    'status' => 'ACTIVE',
                    'scopeType' => $scopeType,
                    'businessId' => $businessId,
                    'createdAt' => $isoNow,
                    'updatedAt' => $isoNow,
                ];
                DB::table('product_categories')->insert([
                    'id' => $id,
                    'document_path' => "product_categories/$id",
                    'business_id' => $businessId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $id,
                        '_documentPath' => "product_categories/$id",
                        'fields' => $fields,
                    ]),
                ]);

                return ApiResponse::success([
                    'id' => $id,
                    'name' => $name,
                    'code' => $code ?: null,
                    'type' => 'CATEGORY',
                    'status' => 'ACTIVE',
                ], 'Category created successfully.', 201);

            case 'SUBCATEGORY':
                if (empty($categoryId)) {
                    return ApiResponse::error('Category ID is required for subcategory.', 422);
                }
                $id = ($scopeType === 'SYSTEM' ? 'SYS_SUBCATEGORY_' : 'BUS_SUBCATEGORY_').substr($categoryId, 0, 20).'_'.substr($slug, 0, 30).'_'.strtoupper(Str::random(6));
                $fields = [
                    'subcategoryId' => $id,
                    'categoryId' => $categoryId,
                    'name' => $name,
                    'status' => 'ACTIVE',
                    'scopeType' => $scopeType,
                    'businessId' => $businessId,
                    'createdAt' => $isoNow,
                    'updatedAt' => $isoNow,
                ];
                DB::table('product_subcategories')->insert([
                    'id' => $id,
                    'document_path' => "product_subcategories/$id",
                    'business_id' => $businessId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $id,
                        '_documentPath' => "product_subcategories/$id",
                        'fields' => $fields,
                    ]),
                ]);

                // Also create default template entry in subcategory_attribute_templates so template is ready!
                $templateId = 'TMPL_'.$id;
                DB::table('subcategory_attribute_templates')->insert([
                    'id' => $templateId,
                    'document_path' => "subcategory_attribute_templates/$templateId",
                    'business_id' => $businessId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $templateId,
                        '_documentPath' => "subcategory_attribute_templates/$templateId",
                        'fields' => [
                            'templateId' => $templateId,
                            'subcategoryId' => $id,
                            'categoryId' => $categoryId,
                            'attributeId' => 'soldAs',
                            'attributeName' => 'Selling Unit',
                            'level' => 'VARIANT',
                            'attributeLevel' => 'VARIANT',
                            'dataType' => 'SELECT',
                            'required' => true,
                            'status' => 'ACTIVE',
                            'scopeType' => $scopeType,
                        ],
                    ]),
                ]);

                return ApiResponse::success([
                    'id' => $id,
                    'name' => $name,
                    'categoryId' => $categoryId,
                    'type' => 'SUBCATEGORY',
                    'status' => 'ACTIVE',
                ], 'Subcategory created successfully.', 201);

            case 'UNIT':
                $id = ($scopeType === 'SYSTEM' ? 'UNIT_' : 'BUS_UNIT_').substr($slug, 0, 20).'_'.strtoupper(Str::random(4));
                $fields = [
                    'unitId' => $id,
                    'name' => $name,
                    'symbol' => $code ?: $name,
                    'precision' => 0,
                    'status' => 'ACTIVE',
                    'scopeType' => $scopeType,
                    'businessId' => $businessId,
                    'createdAt' => $isoNow,
                    'updatedAt' => $isoNow,
                ];
                DB::table('units')->insert([
                    'id' => $id,
                    'document_path' => "units/$id",
                    'business_id' => $businessId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'payload' => json_encode([
                        '_documentId' => $id,
                        '_documentPath' => "units/$id",
                        'fields' => $fields,
                    ]),
                ]);

                return ApiResponse::success([
                    'id' => $id,
                    'name' => $name,
                    'symbol' => $code ?: $name,
                    'type' => 'UNIT',
                    'status' => 'ACTIVE',
                ], 'Unit created successfully.', 201);

            default:
                return ApiResponse::error("Unsupported master type: {$type}", 422);
        }
    }

    /**
     * Get stock movement history timeline for a product.
     */
    public function stockHistory(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $businessId = $request->query('business_id') ?? $request->header('X-Business-Id') ?? $user->defaultBusinessId();

        if ($businessId && ! $user->canAccessBusiness($businessId)) {
            return ApiResponse::error('Unauthorized access to product stock history.', 403, null, 'FORBIDDEN');
        }

        $outletId = (string) ($request->query('outlet_id') ?? $request->query('outletId') ?? '');
        $stockSvc = $this->stockService();
        $meta = $businessId ? $stockSvc->resolveProductMeta($businessId, $id) : ['behaviour' => 'SEPARATE_VARIANT', 'baseUnitSymbol' => 'PCS'];

        $movements = [];
        if (DB::getSchemaBuilder()->hasTable('stock_movements')) {
            $query = DB::table('stock_movements')
                ->where('product_id', $id);

            if ($businessId) {
                $query->where('business_id', $businessId);
            }
            if ($outletId !== '' && $outletId !== 'ALL') {
                $query->where('outlet_id', $outletId);
            }

            $movements = $query->orderBy('created_at', 'desc')
                ->limit(100)
                ->get()
                ->map(function ($m) {
                    $mp = json_decode($m->payload ?? '{}', true) ?: [];
                    $mf = $mp['fields'] ?? [];

                    return [
                        'id' => $m->id,
                        'type' => $m->type,
                        'qty_change' => (float) $m->qty_change,
                        'balance_after' => isset($m->balance_after) ? (float) $m->balance_after : null,
                        'reference_type' => $m->reference_type,
                        'reference_id' => $m->reference_id,
                        'purchaseOptionId' => $mf['purchaseOptionId'] ?? ($m->purchase_option_id ?? null),
                        'purchaseOptionName' => $mf['purchaseOptionName'] ?? ($m->purchase_option_name ?? null),
                        'conversionFactor' => isset($mf['conversionFactor']) ? (float) $mf['conversionFactor'] : null,
                        'effectiveBaseUnitCost' => isset($mf['effectiveBaseUnitCost'])
                            ? (int) $mf['effectiveBaseUnitCost']
                            : (isset($m->effective_base_unit_cost) ? (int) $m->effective_base_unit_cost : null),
                        'condition' => $m->condition ?? null,
                        'note' => $m->note ?? null,
                        'created_at' => $m->created_at,
                    ];
                });
        }

        // Current stock balance (pooled in base units across unified or variant rows)
        $currentStock = 0.0;
        if ($businessId && DB::getSchemaBuilder()->hasTable('stock_balances')) {
            if ($outletId !== '' && $outletId !== 'ALL') {
                $target = $stockSvc->inspectStockTarget($businessId, $outletId, $id, null, $meta['behaviour']);
                $currentStock = round($target['totalMilli'] / 1000.0, 3);
            } else {
                $balRows = DB::table('stock_balances')
                    ->where('business_id', $businessId)
                    ->where('is_deleted', 0)
                    ->where(function ($q) use ($id) {
                        $q->where('product_id', $id)
                            ->orWhere('payload->fields->productId', $id)
                            ->orWhere('id', 'like', "%_{$id}");
                    })
                    ->get();
                $totalMilli = 0;
                foreach ($balRows as $balRow) {
                    $totalMilli += $stockSvc->parseBalanceMilli($balRow);
                }
                $currentStock = round($totalMilli / 1000.0, 3);
            }
        }

        return ApiResponse::success([
            'productId' => $id,
            'inventoryBehaviour' => $meta['behaviour'],
            'baseUnitSymbol' => $meta['baseUnitSymbol'],
            'currentStock' => $currentStock,
            'movements' => $movements,
        ], 'Stock history timeline retrieved.');
    }

    private function mergeMultipartPayload(Request $request): void
    {
        if (! $request->has('payload')) {
            return;
        }

        $payload = json_decode((string) $request->input('payload'), true);
        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'payload' => 'The product payload must be valid JSON.',
            ]);
        }

        $request->merge($payload);
    }

    private function stockService(): \App\Services\InventoryStockService
    {
        return new \App\Services\InventoryStockService($this->unitService);
    }

    /**
     * Upsert & soft-delete purchase/sales options for a parent product.
     *
     * @return array<int,array<string,mixed>>
     */
    private function syncPurchaseOptionsForProduct(
        string $businessId,
        ?string $outletId,
        string $tenantId,
        string $productId,
        array $inputOptions,
        array $productFields,
        \DateTimeInterface|string $now,
        string $nowIso
    ): array {
        if (! \Illuminate\Support\Facades\Schema::hasTable('product_purchase_options')) {
            return [];
        }

        $stockSvc = $this->stockService();
        $existingRows = DB::table('product_purchase_options')
            ->where('product_id', $productId)
            ->where('is_deleted', 0)
            ->get()
            ->keyBy('id');

        $processedIds = [];
        $baseUnitId = (string) ($productFields['baseUnitId'] ?? 'UNIT');

        foreach ($inputOptions as $idx => $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $optId = trim((string) ($opt['purchaseOptionId'] ?? $opt['id'] ?? ''));
            if ($optId === '') {
                $optId = 'POPT-'.strtoupper(\Illuminate\Support\Str::random(20));
            }
            $optionName = trim((string) ($opt['optionName'] ?? $opt['name'] ?? ("Option ".($idx + 1))));
            $optionType = strtoupper(trim((string) ($opt['optionType'] ?? 'PURCHASE')));
            if (! in_array($optionType, ['PURCHASE', 'SALE', 'BOTH'], true)) {
                $optionType = 'PURCHASE';
            }

            $diameterMm = isset($opt['diameterMm']) && is_numeric($opt['diameterMm']) ? (float) $opt['diameterMm'] : null;
            $lengthMetre = isset($opt['lengthMetre']) && is_numeric($opt['lengthMetre']) ? (float) $opt['lengthMetre'] : null;
            $weightPerPieceKg = isset($opt['weightPerPieceKg']) && is_numeric($opt['weightPerPieceKg']) ? (float) $opt['weightPerPieceKg'] : null;
            if (($weightPerPieceKg === null || $weightPerPieceKg <= 0) && $diameterMm && $lengthMetre) {
                $weightPerPieceKg = $stockSvc->calculateTmtBarWeightKg($diameterMm, $lengthMetre);
            }

            $conversionFactor = (float) ($opt['conversionFactor'] ?? ($weightPerPieceKg ?: 1.0));
            if ($conversionFactor <= 0) {
                $conversionFactor = 1.0;
            }
            $conversionUnitId = (string) ($opt['conversionUnitId'] ?? $baseUnitId);
            $conversionUnitDef = $this->unitService->resolveUnit($conversionUnitId, $businessId);
            $conversionUnitSymbol = (string) ($opt['conversionUnitSymbol'] ?? $conversionUnitDef['symbol']);

            $purchasePrice = self::extractInt($opt['purchasePrice'] ?? $opt['purchase_price'] ?? 0, 0);
            $defaultSellingPrice = array_key_exists('defaultSellingPrice', $opt) || array_key_exists('default_selling_price', $opt)
                ? self::extractInt($opt['defaultSellingPrice'] ?? $opt['default_selling_price'], 0)
                : null;
            $isActive = array_key_exists('isActive', $opt) ? (bool) $opt['isActive'] : true;
            $barcode = isset($opt['barcode']) && trim((string) $opt['barcode']) !== '' ? trim((string) $opt['barcode']) : null;
            $variantId = isset($opt['variantId']) && trim((string) $opt['variantId']) !== '' ? trim((string) $opt['variantId']) : null;

            $optFields = [
                'purchaseOptionId' => $optId,
                'productId' => $productId,
                'variantId' => $variantId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'tenantId' => $tenantId,
                'optionName' => $optionName,
                'optionType' => $optionType,
                'conversionFactor' => $conversionFactor,
                'conversionUnitId' => $conversionUnitId,
                'conversionUnitSymbol' => $conversionUnitSymbol,
                'purchasePrice' => $purchasePrice,
                'defaultSellingPrice' => $defaultSellingPrice,
                'diameterMm' => $diameterMm,
                'lengthMetre' => $lengthMetre,
                'weightPerPieceKg' => $weightPerPieceKg,
                'barcode' => $barcode,
                'isActive' => $isActive,
                'status' => $isActive ? 'ACTIVE' : 'INACTIVE',
                'updatedAt' => $nowIso,
            ];

            $payloadJson = json_encode([
                '_documentId' => $optId,
                '_documentPath' => "product_purchase_options/{$optId}",
                'fields' => $optFields,
            ]);

            $rowData = [
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'option_name' => $optionName,
                'option_type' => $optionType,
                'conversion_factor' => $conversionFactor,
                'conversion_unit_id' => $conversionUnitId,
                'purchase_price' => $purchasePrice,
                'default_selling_price' => $defaultSellingPrice,
                'diameter_mm' => $diameterMm,
                'length_metre' => $lengthMetre,
                'weight_per_piece_kg' => $weightPerPieceKg,
                'barcode' => $barcode,
                'is_active' => $isActive ? 1 : 0,
                'status' => $isActive ? 'ACTIVE' : 'INACTIVE',
                'is_deleted' => 0,
                'payload' => $payloadJson,
                'updated_at' => $now,
            ];

            if (isset($existingRows[$optId])) {
                DB::table('product_purchase_options')->where('id', $optId)->update($rowData);
            } else {
                $rowData['id'] = $optId;
                $rowData['document_path'] = "product_purchase_options/{$optId}";
                $rowData['created_at'] = $now;
                DB::table('product_purchase_options')->insert($rowData);
            }
            $processedIds[] = $optId;
        }

        foreach ($existingRows as $oldId => $oldRow) {
            if (! in_array($oldId, $processedIds, true)) {
                DB::table('product_purchase_options')->where('id', $oldId)->update([
                    'is_deleted' => 1,
                    'is_active' => 0,
                    'status' => 'INACTIVE',
                    'updated_at' => $now,
                ]);
            }
        }

        $grouped = $stockSvc->getPurchaseOptionsByProductIds($businessId, [$productId]);

        return $grouped[$productId] ?? [];
    }
}
