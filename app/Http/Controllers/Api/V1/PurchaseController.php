<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\SendFcmNotificationJob;
use App\Services\InventoryStockService;
use App\Services\UnitConversionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function __construct(private readonly UnitConversionService $unitService = new UnitConversionService) {}

    /**
     * List purchase bills for business and outlet.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $supplierId = $request->query('supplier_id') ?? $request->query('supplierId');
        $startDate = $request->query('start_date') ?? $request->query('startDate');
        $endDate = $request->query('end_date') ?? $request->query('endDate');
        $statusFilter = strtoupper((string) ($request->query('status') ?? 'ALL'));
        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        $query = DB::table('purchase_bills')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId && $outletId !== 'ALL') {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                    ->orWhere('outlet_id', '')
                    ->orWhereNull('outlet_id');
            });
        }

        if ($startDate && $endDate) {
            $startDt = date('Y-m-d 00:00:00', strtotime($startDate));
            $endDt = date('Y-m-d 23:59:59', strtotime($endDate));
            $query->whereBetween('created_at', [$startDt, $endDt]);
        }

        if ($supplierId) {
            $query->where(function ($q) use ($supplierId) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.supplierId')) = ?", [$supplierId]);
            });
        }

        if ($statusFilter !== 'ALL') {
            if ($statusFilter === 'HISTORICAL') {
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
                        ->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.status'))) = ?", [$statusFilter]);
                });
            }
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        // Pre-fetch product HSN map for line items if needed
        $allProductIds = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];
            foreach ($fields['items'] ?? [] as $it) {
                if (! empty($it['productId'])) {
                    $allProductIds[] = $it['productId'];
                }
            }
        }
        $hsnMap = [];
        if (! empty($allProductIds)) {
            $prods = DB::table('products')->whereIn('id', array_unique($allProductIds))->get();
            foreach ($prods as $p) {
                $pPayload = json_decode($p->payload, true) ?: [];
                $hsn = $pPayload['fields']['hsnCode'] ?? $pPayload['fields']['hsn'] ?? null;
                if ($hsn) {
                    $hsnMap[$p->id] = (string) $hsn;
                }
            }
        }

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

            $items = $fields['items'] ?? [];
            if (! empty($items) && is_array($items)) {
                foreach ($items as &$it) {
                    if (empty($it['hsnCode']) && ! empty($it['productId']) && isset($hsnMap[$it['productId']])) {
                        $it['hsnCode'] = $hsnMap[$it['productId']];
                    }
                }
                unset($it);
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
                'isHistorical' => (bool) ($fields['isHistorical'] ?? ($row->is_historical == 1) ?? false),
                'is_historical' => (int) ($row->is_historical ?? ($fields['isHistorical'] ? 1 : 0)),
                'entrySource' => (string) ($fields['entrySource'] ?? $row->entry_source ?? 'normal'),
                'itemsCount' => count($items),
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
            return ApiResponse::error('Purchase bill not found.', 404, 'NOT_FOUND');
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
            return ApiResponse::error('Unauthorized access to this purchase bill.', 403, 'FORBIDDEN');
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

        if (! empty($items) && is_array($items)) {
            $pIds = array_filter(array_column($items, 'productId'));
            if (! empty($pIds)) {
                $prods = DB::table('products')->whereIn('id', array_unique($pIds))->get();
                $hsnMap = [];
                foreach ($prods as $p) {
                    $pPayload = json_decode($p->payload, true) ?: [];
                    $hsn = $pPayload['fields']['hsnCode'] ?? $pPayload['fields']['hsn'] ?? null;
                    if ($hsn) {
                        $hsnMap[$p->id] = (string) $hsn;
                    }
                }
                foreach ($items as &$it) {
                    if (empty($it['hsnCode']) && ! empty($it['productId']) && isset($hsnMap[$it['productId']])) {
                        $it['hsnCode'] = $hsnMap[$it['productId']];
                    }
                }
                unset($it);
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
                'purchase_option_id' => $ri['purchase_option_id'] ?? $ri['purchaseOptionId'] ?? null,
                'purchase_option_name' => $ri['purchase_option_name'] ?? $ri['purchaseOptionName'] ?? null,
                'effective_base_unit_cost' => $ri['effective_base_unit_cost'] ?? $ri['effectiveBaseUnitCost'] ?? null,
                'product_name' => $ri['product_name'] ?? $ri['productName'] ?? '',
                'variant_name' => $ri['variant_name'] ?? $ri['variantName'] ?? '',
                'quantity' => $ri['quantity'] ?? $ri['qty'] ?? 1,
                'quantity_scale' => $ri['quantity_scale'] ?? $ri['quantityScale'] ?? null,
                'conversion_factor' => $ri['conversion_factor'] ?? $ri['conversionFactor'] ?? null,
                'purchase_conversion_factor' => $ri['purchase_conversion_factor'] ?? $ri['purchaseConversionFactor'] ?? null,
                'base_quantity' => $ri['base_quantity'] ?? $ri['baseQuantity'] ?? null,
                'unit_id' => $ri['unit_id'] ?? $ri['unitId'] ?? ($ri['purchase_unit_id'] ?? $ri['purchaseUnitId'] ?? null),
                'unit_symbol' => $ri['unit_symbol'] ?? $ri['unitSymbol'] ?? null,
                'rate' => $ri['rate'] ?? 0,
                'gst_rate_bps' => $ri['gst_rate_bps'] ?? $ri['gstRateBps'] ?? 0,
                'selling_price' => $ri['selling_price'] ?? $ri['sellingPrice'] ?? 0,
                'mrp' => $ri['mrp'] ?? 0,
                'barcode' => $ri['barcode'] ?? $ri['barcodeSnapshot'] ?? null,
                'batch_number' => $ri['batch_number'] ?? $ri['batchNumber'] ?? null,
                'manufacturing_date' => $ri['manufacturing_date'] ?? $ri['manufacturingDate'] ?? null,
                'expiry_date' => $ri['expiry_date'] ?? $ri['expiryDate'] ?? null,
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
            'items.*.variant_id' => 'nullable|string',
            'items.*.purchase_option_id' => 'nullable|string',
            'items.*.purchaseOptionId' => 'nullable|string',
            'items.*.purchase_option_name' => 'nullable|string',
            'items.*.purchaseOptionName' => 'nullable|string',
            'items.*.effective_base_unit_cost' => 'nullable|numeric|min:0',
            'items.*.product_name' => 'nullable|string',
            'items.*.variant_name' => 'nullable|string',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.quantity_scale' => 'nullable|string|in:UNIT,MILLI',
            'items.*.conversion_factor' => 'nullable|numeric|min:0.0001',
            'items.*.purchase_conversion_factor' => 'nullable|numeric|min:0.0001',
            'items.*.base_quantity' => 'nullable|numeric|min:0.001',
            'items.*.unit_id' => 'nullable|string',
            'items.*.unit_symbol' => 'nullable|string',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.gst_rate_bps' => 'nullable|integer|min:0',
            'items.*.selling_price' => 'nullable|integer|min:0',
            'items.*.mrp' => 'nullable|integer|min:0',
            'items.*.barcode' => 'nullable|string',
            'items.*.batch_number' => 'nullable|string',
            'items.*.manufacturing_date' => 'nullable|string',
            'items.*.expiry_date' => 'nullable|string',
            'items.*.aggregate_size' => 'nullable|string',
            'items.*.grade_quality' => 'nullable|string',
            'items.*.custom_attributes' => 'nullable|array',
            'items.*.attribute_values' => 'nullable|array',
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
            return ApiResponse::error('Supplier not found.', 404, 'NOT_FOUND');
        }
        $sPayload = json_decode($supplierRow->payload, true) ?: [];
        $supplierName = $sPayload['fields']['name'] ?? 'Supplier';

        // Calculate totals from line items
        $stockSvcOuter = new InventoryStockService($this->unitService);
        $lineItems = [];
        $subtotal = 0;
        $totalTax = 0;

        foreach ($validated['items'] as $index => $item) {
            $rawQty = (float) $item['quantity'];
            $rate = (int) $item['rate'];
            $gstRateBps = (int) ($item['gst_rate_bps'] ?? 1800);

            $pId = $item['product_id'] ?? null;
            $vIdInput = $item['variant_id'] ?? '';
            $purchaseOptionId = $item['purchase_option_id'] ?? $item['purchaseOptionId'] ?? null;
            $purchaseOptionName = $item['purchase_option_name'] ?? $item['purchaseOptionName'] ?? null;
            $vFields = [];
            $pFields = [];
            if (! empty($vIdInput)) {
                $vRow = DB::table('product_variants')->where('id', $vIdInput)->first();
                if ($vRow) {
                    $vp = json_decode($vRow->payload, true) ?: [];
                    $vFields = $vp['fields'] ?? [];
                    $pId = $pId ?: ($vFields['productId'] ?? '');
                }
            }
            if (! empty($pId)) {
                $pRow = DB::table('products')->where('id', $pId)->first();
                if ($pRow) {
                    $pp = json_decode($pRow->payload, true) ?: [];
                    $pFields = $pp['fields'] ?? [];
                }
            }
            $invBehaviour = $stockSvcOuter->normalizeBehaviour($pFields['inventoryBehaviour'] ?? null, $pFields);
            $matchedOption = ($purchaseOptionId && $pId)
                ? $stockSvcOuter->findPurchaseOption($businessId, (string) $pId, (string) $purchaseOptionId)
                : null;
            if ($matchedOption) {
                $purchaseOptionName ??= $matchedOption['optionName'];
            }
            $effectiveVariantId = $vIdInput !== ''
                ? $vIdInput
                : ($matchedOption && ! empty($matchedOption['variantId'])
                    ? (string) $matchedOption['variantId']
                    : ($pId ? $stockSvcOuter->canonicalUnifiedVariantId((string) $pId) : ''));

            $unitConfig = (! empty($vFields) || ! empty($pFields))
                ? $this->unitService->resolveVariantUnitConfig($vFields, $pFields, $businessId)
                : null;

            $reqUnitRef = $item['unit_id']
                ?? ($matchedOption['conversionUnitId'] ?? null)
                ?? ($unitConfig['purchaseUnitId'] ?? 'SYS_UNIT_PIECE');
            $purchaseUnit = $this->unitService->resolveUnit($reqUnitRef, $businessId);
            $unitSymbol = $item['unit_symbol'] ?? ($purchaseOptionName ?: $purchaseUnit['symbol']);

            $scale = strtoupper((string) ($item['quantity_scale'] ?? ''));
            $isMilliCaller = $scale === 'MILLI';
            if ($scale === '' && isset($item['base_quantity'], $item['conversion_factor'])) {
                $cf = (float) $item['conversion_factor'];
                if ($cf >= 1000 && abs((float) $item['base_quantity'] - (($rawQty * $cf) / 1000.0)) < 2) {
                    $isMilliCaller = true;
                }
            }

            if ($isMilliCaller) {
                $qtyUnits = round($rawQty / 1000.0, 6);
                $convFactorDecimal = round(((float) ($item['conversion_factor'] ?? 1000)) / 1000.0, 6);
                $baseQtyMilli = isset($item['base_quantity']) && $item['base_quantity'] !== null
                    ? (int) round((float) $item['base_quantity'])
                    : $this->unitService->toMilliUnits($this->unitService->toBaseQuantity($qtyUnits, $convFactorDecimal));
                $baseQtyUnits = $this->unitService->fromMilliUnits($baseQtyMilli);
                $taxableAmount = (int) round(($rawQty * $rate) / 1000);
            } else {
                $qtyUnits = round($rawQty, 6);
                $explicitFactor = $item['purchase_conversion_factor'] ?? $item['conversion_factor'] ?? null;
                if ($explicitFactor !== null && (float) $explicitFactor > 0) {
                    $convFactorDecimal = (float) $explicitFactor;
                } elseif ($matchedOption !== null && (float) ($matchedOption['conversionFactor'] ?? 0) > 0) {
                    $convFactorDecimal = (float) $matchedOption['conversionFactor'];
                } elseif ($unitConfig !== null) {
                    $convFactorDecimal = $purchaseUnit['id'] === $unitConfig['purchaseUnitId']
                        ? (float) $unitConfig['purchaseConversionFactor']
                        : $this->unitService->defaultPairFactor($purchaseUnit['id'], $unitConfig['baseUnitId']);
                } else {
                    $convFactorDecimal = 1.0;
                }
                $baseQtyUnits = $this->unitService->toBaseQuantity($qtyUnits, $convFactorDecimal);
                $baseQtyMilli = $this->unitService->toMilliUnits($baseQtyUnits);
                $taxableAmount = $this->unitService->calculateLineGrossPaise($qtyUnits, $rate);
            }

            if ($unitConfig !== null && $matchedOption === null) {
                $baseUnitMeta = $this->unitService->resolveUnit($unitConfig['baseUnitId'], $businessId);
                $rawExplicit = $item['purchase_conversion_factor'] ?? $item['conversion_factor'] ?? null;
                $compatFactor = $rawExplicit !== null ? (float) $rawExplicit : ($convFactorDecimal ?? null);
                if (! $this->unitService->areUnitsCompatible($purchaseUnit, $baseUnitMeta, $compatFactor)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.unit_id" => "Purchase unit '{$purchaseUnit['symbol']}' is incompatible with inventory unit '{$baseUnitMeta['symbol']}'.",
                    ]);
                }
                if (! $isMilliCaller) {
                    $allowFracPurchase = $purchaseUnit['id'] === $unitConfig['baseUnitId']
                        ? (bool) $unitConfig['allowFractionalQuantity']
                        : (bool) $purchaseUnit['allowFractional'];
                    $this->unitService->validateQuantity($qtyUnits, $allowFracPurchase, $unitSymbol);
                }
            }

            if ($taxableAmount == 0 && $qtyUnits > 0 && $rate > 0) {
                $taxableAmount = $this->unitService->calculateLineGrossPaise($qtyUnits, $rate);
            }

            $taxAmount = (int) round(($taxableAmount * $gstRateBps) / 10000);
            $subtotal += $taxableAmount;
            $totalTax += $taxAmount;
            $effectiveBaseCostPaise = isset($item['effective_base_unit_cost']) && (int) $item['effective_base_unit_cost'] > 0
                ? (int) $item['effective_base_unit_cost']
                : $stockSvcOuter->calculateEffectiveBaseUnitCostPaise($taxableAmount + $taxAmount, $baseQtyUnits);

            $mergedAttrSnapshot = array_merge(
                is_array($pFields['attributeValues'] ?? null) ? $pFields['attributeValues'] : [],
                is_array($vFields['attributeValues'] ?? null) ? $vFields['attributeValues'] : [],
                is_array($item['attribute_values'] ?? $item['attributeValues'] ?? null) ? ($item['attribute_values'] ?? $item['attributeValues']) : []
            );
            $snapSize = $item['aggregate_size'] ?? $item['size'] ?? ($mergedAttrSnapshot['aggregateSize'] ?? $mergedAttrSnapshot['size'] ?? null);
            $snapQuality = $item['grade_quality'] ?? $item['quality'] ?? ($mergedAttrSnapshot['gradeQuality'] ?? $mergedAttrSnapshot['quality'] ?? null);
            if ($snapSize !== null && $snapSize !== '') {
                $mergedAttrSnapshot['aggregateSize'] = $snapSize;
            }
            if ($snapQuality !== null && $snapQuality !== '') {
                $mergedAttrSnapshot['gradeQuality'] = $snapQuality;
            }

            $lineItems[] = [
                'purchaseItemId' => 'LINE_'.Str::random(16)."_{$index}",
                'productId' => $pId,
                'variantId' => $effectiveVariantId,
                'categoryId' => $pFields['categoryId'] ?? $pFields['category_id'] ?? null,
                'categoryName' => $pFields['category'] ?? $pFields['categoryName'] ?? null,
                'inventoryBehaviour' => $invBehaviour,
                'purchaseOptionId' => $purchaseOptionId,
                'purchaseOptionName' => $purchaseOptionName,
                'effectiveBaseUnitCost' => $effectiveBaseCostPaise,
                'productName' => $item['product_name'] ?? ($pFields['productName'] ?? $pFields['name'] ?? 'Product'),
                'variantName' => $item['variant_name'] ?? ($purchaseOptionName ?: ($vFields['variantName'] ?? 'Default')),
                'aggregateSize' => $snapSize,
                'gradeQuality' => $snapQuality,
                'attributeValues' => ! empty($mergedAttrSnapshot) ? $mergedAttrSnapshot : null,
                'customAttributes' => is_array($item['custom_attributes'] ?? null)
                    ? $item['custom_attributes']
                    : (is_array($mergedAttrSnapshot['customAttributes'] ?? null) ? $mergedAttrSnapshot['customAttributes'] : (! empty($mergedAttrSnapshot) ? $mergedAttrSnapshot : null)),
                'quantity' => $qtyUnits,
                'quantityScale' => 'UNIT',
                'quantityMilli' => $this->unitService->toMilliUnits($qtyUnits),
                'baseQuantity' => $baseQtyMilli,
                'baseQuantityUnits' => $baseQtyUnits,
                'conversionFactor' => $convFactorDecimal,
                'conversionFactorMilli' => (int) round($convFactorDecimal * 1000),
                'unitId' => $purchaseUnit['id'],
                'unitSymbol' => $unitSymbol,
                'unitDisplayName' => $purchaseOptionName ?: $purchaseUnit['displayName'],
                'baseUnitId' => $unitConfig['baseUnitId'] ?? $purchaseUnit['id'],
                'baseUnitSymbol' => $unitConfig['baseUnitSymbol'] ?? $unitSymbol,
                'rate' => $rate,
                'gstRateBps' => $gstRateBps,
                'taxableAmount' => $taxableAmount,
                'taxAmount' => $taxAmount,
                'sellingPrice' => (int) ($item['selling_price'] ?? 0),
                'mrp' => (int) ($item['mrp'] ?? 0),
                'barcode' => $item['barcode'] ?? null,
                'barcodeSnapshot' => $item['barcode'] ?? null,
                'batch_number' => $item['batch_number'] ?? null,
                'manufacturing_date' => $item['manufacturing_date'] ?? null,
                'expiry_date' => $item['expiry_date'] ?? null,
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
        $purchaseNumber = 'PUR-'.str_pad((string) $billCount, 6, '0', STR_PAD_LEFT);
        $purchaseBillId = 'PB-'.strtoupper(Str::random(32));
        $supplierBusinessId = "{$supplierId}_{$businessId}";

        $logoSnapshot = BusinessController::resolveActiveLogoSnapshot($businessId);
        $signatureSnapshot = BusinessController::resolveActiveSignatureSnapshot($businessId);

        $billFields = array_merge([
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
        ], $logoSnapshot, $signatureSnapshot);

        DB::transaction(function () use (
            $purchaseBillId,
            $purchaseNumber,
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
            $userId,
            $purchaseDate
        ) {
            // 1. Insert into purchase_bills
            DB::table('purchase_bills')->insert([
                'id' => $purchaseBillId,
                'document_path' => "purchase_bills/{$purchaseBillId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'supplier_id' => $supplierId,
                'status' => $status,
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $billFields]),
            ]);

            // 2. Insert line items
            $stockSvc = new InventoryStockService($this->unitService);
            $hasPbUnitCols = Schema::hasColumn('purchase_bill_items', 'unit_symbol');
            $hasPbOptionCols = Schema::hasColumn('purchase_bill_items', 'purchase_option_id');
            foreach ($lineItems as &$item) {
                $pId = (string) ($item['productId'] ?? $item['product_id'] ?? '');
                $purchaseOptionId = $item['purchaseOptionId'] ?? $item['purchase_option_id'] ?? null;
                $purchaseOptionName = $item['purchaseOptionName'] ?? $item['purchase_option_name'] ?? null;
                if ($purchaseOptionId && $pId !== '') {
                    $opt = $stockSvc->findPurchaseOption($businessId, $pId, (string) $purchaseOptionId);
                    if ($opt) {
                        $purchaseOptionName ??= $opt['optionName'];
                        $item['purchaseOptionName'] = $purchaseOptionName;
                        if (empty($item['conversionFactor']) || (float) $item['conversionFactor'] <= 0) {
                            $item['conversionFactor'] = max(0.0001, (float) ($opt['conversionFactor'] ?? 1.0));
                            $qtyUnitsOpt = (float) ($item['quantity'] ?? 0);
                            $baseUnitsOpt = $this->unitService->toBaseQuantity($qtyUnitsOpt, (float) $item['conversionFactor']);
                            $item['baseQuantityUnits'] = $baseUnitsOpt;
                            $item['baseQuantity'] = $this->unitService->toMilliUnits($baseUnitsOpt);
                        }
                    }
                }
                $qtyUnits = (float) ($item['quantity'] ?? 0);
                $baseUnitsForCost = (float) ($item['baseQuantityUnits'] ?? $this->unitService->fromMilliUnits((int) ($item['baseQuantity'] ?? 0)));
                $rate = (int) ($item['rate'] ?? 0);
                $lineCost = (int) ($item['taxableAmount'] ?? 0) + (int) ($item['taxAmount'] ?? 0);
                if ($lineCost <= 0 && $qtyUnits > 0 && $rate > 0) {
                    $lineCost = $this->unitService->calculateLineGrossPaise($qtyUnits, $rate);
                }
                $effectiveBaseCostPaise = (int) ($item['effectiveBaseUnitCost'] ?? $item['effective_base_unit_cost'] ?? 0);
                if ($effectiveBaseCostPaise <= 0 && $baseUnitsForCost > 0 && $lineCost > 0) {
                    $effectiveBaseCostPaise = $stockSvc->calculateEffectiveBaseUnitCostPaise($lineCost, $baseUnitsForCost);
                }
                $item['purchaseOptionId'] = $purchaseOptionId;
                $item['purchaseOptionName'] = $purchaseOptionName;
                $item['effectiveBaseUnitCost'] = $effectiveBaseCostPaise;

                $itemId = $item['purchaseItemId'];
                $pbItemRow = [
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
                ];
                if (Schema::hasColumn('purchase_bill_items', 'quantity')) {
                    $pbItemRow['quantity'] = $item['quantity'] ?? 0;
                }
                if ($hasPbUnitCols) {
                    $pbItemRow['unit_id'] = $item['unitId'] ?? null;
                    $pbItemRow['unit_symbol'] = $item['unitSymbol'] ?? null;
                    $pbItemRow['unit_display_name'] = $item['unitDisplayName'] ?? null;
                    $pbItemRow['conversion_factor'] = $item['conversionFactor'] ?? 1.0;
                    $pbItemRow['base_quantity'] = $item['baseQuantityUnits'] ?? ($item['quantity'] ?? 0);
                }
                if ($hasPbOptionCols) {
                    $pbItemRow['purchase_option_id'] = $purchaseOptionId;
                    $pbItemRow['purchase_option_name'] = $purchaseOptionName;
                    $pbItemRow['effective_base_unit_cost'] = $effectiveBaseCostPaise;
                }
                DB::table('purchase_bill_items')->insert($pbItemRow);
            }
            unset($item);

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
                    $baseQuantity = (int) ($item['baseQuantity'] ?? $item['base_quantity'] ?? 0);
                    $qtyUnits = (float) ($item['quantity'] ?? 0);
                    $baseQtyUnits = (float) ($item['baseQuantityUnits'] ?? $this->unitService->fromMilliUnits($baseQuantity));
                    $unitSymbol = (string) ($item['unitSymbol'] ?? 'PCS');
                    $baseUnitSymbol = (string) ($item['baseUnitSymbol'] ?? $unitSymbol);
                    $rate = (int) ($item['rate'] ?? 0);
                    $lineCost = (int) ($item['taxableAmount'] ?? 0) + (int) ($item['taxAmount'] ?? 0);
                    if ($lineCost <= 0 && $baseQuantity > 0 && $rate > 0) {
                        $lineCost = $this->unitService->calculateLineGrossPaise($qtyUnits > 0 ? $qtyUnits : $baseQtyUnits, $rate);
                    }
                    if ((! $vId && ! $pId) || $baseQuantity <= 0) {
                        continue;
                    }

                    $purchaseOptionId = $item['purchaseOptionId'] ?? $item['purchase_option_id'] ?? null;
                    $purchaseOptionName = $item['purchaseOptionName'] ?? $item['purchase_option_name'] ?? null;
                    $effectiveBaseCostPaise = (int) ($item['effectiveBaseUnitCost'] ?? 0);
                    if ($effectiveBaseCostPaise <= 0 && $baseQtyUnits > 0 && $lineCost > 0) {
                        $effectiveBaseCostPaise = $stockSvc->calculateEffectiveBaseUnitCostPaise($lineCost, $baseQtyUnits);
                    }
                    $effectiveRateForBalance = $effectiveBaseCostPaise > 0 ? $effectiveBaseCostPaise : $rate;

                    $stockResult = $this->upsertStockBalanceOnPurchase(
                        $businessId,
                        $outletId,
                        $userId,
                        $pId,
                        $vId,
                        $baseQuantity,
                        $lineCost,
                        $effectiveRateForBalance,
                        $item['baseUnitId'] ?? null,
                        $now,
                        $dbNow
                    );
                    $newStock = $stockResult['newStockMilli'];
                    $invBehaviour = $stockResult['behaviour'] ?? 'SEPARATE_VARIANT';
                    $balUnits = $this->unitService->fromMilliUnits($newStock);
                    $movementId = 'STM-'.strtoupper(Str::random(24));
                    $optNote = $purchaseOptionName ? " via {$purchaseOptionName}" : '';
                    $movementInsert = [
                        'id' => $movementId,
                        'document_path' => "stock_movements/{$movementId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'product_id' => $pId ?: $vId,
                        'variant_id' => $stockResult['targetVariantId'],
                        'type' => 'purchase',
                        'qty_change' => $baseQtyUnits,
                        'balance_after' => $balUnits,
                        'reference_type' => 'purchase_bill',
                        'reference_id' => $purchaseBillId,
                        'condition' => 'good',
                        'note' => "Purchase {$purchaseNumber}{$optNote} inwarded ({$qtyUnits} {$unitSymbol} -> {$baseQtyUnits} {$baseUnitSymbol})",
                        'created_by' => $userId,
                        'created_at' => $dbNow,
                        'updated_at' => $dbNow,
                        'payload' => json_encode([
                            'fields' => [
                                'movementId' => $movementId,
                                'businessId' => $businessId,
                                'outletId' => $outletId,
                                'productId' => $pId ?: $vId,
                                'variantId' => $stockResult['targetVariantId'],
                                'originalVariantId' => $vId ?: null,
                                'inventoryBehaviour' => $invBehaviour,
                                'purchaseOptionId' => $purchaseOptionId,
                                'purchaseOptionName' => $purchaseOptionName,
                                'effectiveBaseUnitCost' => $effectiveBaseCostPaise,
                                'type' => 'PURCHASE',
                                'qtyBase' => $baseQuantity,
                                'quantity' => $baseQtyUnits,
                                'purchaseQuantity' => $qtyUnits,
                                'unit' => $baseUnitSymbol,
                                'purchaseUnit' => $unitSymbol,
                                'conversionFactor' => $item['conversionFactor'] ?? 1.0,
                                'balanceAfter' => $newStock,
                                'balanceAfterUnits' => $balUnits,
                                'reason' => "Purchase {$purchaseNumber}{$optNote}",
                                'referenceId' => $purchaseBillId,
                                'userId' => $userId,
                                'createdAt' => $now,
                                'immutable' => true,
                            ],
                        ]),
                    ];
                    if (Schema::hasColumn('stock_movements', 'purchase_option_id')) {
                        $movementInsert['purchase_option_id'] = $purchaseOptionId;
                        $movementInsert['purchase_option_name'] = $purchaseOptionName;
                        $movementInsert['effective_base_unit_cost'] = $effectiveBaseCostPaise;
                        $movementInsert['inventory_behaviour'] = $invBehaviour;
                    }
                    DB::table('stock_movements')->insert($movementInsert);

                    // Create Inventory Batch for new purchase line in base inventory units with effective base cost
                    $batchNumber = ! empty($item['batch_number']) ? trim((string) $item['batch_number']) : ('BATCH-'.strtoupper(Str::random(8)));
                    $autoBatchId = 'BATCH-'.strtoupper(Str::random(24));
                    $batchMfgDate = ! empty($item['manufacturing_date']) ? Carbon::parse($item['manufacturing_date'])->toDateString() : null;
                    $batchExpiryDate = ! empty($item['expiry_date']) ? Carbon::parse($item['expiry_date'])->toDateString() : null;
                    $purDateStr = Carbon::parse($purchaseDate ?: $now)->toDateString();

                    $batchCostRupees = $effectiveRateForBalance >= 100 || $effectiveBaseCostPaise > 0
                        ? round($effectiveRateForBalance / 100.0, 4)
                        : $effectiveRateForBalance;

                    $pcBatchData = [
                        'id' => $autoBatchId,
                        'document_path' => "inventory_batches/{$autoBatchId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'product_id' => $pId ?: $vId,
                        'status' => 'ACTIVE',
                        'created_at' => $dbNow,
                        'updated_at' => $dbNow,
                        'is_deleted' => 0,
                        'payload' => json_encode(['fields' => [
                            'batchId' => $autoBatchId,
                            'batchNumber' => $batchNumber,
                            'productId' => $pId ?: $vId,
                            'variantId' => $stockResult['targetVariantId'],
                            'inventoryBehaviour' => $invBehaviour,
                            'purchaseBillId' => $purchaseBillId,
                            'purchaseOptionId' => $purchaseOptionId,
                            'purchaseOptionName' => $purchaseOptionName,
                            'originalInvoiceNumber' => $purchaseNumber,
                            'originalQuantity' => $baseQtyUnits,
                            'availableQuantity' => $baseQtyUnits,
                            'soldQuantity' => 0,
                            'unitId' => $item['baseUnitId'] ?? null,
                            'unitSymbol' => $baseUnitSymbol,
                            'purchaseDate' => $purDateStr,
                            'purchaseRate' => $effectiveRateForBalance,
                            'effectiveBaseUnitCost' => $effectiveBaseCostPaise,
                            'mfgDate' => $batchMfgDate,
                            'expiryDate' => $batchExpiryDate,
                            'isHistorical' => false,
                            'status' => 'ACTIVE',
                        ]]),
                    ];
                    if (Schema::hasColumn('inventory_batches', 'variant_id')) {
                        $pcBatchData['variant_id'] = $stockResult['targetVariantId'];
                    }
                    if (Schema::hasColumn('inventory_batches', 'batch_number')) {
                        $pcBatchData['batch_number'] = $batchNumber;
                    }
                    if (Schema::hasColumn('inventory_batches', 'mfg_date')) {
                        $pcBatchData['mfg_date'] = $batchMfgDate;
                    }
                    if (Schema::hasColumn('inventory_batches', 'expiry_date')) {
                        $pcBatchData['expiry_date'] = $batchExpiryDate;
                    }
                    if (Schema::hasColumn('inventory_batches', 'purchase_rate')) {
                        $pcBatchData['purchase_rate'] = $batchCostRupees;
                    }
                    if (Schema::hasColumn('inventory_batches', 'quantity_initial')) {
                        $pcBatchData['quantity_initial'] = $baseQtyUnits;
                    }
                    if (Schema::hasColumn('inventory_batches', 'quantity_available')) {
                        $pcBatchData['quantity_available'] = $baseQtyUnits;
                    }
                    if (Schema::hasColumn('inventory_batches', 'is_historical')) {
                        $pcBatchData['is_historical'] = 0;
                    }
                    if (Schema::hasColumn('inventory_batches', 'purchase_bill_id')) {
                        $pcBatchData['purchase_bill_id'] = $purchaseBillId;
                    }
                    if (Schema::hasColumn('inventory_batches', 'purchase_date')) {
                        $pcBatchData['purchase_date'] = $purDateStr;
                    }

                    DB::table('inventory_batches')->insert($pcBatchData);

                    $ibmId = 'IBM-'.strtoupper(Str::random(24));
                    $pcIbmData = [
                        'id' => $ibmId,
                        'document_path' => "inventory_batch_movements/{$ibmId}",
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'product_id' => $pId ?: $vId,
                        'status' => 'ACTIVE',
                        'created_at' => $dbNow,
                        'updated_at' => $dbNow,
                        'is_deleted' => 0,
                        'payload' => json_encode(['fields' => [
                            'movementId' => $ibmId,
                            'batchId' => $autoBatchId,
                            'referenceType' => 'purchase_bill',
                            'referenceId' => $purchaseBillId,
                            'movementType' => 'PURCHASE_INWARD',
                            'quantityChanged' => $baseQtyUnits,
                            'quantityAfter' => $baseQtyUnits,
                        ]]),
                    ];
                    if (Schema::hasColumn('inventory_batch_movements', 'batch_id')) {
                        $pcIbmData['batch_id'] = $autoBatchId;
                    }
                    if (Schema::hasColumn('inventory_batch_movements', 'reference_type')) {
                        $pcIbmData['reference_type'] = 'purchase_bill';
                    }
                    if (Schema::hasColumn('inventory_batch_movements', 'reference_id')) {
                        $pcIbmData['reference_id'] = $purchaseBillId;
                    }
                    if (Schema::hasColumn('inventory_batch_movements', 'movement_type')) {
                        $pcIbmData['movement_type'] = 'PURCHASE_INWARD';
                    }
                    if (Schema::hasColumn('inventory_batch_movements', 'quantity_changed')) {
                        $pcIbmData['quantity_changed'] = $baseQtyUnits;
                    }
                    if (Schema::hasColumn('inventory_batch_movements', 'quantity_after')) {
                        $pcIbmData['quantity_after'] = $baseQtyUnits;
                    }

                    DB::table('inventory_batch_movements')->insert($pcIbmData);

                    // Auto-reflect purchase selling price, MRP, and effective base rate without overwriting base rates with load/tonne prices
                    $isUnified = $stockSvc->isUnifiedBehaviour($invBehaviour);
                    $convFactor = (float) ($item['conversionFactor'] ?? 1.0);
                    $rateToReflect = ($isUnified || $convFactor !== 1.0) && $effectiveBaseCostPaise > 0
                        ? $effectiveBaseCostPaise
                        : $rate;

                    if (! empty($vId)) {
                        $vRow = DB::table('product_variants')
                            ->where('id', $vId)
                            ->orWhere('payload->fields->variantId', $vId)
                            ->first();
                        if ($vRow) {
                            $vPayload = json_decode($vRow->payload, true) ?: [];
                            $vFields = $vPayload['fields'] ?? [];
                            if (! empty($item['sellingPrice']) && (int) $item['sellingPrice'] > 0 && ! $isUnified && $convFactor == 1.0) {
                                $vFields['sellingPrice'] = (int) $item['sellingPrice'];
                            }
                            if (! empty($item['mrp']) && (int) $item['mrp'] > 0 && ! $isUnified && $convFactor == 1.0) {
                                $vFields['mrp'] = (int) $item['mrp'];
                            }
                            if ($rateToReflect > 0) {
                                $vFields['purchaseRate'] = $rateToReflect;
                                $vFields['purchasePricePerUnit'] = $rateToReflect;
                            }
                            if (! empty($item['barcode']) && ! $isUnified) {
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

                    if (! empty($pId)) {
                        $pRow = DB::table('products')
                            ->where('id', $pId)
                            ->orWhere('payload->fields->productId', $pId)
                            ->first();
                        if ($pRow) {
                            $pPayload = json_decode($pRow->payload, true) ?: [];
                            $pFields = $pPayload['fields'] ?? [];
                            if (! empty($item['sellingPrice']) && (int) $item['sellingPrice'] > 0 && ! $isUnified && $convFactor == 1.0) {
                                $pFields['sellingPrice'] = (int) $item['sellingPrice'];
                            }
                            if (! empty($item['mrp']) && (int) $item['mrp'] > 0 && ! $isUnified && $convFactor == 1.0) {
                                $pFields['mrp'] = (int) $item['mrp'];
                            }
                            if ($rateToReflect > 0) {
                                $pFields['purchaseRate'] = $rateToReflect;
                                $pFields['purchasePricePerUnit'] = $rateToReflect;
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

        try {
            $billNo = (string) ($billFields['billNumber'] ?? $billId);
            $supName = (string) ($billFields['supplierName'] ?? 'Supplier');
            $totRupees = number_format(((int) ($billFields['totalAmount'] ?? $billFields['totalPaise'] ?? 0)) / 100, 2);
            $staffName = $user ? ($user->name ?: $user->email) : 'Staff';

            SendFcmNotificationJob::dispatch(
                null,
                null,
                'Purchase Inward Recorded',
                "Purchase bill {$billNo} from {$supName} (INR {$totRupees}) recorded by {$staffName}.",
                [
                    'target_screen' => 'purchase',
                    'type' => 'purchase_created',
                    'purchase_id' => (string) $billId,
                    'bill_number' => $billNo,
                    'business_id' => (string) $businessId,
                    'total_amount' => $totRupees,
                ],
                (string) $businessId
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to dispatch purchase notification: '.$e->getMessage());
        }

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

        $requestedBusinessId = $request->input('business_id') ?? $request->query('business_id') ?? $request->header('X-Business-Id');
        $requestedOutletId = $request->input('outlet_id') ?? $request->query('outlet_id') ?? $request->header('X-Outlet-Id');
        if (! $row || ($requestedBusinessId && (string) $row->business_id !== (string) $requestedBusinessId) || ($requestedOutletId && (string) $row->outlet_id !== (string) $requestedOutletId)) {
            return ApiResponse::error('Purchase bill not found.', 404);
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
            return ApiResponse::error('Unauthorized to approve this purchase bill.', 403, null, 'FORBIDDEN');
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
            DB::table('supplier_ledger_entries')->updateOrInsert(
                ['id' => $ledgerBillId],
                [
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
                ]
            );

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
            $stockSvc = new InventoryStockService($this->unitService);
            foreach ($lineItems as $item) {
                $vId = $item['variantId'] ?? $item['variant_id'] ?? '';
                $pId = $item['productId'] ?? $item['product_id'] ?? '';
                $purchaseOptionId = $item['purchaseOptionId'] ?? $item['purchase_option_id'] ?? null;
                $purchaseOptionName = $item['purchaseOptionName'] ?? $item['purchase_option_name'] ?? null;
                $convFactor = (float) ($item['conversionFactor'] ?? $item['conversion_factor'] ?? 1.0);
                $optMatchedFactor = null;
                if ($purchaseOptionId && $pId !== '') {
                    $opt = $stockSvc->findPurchaseOption($businessId, $pId, (string) $purchaseOptionId);
                    if ($opt) {
                        $purchaseOptionName ??= $opt['optionName'];
                        $optMatchedFactor = max(0.0001, (float) ($opt['conversionFactor'] ?? 1.0));
                        if (! isset($item['conversionFactor']) && ! isset($item['conversion_factor'])) {
                            $convFactor = $optMatchedFactor;
                        }
                    }
                }
                if ($convFactor <= 0) {
                    $convFactor = 1.0;
                }

                $baseQuantity = (int) ($item['baseQuantity'] ?? $item['base_quantity'] ?? 0);
                if ($baseQuantity <= 0 && isset($item['baseQuantityUnits'])) {
                    $baseQuantity = $this->unitService->toMilliUnits((float) $item['baseQuantityUnits']);
                }
                if ($baseQuantity <= 0 && isset($item['quantity'])) {
                    $itemScale = strtoupper((string) ($item['quantityScale'] ?? $item['quantity_scale'] ?? ''));
                    if ($itemScale === 'MILLI') {
                        $qtyUnits = ((float) $item['quantity']) / 1000.0;
                        $factorDecimal = $convFactor >= 1000 ? ($convFactor / 1000.0) : $convFactor;
                        $baseQuantity = $this->unitService->toMilliUnits($this->unitService->toBaseQuantity($qtyUnits, $factorDecimal));
                    } elseif ($optMatchedFactor !== null && $convFactor >= 1000 && abs(($convFactor / 1000.0) - $optMatchedFactor) < 0.01) {
                        $qtyUnits = (float) $item['quantity'] >= 1000 ? ((float) $item['quantity'] / 1000.0) : (float) $item['quantity'];
                        $baseQuantity = $this->unitService->toMilliUnits($this->unitService->toBaseQuantity($qtyUnits, $optMatchedFactor));
                    } else {
                        $baseQuantity = $this->unitService->toMilliUnits($this->unitService->toBaseQuantity((float) $item['quantity'], $convFactor));
                    }
                }
                $rate = (int) ($item['rate'] ?? 0);
                $lineCost = (int) ($item['taxableAmount'] ?? 0) + (int) ($item['taxAmount'] ?? 0);
                if ($lineCost <= 0 && $baseQuantity > 0 && $rate > 0) {
                    $qtyUnitsForCost = (float) ($item['quantity'] ?? $this->unitService->fromMilliUnits($baseQuantity));
                    $lineCost = $this->unitService->calculateLineGrossPaise($qtyUnitsForCost, $rate);
                }
                $baseQtyUnits = $this->unitService->fromMilliUnits($baseQuantity);
                $effectiveBaseCostPaise = (int) ($item['effectiveBaseUnitCost'] ?? $item['effective_base_unit_cost'] ?? 0);
                if ($effectiveBaseCostPaise <= 0 && $baseQtyUnits > 0 && $lineCost > 0) {
                    $effectiveBaseCostPaise = $stockSvc->calculateEffectiveBaseUnitCostPaise($lineCost, $baseQtyUnits);
                }

                if ((! $vId && ! $pId) || $baseQuantity <= 0) {
                    continue;
                }

                $stockResult = $this->upsertStockBalanceOnPurchase(
                    $businessId,
                    $outletId,
                    $userId,
                    $pId,
                    $vId,
                    $baseQuantity,
                    $lineCost,
                    $effectiveBaseCostPaise > 0 ? $effectiveBaseCostPaise : $rate,
                    $item['baseUnitId'] ?? null,
                    $now,
                    $dbNow
                );
                $meta = $pId !== '' ? $stockSvc->resolveProductMeta($businessId, $pId) : ['behaviour' => 'SEPARATE_VARIANT'];
                $isUnified = $stockSvc->isUnifiedBehaviour($meta['behaviour']);
                $rateToReflect = ($isUnified || $convFactor !== 1.0) && $effectiveBaseCostPaise > 0
                    ? $effectiveBaseCostPaise
                    : $rate;

                // Auto-reflect purchase selling price, MRP, and effective base rate without overwriting with load prices
                if (! empty($vId)) {
                    $vRow = DB::table('product_variants')
                        ->where('id', $vId)
                        ->orWhere('payload->fields->variantId', $vId)
                        ->first();
                    if ($vRow) {
                        $vPayload = json_decode($vRow->payload, true) ?: [];
                        $vFields = $vPayload['fields'] ?? [];
                        if (! empty($item['sellingPrice']) && (int) $item['sellingPrice'] > 0 && ! $isUnified && $convFactor == 1.0) {
                            $vFields['sellingPrice'] = (int) $item['sellingPrice'];
                        }
                        if (! empty($item['mrp']) && (int) $item['mrp'] > 0 && ! $isUnified && $convFactor == 1.0) {
                            $vFields['mrp'] = (int) $item['mrp'];
                        }
                        if ($rateToReflect > 0) {
                            $vFields['purchaseRate'] = $rateToReflect;
                            $vFields['purchasePricePerUnit'] = $rateToReflect;
                        }
                        if (! empty($item['barcode']) && ! $isUnified) {
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

                if (! empty($pId)) {
                    $pRow = DB::table('products')
                        ->where('id', $pId)
                        ->orWhere('payload->fields->productId', $pId)
                        ->first();
                    if ($pRow) {
                        $pPayload = json_decode($pRow->payload, true) ?: [];
                        $pFields = $pPayload['fields'] ?? [];
                        if (! empty($item['sellingPrice']) && (int) $item['sellingPrice'] > 0 && ! $isUnified && $convFactor == 1.0) {
                            $pFields['sellingPrice'] = (int) $item['sellingPrice'];
                        }
                        if (! empty($item['mrp']) && (int) $item['mrp'] > 0 && ! $isUnified && $convFactor == 1.0) {
                            $pFields['mrp'] = (int) $item['mrp'];
                        }
                        if ($rateToReflect > 0) {
                            $pFields['purchaseRate'] = $rateToReflect;
                            $pFields['purchasePricePerUnit'] = $rateToReflect;
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

        try {
            $billNo = (string) ($fields['billNumber'] ?? $id);
            $supName = (string) ($fields['supplierName'] ?? 'Supplier');
            $totRupees = number_format(((int) ($fields['totalAmount'] ?? $fields['totalPaise'] ?? 0)) / 100, 2);
            $staffName = $user ? ($user->name ?: $user->email) : 'Staff';
            $pBusinessId = (string) ($fields['businessId'] ?? $row->business_id);

            SendFcmNotificationJob::dispatch(
                null,
                null,
                'Purchase Bill Approved',
                "Purchase bill {$billNo} from {$supName} (INR {$totRupees}) approved by {$staffName}.",
                [
                    'target_screen' => 'purchase',
                    'type' => 'purchase_approved',
                    'purchase_id' => (string) $id,
                    'bill_number' => $billNo,
                    'business_id' => $pBusinessId,
                    'total_amount' => $totRupees,
                ],
                $pBusinessId
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to dispatch purchase notification: '.$e->getMessage());
        }

        return ApiResponse::success([
            'purchaseBill' => $fields,
        ], 'Purchase bill approved successfully.');
    }

    /**
     * Upsert stock balance in base milli-units without ambiguous <500 heuristics.
     *
    /**
     * @return array{targetVariantId: string, newStockMilli: int, behaviour: string}
     */
    private function upsertStockBalanceOnPurchase(
        string $businessId,
        string $outletId,
        string $userId,
        ?string $productId,
        string $variantId,
        int $baseQuantityMilli,
        int $lineCost,
        int $rate,
        ?string $baseUnitId,
        string $now,
        $dbNow
    ): array {
        $stockSvc = new InventoryStockService($this->unitService);
        $meta = ! empty($productId)
            ? $stockSvc->resolveProductMeta($businessId, $productId)
            : [
                'behaviour' => InventoryStockService::BEHAVIOUR_SEPARATE_VARIANT,
                'baseUnitId' => $baseUnitId ?? 'UNIT',
            ];
        $behaviour = $meta['behaviour'];
        $target = $stockSvc->inspectStockTarget($businessId, $outletId, (string) $productId, $variantId, $behaviour);
        $targetVariantId = $target['canonicalVariantId'] ?: $variantId;
        $stockRow = $target['canonicalRow'];
        $resolvedBaseUnitId = $baseUnitId ?: $meta['baseUnitId'];
        $stockBalId = $stockRow ? (string) $stockRow->id : "{$userId}_{$outletId}_{$targetVariantId}";

        if ($stockRow) {
            $currStock = $target['totalMilli'];
            $newStock = $currStock + $baseQuantityMilli;
            $oldUnits = $this->unitService->fromMilliUnits($currStock);
            $addedUnits = $this->unitService->fromMilliUnits($baseQuantityMilli);
            $oldAvgPaise = $target['weightedAvgCostPaise'] > 0 ? $target['weightedAvgCostPaise'] : $rate;
            $avgPaise = $stockSvc->calculateWeightedAverageCostPaise($oldUnits, $oldAvgPaise, $addedUnits, $lineCost);

            $sbPayload = json_decode($stockRow->payload ?? '{}', true) ?: [];
            $sbFields = $sbPayload['fields'] ?? [];
            $oldVal = (int) ($sbFields['totalValue'] ?? round($oldUnits * $oldAvgPaise));
            $newVal = $oldVal + $lineCost;

            $sbFields['productId'] = $productId ?: ($sbFields['productId'] ?? null);
            $sbFields['variantId'] = $targetVariantId;
            $sbFields['inventoryBehaviour'] = $behaviour;
            $sbFields['quantityOnHand'] = $newStock;
            $sbFields['currentStock'] = $newStock;
            $sbFields['stockScale'] = 'MILLI';
            if ($resolvedBaseUnitId) {
                $sbFields['baseUnitId'] = $resolvedBaseUnitId;
            }
            $sbFields['totalValue'] = $newVal;
            $sbFields['averageCost'] = $avgPaise;
            $sbFields['avgCostPaise'] = $avgPaise;
            $sbFields['costPrice'] = $avgPaise;
            $sbFields['weightedAvgCost'] = $avgPaise;
            $sbFields['lastPurchasePricePaise'] = $rate;
            $sbFields['updatedAt'] = $now;
            $sbPayload['fields'] = $sbFields;

            $upd = [
                'updated_at' => $dbNow,
                'payload' => json_encode($sbPayload),
            ];
            if (Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
                $upd['quantity_on_hand'] = $this->unitService->fromMilliUnits($newStock);
            }
            if (Schema::hasColumn('stock_balances', 'product_id') && $productId) {
                $upd['product_id'] = $productId;
            }
            if (Schema::hasColumn('stock_balances', 'variant_id')) {
                $upd['variant_id'] = $targetVariantId;
            }
            if (Schema::hasColumn('stock_balances', 'inventory_behaviour')) {
                $upd['inventory_behaviour'] = $behaviour;
            }
            DB::table('stock_balances')->where('id', $stockRow->id)->update($upd);

            if ($stockSvc->isUnifiedBehaviour($behaviour)) {
                foreach ($target['allRows'] as $extraRow) {
                    if ((string) $extraRow->id === (string) $stockRow->id) {
                        continue;
                    }
                    $ep = json_decode($extraRow->payload ?? '{}', true) ?: [];
                    $ef = $ep['fields'] ?? [];
                    $ef['quantityOnHand'] = 0;
                    $ef['currentStock'] = 0;
                    $ef['consolidatedInto'] = $stockRow->id;
                    $ef['updatedAt'] = $now;
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

            return ['targetVariantId' => $targetVariantId, 'newStockMilli' => $newStock, 'behaviour' => $behaviour];
        }

        $baseQtyUnits = $this->unitService->fromMilliUnits($baseQuantityMilli);
        $avgPaise = $baseQtyUnits > 0 ? $stockSvc->calculateEffectiveBaseUnitCostPaise($lineCost, $baseQtyUnits) : $rate;
        $sbFields = [
            'stockBalanceId' => $stockBalId,
            'tenantId' => $userId,
            'businessId' => $businessId,
            'outletId' => $outletId,
            'variantId' => $targetVariantId,
            'productId' => $productId,
            'inventoryBehaviour' => $behaviour,
            'quantityOnHand' => $baseQuantityMilli,
            'currentStock' => $baseQuantityMilli,
            'stockScale' => 'MILLI',
            'baseUnitId' => $resolvedBaseUnitId,
            'totalValue' => $lineCost,
            'averageCost' => $avgPaise,
            'avgCostPaise' => $avgPaise,
            'costPrice' => $avgPaise,
            'weightedAvgCost' => $avgPaise,
            'lastPurchasePricePaise' => $rate,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
        $ins = [
            'id' => $stockBalId,
            'document_path' => "stock_balances/{$stockBalId}",
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'status' => 'ACTIVE',
            'created_at' => $dbNow,
            'updated_at' => $dbNow,
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => $sbFields]),
        ];
        if (Schema::hasColumn('stock_balances', 'quantity_on_hand')) {
            $ins['quantity_on_hand'] = $this->unitService->fromMilliUnits($baseQuantityMilli);
        }
        if (Schema::hasColumn('stock_balances', 'product_id')) {
            $ins['product_id'] = $productId;
        }
        if (Schema::hasColumn('stock_balances', 'variant_id')) {
            $ins['variant_id'] = $targetVariantId;
        }
        if (Schema::hasColumn('stock_balances', 'inventory_behaviour')) {
            $ins['inventory_behaviour'] = $behaviour;
        }
        DB::table('stock_balances')->insert($ins);

        return ['targetVariantId' => $targetVariantId, 'newStockMilli' => $baseQuantityMilli, 'behaviour' => $behaviour];
    }
}
