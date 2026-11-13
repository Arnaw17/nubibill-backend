<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\BusinessController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceCreationService
{
    public function __construct(
        private readonly UnitConversionService $unitService = new UnitConversionService
    ) {}

    /**
     * Compute Indian Financial Year label (e.g. 2026-04 starts FY 26).
     */
    public function financialYearLabel(\DateTimeInterface $date): string
    {
        $m = (int) $date->format('n');
        $y = (int) $date->format('Y');
        $fyYear = $m >= 4 ? $y : $y - 1;

        return str_pad((string) ($fyYear % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Normalize business short code prefix for invoice/quotation numbering.
     */
    public function normalizeShortCode(?string $shortCode, string $fallback = 'INV'): string
    {
        $clean = strtoupper(trim((string) ($shortCode ?? '')));
        if ($clean === '' || ! preg_match('/^[A-Z0-9]+$/', $clean)) {
            return $fallback;
        }

        return $clean;
    }

    /**
     * Validate and calculate normalized line items and tax totals.
     *
     * @return array{
     *   lines: array<int, array<string, mixed>>,
     *   grossTotal: int,
     *   discountTotal: int,
     *   taxableTotal: int,
     *   taxTotal: int,
     *   grandTotal: int
     * }
     */
    public function calculateLinesAndTotals(
        string $businessId,
        string $parentDocId,
        array $rawLines,
        string $taxMode = 'GST',
        string $taxStructure = 'INTRA_STATE',
        int $billDiscountPaise = 0,
        float $billDiscountPercent = 0.0
    ): array {
        if ($billDiscountPaise > 0 || $billDiscountPercent > 0) {
            $totalGrossForDistribution = 0;
            $lineGrosses = [];
            foreach ($rawLines as $idx => $rl) {
                $rQty = (float) ($rl['quantity'] ?? 0);
                $rPrice = (int) ($rl['unit_price_paise'] ?? 0);
                $lg = $rQty > 0 ? $this->unitService->calculateLineGrossPaise($rQty, $rPrice) : 0;
                $lineGrosses[$idx] = $lg;
                $totalGrossForDistribution += $lg;
            }
            if ($totalGrossForDistribution > 0) {
                $effectiveBillDiscount = $billDiscountPercent > 0
                    ? (int) round($totalGrossForDistribution * min(100.0, max(0.0, $billDiscountPercent)) / 100.0)
                    : min($billDiscountPaise, $totalGrossForDistribution);
                $allocated = 0;
                $lastIdx = count($rawLines) - 1;
                foreach ($rawLines as $idx => &$rl) {
                    $lg = $lineGrosses[$idx] ?? 0;
                    if ($idx === $lastIdx) {
                        $extraDisc = max(0, $effectiveBillDiscount - $allocated);
                    } else {
                        $extraDisc = (int) round(($effectiveBillDiscount * $lg) / $totalGrossForDistribution);
                        $allocated += $extraDisc;
                    }
                    $existingDisc = (int) ($rl['discount_paise'] ?? 0);
                    if ($existingDisc <= 0 && (float) ($rl['discount_percent'] ?? 0) > 0) {
                        $existingDisc = (int) round($lg * ((float) $rl['discount_percent']) / 100.0);
                    }
                    $rl['discount_paise'] = $existingDisc + $extraDisc;
                }
                unset($rl);
            }
        }

        $calculatedLines = [];
        $grossTotal = 0;
        $discountTotal = 0;
        $taxableTotal = 0;
        $taxTotal = 0;
        $isNonGst = strtoupper($taxMode) === 'NO_TAX' || strtoupper($taxMode) === 'NON_GST';
        $stockSvc = new InventoryStockService($this->unitService);

        foreach ($rawLines as $index => $item) {
            $qty = round((float) $item['quantity'], 6);
            $unitPrice = (int) $item['unit_price_paise'];
            $discount = (int) ($item['discount_paise'] ?? 0);

            $rawPId = trim((string) ($item['product_id'] ?? ''));
            $rawVId = trim((string) ($item['variant_id'] ?? ''));
            $parsedVar = $stockSvc->parseSyntheticVariantId($rawVId);
            $cleanVId = $parsedVar['cleanVariantId'];
            $vRow = null;
            $vFields = [];
            $pRow = null;
            $pFields = [];

            if ($cleanVId !== '' && ! $parsedVar['isUnifiedPrefix']) {
                $vRow = DB::table('product_variants')
                    ->where('id', $cleanVId)
                    ->orWhere('payload->fields->variantId', $cleanVId)
                    ->first();
                if ($vRow) {
                    $vp = json_decode($vRow->payload ?? '{}', true) ?: [];
                    $vFields = $vp['fields'] ?? [];
                }
            }

            $parsedPId = $stockSvc->parseSyntheticVariantId($rawPId);
            $candidateProductIds = array_values(array_unique(array_filter([
                $parsedPId['derivedProductId'] !== '' ? $parsedPId['derivedProductId'] : $rawPId,
                $vRow ? (string) ($vFields['productId'] ?? $vRow->product_id ?? '') : '',
                $parsedVar['derivedProductId'],
            ])));

            $pId = null;
            foreach ($candidateProductIds as $candPid) {
                $pRow = DB::table('products')->where('id', $candPid)->first();
                if ($pRow) {
                    $pp = json_decode($pRow->payload ?? '{}', true) ?: [];
                    $pFields = $pp['fields'] ?? [];
                    $pId = (string) $pRow->id;
                    break;
                }
            }
            if (! $pRow) {
                $pId = $candidateProductIds[0] ?? null;
            }
            $vId = $rawVId !== '' ? $rawVId : null;

            $matchedOption = ($parsedVar['purchaseOptionId'] !== null)
                ? $stockSvc->findPurchaseOption($businessId, (string) ($pId ?? ''), $parsedVar['purchaseOptionId'])
                : null;

            $unitConfig = (! empty($vFields) || ! empty($pFields))
                ? $this->unitService->resolveVariantUnitConfig($vFields, $pFields, $businessId)
                : null;

            $rawReqUnit = trim((string) ($item['unit_id'] ?? $item['unit'] ?? ''));
            $isGenericPlaceholder = ($rawReqUnit === '' || strtoupper($rawReqUnit) === 'UNIT');

            if ($unitConfig !== null) {
                $configuredUnit = $this->unitService->resolveUnit($unitConfig['defaultSalesUnitId'], $businessId);
                $optionFactor = ($matchedOption !== null && (float) ($matchedOption['conversionFactor'] ?? 0) > 0)
                    ? (float) $matchedOption['conversionFactor']
                    : null;
                $isExplicitProductOptionOrBase = ($matchedOption !== null) || $parsedVar['isBaseLoose'];
                $defaultOptionOrBaseFactor = $parsedVar['isBaseLoose']
                    ? 1.0
                    : ($optionFactor ?? $unitConfig['salesConversionFactor']);

                if (! $isGenericPlaceholder) {
                    $requestedUnit = $this->unitService->resolveUnit($rawReqUnit, $businessId);
                    $matchesDefault = ($requestedUnit['id'] === $configuredUnit['id'])
                        || (strtolower($rawReqUnit) === strtolower($unitConfig['unitSymbol']))
                        || (strtolower($rawReqUnit) === strtolower($unitConfig['unitDisplayName']));

                    if (! $matchesDefault && ! $unitConfig['allowManualUnitOverride'] && ! $isExplicitProductOptionOrBase) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.unit_id" => "Manual unit override is disabled for '{$item['product_name']}' (configured unit: {$unitConfig['unitSymbol']}).",
                        ]);
                    }
                    $effectiveUnit = $matchesDefault ? $configuredUnit : $requestedUnit;
                    $unitSymbol = $matchesDefault
                        ? $unitConfig['unitSymbol']
                        : ($item['unit_symbol'] ?? ((! empty($matchedOption['conversionUnitSymbol'])) ? $matchedOption['conversionUnitSymbol'] : $effectiveUnit['symbol']));
                    $unitDisplayName = $matchesDefault ? $unitConfig['unitDisplayName'] : ($item['unit_display_name'] ?? $effectiveUnit['displayName']);
                    $nonDefaultFallbackFactor = $parsedVar['isBaseLoose']
                        ? 1.0
                        : ($optionFactor ?? $this->unitService->defaultPairFactor($effectiveUnit['id'], $unitConfig['baseUnitId']));
                    $salesFactor = $matchesDefault
                        ? (float) ($item['sales_unit_conversion_factor'] ?? $item['conversion_factor'] ?? $defaultOptionOrBaseFactor)
                        : (float) ($item['sales_unit_conversion_factor'] ?? $item['conversion_factor'] ?? $nonDefaultFallbackFactor);
                    $allowFractional = $matchesDefault ? $unitConfig['allowFractionalQuantity'] : (bool) $effectiveUnit['allowFractional'];
                    if (! $matchesDefault && ! $isExplicitProductOptionOrBase) {
                        $baseUnitMeta = $this->unitService->resolveUnit($unitConfig['baseUnitId'], $businessId);
                        $explicitFactor = $item['sales_unit_conversion_factor'] ?? $item['conversion_factor'] ?? $salesFactor;
                        if (! $this->unitService->areUnitsCompatible($effectiveUnit, $baseUnitMeta, $explicitFactor !== null ? (float) $explicitFactor : null)) {
                            throw ValidationException::withMessages([
                                "lines.{$index}.unit_id" => "Sales unit '{$effectiveUnit['symbol']}' is incompatible with inventory unit '{$baseUnitMeta['symbol']}'.",
                            ]);
                        }
                    }
                } else {
                    $effectiveUnit = $configuredUnit;
                    $unitSymbol = $matchedOption
                        ? ((! empty($matchedOption['conversionUnitSymbol'])) ? $matchedOption['conversionUnitSymbol'] : ($matchedOption['optionName'] ?: $unitConfig['unitSymbol']))
                        : $unitConfig['unitSymbol'];
                    $unitDisplayName = $matchedOption
                        ? ($matchedOption['optionName'] ?: $unitConfig['unitDisplayName'])
                        : $unitConfig['unitDisplayName'];
                    $salesFactor = (float) ($item['sales_unit_conversion_factor'] ?? $item['conversion_factor'] ?? $defaultOptionOrBaseFactor);
                    $allowFractional = $unitConfig['allowFractionalQuantity'];
                }
                $baseUnitId = $unitConfig['baseUnitId'];
                $baseUnitSymbol = $unitConfig['baseUnitSymbol'];
            } else {
                $effectiveUnit = $this->unitService->resolveUnit($isGenericPlaceholder ? 'SYS_UNIT_PIECE' : $rawReqUnit, $businessId);
                $unitSymbol = $item['unit_symbol'] ?? $effectiveUnit['symbol'];
                $unitDisplayName = $item['unit_display_name'] ?? $effectiveUnit['displayName'];
                $salesFactor = (float) ($item['sales_unit_conversion_factor'] ?? $item['conversion_factor'] ?? ($matchedOption['conversionFactor'] ?? 1.0));
                $allowFractional = (bool) $effectiveUnit['allowFractional'];
                $baseUnitId = $effectiveUnit['id'];
                $baseUnitSymbol = $effectiveUnit['symbol'];
            }

            if ($salesFactor <= 0) {
                $salesFactor = 1.0;
            }
            $this->unitService->validateQuantity($qty, $allowFractional, $unitSymbol);

            $qtyBase = $this->unitService->toBaseQuantity($qty, $salesFactor);
            $gross = $this->unitService->calculateLineGrossPaise($qty, $unitPrice);
            if ($discount <= 0 && (float) ($item['discount_percent'] ?? 0) > 0) {
                $discount = (int) round($gross * max(0.0, (float) $item['discount_percent']) / 100.0);
            }
            $discount = min($discount, $gross);
            $net = $gross - $discount;

            $singlePurchasePricePaise = $this->resolveSinglePurchasePricePaise(
                $businessId,
                $pId,
                $vRow ?? null,
                $vFields,
                $pRow ?? null,
                $pFields,
                $matchedOption,
                $unitConfig,
                (float) $salesFactor
            );
            if ($singlePurchasePricePaise > 0) {
                $minimumTotalPaise = $this->unitService->calculateLineGrossPaise($qty, $singlePurchasePricePaise);
                if ($net < $minimumTotalPaise) {
                    $lineName = (string) ($item['product_name'] ?? $item['variant_name'] ?? ($pFields['productName'] ?? $pFields['name'] ?? 'Product'));
                    $minRupees = number_format($singlePurchasePricePaise / 100, 2, '.', '');
                    throw ValidationException::withMessages([
                        "lines.{$index}" => "Selling price cannot be lower than the purchase price. Minimum permissible selling price for '{$lineName}' is ₹{$minRupees} per {$unitSymbol}.",
                    ]);
                }
            }

            $gstRateBps = $isNonGst ? 0 : (int) ($item['gst_rate_bps'] ?? 1800);
            $priceIncludesTax = (bool) ($item['price_includes_tax'] ?? true);

            if ($gstRateBps <= 0) {
                $taxable = $net;
                $tax = 0;
            } elseif ($priceIncludesTax) {
                $taxable = (int) round(($net * 10000) / (10000 + $gstRateBps));
                $tax = $net - $taxable;
            } else {
                $taxable = $net;
                $tax = (int) round(($taxable * $gstRateBps) / 10000);
            }

            $isInterState = strtoupper($taxStructure) === 'INTER_STATE';
            $cgst = $isInterState ? 0 : (int) ($tax / 2);
            $sgst = $isInterState ? 0 : ($tax - $cgst);
            $igst = $isInterState ? $tax : 0;

            $grossTotal += $gross;
            $discountTotal += $discount;
            $taxableTotal += $taxable;
            $taxTotal += $tax;

            $mergedAttrs = array_merge(
                is_array($pFields['attributeValues'] ?? null) ? $pFields['attributeValues'] : [],
                is_array($vFields['attributeValues'] ?? null) ? $vFields['attributeValues'] : [],
                is_array($item['attribute_values'] ?? $item['attributeValues'] ?? null) ? ($item['attribute_values'] ?? $item['attributeValues']) : []
            );
            $snapSize = $item['aggregate_size'] ?? $item['size'] ?? ($mergedAttrs['aggregateSize'] ?? $mergedAttrs['size'] ?? null);
            $snapQuality = $item['grade_quality'] ?? $item['quality'] ?? ($mergedAttrs['gradeQuality'] ?? $mergedAttrs['quality'] ?? null);
            if ($snapSize !== null && $snapSize !== '') {
                $mergedAttrs['aggregateSize'] = $snapSize;
            }
            if ($snapQuality !== null && $snapQuality !== '') {
                $mergedAttrs['gradeQuality'] = $snapQuality;
            }
            $customAttrSnap = is_array($item['custom_attributes'] ?? null)
                ? $item['custom_attributes']
                : (is_array($mergedAttrs['customAttributes'] ?? null) ? $mergedAttrs['customAttributes'] : $mergedAttrs);

            $calculatedLines[] = [
                'itemId' => "REQ_{$parentDocId}_{$index}",
                'productId' => $pId,
                'variantId' => $vId,
                'sku' => $item['sku'] ?? '',
                'name' => $item['product_name'] ?? 'Product',
                'variantName' => $item['variant_name'] ?? ($item['product_name'] ?? 'Variant'),
                'quantity' => $qty,
                'quantityBase' => $qtyBase,
                'baseQuantity' => $qtyBase,
                'unit' => $unitSymbol,
                'unitId' => $effectiveUnit['id'],
                'unitSymbol' => $unitSymbol,
                'unitDisplayName' => $unitDisplayName,
                'baseUnitId' => $baseUnitId,
                'baseUnitSymbol' => $baseUnitSymbol,
                'allowFractionalQuantity' => $allowFractional,
                'priceIncludesTax' => $priceIncludesTax,
                'trackStock' => array_key_exists('track_stock', $item) ? (bool) $item['track_stock'] : true,
                'unitPricePaise' => $unitPrice,
                'discountPaise' => $discount,
                'taxablePaise' => $taxable,
                'taxPaise' => $tax,
                'cgstPaise' => $cgst,
                'sgstPaise' => $sgst,
                'igstPaise' => $igst,
                'lineTotalPaise' => $taxable + $tax,
                'gstRateBps' => $gstRateBps,
                'hsn' => $item['hsn_code'] ?? '',
                'categoryId' => $pFields['categoryId'] ?? $pFields['category_id'] ?? null,
                'aggregateSize' => $snapSize,
                'gradeQuality' => $snapQuality,
                'attributeValues' => ! empty($mergedAttrs) ? $mergedAttrs : null,
                'customAttributes' => ! empty($customAttrSnap) ? $customAttrSnap : null,
                'sellingPrice' => $item['selling_price'] ?? $unitPrice,
                'mrp' => $item['mrp'] ?? $unitPrice,
                'conversionFactor' => $salesFactor,
                'batchId' => $item['batch_id'] ?? null,
                'rackId' => $item['rack_id'] ?? null,
            ];
        }

        return [
            'lines' => $calculatedLines,
            'grossTotal' => $grossTotal,
            'discountTotal' => $discountTotal,
            'taxableTotal' => $taxableTotal,
            'taxTotal' => $taxTotal,
            'grandTotal' => $taxableTotal + $taxTotal,
        ];
    }

    /**
     * Increment and return the next sequence number for a document sequence type.
     */
    /**
     * Resolve the single-unit purchase price (in paise) for the sold unit.
     */
    public function resolveSinglePurchasePricePaise(
        string $businessId,
        ?string $productId,
        ?object $vRow,
        array $vFields,
        ?object $pRow,
        array $pFields,
        ?array $matchedOption,
        ?array $unitConfig,
        float $salesFactor = 1.0
    ): int {
        $safeSalesFactor = $salesFactor > 0.00001 ? $salesFactor : 1.0;

        // 1. Purchase/Sales option with explicit option purchase price
        if ($matchedOption !== null && (int) ($matchedOption['purchasePrice'] ?? 0) > 0) {
            $optPrice = (int) $matchedOption['purchasePrice'];
            $optFactor = (float) ($matchedOption['conversionFactor'] ?? 1.0);
            if ($optFactor <= 0.00001) {
                $optFactor = 1.0;
            }
            if (abs($safeSalesFactor - $optFactor) < 0.0001) {
                return $optPrice;
            }

            return (int) round(($optPrice / $optFactor) * $safeSalesFactor);
        }

        // 2. Variant purchase rate / purchasePricePerUnit
        $rawVariantRate = (int) ($vFields['purchaseRate'] ?? $vFields['purchasePrice'] ?? ($vRow->purchase_rate ?? 0));
        $rawVariantPerUnit = (int) ($vFields['purchasePricePerUnit'] ?? $vFields['purchase_price_per_unit'] ?? 0);
        $rawProductRate = (int) ($pFields['purchaseRate'] ?? $pFields['purchasePrice'] ?? 0);
        $rawProductPerUnit = (int) ($pFields['purchasePricePerUnit'] ?? $pFields['purchase_price_per_unit'] ?? 0);

        $purchaseFactor = (float) ($unitConfig['purchaseConversionFactor']
            ?? $vFields['purchaseConversionFactor']
            ?? $vFields['baseQuantityPerCommercialUnit']
            ?? 1.0);
        if ($purchaseFactor <= 0.00001) {
            $purchaseFactor = 1.0;
        }
        $baseUnitId = (string) ($unitConfig['baseUnitId'] ?? $vFields['baseUnitId'] ?? $pFields['baseUnitId'] ?? '');
        $purchaseUnitId = (string) ($unitConfig['purchaseUnitId'] ?? $vFields['purchaseUnitId'] ?? $pFields['purchaseUnitId'] ?? $baseUnitId);

        $perBasePaise = 0.0;
        if ($rawVariantPerUnit > 0) {
            if ($rawVariantRate > 0 && $rawVariantPerUnit === $rawVariantRate && $purchaseFactor > 1.0001 && $purchaseUnitId !== $baseUnitId) {
                $perBasePaise = $rawVariantRate / $purchaseFactor;
            } else {
                $perBasePaise = (float) $rawVariantPerUnit;
            }
        } elseif ($rawVariantRate > 0) {
            $perBasePaise = ($purchaseFactor > 1.0001 && $purchaseUnitId !== $baseUnitId)
                ? ($rawVariantRate / $purchaseFactor)
                : (float) $rawVariantRate;
        } elseif ($rawProductPerUnit > 0) {
            if ($rawProductRate > 0 && $rawProductPerUnit === $rawProductRate && $purchaseFactor > 1.0001 && $purchaseUnitId !== $baseUnitId) {
                $perBasePaise = $rawProductRate / $purchaseFactor;
            } else {
                $perBasePaise = (float) $rawProductPerUnit;
            }
        } elseif ($rawProductRate > 0) {
            $perBasePaise = ($purchaseFactor > 1.0001 && $purchaseUnitId !== $baseUnitId)
                ? ($rawProductRate / $purchaseFactor)
                : (float) $rawProductRate;
        }

        // 3. Unified base_loose fallback: check a 1:1 base purchase option if product has no variant purchaseRate
        if ($perBasePaise <= 0 && ! empty($productId) && Schema::hasTable('product_purchase_options')) {
            $optRows = DB::table('product_purchase_options')
                ->where('business_id', $businessId)
                ->where('product_id', $productId)
                ->where('is_deleted', 0)
                ->get();
            foreach ($optRows as $optRow) {
                $optPayload = json_decode($optRow->payload ?? '{}', true) ?: [];
                $optFields = $optPayload['fields'] ?? [];
                $factor = (float) ($optFields['conversionFactor'] ?? ($optRow->conversion_factor ?? 0));
                $price = (int) ($optFields['purchasePrice'] ?? ($optRow->purchase_price ?? 0));
                if ($price > 0 && $factor > 0 && abs($factor - 1.0) < 0.0001) {
                    $perBasePaise = (float) $price;
                    break;
                }
            }
        }

        if ($perBasePaise <= 0) {
            return 0;
        }

        return (int) round($perBasePaise * $safeSalesFactor);
    }

    public function nextSequence(
        string $businessId,
        string $outletId,
        string $sequenceType,
        string $fy,
        string $nowStr,
        $dbNow
    ): int {
        $sequenceId = "{$businessId}_{$fy}_{$sequenceType}";
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
                        'sequenceType' => $sequenceType,
                        'financialYear' => $fy,
                        'updatedAt' => $nowStr,
                    ],
                ]),
            ]);
        }

        return $nextSequence;
    }

    /**
     * Finalize and persist a real sales invoice, including ledger, payment, and stock deduction.
     *
     * @return array{
     *   invoiceId: string,
     *   invoiceNumber: string,
     *   existing: bool,
     *   invoice?: array<string, mixed>
     * }
     */
    public function finalizeInvoice(array $params): array
    {
        $requestId = $params['request_id'] ?? null;
        $invoiceId = $requestId ? "REQ_{$requestId}" : ('INV-'.strtoupper(Str::random(24)));
        $businessId = $params['business_id'];
        $outletId = $params['outlet_id'];
        $shortCode = $this->normalizeShortCode($params['business_short_code'] ?? 'INV', 'INV');
        $userId = $params['user_id'] ?? 'SYSTEM';
        $taxMode = $params['tax_mode'] ?? 'GST';
        $taxStructure = $params['tax_structure'] ?? 'INTRA_STATE';

        $nowDt = new \DateTimeImmutable;
        $nowStr = $nowDt->format(\DateTimeInterface::ATOM);
        $dbNow = now();
        $fy = $this->financialYearLabel($nowDt);

        if ($requestId) {
            $existing = DB::table('invoices')->where('id', $invoiceId)->first();
            if ($existing) {
                $payload = json_decode($existing->payload, true) ?: [];
                $fields = $payload['fields'] ?? [];

                return [
                    'invoiceId' => $invoiceId,
                    'invoiceNumber' => $fields['invoiceNumber'] ?? $existing->id,
                    'existing' => true,
                    'invoice' => $fields,
                ];
            }
        }

        $customerId = $params['customer_id'] ?? null;
        $customerName = $params['customer_name'] ?? null;
        $customerPhone = ! empty($params['customer_phone']) ? preg_replace('/\D/', '', $params['customer_phone']) : null;
        $customerGstin = ! empty($params['customer_gstin']) ? strtoupper(trim($params['customer_gstin'])) : null;
        $customerStateCode = $params['customer_state_code'] ?? null;

        $cashPaise = (int) ($params['cash_paise'] ?? 0);
        $upiPaise = (int) ($params['upi_paise'] ?? 0);
        $advancePaise = (int) ($params['advance_paise'] ?? 0);
        $duePaise = (int) ($params['due_paise'] ?? 0);
        $dueDate = $params['due_date'] ?? null;

        if ($duePaise > 0 && empty($customerId)) {
            throw ValidationException::withMessages([
                'customer_id' => 'A customer must be selected for credit / due (Udhaar) sales.',
            ]);
        }

        $calc = $this->calculateLinesAndTotals(
            $businessId,
            $invoiceId,
            $params['lines'],
            $taxMode,
            $taxStructure,
            (int) ($params['bill_discount_paise'] ?? $params['discount_paise'] ?? 0),
            (float) ($params['bill_discount_percent'] ?? $params['discount_percent'] ?? 0.0)
        );

        $calculatedLines = $calc['lines'];
        $grossTotal = $calc['grossTotal'];
        $discountTotal = $calc['discountTotal'];
        $taxableTotal = $calc['taxableTotal'];
        $taxTotal = $calc['taxTotal'];
        $grandTotal = $calc['grandTotal'];

        $totalPaidOnBill = $cashPaise + $upiPaise + $advancePaise;
        if (($params['enforce_payment_allocation'] ?? false) && ($totalPaidOnBill + $duePaise) < $grandTotal) {
            throw ValidationException::withMessages([
                'cash_paise' => 'Total allocated payment plus due is less than bill grand total.',
            ]);
        }

        $status = $duePaise == 0 ? 'PAID' : ($totalPaidOnBill > 0 ? 'PARTIALLY_PAID' : 'PENDING');

        $quotationId = $params['quotation_id'] ?? null;
        $quotationNumber = $params['quotation_number'] ?? null;

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
            $quotationId,
            $quotationNumber,
            $taxStructure,
            $nowStr,
            $dbNow,
            $userId
        ) {
            $nextSequence = $this->nextSequence(
                $businessId,
                $outletId,
                'SALES_INVOICE',
                $fy,
                $nowStr,
                $dbNow
            );

            $seqFormatted = str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
            $invoiceNumber = "{$shortCode}-{$fy}-{$seqFormatted}";

            $logoSnapshot = BusinessController::resolveActiveLogoSnapshot($businessId);
            $signatureSnapshot = BusinessController::resolveActiveSignatureSnapshot($businessId);

            $isInterState = strtoupper($taxStructure) === 'INTER_STATE';
            $cgstTotal = $isInterState ? 0 : (int) ($taxTotal / 2);
            $sgstTotal = $isInterState ? 0 : ($taxTotal - $cgstTotal);
            $igstTotal = $isInterState ? $taxTotal : 0;

            $invoiceFields = array_merge([
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
                'cgstPaise' => $cgstTotal,
                'sgstPaise' => $sgstTotal,
                'igstPaise' => $igstTotal,
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
                'quotationId' => $quotationId,
                'quotationNumber' => $quotationNumber,
                'createdAt' => $nowStr,
                'createdByUid' => $userId,
                'updatedAt' => $nowStr,
            ], $logoSnapshot, $signatureSnapshot);

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
            $hasInvItemUnitCols = Schema::hasColumn('invoice_items', 'unit_symbol');
            foreach ($calculatedLines as $line) {
                $invItemRow = [
                    'id' => $line['itemId'],
                    'document_path' => "invoice_items/{$line['itemId']}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'invoice_id' => $invoiceId,
                    'product_id' => $line['productId'] ?? null,
                    'quantity' => $line['quantity'] ?? 0,
                    'returned_qty' => 0,
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
                ];
                if ($hasInvItemUnitCols) {
                    $invItemRow['unit_id'] = $line['unitId'] ?? null;
                    $invItemRow['unit_symbol'] = $line['unitSymbol'] ?? ($line['unit'] ?? null);
                    $invItemRow['unit_display_name'] = $line['unitDisplayName'] ?? null;
                    $invItemRow['conversion_factor'] = $line['conversionFactor'] ?? 1.0;
                    $invItemRow['base_quantity'] = $line['baseQuantity'] ?? ($line['quantity'] ?? 0);
                }
                DB::table('invoice_items')->insert($invItemRow);
            }

            $ledgerItemsSummary = implode(', ', array_map(function ($line) {
                $qStr = rtrim(rtrim(number_format((float) ($line['quantity'] ?? 0), 3, '.', ''), '0'), '.');
                $uStr = (string) ($line['unitSymbol'] ?? $line['unit'] ?? '');

                return trim("{$line['name']} ({$qStr} {$uStr})");
            }, $calculatedLines));

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

                if ($duePaise > 0) {
                    $ledgerId = 'LED-'.strtoupper(Str::random(24));
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
                                'invoiceNumber' => $invoiceNumber,
                                'description' => "Credit Sale {$invoiceNumber}: {$ledgerItemsSummary}",
                                'itemsSummary' => $ledgerItemsSummary,
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

                $paidNow = $cashPaise + $upiPaise;
                if ($paidNow > 0) {
                    $ledgerPayId = 'LED-'.strtoupper(Str::random(24));
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
                                'invoiceNumber' => $invoiceNumber,
                                'description' => "Payment {$invoiceNumber}: {$ledgerItemsSummary}",
                                'itemsSummary' => $ledgerItemsSummary,
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
                $payId = 'PAY-'.strtoupper(Str::random(24));
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

            // 5. Update stock balances: deduct exact sold base quantity
            $stockSvc = new InventoryStockService($this->unitService);
            $lowStockAlerts = [];
            foreach ($calculatedLines as $line) {
                $vId = $line['variantId'] ?? '';
                $pId = $line['productId'] ?? '';
                $qty = (float) ($line['quantity'] ?? 0);
                $qtyBase = (float) ($line['quantityBase'] ?? $qty);
                $unitSymbol = (string) ($line['unitSymbol'] ?? $line['unit'] ?? 'PCS');
                $baseUnitSymbol = (string) ($line['baseUnitSymbol'] ?? $unitSymbol);
                if ($qtyBase <= 0 || ($line['trackStock'] ?? true) === false) {
                    continue;
                }

                $parsedVar = $stockSvc->parseSyntheticVariantId($vId);
                $cleanVId = $parsedVar['cleanVariantId'];
                $resolvedPid = trim((string) $pId) !== '' ? trim((string) $pId) : $parsedVar['derivedProductId'];

                $meta = $resolvedPid !== '' ? $stockSvc->resolveProductMeta($businessId, $resolvedPid) : [
                    'behaviour' => $parsedVar['isUnifiedPrefix']
                        ? InventoryStockService::BEHAVIOUR_UNIFIED_PARENT
                        : InventoryStockService::BEHAVIOUR_SEPARATE_VARIANT,
                    'fields' => [],
                ];
                $pId = $resolvedPid;
                $invBehaviour = $meta['behaviour'];
                $target = $stockSvc->inspectStockTarget($businessId, $outletId, $pId, $cleanVId, $invBehaviour);
                $stockRow = $target['canonicalRow'];
                if (! $stockRow && $pId !== '' && ! $stockSvc->isUnifiedBehaviour($invBehaviour)
                    && $stockSvc->isSandOrGravelCftProduct($meta['fields'] ?? [], [])) {
                    $unifiedTarget = $stockSvc->inspectStockTarget(
                        $businessId,
                        $outletId,
                        $pId,
                        $cleanVId,
                        InventoryStockService::BEHAVIOUR_UNIFIED_PARENT
                    );
                    if ($unifiedTarget['canonicalRow']) {
                        $invBehaviour = InventoryStockService::BEHAVIOUR_UNIFIED_PARENT;
                        $target = $unifiedTarget;
                        $stockRow = $unifiedTarget['canonicalRow'];
                    }
                }
                $targetVariantId = $target['canonicalVariantId'] ?: ($cleanVId ?: $pId);

                if ($stockRow) {
                    $currStockMilli = $target['totalMilli'];

                    $milliQty = $this->unitService->toMilliUnits($qtyBase);
                    if ($currStockMilli < $milliQty) {
                        $availUnits = $this->unitService->fromMilliUnits($currStockMilli);
                        throw ValidationException::withMessages([
                            'lines' => "Insufficient stock for '{$line['name']}'. Available: {$availUnits} {$baseUnitSymbol}, Requested: {$qtyBase} {$baseUnitSymbol}.",
                        ]);
                    }

                    $newStockMilli = max(0, $currStockMilli - $milliQty);
                    $sbPayload = json_decode($stockRow->payload, true) ?: [];
                    $sbFields = $sbPayload['fields'] ?? [];
                    $oldVal = (int) ($sbFields['totalValue'] ?? 0);
                    $newVal = $currStockMilli > 0 ? max(0, (int) round($oldVal * ($newStockMilli / $currStockMilli))) : 0;

                    $sbFields['quantityOnHand'] = $newStockMilli;
                    $sbFields['currentStock'] = $newStockMilli;
                    $sbFields['stockScale'] = 'MILLI';
                    $sbFields['inventoryBehaviour'] = $invBehaviour;
                    if ($pId !== '') {
                        $sbFields['productId'] = $pId;
                    }
                    $sbFields['variantId'] = $targetVariantId;
                    $sbFields['baseUnitId'] = $line['baseUnitId'] ?? ($sbFields['baseUnitId'] ?? null);
                    $sbFields['totalValue'] = $newVal;
                    $sbFields['updatedAt'] = $nowStr;
                    $sbPayload['fields'] = $sbFields;

                    $balanceAfterUnits = $this->unitService->fromMilliUnits($newStockMilli);
                    $mFields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];
                    $reorderLevel = (float) ($mFields['reorderLevel'] ?? $mFields['reorder_level'] ?? 5);
                    if ($balanceAfterUnits <= $reorderLevel) {
                        $lowStockAlerts[] = [
                            'productId' => $pId,
                            'variantId' => $targetVariantId,
                            'productName' => (string) ($line['name'] ?? $mFields['productName'] ?? 'Product'),
                            'remainingStock' => $balanceAfterUnits,
                            'reorderLevel' => $reorderLevel,
                            'unit' => $baseUnitSymbol,
                            'isOutOfStock' => $balanceAfterUnits <= 0,
                        ];
                    }
                    $sbUpdate = [
                        'updated_at' => $dbNow,
                        'payload' => json_encode($sbPayload),
                    ];
                    if (Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
                        $sbUpdate['quantity_on_hand'] = $balanceAfterUnits;
                    }
                    if (Schema::hasColumn('stock_balances', 'product_id') && $pId !== '') {
                        $sbUpdate['product_id'] = $pId;
                    }
                    if (Schema::hasColumn('stock_balances', 'variant_id')) {
                        $sbUpdate['variant_id'] = $targetVariantId;
                    }
                    if (Schema::hasColumn('stock_balances', 'inventory_behaviour')) {
                        $sbUpdate['inventory_behaviour'] = $invBehaviour;
                    }
                    DB::table('stock_balances')->where('id', $stockRow->id)->update($sbUpdate);

                    if ($stockSvc->isUnifiedBehaviour($invBehaviour)) {
                        foreach ($target['allRows'] as $extraRow) {
                            if ((string) $extraRow->id === (string) $stockRow->id) {
                                continue;
                            }
                            $ep = json_decode($extraRow->payload ?? '{}', true) ?: [];
                            $ef = $ep['fields'] ?? [];
                            $ef['quantityOnHand'] = 0;
                            $ef['currentStock'] = 0;
                            $ef['consolidatedInto'] = $stockRow->id;
                            $ef['updatedAt'] = $nowStr;
                            $ep['fields'] = $ef;
                            $extraUpd = [
                                'is_deleted' => 1,
                                'status' => 'CONSOLIDATED',
                                'updated_at' => $dbNow,
                                'payload' => json_encode($ep),
                            ];
                            if (Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
                                $extraUpd['quantity_on_hand'] = 0;
                            }
                            DB::table('stock_balances')->where('id', $extraRow->id)->update($extraUpd);
                        }
                    }

                    $movementId = 'STM-'.strtoupper(Str::random(24));
                    $movInsert = [
                        'id' => $movementId,
                        'document_path' => "stock_movements/{$movementId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'product_id' => $pId ?: $targetVariantId,
                        'variant_id' => $targetVariantId,
                        'type' => 'sale',
                        'qty_change' => -$qtyBase,
                        'balance_after' => $balanceAfterUnits,
                        'reference_type' => 'invoice',
                        'reference_id' => $invoiceId,
                        'condition' => 'good',
                        'note' => "Sale {$invoiceNumber} ({$qty} {$unitSymbol})",
                        'created_by' => $userId,
                        'created_at' => $dbNow,
                        'updated_at' => $dbNow,
                        'payload' => json_encode([
                            'fields' => [
                                'movementId' => $movementId,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'productId' => $pId ?: $targetVariantId,
                                'variantId' => $targetVariantId,
                                'originalVariantId' => $vId ?: null,
                                'inventoryBehaviour' => $invBehaviour,
                                'type' => 'SALE',
                                'qtyBase' => -$milliQty,
                                'quantity' => -$qty,
                                'unit' => $unitSymbol,
                                'unitId' => $line['unitId'] ?? null,
                                'baseUnitSymbol' => $baseUnitSymbol,
                                'conversionFactor' => $line['conversionFactor'] ?? 1.0,
                                'balanceAfter' => $newStockMilli,
                                'balanceAfterUnits' => $balanceAfterUnits,
                                'reason' => "Sale {$invoiceNumber} ({$qty} {$unitSymbol})",
                                'referenceId' => $invoiceId,
                                'userId' => $userId,
                                'createdAt' => $nowStr,
                                'immutable' => true,
                            ],
                        ]),
                    ];
                    if (Schema::hasColumn('stock_movements', 'inventory_behaviour')) {
                        $movInsert['inventory_behaviour'] = $invBehaviour;
                    }
                    DB::table('stock_movements')->insert($movInsert);
                }
            }

            return [
                'invoiceId' => $invoiceId,
                'invoiceNumber' => $invoiceNumber,
                'existing' => false,
                'invoice' => $invoiceFields,
                'lowStockAlerts' => $lowStockAlerts,
            ];
        });
    }
}
