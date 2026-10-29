<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductController extends Controller
{
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
            // Find first active business accessible by user
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        if (! $businessId) {
            return ApiResponse::success([], 'No active business selected.');
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
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

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

            $vSellingPrice = (int) ($vFields['sellingPrice'] ?? 0);
            $vPurchaseRate = (int) ($vFields['purchaseRate'] ?? 0);
            $vMrp = (int) ($vFields['mrp'] ?? 0);

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
                    if ($vSellingPrice === 0 && !empty($pbiFields['sellingPrice'])) {
                        $vSellingPrice = (int) $pbiFields['sellingPrice'];
                    }
                    if ($vMrp === 0 && !empty($pbiFields['mrp'])) {
                        $vMrp = (int) $pbiFields['mrp'];
                    }
                    if ($vPurchaseRate === 0 && !empty($pbiFields['rate'])) {
                        $vPurchaseRate = (int) $pbiFields['rate'];
                    }
                }
            }

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
                'baseUnitId' => (string) ($vFields['baseUnitId'] ?? $vFields['unitId'] ?? 'UNIT'),
                'packUnitId' => $vFields['packUnitId'] ?? null,
                'commercialUnitId' => $vFields['commercialUnitId'] ?? null,
                'commercialUnitName' => $vFields['commercialUnitName'] ?? null,
                'baseQuantityPerCommercialUnit' => (float) ($vFields['baseQuantityPerCommercialUnit'] ?? 1),
                'purchaseRate' => $vPurchaseRate,
                'sellingPrice' => $vSellingPrice,
                'mrp' => $vMrp,
                'defaultGstRateBps' => (int) ($vFields['defaultGstRateBps'] ?? $vFields['defaultGstRate'] ?? 0),
                'status' => (string) ($vFields['status'] ?? 'ACTIVE'),
                'isActive' => (($vFields['status'] ?? 'ACTIVE') === 'ACTIVE'),
                'attributeValues' => !empty($vFields['attributeValues']) ? $vFields['attributeValues'] : (object) [],
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

            $name = (string) ($fields['productName'] ?? $fields['name'] ?? '');
            $code = (string) ($fields['productCode'] ?? '');
            $brand = (string) ($fields['brand'] ?? '');
            $cat = (string) ($fields['categoryName'] ?? $fields['category'] ?? '');
            $subcat = (string) ($fields['subcategory'] ?? '');

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
                    || str_contains(strtolower($subcat), $searchLower);

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
                if (empty($pv['hsnCode']) && !empty($parentHsn)) {
                    $pv['hsnCode'] = $parentHsn;
                }
                if (empty($pv['defaultGstRateBps']) && $parentGst > 0) {
                    $pv['defaultGstRateBps'] = $parentGst;
                }
                if ((empty($pv['baseUnitId']) || strtoupper($pv['baseUnitId']) === 'UNIT') && !empty($parentBaseUnit)) {
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
                    if (!empty($attrSoldAs)) {
                        $pv['commercialUnitName'] = $attrSoldAs;
                    }
                }
            }
            unset($pv);

            $products[] = [
                'productId' => $prodId,
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
                'description' => $fields['description'] ?? null,
                'productImage' => $fields['productImage'] ?? $fields['imageUrl'] ?? null,
                'defaultGstRateBps' => (int) ($fields['defaultGstRateBps'] ?? 0),
                'hsnCode' => $fields['hsnCode'] ?? null,
                'status' => (string) ($fields['status'] ?? 'ACTIVE'),
                'isActive' => (($fields['status'] ?? 'ACTIVE') === 'ACTIVE'),
                'attributeValues' => !empty($fields['attributeValues']) ? $fields['attributeValues'] : (object) [],
                'variants' => $prodVariants,
                'createdAt' => $fields['createdAt'] ?? $row->created_at,
                'updatedAt' => $fields['updatedAt'] ?? $row->updated_at,
            ];
        }

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
                if ((empty($vUnit) || strtoupper($vUnit) === 'UNIT') && !empty($parentBaseUnit)) {
                    $vUnit = $parentBaseUnit;
                } elseif (empty($vUnit)) {
                    $vUnit = 'UNIT';
                }

                $vCommUnitName = $vFields['commercialUnitName'] ?? null;
                if (empty($vCommUnitName)) {
                    $vCommUnitName = $vAttrs['soldAs'] ?? ($vAttrs['commercialUnit'] ?? $parentSoldAs);
                }

                $vSellingPrice = (int) ($vFields['sellingPrice'] ?? 0);
                $vPurchaseRate = (int) ($vFields['purchaseRate'] ?? 0);
                $vMrp = (int) ($vFields['mrp'] ?? 0);
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
                        if ($vSellingPrice === 0 && !empty($pbiFields['sellingPrice'])) {
                            $vSellingPrice = (int) $pbiFields['sellingPrice'];
                        }
                        if ($vMrp === 0 && !empty($pbiFields['mrp'])) {
                            $vMrp = (int) $pbiFields['mrp'];
                        }
                        if ($vPurchaseRate === 0 && !empty($pbiFields['rate'])) {
                            $vPurchaseRate = (int) $pbiFields['rate'];
                        }
                    }
                }

                $variants[] = [
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
                    'baseQuantityPerCommercialUnit' => (float) ($vFields['baseQuantityPerCommercialUnit'] ?? 1),
                    'purchaseRate' => $vPurchaseRate,
                    'sellingPrice' => $vSellingPrice,
                    'mrp' => $vMrp,
                    'defaultGstRateBps' => (int) ($vFields['defaultGstRateBps'] ?? $fields['defaultGstRateBps'] ?? 0),
                    'status' => (string) ($vFields['status'] ?? 'ACTIVE'),
                    'isActive' => (($vFields['status'] ?? 'ACTIVE') === 'ACTIVE'),
                    'attributeValues' => !empty($vAttrs) ? $vAttrs : (object) [],
                ];
            }
        }

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
            'status' => (string) ($fields['status'] ?? 'ACTIVE'),
            'isActive' => (($fields['status'] ?? 'ACTIVE') === 'ACTIVE'),
            'attributeValues' => !empty($fields['attributeValues']) ? $fields['attributeValues'] : (object) [],
            'variants' => $variants,
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

        $generated = DB::transaction(function () use ($businessId, $outletId, $ownerUid, $user): int {
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
            'default_gst_rate_bps' => ['nullable', 'integer'],
            'defaultGstRateBps' => ['nullable', 'integer'],
            'hsn_code' => ['nullable', 'string'],
            'hsnCode' => ['nullable', 'string'],
            'variants' => ['nullable', 'array'],
        ]);

        $businessId = trim((string) ($request->input('business_id') ?? $request->input('businessId') ?? ''));
        $outletId = $request->input('outlet_id') ?? $request->input('outletId');
        $name = trim((string) ($request->input('name') ?? $request->input('productName') ?? ''));
        $productCode = trim((string) ($request->input('product_code') ?? $request->input('productCode') ?? 'PRD-' . strtoupper(Str::random(6))));
        $brand = $request->input('brand');
        $brandId = $request->input('brand_id') ?? $request->input('brandId');
        $category = $request->input('category');
        $categoryId = $request->input('category_id') ?? $request->input('categoryId');
        $subcategory = $request->input('subcategory');
        $subcategoryId = $request->input('subcategoryId');
        $description = $request->input('description');
        $productImage = $request->input('product_image') ?? $request->input('productImage');
        $defaultGstRateBps = (int) ($request->input('default_gst_rate_bps') ?? $request->input('defaultGstRateBps') ?? 0);
        $hsnCode = $request->input('hsn_code') ?? $request->input('hsnCode');
        $attributeValues = (array) ($request->input('attribute_values') ?? $request->input('attributeValues') ?? []);

        $productId = 'PROD-' . strtoupper(Str::random(24));
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
            'defaultGstRateBps' => $defaultGstRateBps,
            'hsnCode' => $hsnCode,
            'attributeValues' => $attributeValues,
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
            $variantId = 'VAR-' . strtoupper(Str::random(24));
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
            $vSku = $v['sku'] ?? 'SKU-' . strtoupper(Str::random(6));

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
                'baseUnitId' => $vUnit,
                'commercialUnitName' => $vCommUnitName,
                'purchaseRate' => $vPurchase,
                'sellingPrice' => $vSelling,
                'mrp' => $vMrp,
                'defaultGstRateBps' => $defaultGstRateBps,
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

        DB::transaction(function () use (
            $productId,
            $businessId,
            $outletId,
            $nowDb,
            $productFields,
            $variantRecords,
            $userId
        ) {
            // 1. Insert product
            DB::table('products')->insert([
                'id' => $productId,
                'document_path' => "products/$productId",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'ACTIVE',
                'is_deleted' => 0,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
                'payload' => json_encode([
                    '_documentId' => $productId,
                    '_documentPath' => "products/$productId",
                    'fields' => $productFields,
                ]),
            ]);

            // 2. Insert variants
            foreach ($variantRecords as $vRecord) {
                DB::table('product_variants')->insert([
                    'id' => $vRecord['id'],
                    'document_path' => $vRecord['document_path'],
                    'business_id' => $vRecord['business_id'],
                    'outlet_id' => $vRecord['outlet_id'],
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $vRecord['created_at'],
                    'updated_at' => $vRecord['updated_at'],
                    'payload' => $vRecord['payload'],
                ]);
            }

            // 3. Insert outlet placement if outlet is specified
            if ($outletId) {
                $opId = 'OP_' . strtolower(Str::random(16));
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

        Log::info("Product created: {$productId} ('{$name}') with " . count($variantRecords) . " variant(s)");

        $productFields['variants'] = array_map(fn ($r) => $r['raw_fields'], $variantRecords);

        return ApiResponse::success([
            'product' => $productFields,
        ], 'Product created successfully.', 201);
    }

    /**
     * Soft delete product and its variants.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
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
                    $opId = 'OP_' . strtolower(Str::random(16));
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

        $nowDb = now();
        $isDeleted = ($status === 'DELETED') ? 1 : 0;
        $activeStatus = ($status === 'DELETED') ? 'INACTIVE' : $status;

        DB::transaction(function () use ($productIds, $status, $activeStatus, $isDeleted, $nowDb) {
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

        // Units
        $unitRows = DB::table('units')
            ->where('is_deleted', 0)
            ->get();
        $units = [];
        foreach ($unitRows as $uRow) {
            $p = json_decode($uRow->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $units[] = [
                'id' => (string) ($f['unitId'] ?? $uRow->id),
                'name' => (string) ($f['name'] ?? $uRow->id),
                'symbol' => $f['symbol'] ?? $f['name'] ?? $uRow->id,
                'precision' => (int) ($f['precision'] ?? 0),
            ];
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
                    ->orWhere('payload->fields->normalizedProductName', 'LIKE', '%' . strtolower($q) . '%');
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
        $now = now();
        $isoNow = $now->toISOString();

        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        $slug = trim($slug, '_');
        if (empty($slug)) {
            $slug = 'ITEM_' . strtoupper(Str::random(6));
        }

        switch ($type) {
            case 'BRAND':
                $id = 'SYS_BRAND_' . substr($slug, 0, 40) . '_' . strtoupper(Str::random(6));
                $fields = [
                    'brandId' => $id,
                    'name' => $name,
                    'status' => 'ACTIVE',
                    'scopeType' => 'SYSTEM',
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
                $id = 'SYS_CATEGORY_' . substr($slug, 0, 40) . '_' . strtoupper(Str::random(6));
                $fields = [
                    'categoryId' => $id,
                    'name' => $name,
                    'code' => $code ?: null,
                    'status' => 'ACTIVE',
                    'scopeType' => 'SYSTEM',
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
                $id = 'SYS_SUBCATEGORY_' . substr($categoryId, 0, 20) . '_' . substr($slug, 0, 30) . '_' . strtoupper(Str::random(6));
                $fields = [
                    'subcategoryId' => $id,
                    'categoryId' => $categoryId,
                    'name' => $name,
                    'status' => 'ACTIVE',
                    'scopeType' => 'SYSTEM',
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
                $templateId = 'TMPL_' . $id;
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
                            'scopeType' => 'SYSTEM',
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
                $id = 'UNIT_' . substr($slug, 0, 20) . '_' . strtoupper(Str::random(4));
                $fields = [
                    'unitId' => $id,
                    'name' => $name,
                    'symbol' => $code ?: $name,
                    'precision' => 0,
                    'status' => 'ACTIVE',
                    'scopeType' => 'SYSTEM',
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
}
