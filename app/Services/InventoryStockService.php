<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryStockService
{
    public const BEHAVIOUR_SEPARATE_VARIANT = 'SEPARATE_VARIANT';

    public const BEHAVIOUR_UNIFIED_PARENT = 'UNIFIED_PARENT';

    public const OPTION_TYPE_PURCHASE = 'PURCHASE';

    public const OPTION_TYPE_SALE = 'SALE';

    public const OPTION_TYPE_BOTH = 'BOTH';

    public function __construct(private ?UnitConversionService $unitService = null)
    {
        $this->unitService ??= new UnitConversionService;
    }

    /**
     * Normalize inventory behaviour string while keeping existing products backward-compatible.
     */
    public function normalizeBehaviour(?string $behaviour, array $productFields = []): string
    {
        $candidate = strtoupper(trim((string) ($behaviour
            ?? $productFields['inventoryBehaviour']
            ?? $productFields['inventory_behaviour']
            ?? '')));

        if ($candidate === self::BEHAVIOUR_UNIFIED_PARENT || $candidate === 'UNIFIED') {
            return self::BEHAVIOUR_UNIFIED_PARENT;
        }
        if ($candidate === self::BEHAVIOUR_SEPARATE_VARIANT || $candidate === 'SEPARATE') {
            return self::BEHAVIOUR_SEPARATE_VARIANT;
        }
        if (! empty($productFields['isUnifiedInventory']) || ! empty($productFields['unifiedInventory'])) {
            return self::BEHAVIOUR_UNIFIED_PARENT;
        }

        return self::BEHAVIOUR_SEPARATE_VARIANT;
    }

    /**
     * Suggest default inventory behaviour by category/subcategory for UI & seeding.
     */
    public function recommendedBehaviourForCategory(?string $categoryName, ?string $subcategoryName = null): string
    {
        $text = strtoupper(trim(($categoryName ?? '').' '.($subcategoryName ?? '')));
        if ($text === '') {
            return self::BEHAVIOUR_SEPARATE_VARIANT;
        }

        foreach (['PAINT', 'ENAMEL', 'EMULSION', 'PRIMER', 'DISTEMPER', 'PUTTY'] as $kw) {
            if (str_contains($text, $kw)) {
                return self::BEHAVIOUR_SEPARATE_VARIANT;
            }
        }

        foreach (['SAND', 'GRAVEL', 'CHIP', 'AGGREGATE', 'STONE', 'BRICK', 'BLOCK', 'TMT', 'STEEL', 'REBAR', 'ROD', 'BAR'] as $kw) {
            if (str_contains($text, $kw)) {
                return self::BEHAVIOUR_UNIFIED_PARENT;
            }
        }

        return self::BEHAVIOUR_SEPARATE_VARIANT;
    }

    public function isUnifiedBehaviour(string $behaviour): bool
    {
        return $this->normalizeBehaviour($behaviour) === self::BEHAVIOUR_UNIFIED_PARENT;
    }

    public function canonicalUnifiedVariantId(string $productId): string
    {
        return "UNIFIED_{$productId}";
    }

    /**
     * Strip synthetic UI suffixes (`_base_loose`, `__OPT__<id>`) and extract embedded productId from `UNIFIED_<pid>`.
     *
     * @return array{cleanVariantId:string,derivedProductId:string,purchaseOptionId:?string,isBaseLoose:bool,isUnifiedPrefix:bool}
     */
    public function parseSyntheticVariantId(?string $variantId): array
    {
        $raw = trim((string) ($variantId ?? ''));
        $isBaseLoose = $raw !== '' && str_ends_with($raw, '_base_loose');
        $working = $isBaseLoose ? substr($raw, 0, -11) : $raw;

        $purchaseOptionId = null;
        $optPos = strpos($working, '__OPT__');
        if ($optPos !== false) {
            $optPart = trim(substr($working, $optPos + 7));
            $purchaseOptionId = $optPart !== '' ? $optPart : null;
            $working = trim(substr($working, 0, $optPos));
        }

        $isUnifiedPrefix = $working !== '' && str_starts_with($working, 'UNIFIED_');
        $derivedProductId = $isUnifiedPrefix ? trim(substr($working, 8)) : '';

        return [
            'cleanVariantId' => $working,
            'derivedProductId' => $derivedProductId,
            'purchaseOptionId' => $purchaseOptionId,
            'isBaseLoose' => $isBaseLoose,
            'isUnifiedPrefix' => $isUnifiedPrefix,
        ];
    }

    /**
     * Calculate standard TMT steel bar weight in KG: ((d^2) / 162) * lengthMetre.
     */
    public function calculateTmtBarWeightKg(float $diameterMm, float $lengthMetre = 12.0): float
    {
        if ($diameterMm <= 0 || $lengthMetre <= 0) {
            return 0.0;
        }

        return round((($diameterMm * $diameterMm) / 162.0) * $lengthMetre, 4);
    }

    /**
     * Effective cost per single base unit in paise: round(lineTotalPaise / baseQtyUnits).
     */
    public function calculateEffectiveBaseUnitCostPaise(int $lineTotalPaise, float $baseQuantityUnits): int
    {
        if ($lineTotalPaise <= 0 || $baseQuantityUnits <= 0.00001) {
            return 0;
        }

        return (int) round($lineTotalPaise / $baseQuantityUnits);
    }

    /**
     * Weighted average cost per single base unit in paise.
     */
    public function calculateWeightedAverageCostPaise(
        float $existingBaseQty,
        int $existingAvgCostPaise,
        float $incomingBaseQty,
        int $incomingTotalCostPaise
    ): int {
        $safeExistingQty = max(0.0, $existingBaseQty);
        $safeIncomingQty = max(0.0, $incomingBaseQty);
        $totalQty = $safeExistingQty + $safeIncomingQty;

        if ($totalQty <= 0.00001) {
            return max(0, $existingAvgCostPaise);
        }

        $existingVal = $safeExistingQty * max(0, $existingAvgCostPaise);
        $incomingVal = max(0, $incomingTotalCostPaise);

        return (int) round(($existingVal + $incomingVal) / $totalQty);
    }

    /**
     * Fetch product fields & normalized behaviour from products table.
     *
     * @return array{row:?object,fields:array,productId:string,behaviour:string,baseUnitId:string,baseUnitSymbol:string,defaultSalesUnitId:string,allowMultipleSalesOptions:bool,trackStock:bool}
     */
    public function resolveProductMeta(string $businessId, string $productId): array
    {
        $row = null;
        if ($productId !== '' && Schema::hasTable('products')) {
            $row = DB::table('products')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->where(function ($q) use ($productId) {
                    $q->where('id', $productId)
                        ->orWhere('payload->fields->productId', $productId);
                })
                ->first();
        }

        $payload = $row ? (json_decode($row->payload ?? '{}', true) ?: []) : [];
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];

        $colBehaviour = $row && property_exists($row, 'inventory_behaviour') ? $row->inventory_behaviour : null;
        $rawBehaviour = $fields['inventoryBehaviour'] ?? $colBehaviour;
        $behaviour = $this->normalizeBehaviour($rawBehaviour, $fields);
        if (($rawBehaviour === null || $rawBehaviour === '') && $this->isSandOrGravelCftProduct($fields)) {
            $hasPurchaseOptions = Schema::hasTable('product_purchase_options')
                && $productId !== ''
                && DB::table('product_purchase_options')
                    ->where('business_id', $businessId)
                    ->where('product_id', $productId)
                    ->where('is_deleted', 0)
                    ->exists();
            $variantCount = $productId !== '' && Schema::hasTable('product_variants')
                ? DB::table('product_variants')
                    ->where('business_id', $businessId)
                    ->where('product_id', $productId)
                    ->where('is_deleted', 0)
                    ->count()
                : 0;
            if ($hasPurchaseOptions || $variantCount > 1) {
                $behaviour = self::BEHAVIOUR_UNIFIED_PARENT;
            }
        }

        $unitConf = $this->unitService->resolveVariantUnitConfig([], $fields, $businessId);
        $baseUnitId = (string) ($fields['baseUnitId'] ?? $unitConf['baseUnitId']);
        $baseUnitSymbol = (string) ($fields['unitSymbol'] ?? $unitConf['baseUnitSymbol']);
        $defaultSalesUnitId = (string) ($fields['defaultSalesUnitId'] ?? $fields['salesUnitId'] ?? $unitConf['defaultSalesUnitId']);

        return [
            'row' => $row,
            'fields' => $fields,
            'productId' => (string) ($fields['productId'] ?? ($row->id ?? $productId)),
            'behaviour' => $behaviour,
            'baseUnitId' => $baseUnitId,
            'baseUnitSymbol' => $baseUnitSymbol,
            'defaultSalesUnitId' => $defaultSalesUnitId,
            'allowMultipleSalesOptions' => (bool) ($fields['allowMultipleSalesOptions'] ?? ($row->allow_multiple_sales_options ?? false)),
            'trackStock' => array_key_exists('trackStock', $fields) ? (bool) $fields['trackStock'] : true,
        ];
    }

    /**
     * Detect whether a product is a Sand/Gravel/Aggregate CFT bulk construction material.
     */
    public function isSandOrGravelCftProduct(array $pFields, ?array $vFields = null): bool
    {
        $baseUnitId = strtoupper(trim((string) ($pFields['baseUnitId'] ?? $vFields['baseUnitId'] ?? $pFields['unitId'] ?? $vFields['unitId'] ?? '')));
        $unitSymbol = strtoupper(trim((string) ($pFields['unitSymbol'] ?? $vFields['unitSymbol'] ?? $vFields['commercialUnitName'] ?? '')));
        $text = strtoupper(trim(implode(' ', array_filter([
            $pFields['category'] ?? '',
            $pFields['categoryName'] ?? '',
            $pFields['subcategory'] ?? '',
            $pFields['name'] ?? ($pFields['productName'] ?? ''),
        ]))));

        foreach (['PAINT', 'ENAMEL', 'EMULSION', 'PRIMER', 'DISTEMPER', 'PUTTY'] as $paintKw) {
            if (str_contains($text, $paintKw)) {
                return false;
            }
        }

        $isCftUnit = $baseUnitId === 'SYS_UNIT_CFT' || $unitSymbol === 'CFT' || str_contains($text, 'CFT');
        if ($isCftUnit) {
            return true;
        }

        foreach (['SAND', 'GRAVEL', 'AGGREGATE', 'CRUSHER', 'STONE DUST', 'CHIPS', 'BALLAST', 'M-SAND', 'MSAND'] as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse quantity from a stock_balances row in milli-units (1000 = 1 base unit).
     */
    public function parseBalanceMilli(object $row, ?array $fields = null, ?array $context = null): int
    {
        if ($fields === null) {
            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : $payload;
        }

        $stockScale = strtoupper(trim((string) ($fields['stockScale'] ?? '')));
        $hasCol = property_exists($row, 'quantity_on_hand') && $row->quantity_on_hand !== null;
        if (array_key_exists('quantityOnHand', $fields) || array_key_exists('currentStock', $fields) || array_key_exists('quantity', $fields)) {
            $raw = (float) ($fields['quantityOnHand'] ?? $fields['currentStock'] ?? $fields['quantity']);
            if ($stockScale === 'MILLI') {
                return (int) round($raw);
            }
            if ($stockScale === 'UNIT') {
                return $this->unitService->toMilliUnits($raw);
            }
            if ($hasCol && (float) $row->quantity_on_hand > 0) {
                if (abs(((float) $row->quantity_on_hand * 1000.0) - $raw) < 2.0) {
                    return (int) round($raw);
                }
                // Both column and JSON store plain units (e.g. 1500 == 1500 CFT) without stockScale
                if (abs((float) $row->quantity_on_hand - $raw) < 0.01 && $raw > 0) {
                    return $this->unitService->toMilliUnits($raw);
                }
            }
            if ($raw >= 1000.0 || $raw == 0.0) {
                return (int) round($raw);
            }

            return $this->unitService->toMilliUnits($raw);
        }

        return $hasCol ? $this->unitService->toMilliUnits((float) $row->quantity_on_hand) : 0;
    }

    /**
     * Resolve stock rows & target canonical variant ID for either SEPARATE_VARIANT or UNIFIED_PARENT.
     *
     * @return array{
     *   behaviour:string,
     *   canonicalVariantId:string,
     *   canonicalRow:?object,
     *   allRows:array<int,object>,
     *   totalMilli:int,
     *   weightedAvgCostPaise:int,
     *   scopeKey:string
     * }
     */
    public function inspectStockTarget(
        string $businessId,
        string $outletId,
        string $productId,
        ?string $variantId,
        ?string $behaviour = null
    ): array {
        $parsedVar = $this->parseSyntheticVariantId($variantId);
        $cleanVariantId = $parsedVar['cleanVariantId'];

        $resolvedProductId = trim($productId);
        if ($resolvedProductId === '' && $parsedVar['derivedProductId'] !== '') {
            $resolvedProductId = $parsedVar['derivedProductId'];
        }
        if ($resolvedProductId === '' && $cleanVariantId !== '' && ! $parsedVar['isUnifiedPrefix']) {
            $vMeta = DB::table('product_variants')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->where(function ($q) use ($cleanVariantId) {
                    $q->where('id', $cleanVariantId)
                        ->orWhere('payload->fields->variantId', $cleanVariantId);
                })
                ->first();
            if ($vMeta) {
                $vp = json_decode($vMeta->payload ?? '{}', true) ?: [];
                $resolvedProductId = (string) ($vp['fields']['productId'] ?? $vMeta->product_id ?? '');
            }
        }

        $meta = $resolvedProductId !== '' ? $this->resolveProductMeta($businessId, $resolvedProductId) : null;
        $effectiveBehaviour = $behaviour
            ? $this->normalizeBehaviour($behaviour)
            : ($meta['behaviour'] ?? ($parsedVar['isUnifiedPrefix'] ? self::BEHAVIOUR_UNIFIED_PARENT : self::BEHAVIOUR_SEPARATE_VARIANT));

        $allRows = [];
        if ($this->isUnifiedBehaviour($effectiveBehaviour) && $resolvedProductId !== '') {
            $canonicalVariantId = $this->canonicalUnifiedVariantId($resolvedProductId);
            $variantIds = DB::table('product_variants')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->where(function ($q) use ($resolvedProductId) {
                    $q->where('product_id', $resolvedProductId)
                        ->orWhere('payload->fields->productId', $resolvedProductId);
                })
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();
            if ($cleanVariantId !== '' && ! in_array($cleanVariantId, $variantIds, true)) {
                $variantIds[] = $cleanVariantId;
            }
            $variantIds[] = $canonicalVariantId;
            $variantIds = array_values(array_unique(array_filter($variantIds)));

            $allRows = DB::table('stock_balances')
                ->where('business_id', $businessId)
                ->when($outletId !== '' && $outletId !== 'ALL', fn ($q) => $q->where('outlet_id', $outletId))
                ->where('is_deleted', 0)
                ->where(function ($q) use ($resolvedProductId, $variantIds) {
                    $q->where('product_id', $resolvedProductId)
                        ->orWhere('payload->fields->productId', $resolvedProductId)
                        ->orWhereIn('variant_id', $variantIds);
                })
                ->get()
                ->all();
        } else {
            $explicitVariant = $cleanVariantId;
            $targetVariantId = $explicitVariant !== '' ? $explicitVariant : $resolvedProductId;
            $canonicalVariantId = $targetVariantId;
            if ($targetVariantId !== '') {
                $allRows = DB::table('stock_balances')
                    ->where('business_id', $businessId)
                    ->when($outletId !== '' && $outletId !== 'ALL', fn ($q) => $q->where('outlet_id', $outletId))
                    ->where('is_deleted', 0)
                    ->where(function ($q) use ($targetVariantId, $explicitVariant, $resolvedProductId) {
                        $q->where('id', 'LIKE', "%_{$targetVariantId}")
                            ->orWhere('variant_id', $targetVariantId)
                            ->orWhere('payload->fields->variantId', $targetVariantId);
                        if ($explicitVariant === '' && $resolvedProductId !== '') {
                            $q->orWhere('product_id', $resolvedProductId)
                                ->orWhere('payload->fields->productId', $resolvedProductId);
                        }
                    })
                    ->get()
                    ->all();
            }
        }

        $canonicalRow = null;
        $totalMilli = 0;
        $weightedCostNumerator = 0.0;
        $fallbackCost = 0;

        foreach ($allRows as $row) {
            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : $payload;
            $rowVarId = (string) ($fields['variantId'] ?? $row->variant_id ?? '');
            if ($rowVarId === $canonicalVariantId || $canonicalRow === null) {
                $canonicalRow = $row;
            }
            $milli = $this->parseBalanceMilli($row, $fields);
            $totalMilli += $milli;
            $rowCost = (int) ($fields['costPrice'] ?? $fields['weightedAvgCost'] ?? 0);
            if ($rowCost > 0) {
                $fallbackCost = $rowCost;
                if ($milli > 0) {
                    $weightedCostNumerator += (($milli / 1000.0) * $rowCost);
                }
            }
        }

        $totalUnits = $totalMilli / 1000.0;
        $weightedAvgCost = $totalUnits > 0.0001 && $weightedCostNumerator > 0
            ? (int) round($weightedCostNumerator / $totalUnits)
            : $fallbackCost;

        $scopeKey = $canonicalRow ? (string) $canonicalRow->id : '';
        if ($scopeKey === '' && $canonicalVariantId !== '') {
            $firstPayload = $canonicalRow ? (json_decode($canonicalRow->payload ?? '{}', true) ?: []) : [];
            $tenantScope = (string) ($firstPayload['fields']['tenantId'] ?? $businessId);
            $scopeKey = "{$tenantScope}_{$outletId}_{$canonicalVariantId}";
        }

        return [
            'behaviour' => $effectiveBehaviour,
            'canonicalVariantId' => $canonicalVariantId,
            'canonicalRow' => $canonicalRow,
            'allRows' => $allRows,
            'totalMilli' => $totalMilli,
            'weightedAvgCostPaise' => $weightedAvgCost,
            'scopeKey' => $scopeKey,
        ];
    }

    /**
     * Load active purchase options for one or more products.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function getPurchaseOptionsByProductIds(string $businessId, array $productIds): array
    {
        if (! Schema::hasTable('product_purchase_options') || empty($productIds)) {
            return [];
        }

        $rows = DB::table('product_purchase_options')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->whereIn('product_id', array_values(array_unique(array_filter($productIds))))
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
            $pId = (string) ($fields['productId'] ?? $row->product_id ?? '');
            if ($pId === '') {
                continue;
            }
            $grouped[$pId][] = [
                'id' => (string) $row->id,
                'purchaseOptionId' => (string) ($fields['purchaseOptionId'] ?? $row->id),
                'productId' => $pId,
                'variantId' => $fields['variantId'] ?? $row->variant_id,
                'optionName' => (string) ($fields['optionName'] ?? $row->option_name ?? ''),
                'optionType' => strtoupper((string) ($fields['optionType'] ?? $row->option_type ?? self::OPTION_TYPE_PURCHASE)),
                'conversionFactor' => (float) ($fields['conversionFactor'] ?? $row->conversion_factor ?? 1.0),
                'conversionUnitId' => (string) ($fields['conversionUnitId'] ?? $row->conversion_unit_id ?? ''),
                'conversionUnitSymbol' => (string) ($fields['conversionUnitSymbol'] ?? ''),
                'purchasePrice' => (int) ($fields['purchasePrice'] ?? $row->purchase_price ?? 0),
                'defaultSellingPrice' => isset($fields['defaultSellingPrice'])
                    ? (int) $fields['defaultSellingPrice']
                    : ($row->default_selling_price !== null ? (int) $row->default_selling_price : null),
                'diameterMm' => isset($fields['diameterMm'])
                    ? (float) $fields['diameterMm']
                    : ($row->diameter_mm !== null ? (float) $row->diameter_mm : null),
                'lengthMetre' => isset($fields['lengthMetre'])
                    ? (float) $fields['lengthMetre']
                    : ($row->length_metre !== null ? (float) $row->length_metre : null),
                'weightPerPieceKg' => isset($fields['weightPerPieceKg'])
                    ? (float) $fields['weightPerPieceKg']
                    : ($row->weight_per_piece_kg !== null ? (float) $row->weight_per_piece_kg : null),
                'barcode' => $fields['barcode'] ?? $row->barcode,
                'isActive' => array_key_exists('isActive', $fields) ? (bool) $fields['isActive'] : (bool) $row->is_active,
            ];
        }

        return $grouped;
    }

    /**
     * Resolve a single purchase option row by option ID or fallback fields.
     */
    public function findPurchaseOption(string $businessId, string $productId, ?string $purchaseOptionId): ?array
    {
        if (! $purchaseOptionId || ! Schema::hasTable('product_purchase_options')) {
            return null;
        }

        $row = DB::table('product_purchase_options')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->where(function ($q) use ($purchaseOptionId) {
                $q->where('id', $purchaseOptionId)
                    ->orWhere('payload->fields->purchaseOptionId', $purchaseOptionId);
            })
            ->when($productId !== '', fn ($q) => $q->where('product_id', $productId))
            ->first();

        if (! $row) {
            return null;
        }

        $grouped = $this->getPurchaseOptionsByProductIds($businessId, [(string) ($row->product_id ?? $productId)]);
        $items = $grouped[(string) ($row->product_id ?? $productId)] ?? [];
        foreach ($items as $item) {
            if ($item['id'] === (string) $row->id || $item['purchaseOptionId'] === $purchaseOptionId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Load lightweight maps of products and variants for a business in bulk.
     *
     * @return array{products:array<string,array<string,mixed>>,variants:array<string,array<string,mixed>>,variantToProduct:array<string,string>}
     */
    private function loadBusinessCatalogueMeta(string $businessId): array
    {
        $products = [];
        $variants = [];
        $variantToProduct = [];

        if (Schema::hasTable('products')) {
            $pRows = DB::table('products')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->get();
            foreach ($pRows as $pRow) {
                $pp = json_decode($pRow->payload ?? '{}', true) ?: [];
                $pf = is_array($pp['fields'] ?? null) ? $pp['fields'] : [];
                $pId = (string) ($pf['productId'] ?? $pRow->id);
                if ($pId === '') {
                    continue;
                }
                $colBeh = property_exists($pRow, 'inventory_behaviour') ? $pRow->inventory_behaviour : null;
                $rawBeh = $pf['inventoryBehaviour'] ?? $colBeh;
                $products[$pId] = [
                    'row' => $pRow,
                    'fields' => $pf,
                    'rawBehaviour' => $rawBeh,
                    'behaviour' => $this->normalizeBehaviour($rawBeh, $pf),
                    'baseUnitId' => (string) ($pf['baseUnitId'] ?? ''),
                    'isCft' => $this->isSandOrGravelCftProduct($pf),
                    'variantsCount' => 0,
                ];
            }
        }

        if (Schema::hasTable('product_variants')) {
            $vRows = DB::table('product_variants')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->get();
            foreach ($vRows as $vRow) {
                $vp = json_decode($vRow->payload ?? '{}', true) ?: [];
                $vf = is_array($vp['fields'] ?? null) ? $vp['fields'] : [];
                $vId = (string) ($vf['variantId'] ?? $vRow->id);
                $pId = (string) ($vf['productId'] ?? $vRow->product_id ?? '');
                if ($vId !== '') {
                    $variants[$vId] = [
                        'row' => $vRow,
                        'fields' => $vf,
                        'productId' => $pId,
                        'factor' => (float) ($vf['baseQuantityPerCommercialUnit'] ?? $vf['conversionFactor'] ?? 1.0),
                        'unitId' => (string) ($vf['baseUnitId'] ?? $vf['unitId'] ?? ''),
                        'name' => (string) ($vf['variantName'] ?? $vf['name'] ?? ''),
                    ];
                    if ($pId !== '') {
                        $variantToProduct[$vId] = $pId;
                    }
                }
                if ($pId !== '' && isset($products[$pId])) {
                    $products[$pId]['variantsCount']++;
                    if (! $products[$pId]['isCft'] && $this->isSandOrGravelCftProduct($products[$pId]['fields'], $vf)) {
                        $products[$pId]['isCft'] = true;
                    }
                    if ($products[$pId]['baseUnitId'] === '' && ! empty($vf['baseUnitId'])) {
                        $products[$pId]['baseUnitId'] = (string) $vf['baseUnitId'];
                    }
                }
            }
        }

        if (Schema::hasTable('product_purchase_options')) {
            $optRows = DB::table('product_purchase_options')
                ->where('business_id', $businessId)
                ->where('is_deleted', 0)
                ->select('product_id')
                ->distinct()
                ->pluck('product_id')
                ->all();
            foreach ($optRows as $optPid) {
                $pid = (string) $optPid;
                if (isset($products[$pid])) {
                    $products[$pid]['hasPurchaseOptions'] = true;
                }
            }
        }

        foreach ($products as $pid => &$pMeta) {
            if ($pMeta['isCft'] && (($pMeta['rawBehaviour'] ?? null) === null || $pMeta['rawBehaviour'] === '') && (($pMeta['variantsCount'] ?? 0) > 1 || ! empty($pMeta['hasPurchaseOptions']))) {
                $pMeta['behaviour'] = self::BEHAVIOUR_UNIFIED_PARENT;
            }
            if ($pMeta['isCft'] && $pMeta['baseUnitId'] === '') {
                $pMeta['baseUnitId'] = 'SYS_UNIT_CFT';
            }
        }
        unset($pMeta);

        return [
            'products' => $products,
            'variants' => $variants,
            'variantToProduct' => $variantToProduct,
        ];
    }

    /**
     * Build a normalized stock snapshot per outlet/business, consolidating UNIFIED_PARENT & CFT products.
     */
    public function getConsolidatedStockSnapshot(string $businessId, ?string $outletId = null, ?string $variantFilter = null): array
    {
        $catMeta = $this->loadBusinessCatalogueMeta($businessId);
        $products = $catMeta['products'];
        $variantToProduct = $catMeta['variantToProduct'];

        $query = DB::table('stock_balances')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        if ($outletId && $outletId !== 'ALL') {
            $query->where('outlet_id', $outletId);
        }
        $rows = $query->get();

        // Group unified product rows by (outletId, productId) so consolidated items appear once per outlet without over-counting
        $unifiedBuckets = [];
        $separateEntries = [];

        foreach ($rows as $row) {
            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            $fields = is_array($payload['fields'] ?? null) ? ($payload['fields'] ?: []) : $payload;

            $rawVarId = (string) ($fields['variantId'] ?? $row->variant_id ?? $fields['productId'] ?? $row->product_id ?? '');
            $rawProdId = (string) ($fields['productId'] ?? $row->product_id ?? '');
            if ($rawProdId === '' && $rawVarId !== '') {
                if (str_starts_with($rawVarId, 'UNIFIED_')) {
                    $rawProdId = substr($rawVarId, 8);
                } elseif (isset($variantToProduct[$rawVarId])) {
                    $rawProdId = $variantToProduct[$rawVarId];
                } else {
                    $rawProdId = $rawVarId;
                }
            }
            if ($rawVarId === '' && $rawProdId === '') {
                continue;
            }

            $pMeta = $rawProdId !== '' ? ($products[$rawProdId] ?? null) : null;
            $rowBehaviour = $fields['inventoryBehaviour'] ?? ($row->inventory_behaviour ?? null);
            $effectiveBehaviour = $pMeta
                ? ($this->isUnifiedBehaviour((string) ($rowBehaviour ?? '')) ? self::BEHAVIOUR_UNIFIED_PARENT : $pMeta['behaviour'])
                : $this->normalizeBehaviour($rowBehaviour, $fields);

            $qtyMilli = $this->parseBalanceMilli($row, $fields, [
                'isCftProduct' => (bool) ($pMeta['isCft'] ?? false),
            ]);
            $avgCost = (int) ($fields['avgCostPaise'] ?? $fields['costPrice'] ?? $fields['weightedAvgCost'] ?? $fields['averageCost'] ?? 0);
            $storedTotVal = (int) ($fields['totalValue'] ?? 0);
            if ($storedTotVal === 0 && $qtyMilli > 0 && $avgCost > 0) {
                $storedTotVal = (int) round(($qtyMilli / 1000.0) * $avgCost);
            }
            $lastPrice = (int) ($fields['lastPurchasePricePaise'] ?? 0);
            $baseUnitId = (string) ($fields['baseUnitId'] ?? ($pMeta['baseUnitId'] ?? ''));
            if ($baseUnitId === '' && ($pMeta['isCft'] ?? false)) {
                $baseUnitId = 'SYS_UNIT_CFT';
            }
            $needsRec = (bool) ($fields['needsReconciliation'] ?? false);
            $recReason = $fields['reconciliationReason'] ?? null;

            $rowOutletId = (string) $row->outlet_id;
            $isUnified = $this->isUnifiedBehaviour($effectiveBehaviour) && $rawProdId !== '';

            if ($isUnified) {
                $bucketKey = "{$rowOutletId}::{$rawProdId}";
                if (! isset($unifiedBuckets[$bucketKey])) {
                    $unifiedBuckets[$bucketKey] = [
                        'id' => (string) $row->id,
                        'businessId' => (string) $row->business_id,
                        'outletId' => $rowOutletId,
                        'variantId' => $this->canonicalUnifiedVariantId($rawProdId),
                        'productId' => $rawProdId,
                        'inventoryBehaviour' => self::BEHAVIOUR_UNIFIED_PARENT,
                        'baseUnitId' => $baseUnitId ?: 'SYS_UNIT_CFT',
                        'qtyMilli' => 0,
                        'totalValue' => 0,
                        'weightedCostNum' => 0.0,
                        'fallbackAvgCost' => 0,
                        'lastPurchasePricePaise' => 0,
                        'updatedAt' => $row->updated_at,
                        'needsReconciliation' => false,
                        'reconciliationReason' => null,
                        'aliasVariantIds' => [],
                    ];
                }
                if ($rawVarId === $this->canonicalUnifiedVariantId($rawProdId)) {
                    $unifiedBuckets[$bucketKey]['id'] = (string) $row->id;
                }
                $unifiedBuckets[$bucketKey]['qtyMilli'] += $qtyMilli;
                $unifiedBuckets[$bucketKey]['totalValue'] += $storedTotVal;
                if ($avgCost > 0) {
                    $unifiedBuckets[$bucketKey]['fallbackAvgCost'] = $avgCost;
                    if ($qtyMilli > 0) {
                        $unifiedBuckets[$bucketKey]['weightedCostNum'] += (($qtyMilli / 1000.0) * $avgCost);
                    }
                }
                if ($lastPrice > 0) {
                    $unifiedBuckets[$bucketKey]['lastPurchasePricePaise'] = $lastPrice;
                }
                if ($needsRec) {
                    $unifiedBuckets[$bucketKey]['needsReconciliation'] = true;
                    $unifiedBuckets[$bucketKey]['reconciliationReason'] ??= $recReason;
                }
                if ($rawVarId !== '') {
                    $unifiedBuckets[$bucketKey]['aliasVariantIds'][] = $rawVarId;
                }
                if ((string) $row->updated_at > (string) $unifiedBuckets[$bucketKey]['updatedAt']) {
                    $unifiedBuckets[$bucketKey]['updatedAt'] = $row->updated_at;
                }
            } else {
                $separateEntries[] = [
                    'id' => (string) $row->id,
                    'businessId' => (string) $row->business_id,
                    'outletId' => $rowOutletId,
                    'variantId' => $rawVarId ?: $rawProdId,
                    'productId' => $rawProdId ?: $rawVarId,
                    'inventoryBehaviour' => self::BEHAVIOUR_SEPARATE_VARIANT,
                    'baseUnitId' => $baseUnitId !== '' ? $baseUnitId : null,
                    'qtyMilli' => $qtyMilli,
                    'totalValue' => $storedTotVal,
                    'avgCostPaise' => $avgCost,
                    'lastPurchasePricePaise' => $lastPrice,
                    'updatedAt' => $row->updated_at,
                    'needsReconciliation' => $needsRec,
                    'reconciliationReason' => $recReason,
                ];
            }
        }

        $stockMap = [];
        $parentStockMap = [];
        $parentStockUnitsMap = [];
        $valuationMap = [];
        $reconciliationMap = [];
        $balances = [];

        $appendEntry = function (array $entry) use ($variantFilter, &$stockMap, &$parentStockMap, &$parentStockUnitsMap, &$valuationMap, &$reconciliationMap, &$balances) {
            $vId = $entry['variantId'];
            $pId = $entry['productId'];
            $qty = (int) $entry['qtyMilli'];
            $totVal = (int) $entry['totalValue'];
            $avgCost = (int) $entry['avgCostPaise'];

            if ($variantFilter) {
                $aliases = $entry['aliasVariantIds'] ?? [$vId];
                if ($vId !== $variantFilter && ! in_array($variantFilter, $aliases, true)) {
                    return;
                }
            }

            if ($totVal === 0 && $qty > 0 && $avgCost > 0) {
                $totVal = (int) round(($qty / 1000.0) * $avgCost);
            }

            $stockMap[$vId] = ($stockMap[$vId] ?? 0) + $qty;
            if ($pId !== '') {
                $parentStockMap[$pId] = ($parentStockMap[$pId] ?? 0) + $qty;
                $parentStockUnitsMap[$pId] = round($parentStockMap[$pId] / 1000.0, 3);
            }
            $valuationMap[$vId] = ($valuationMap[$vId] ?? 0) + $totVal;
            if (! empty($entry['needsReconciliation']) && $pId !== '') {
                $reconciliationMap[$pId] = $entry['reconciliationReason'] ?: 'REQUIRES_CFT_RECONCILIATION';
            }

            $balances[] = [
                'id' => $entry['id'],
                'businessId' => $entry['businessId'],
                'outletId' => $entry['outletId'],
                'variantId' => $vId,
                'productId' => $pId,
                'inventoryBehaviour' => $entry['inventoryBehaviour'],
                'baseUnitId' => $entry['baseUnitId'],
                'quantityOnHand' => $qty,
                'quantityOnHandUnits' => round($qty / 1000.0, 3),
                'totalValue' => $totVal,
                'avgCostPaise' => $avgCost,
                'lastPurchasePricePaise' => $entry['lastPurchasePricePaise'],
                'needsReconciliation' => (bool) ($entry['needsReconciliation'] ?? false),
                'reconciliationReason' => $entry['reconciliationReason'] ?? null,
                'updatedAt' => $entry['updatedAt'],
            ];
        };

        foreach ($unifiedBuckets as $bucket) {
            $qty = (int) $bucket['qtyMilli'];
            $units = $qty / 1000.0;
            $avgCost = $units > 0.0001 && $bucket['weightedCostNum'] > 0
                ? (int) round($bucket['weightedCostNum'] / $units)
                : (int) $bucket['fallbackAvgCost'];
            $totVal = (int) $bucket['totalValue'];
            if ($avgCost > 0 && $qty > 0 && $totVal <= 0) {
                $totVal = (int) round($units * $avgCost);
            }
            $bucket['avgCostPaise'] = $avgCost;
            $bucket['totalValue'] = $totVal;
            $appendEntry($bucket);
        }

        foreach ($separateEntries as $entry) {
            $appendEntry($entry);
        }

        return [
            'stockMap' => $stockMap,
            'parentStockMap' => $parentStockMap,
            'parentStockUnitsMap' => $parentStockUnitsMap,
            'valuationMap' => $valuationMap,
            'reconciliationMap' => $reconciliationMap,
            'balances' => $balances,
        ];
    }

    /**
     * Recalculate & reconcile CFT Sand/Gravel stock from verified transactions without guessing missing load capacities.
     */
    public function reconcileCftInventory(
        string $businessId,
        ?string $outletId = null,
        bool $apply = true,
        ?string $productIdFilter = null
    ): array {
        $catMeta = $this->loadBusinessCatalogueMeta($businessId);
        $products = $catMeta['products'];
        $variants = $catMeta['variants'];
        $purchaseOptionsByProduct = $this->getPurchaseOptionsByProductIds($businessId, array_keys($products));

        $cftProductIds = [];
        foreach ($products as $pId => $meta) {
            if ($productIdFilter && $pId !== $productIdFilter) {
                continue;
            }
            if ($meta['isCft']) {
                $cftProductIds[$pId] = $meta;
            }
        }

        if (empty($cftProductIds)) {
            return ['checkedProducts' => 0, 'reconciledCount' => 0, 'flaggedCount' => 0, 'items' => []];
        }

        $loadKeywordPattern = '/(TRACTOR|TRUCK|MINI|TIPPER|DUMPER|HYVA|BRASS|LOAD|TRIP)/i';
        $ledgers = []; // [$outletId][$productId] => ledger breakdown
        $initLedger = function (string $oId, string $pId) use (&$ledgers) {
            if (! isset($ledgers[$oId][$pId])) {
                $ledgers[$oId][$pId] = [
                    'openingMilli' => 0,
                    'purchasesMilli' => 0,
                    'purchaseValuePaise' => 0,
                    'purchaseReturnsInMilli' => 0,
                    'customerReturnsInMilli' => 0,
                    'adjustInMilli' => 0,
                    'salesOutMilli' => 0,
                    'supplierReturnsOutMilli' => 0,
                    'adjustOutMilli' => 0,
                    'countCorrectionDeltaMilli' => 0,
                    'hasLedgerEvents' => false,
                    'unverifiedReasons' => [],
                    'countedPurchaseRefs' => [],
                    'countedInvoiceRefs' => [],
                    'countedReturnRefs' => [],
                ];
            }
        };

        $resolveLineToCftProduct = function (?string $pId, ?string $vId) use ($cftProductIds, $variants): ?string {
            if ($pId && isset($cftProductIds[$pId])) {
                return $pId;
            }
            if ($vId) {
                if (str_starts_with($vId, 'UNIFIED_')) {
                    $candidate = substr($vId, 8);
                    if (isset($cftProductIds[$candidate])) {
                        return $candidate;
                    }
                }
                if (isset($variants[$vId])) {
                    $vpId = $variants[$vId]['productId'];
                    if (isset($cftProductIds[$vpId])) {
                        return $vpId;
                    }
                }
            }

            return null;
        };

        // 1. Approved / Received Purchases
        if (Schema::hasTable('purchase_bills') && Schema::hasTable('purchase_bill_items')) {
            $billRows = DB::table('purchase_bills')
                ->where('business_id', $businessId)
                ->when($outletId && $outletId !== 'ALL', fn ($q) => $q->where('outlet_id', $outletId))
                ->where('is_deleted', 0)
                ->whereIn(DB::raw('UPPER(COALESCE(status, ""))'), ['APPROVED', 'RECEIVED'])
                ->get()
                ->keyBy('id');

            if ($billRows->isNotEmpty()) {
                $pbiRows = DB::table('purchase_bill_items')
                    ->where('business_id', $businessId)
                    ->where('is_deleted', 0)
                    ->whereIn('purchase_bill_id', $billRows->keys()->all())
                    ->get();

                foreach ($pbiRows as $itemRow) {
                    $bill = $billRows->get($itemRow->purchase_bill_id);
                    if (! $bill) {
                        continue;
                    }
                    $ip = json_decode($itemRow->payload ?? '{}', true) ?: [];
                    $if = is_array($ip['fields'] ?? null) ? $ip['fields'] : $ip;
                    $pId = $resolveLineToCftProduct($if['productId'] ?? ($itemRow->product_id ?? null), $if['variantId'] ?? ($itemRow->variant_id ?? null));
                    if (! $pId) {
                        continue;
                    }

                    $oId = (string) $bill->outlet_id;
                    $initLedger($oId, $pId);
                    $ledgers[$oId][$pId]['hasLedgerEvents'] = true;
                    $ledgers[$oId][$pId]['countedPurchaseRefs'][(string) $bill->id] = true;

                    $rawBase = (float) ($if['baseQuantity'] ?? $if['base_quantity'] ?? 0);
                    $rawQty = (float) ($if['quantity'] ?? 0);
                    $conv = (float) ($if['conversionFactor'] ?? $if['conversion_factor'] ?? 0);
                    $optId = $if['purchaseOptionId'] ?? ($itemRow->purchase_option_id ?? null);
                    $optName = (string) ($if['purchaseOptionName'] ?? $if['unitSymbol'] ?? $if['unit'] ?? $if['variantName'] ?? '');
                    $vId = (string) ($if['variantId'] ?? '');

                    $matchedOptFactor = null;
                    if ($optId && ! empty($purchaseOptionsByProduct[$pId])) {
                        foreach ($purchaseOptionsByProduct[$pId] as $opt) {
                            if ($opt['purchaseOptionId'] === $optId || $opt['id'] === $optId) {
                                $matchedOptFactor = (float) $opt['conversionFactor'];
                                break;
                            }
                        }
                    }

                    $resolvedBaseMilli = null;
                    $isLoadName = preg_match($loadKeywordPattern, $optName) === 1;
                    if ($rawBase > 0) {
                        // Guard against corrupted baseQuantity equal to milli 1000 when option is a larger load
                        if ($matchedOptFactor && $matchedOptFactor > 1 && $rawQty > 0) {
                            $qtyUnits = $rawQty >= 1000 && abs(($rawQty / 1000.0) * $matchedOptFactor * 1000 - $rawBase) > 10 && abs($rawBase - $rawQty) < 2
                                ? ($rawQty / 1000.0)
                                : ($rawQty >= 1000 && ! $isLoadName ? $rawQty : ($rawQty >= 1000 && $conv >= 1000 ? $rawQty / 1000.0 : $rawQty));
                            $expectedFromOpt = (int) round($qtyUnits * $matchedOptFactor * 1000.0);
                            if (abs($rawBase - $rawQty) < 2 && $expectedFromOpt > $rawBase) {
                                $resolvedBaseMilli = $expectedFromOpt;
                            }
                        }
                        if ($resolvedBaseMilli === null) {
                            $resolvedBaseMilli = (int) round($rawBase);
                        }
                    } elseif ($matchedOptFactor !== null && $matchedOptFactor > 0 && $rawQty > 0) {
                        $qtyUnits = ($conv >= 1000 && $rawQty >= 1000) ? ($rawQty / 1000.0) : $rawQty;
                        $resolvedBaseMilli = $this->unitService->toMilliUnits($qtyUnits * $matchedOptFactor);
                    } elseif ($conv > 1 && $rawQty > 0) {
                        $factorDecimal = $conv >= 1000 ? ($conv / 1000.0) : $conv;
                        $qtyUnits = ($conv >= 1000 && $rawQty >= 1000) ? ($rawQty / 1000.0) : $rawQty;
                        $resolvedBaseMilli = $this->unitService->toMilliUnits($qtyUnits * $factorDecimal);
                    } elseif (! $isLoadName && $rawQty > 0) {
                        $vFactor = $variants[$vId]['factor'] ?? 1.0;
                        $resolvedBaseMilli = $this->unitService->toMilliUnits($rawQty * ($vFactor > 0 ? $vFactor : 1.0));
                    } else {
                        $ledgers[$oId][$pId]['unverifiedReasons'][] = "Purchase bill {$bill->id} ({$optName}) missing load CFT conversion capacity.";
                        continue;
                    }

                    $ledgers[$oId][$pId]['purchasesMilli'] += max(0, $resolvedBaseMilli);
                    $lineTotal = (int) ($if['taxableAmount'] ?? 0) + (int) ($if['taxAmount'] ?? 0);
                    $ledgers[$oId][$pId]['purchaseValuePaise'] += max(0, $lineTotal);
                }
            }
        }

        // 2. Sales Invoices (excluding CANCELLED / VOID / DRAFT)
        if (Schema::hasTable('invoices') && Schema::hasTable('invoice_items')) {
            $invRows = DB::table('invoices')
                ->where('business_id', $businessId)
                ->when($outletId && $outletId !== 'ALL', fn ($q) => $q->where('outlet_id', $outletId))
                ->where('is_deleted', 0)
                ->whereNotIn(DB::raw('UPPER(COALESCE(status, ""))'), ['CANCELLED', 'VOID', 'DRAFT', 'RETURN'])
                ->get()
                ->keyBy('id');

            if ($invRows->isNotEmpty()) {
                $iiRows = DB::table('invoice_items')
                    ->where('business_id', $businessId)
                    ->where('is_deleted', 0)
                    ->whereIn('invoice_id', $invRows->keys()->all())
                    ->get();

                foreach ($iiRows as $itemRow) {
                    $inv = $invRows->get($itemRow->invoice_id);
                    if (! $inv) {
                        continue;
                    }
                    $ip = json_decode($itemRow->payload ?? '{}', true) ?: [];
                    $if = is_array($ip['fields'] ?? null) ? $ip['fields'] : $ip;
                    if (array_key_exists('trackStock', $if) && $if['trackStock'] === false) {
                        continue;
                    }
                    $pId = $resolveLineToCftProduct($if['productId'] ?? ($itemRow->product_id ?? null), $if['variantId'] ?? ($itemRow->variant_id ?? null));
                    if (! $pId) {
                        continue;
                    }

                    $oId = (string) $inv->outlet_id;
                    $initLedger($oId, $pId);
                    $ledgers[$oId][$pId]['hasLedgerEvents'] = true;
                    $ledgers[$oId][$pId]['countedInvoiceRefs'][(string) $inv->id] = true;

                    $rawBase = (float) ($if['quantityBase'] ?? $if['baseQuantity'] ?? 0);
                    $rawQty = (float) ($if['quantity'] ?? 0);
                    $conv = (float) ($if['conversionFactor'] ?? $if['conversion_factor'] ?? 1.0);
                    $unitName = (string) ($if['unitSymbol'] ?? $if['unit'] ?? $if['variantName'] ?? '');
                    $vId = (string) ($if['variantId'] ?? '');
                    $isLoadName = preg_match($loadKeywordPattern, $unitName) === 1;

                    if ($rawBase > 0) {
                        $ledgers[$oId][$pId]['salesOutMilli'] += (int) round($rawBase);
                    } elseif ($conv > 1 && $rawQty > 0) {
                        $ledgers[$oId][$pId]['salesOutMilli'] += $this->unitService->toMilliUnits($rawQty * $conv);
                    } elseif (! $isLoadName && $rawQty > 0) {
                        $vFactor = $variants[$vId]['factor'] ?? 1.0;
                        $ledgers[$oId][$pId]['salesOutMilli'] += $this->unitService->toMilliUnits($rawQty * ($vFactor > 0 ? $vFactor : 1.0));
                    } else {
                        $ledgers[$oId][$pId]['unverifiedReasons'][] = "Invoice {$inv->id} ({$unitName}) missing load CFT conversion capacity.";
                    }
                }
            }
        }

        // 3. Customer Sales Returns (+) & Supplier Purchase Returns (-)
        foreach (['sales_returns' => 'customerReturnsInMilli', 'purchase_returns' => 'supplierReturnsOutMilli'] as $table => $bucketField) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $retRows = DB::table($table)
                ->where('business_id', $businessId)
                ->when($outletId && $outletId !== 'ALL', fn ($q) => $q->where('outlet_id', $outletId))
                ->where('is_deleted', 0)
                ->where('status', '!=', 'CANCELLED')
                ->get();

            foreach ($retRows as $ret) {
                $rp = json_decode($ret->payload ?? '{}', true) ?: [];
                $rf = is_array($rp['fields'] ?? null) ? $rp['fields'] : $rp;
                $items = is_array($rf['items'] ?? null) ? $rf['items'] : [];
                $oId = (string) $ret->outlet_id;
                foreach ($items as $it) {
                    if (! is_array($it)) {
                        continue;
                    }
                    $pId = $resolveLineToCftProduct($it['productId'] ?? ($it['product_id'] ?? null), $it['variantId'] ?? ($it['variant_id'] ?? null));
                    if (! $pId) {
                        continue;
                    }
                    $initLedger($oId, $pId);
                    $ledgers[$oId][$pId]['hasLedgerEvents'] = true;
                    $ledgers[$oId][$pId]['countedReturnRefs'][(string) $ret->id] = true;

                    $rawBase = (float) ($it['baseQuantity'] ?? $it['quantityBase'] ?? 0);
                    $rawQty = (float) ($it['returnedQuantity'] ?? $it['quantity'] ?? 0);
                    if ($rawBase > 0) {
                        $ledgers[$oId][$pId][$bucketField] += (int) round($rawBase);
                    } elseif ($rawQty > 0) {
                        $ledgers[$oId][$pId][$bucketField] += ($rawQty >= 1000 && (($it['quantityScale'] ?? '') === 'MILLI'))
                            ? (int) round($rawQty)
                            : $this->unitService->toMilliUnits($rawQty);
                    }
                }
            }
        }

        // 4. Stock Movements (Opening Stock, Adjustments, Damage, Purchase Returns Received, & standalone uncounted movements)
        if (Schema::hasTable('stock_movements')) {
            $movRows = DB::table('stock_movements')
                ->where('business_id', $businessId)
                ->when($outletId && $outletId !== 'ALL', fn ($q) => $q->where('outlet_id', $outletId))
                ->where('is_deleted', 0)
                ->orderBy('created_at')
                ->get();

            foreach ($movRows as $mRow) {
                $mp = json_decode($mRow->payload ?? '{}', true) ?: [];
                $mf = is_array($mp['fields'] ?? null) ? $mp['fields'] : $mp;
                $pId = $resolveLineToCftProduct($mf['productId'] ?? ($mRow->product_id ?? null), $mf['variantId'] ?? ($mRow->variant_id ?? null));
                if (! $pId) {
                    continue;
                }
                $oId = (string) $mRow->outlet_id;
                $initLedger($oId, $pId);

                $mType = strtoupper((string) ($mf['type'] ?? $mRow->type ?? ''));
                $refId = (string) ($mf['referenceId'] ?? $mRow->reference_id ?? '');

                // Skip purchase/sale/return movements already tallied from document line tables to avoid double counting
                if ($refId !== '' && (isset($ledgers[$oId][$pId]['countedPurchaseRefs'][$refId]) || isset($ledgers[$oId][$pId]['countedInvoiceRefs'][$refId]) || isset($ledgers[$oId][$pId]['countedReturnRefs'][$refId]))) {
                    continue;
                }
                if (in_array($mType, ['PURCHASE_REVERSAL', 'PURCHASE_APPROVAL'], true) && $refId !== '' && isset($ledgers[$oId][$pId]['countedPurchaseRefs'][$refId])) {
                    continue;
                }

                $qtyBase = (int) ($mf['qtyBase'] ?? 0);
                if ($qtyBase <= 0 && isset($mRow->qty_change) && (float) $mRow->qty_change != 0.0) {
                    $qtyBase = $this->unitService->toMilliUnits(abs((float) $mRow->qty_change));
                }
                if ($qtyBase <= 0) {
                    continue;
                }
                $ledgers[$oId][$pId]['hasLedgerEvents'] = true;

                if ($mType === 'OPENING_STOCK') {
                    $ledgers[$oId][$pId]['openingMilli'] += $qtyBase;
                } elseif (in_array($mType, ['PURCHASE', 'RECEIVE'], true)) {
                    $ledgers[$oId][$pId]['purchasesMilli'] += $qtyBase;
                } elseif ($mType === 'PURCHASE_RETURN_RECEIVED') {
                    $ledgers[$oId][$pId]['purchaseReturnsInMilli'] += $qtyBase;
                } elseif (in_array($mType, ['RETURN', 'SALES_RETURN', 'RETURN_ADJUSTMENT'], true)) {
                    $ledgers[$oId][$pId]['customerReturnsInMilli'] += $qtyBase;
                } elseif (in_array($mType, ['ADJUST_IN'], true)) {
                    $ledgers[$oId][$pId]['adjustInMilli'] += $qtyBase;
                } elseif (in_array($mType, ['SALE', 'SALE_ADJUSTMENT'], true)) {
                    $ledgers[$oId][$pId]['salesOutMilli'] += $qtyBase;
                } elseif ($mType === 'PURCHASE_RETURN') {
                    $ledgers[$oId][$pId]['supplierReturnsOutMilli'] += $qtyBase;
                } elseif (in_array($mType, ['ADJUST_OUT', 'DAMAGE_LOSS'], true)) {
                    $ledgers[$oId][$pId]['adjustOutMilli'] += $qtyBase;
                } elseif ($mType === 'COUNT_CORRECTION') {
                    // Explicit count correction resets running expectation when verifiable
                    $prev = (int) ($mf['previousQuantity'] ?? 0);
                    $ledgers[$oId][$pId]['countCorrectionDeltaMilli'] += ($qtyBase - $prev);
                }
            }
        }

        // Compare against existing stock_balances and consolidate/update verified CFT products
        $reconciledCount = 0;
        $flaggedCount = 0;
        $itemsReport = [];

        $existingRows = DB::table('stock_balances')
            ->where('business_id', $businessId)
            ->when($outletId && $outletId !== 'ALL', fn ($q) => $q->where('outlet_id', $outletId))
            ->where('is_deleted', 0)
            ->get();

        $rowsByOutletProd = [];
        foreach ($existingRows as $sRow) {
            $sp = json_decode($sRow->payload ?? '{}', true) ?: [];
            $sf = is_array($sp['fields'] ?? null) ? $sp['fields'] : $sp;
            $pId = $resolveLineToCftProduct($sf['productId'] ?? ($sRow->product_id ?? null), $sf['variantId'] ?? ($sRow->variant_id ?? null));
            if (! $pId) {
                continue;
            }
            $oId = (string) $sRow->outlet_id;
            $initLedger($oId, $pId);
            $rowsByOutletProd[$oId][$pId][] = $sRow;
        }

        $nowStr = now()->toIso8601String();
        $dbNow = now();

        foreach ($ledgers as $oId => $prodLedgers) {
            foreach ($prodLedgers as $pId => $ledger) {
                $existingForBucket = $rowsByOutletProd[$oId][$pId] ?? [];
                $currentParsedMilli = 0;
                $primaryRow = null;
                $existingAvgCost = 0;
                foreach ($existingForBucket as $r) {
                    $sp = json_decode($r->payload ?? '{}', true) ?: [];
                    $sf = is_array($sp['fields'] ?? null) ? $sp['fields'] : $sp;
                    $m = $this->parseBalanceMilli($r, $sf, ['isCftProduct' => true]);
                    $currentParsedMilli += $m;
                    $rCost = (int) ($sf['avgCostPaise'] ?? $sf['costPrice'] ?? $sf['weightedAvgCost'] ?? 0);
                    if ($rCost > 0) {
                        $existingAvgCost = $rCost;
                    }
                    $rvId = (string) ($sf['variantId'] ?? $r->variant_id ?? '');
                    if ($rvId === $this->canonicalUnifiedVariantId($pId) || $primaryRow === null) {
                        $primaryRow = $r;
                    }
                }

                $unverified = $ledger['unverifiedReasons'];
                $expectedMilli = max(
                    0,
                    $ledger['openingMilli']
                    + $ledger['purchasesMilli']
                    + $ledger['purchaseReturnsInMilli']
                    + $ledger['customerReturnsInMilli']
                    + $ledger['adjustInMilli']
                    + $ledger['countCorrectionDeltaMilli']
                    - $ledger['salesOutMilli']
                    - $ledger['supplierReturnsOutMilli']
                    - $ledger['adjustOutMilli']
                );

                $needsReconciliation = ! empty($unverified);
                $updated = false;

                if ($apply && ! $needsReconciliation && ($ledger['hasLedgerEvents'] || count($existingForBucket) > 1)) {
                    $targetMilli = $ledger['hasLedgerEvents'] ? $expectedMilli : $currentParsedMilli;
                    $targetUnits = $this->unitService->fromMilliUnits($targetMilli);
                    $purchaseMilliTotal = max(1, $ledger['purchasesMilli']);
                    $derivedAvgCost = $ledger['purchaseValuePaise'] > 0 && $ledger['purchasesMilli'] > 0
                        ? (int) round($ledger['purchaseValuePaise'] / ($purchaseMilliTotal / 1000.0))
                        : $existingAvgCost;
                    $totalVal = $derivedAvgCost > 0 ? (int) round($targetUnits * $derivedAvgCost) : 0;
                    $canonicalVarId = $this->canonicalUnifiedVariantId($pId);

                    if ($primaryRow) {
                        $pp = json_decode($primaryRow->payload ?? '{}', true) ?: [];
                        $pf = is_array($pp['fields'] ?? null) ? $pp['fields'] : [];
                        $pf['productId'] = $pId;
                        $pf['variantId'] = $canonicalVarId;
                        $pf['inventoryBehaviour'] = self::BEHAVIOUR_UNIFIED_PARENT;
                        $pf['baseUnitId'] = $products[$pId]['baseUnitId'] ?: 'SYS_UNIT_CFT';
                        $pf['quantityOnHand'] = $targetMilli;
                        $pf['currentStock'] = $targetMilli;
                        $pf['stockScale'] = 'MILLI';
                        if ($derivedAvgCost > 0) {
                            $pf['avgCostPaise'] = $derivedAvgCost;
                            $pf['costPrice'] = $derivedAvgCost;
                            $pf['averageCost'] = $derivedAvgCost;
                            $pf['weightedAvgCost'] = $derivedAvgCost;
                        }
                        if ($totalVal > 0 || $targetMilli === 0) {
                            $pf['totalValue'] = $totalVal;
                        }
                        unset($pf['needsReconciliation'], $pf['reconciliationReason']);
                        $pf['updatedAt'] = $nowStr;
                        $pp['fields'] = $pf;

                        $upd = ['updated_at' => $dbNow, 'payload' => json_encode($pp)];
                        if (Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
                            $upd['quantity_on_hand'] = $targetUnits;
                        }
                        if (Schema::hasColumn('stock_balances', 'product_id')) {
                            $upd['product_id'] = $pId;
                        }
                        if (Schema::hasColumn('stock_balances', 'variant_id')) {
                            $upd['variant_id'] = $canonicalVarId;
                        }
                        if (Schema::hasColumn('stock_balances', 'inventory_behaviour')) {
                            $upd['inventory_behaviour'] = self::BEHAVIOUR_UNIFIED_PARENT;
                        }
                        DB::table('stock_balances')->where('id', $primaryRow->id)->update($upd);

                        foreach ($existingForBucket as $extra) {
                            if ((string) $extra->id === (string) $primaryRow->id) {
                                continue;
                            }
                            $ep = json_decode($extra->payload ?? '{}', true) ?: [];
                            $ef = is_array($ep['fields'] ?? null) ? $ep['fields'] : [];
                            $ef['quantityOnHand'] = 0;
                            $ef['currentStock'] = 0;
                            $ef['consolidatedInto'] = (string) $primaryRow->id;
                            $ef['updatedAt'] = $nowStr;
                            $ep['fields'] = $ef;
                            $eUpd = ['is_deleted' => 1, 'status' => 'CONSOLIDATED', 'updated_at' => $dbNow, 'payload' => json_encode($ep)];
                            if (Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
                                $eUpd['quantity_on_hand'] = 0;
                            }
                            DB::table('stock_balances')->where('id', $extra->id)->update($eUpd);
                        }
                        $updated = true;
                    } elseif ($targetMilli > 0) {
                        $newBalId = "{$businessId}_{$oId}_{$canonicalVarId}";
                        $newFields = [
                            'tenantId' => $businessId,
                            'businessId' => $businessId,
                            'outletId' => $oId,
                            'productId' => $pId,
                            'variantId' => $canonicalVarId,
                            'inventoryBehaviour' => self::BEHAVIOUR_UNIFIED_PARENT,
                            'baseUnitId' => $products[$pId]['baseUnitId'] ?: 'SYS_UNIT_CFT',
                            'quantityOnHand' => $targetMilli,
                            'currentStock' => $targetMilli,
                            'stockScale' => 'MILLI',
                            'totalValue' => $totalVal,
                            'avgCostPaise' => $derivedAvgCost,
                            'costPrice' => $derivedAvgCost,
                            'updatedAt' => $nowStr,
                        ];
                        $ins = [
                            'id' => $newBalId,
                            'document_path' => "stock_balances/{$newBalId}",
                            'business_id' => $businessId,
                            'outlet_id' => $oId,
                            'status' => 'ACTIVE',
                            'created_at' => $dbNow,
                            'updated_at' => $dbNow,
                            'is_deleted' => 0,
                            'payload' => json_encode([
                                '_documentId' => $newBalId,
                                '_documentPath' => "stock_balances/{$newBalId}",
                                'fields' => $newFields,
                            ]),
                        ];
                        if (Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
                            $ins['quantity_on_hand'] = $targetUnits;
                        }
                        if (Schema::hasColumn('stock_balances', 'product_id')) {
                            $ins['product_id'] = $pId;
                        }
                        if (Schema::hasColumn('stock_balances', 'variant_id')) {
                            $ins['variant_id'] = $canonicalVarId;
                        }
                        if (Schema::hasColumn('stock_balances', 'inventory_behaviour')) {
                            $ins['inventory_behaviour'] = self::BEHAVIOUR_UNIFIED_PARENT;
                        }
                        DB::table('stock_balances')->insert($ins);
                        $updated = true;
                    }
                    if ($updated) {
                        $reconciledCount++;
                    }
                } elseif ($needsReconciliation) {
                    $flaggedCount++;
                    if ($apply && $primaryRow) {
                        $pp = json_decode($primaryRow->payload ?? '{}', true) ?: [];
                        $pf = is_array($pp['fields'] ?? null) ? $pp['fields'] : [];
                        $pf['needsReconciliation'] = true;
                        $pf['reconciliationReason'] = $unverified[0];
                        $pf['updatedAt'] = $nowStr;
                        $pp['fields'] = $pf;
                        DB::table('stock_balances')->where('id', $primaryRow->id)->update([
                            'updated_at' => $dbNow,
                            'payload' => json_encode($pp),
                        ]);
                    }
                }

                $itemsReport[] = [
                    'productId' => $pId,
                    'outletId' => $oId,
                    'previousMilli' => $currentParsedMilli,
                    'calculatedMilli' => $ledger['hasLedgerEvents'] ? $expectedMilli : $currentParsedMilli,
                    'calculatedCft' => round(($ledger['hasLedgerEvents'] ? $expectedMilli : $currentParsedMilli) / 1000.0, 3),
                    'needsReconciliation' => $needsReconciliation,
                    'unverifiedReasons' => $unverified,
                    'updated' => $updated,
                ];
            }
        }

        return [
            'checkedProducts' => count($cftProductIds),
            'reconciledCount' => $reconciledCount,
            'flaggedCount' => $flaggedCount,
            'items' => $itemsReport,
        ];
    }
}
