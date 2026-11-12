<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReturnRequest;
use App\Http\Resources\ReturnResource;
use App\Http\Responses\ApiResponse;
use App\Models\ReturnModel;
use App\Services\ReturnService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ReturnController extends Controller
{
    public function __construct(
        protected ReturnService $returnService
    ) {}

    /**
     * Create a new sales or purchase return.
     */
    public function store(CreateReturnRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $businessId = $request->input('business_id') ?? $request->header('X-Business-Id') ?? $user->defaultBusinessId();
        if ($businessId && ! $user->canAccessBusiness($businessId)) {
            return ApiResponse::error('Unauthorized access to specified business.', 403, null, 'FORBIDDEN');
        }

        $validated['business_id'] = $businessId;
        $outletId = $request->input('outlet_id') ?? $request->input('outletId') ?? $request->header('X-Outlet-Id');
        if (strtoupper((string) $user->role) === 'STAFF' && empty($outletId)) {
            $outletId = app(\App\Services\EmployeePermissionService::class)->defaultOutletFor($user, $businessId);
        }
        $validated['outlet_id'] = $outletId;

        $return = $this->returnService->createReturn($validated, $user);

        return ApiResponse::success(
            new ReturnResource($return),
            'Return created successfully.',
            201
        );
    }

    /**
     * List returns with filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $businessId = $request->query('business_id') ?? $request->header('X-Business-Id') ?? $user->defaultBusinessId();

        if ($businessId && ! $user->canAccessBusiness($businessId)) {
            return ApiResponse::error('Unauthorized access to specified business.', 403, null, 'FORBIDDEN');
        }

        $query = ReturnModel::with(['items.product', 'reason']);

        if (! $user->isAdmin()) {
            $accessible = $user->accessibleBusinessIds();
            if ($businessId) {
                $query->where('business_id', $businessId);
            } elseif (! empty($accessible)) {
                $query->whereIn('business_id', $accessible);
            } else {
                $query->where('user_id', $user->id);
            }
        } elseif ($businessId) {
            $query->where('business_id', $businessId);
        }

        // Filters
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($partyId = $request->query('party_id')) {
            $query->where('party_id', $partyId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($startDate = $request->query('start_date')) {
            $query->where('return_date', '>=', $startDate);
        }

        if ($endDate = $request->query('end_date')) {
            $query->where('return_date', '<=', $endDate);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('return_no', 'like', "%{$search}%")
                    ->orWhere('original_bill_no', 'like', "%{$search}%")
                    ->orWhere('party_name', 'like', "%{$search}%")
                    ->orWhere('party_phone', 'like', "%{$search}%");
            });
        }

        if ($productId = $request->query('product_id')) {
            $query->whereHas('items', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $returns = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return ApiResponse::success([
            'items' => ReturnResource::collection($returns->items()),
            'pagination' => [
                'current_page' => $returns->currentPage(),
                'last_page' => $returns->lastPage(),
                'per_page' => $returns->perPage(),
                'total' => $returns->total(),
            ],
        ], 'Returns retrieved successfully.');
    }

    /**
     * Get single return details.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $return = ReturnModel::with(['items.product', 'reason', 'stockMovements', 'ledgerEntries', 'audits'])
            ->where('id', $id)
            ->first();

        if (! $return) {
            return ApiResponse::error('Return not found.', 404, null, 'NOT_FOUND');
        }

        if (! $user->isAdmin() && ! $user->canAccessBusiness($return->business_id) && $return->user_id !== $user->id) {
            return ApiResponse::error('Unauthorized access to this return.', 403, null, 'FORBIDDEN');
        }

        return ApiResponse::success(
            new ReturnResource($return),
            'Return details retrieved successfully.'
        );
    }

    /**
     * Update return reason and note.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $return = ReturnModel::where('id', $id)->first();
        if (! $return) {
            return ApiResponse::error('Return not found.', 404, null, 'NOT_FOUND');
        }

        if (! $user->isAdmin() && ! $user->canAccessBusiness($return->business_id) && $return->user_id !== $user->id) {
            return ApiResponse::error('Unauthorized to edit this return.', 403, null, 'FORBIDDEN');
        }

        if ($return->isCancelled()) {
            return ApiResponse::error('Cannot edit a cancelled return.', 422, null, 'INVALID_STATE');
        }

        $validated = $request->validate([
            'reason_id' => 'nullable|string|max:100',
            'reason_note' => 'nullable|string',
        ]);

        $return->update($validated);

        return ApiResponse::success(
            new ReturnResource($return->load(['items.product', 'reason', 'stockMovements', 'ledgerEntries'])),
            'Return updated successfully.'
        );
    }

    /**
     * Cancel an existing return and reverse stock & ledger effects.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $return = ReturnModel::where('id', $id)->first();
        if (! $return) {
            return ApiResponse::error('Return not found.', 404, null, 'NOT_FOUND');
        }

        if (! $user->isAdmin() && ! $user->canAccessBusiness($return->business_id) && $return->user_id !== $user->id) {
            return ApiResponse::error('Unauthorized to cancel this return.', 403, null, 'FORBIDDEN');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $cancelledReturn = $this->returnService->cancelReturn($id, $validated['reason'], $user);

        return ApiResponse::success(
            new ReturnResource($cancelledReturn),
            'Return cancelled and reversed successfully.'
        );
    }

    /**
     * Generate / stream GST Credit or Debit Note PDF.
     */
    public function pdf(Request $request, string $id): Response|JsonResponse
    {
        $user = $request->user();

        $return = ReturnModel::with(['items', 'reason'])
            ->where('id', $id)
            ->first();

        if (! $return) {
            return ApiResponse::error('Return not found.', 404, null, 'NOT_FOUND');
        }

        if (! $user->isAdmin() && ! $user->canAccessBusiness($return->business_id) && $return->user_id !== $user->id) {
            return ApiResponse::error('Unauthorized to view this return PDF.', 403, null, 'FORBIDDEN');
        }

        $businessName = 'NubiBilling Store';
        $businessAddress = null;
        $businessPhone = null;
        $businessGstin = null;
        $busFields = [];
        if ($return->business_id && DB::getSchemaBuilder()->hasTable('businesses')) {
            $busRow = DB::table('businesses')->where('id', $return->business_id)->first();
            if ($busRow) {
                if (! empty($busRow->payload)) {
                    $payload = json_decode($busRow->payload, true) ?: [];
                    $rawFields = $payload['fields'] ?? [];
                    $busFields = is_array($rawFields) ? SyncController::decodeFields($rawFields) : [];
                    $businessName = $busFields['businessName'] ?? $busFields['name'] ?? $businessName;
                    $businessAddress = $busFields['address'] ?? null;
                    $businessPhone = $busFields['phone'] ?? null;
                    $businessGstin = $busFields['gstin'] ?? null;
                }
                if (isset($busRow->name) && $busRow->name) {
                    $businessName = $busRow->name;
                }
            }
        }

        // Resolve logo & signature strictly from the return's captured snapshots (preserving historical returns)
        $resolveDataUri = static function (?string $path, ?string $version, string $historyKey) use ($busFields): ?string {
            $resolved = $path;
            if (! $resolved && ! empty($version) && is_array($busFields[$historyKey] ?? null)) {
                $resolved = $busFields[$historyKey][$version]['path'] ?? null;
            }
            if ($resolved && \Illuminate\Support\Facades\Storage::disk('public')->exists($resolved)) {
                $raw = \Illuminate\Support\Facades\Storage::disk('public')->get($resolved);
                if ($raw !== '') {
                    $imgInfo = @getimagesizefromstring($raw);
                    $mime = is_array($imgInfo) && ! empty($imgInfo['mime']) ? $imgInfo['mime'] : 'image/png';
                    return 'data:'.$mime.';base64,'.base64_encode($raw);
                }
            }
            return null;
        };

        $businessLogoDataUri = $resolveDataUri(
            $return->business_logo_path ?? null,
            $return->business_logo_version ?? null,
            'logoHistory'
        );
        $businessSignatureDataUri = ! empty($return->business_signature_enabled)
            ? $resolveDataUri(
                $return->business_signature_path ?? null,
                $return->business_signature_version ?? null,
                'signatureHistory'
            )
            : null;
        $businessSignatoryName = ! empty($return->business_signature_enabled)
            ? ($return->business_signatory_name ?? null)
            : null;
        $businessSignatoryDesignation = ! empty($return->business_signature_enabled)
            ? ($return->business_signatory_designation ?? null)
            : null;

        // Dynamically resolve product names and clean characters for PDF
        foreach ($return->items as $item) {
            $name = trim((string) $item->product_name);
            if ($name === '' || strcasecmp($name, 'Item') === 0) {
                // 1. Try variant
                $vId = $item->variant_id ?? $item->product_id ?? null;
                if ($vId && str_starts_with($vId, 'VAR-')) {
                    $var = DB::table('product_variants')->where('id', $vId)->first();
                    if ($var) {
                        $vp = json_decode((string) $var->payload, true) ?: [];
                        $vf = $vp['fields'] ?? [];
                        $pName = $vf['productName'] ?? $vf['name'] ?? null;
                        $vName = $vf['variantName'] ?? $vf['displayName'] ?? null;
                        if ($pName) {
                            $name = $pName . ($vName && $vName !== $pName ? " - {$vName}" : '');
                        }
                    }
                }
                // 2. Try product
                if (($name === '' || strcasecmp($name, 'Item') === 0) && !empty($item->product_id) && !str_starts_with($item->product_id, 'VAR-')) {
                    $prod = DB::table('products')->where('id', $item->product_id)->first();
                    if ($prod) {
                        $pp = json_decode((string) $prod->payload, true) ?: [];
                        $pf = $pp['fields'] ?? [];
                        $name = $pf['name'] ?? $pf['productName'] ?? null;
                    }
                }
                // 3. Try original invoice / purchase bill items
                if (($name === '' || strcasecmp($name, 'Item') === 0) && ($return->original_bill_no || $return->original_bill_id)) {
                    $inv = DB::table('invoices')
                        ->where('invoice_number', $return->original_bill_no)
                        ->orWhere('id', $return->original_bill_id)
                        ->first();
                    if ($inv) {
                        $invPayload = json_decode((string) $inv->payload, true) ?: [];
                        $invItems = $invPayload['fields']['items'] ?? [];
                        foreach ($invItems as $invLine) {
                            $mName = $invLine['name'] ?? $invLine['variantName'] ?? $invLine['productName'] ?? $invLine['product_name'] ?? $invLine['itemName'] ?? null;
                            if ($mName && strcasecmp(trim($mName), 'Item') !== 0) {
                                $name = $mName;
                                break;
                            }
                        }
                    }
                }
                if ($name && strcasecmp($name, 'Item') !== 0) {
                    $item->product_name = $name;
                    DB::table('return_items')->where('id', $item->id)->update(['product_name' => $name]);
                }
            }

            // Clean unicode dashes and bullets that break in standard PDF fonts
            $cleanName = str_replace(['–', '—', '•', '₹'], ['-', '-', '-', 'Rs.'], (string) $item->product_name);
            $item->product_name = $cleanName;
        }

        $pdf = Pdf::loadView('pdf.return_bill', [
            'return' => $return,
            'businessName' => $businessName,
            'businessAddress' => $businessAddress,
            'businessPhone' => $businessPhone,
            'businessGstin' => $businessGstin,
            'businessLogoDataUri' => $businessLogoDataUri,
            'businessSignatureDataUri' => $businessSignatureDataUri,
            'businessSignatoryName' => $businessSignatoryName,
            'businessSignatoryDesignation' => $businessSignatoryDesignation,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream("{$return->return_no}.pdf");
    }

    /**
     * Get returnable items with remaining quantities for a sales invoice or purchase bill.
     */
    public function returnableBill(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        // 1. Check sales invoices
        $invoice = null;
        if (DB::getSchemaBuilder()->hasTable('invoices')) {
            $invoice = DB::table('invoices')
                ->where('id', $id)
                ->orWhere('payload->fields->invoiceNumber', $id)
                ->first();
        }

        if ($invoice) {
            $actualInvoiceId = $invoice->id;
            $businessId = $invoice->business_id ?? null;
            if ($businessId && ! $user->canAccessBusiness($businessId) && ! $user->isAdmin()) {
                return ApiResponse::error('Unauthorized access to invoice.', 403, null, 'FORBIDDEN');
            }

            $invPayload = ! empty($invoice->payload) ? (json_decode($invoice->payload, true) ?: []) : [];
            $invFields = $invPayload['fields'] ?? [];

            // Find items from invoice_items table or payload
            $items = [];
            if (DB::getSchemaBuilder()->hasTable('invoice_items')) {
                $dbItems = DB::table('invoice_items')
                    ->where('business_id', $businessId)
                    ->where(function ($q) use ($id, $actualInvoiceId) {
                        $q->where('payload->fields->invoiceId', $id)
                            ->orWhere('payload->fields->invoiceId', $actualInvoiceId)
                            ->orWhere('id', 'like', "{$id}_%")
                            ->orWhere('id', 'like', "{$actualInvoiceId}_%");
                    })
                    ->get();

                foreach ($dbItems as $dbItem) {
                    $p = json_decode($dbItem->payload ?? '{}', true) ?: [];
                    $f = $p['fields'] ?? [];
                    $soldQty = (float) ($f['quantity'] ?? $dbItem->quantity ?? 0);
                    $returnedQty = (float) ($dbItem->returned_qty ?? $f['returnedQty'] ?? 0);
                    $remaining = max(0.0, $soldQty - $returnedQty);

                    $rawProdName = $f['name'] ?? $f['variantName'] ?? $f['productName'] ?? $f['product_name'] ?? $f['itemName'] ?? $f['item_name'] ?? null;
                    if (! $rawProdName || strcasecmp(trim($rawProdName), 'Item') === 0) {
                        $vId = $f['variantId'] ?? $f['variant_id'] ?? $f['productId'] ?? $f['product_id'] ?? null;
                        if ($vId && str_starts_with($vId, 'VAR-')) {
                            $var = DB::table('product_variants')->where('id', $vId)->first();
                            if ($var) {
                                $vp = json_decode((string) $var->payload, true) ?: [];
                                $vf = $vp['fields'] ?? [];
                                $pName = $vf['productName'] ?? $vf['name'] ?? null;
                                $vName = $vf['variantName'] ?? $vf['displayName'] ?? null;
                                if ($pName) {
                                    $rawProdName = $pName . ($vName && $vName !== $pName ? " • {$vName}" : '');
                                }
                            }
                        }
                    }

                    $items[] = [
                        'original_bill_item_id' => $dbItem->id,
                        'product_id' => $f['productId'] ?? $f['product_id'] ?? '',
                        'variant_id' => $f['variantId'] ?? $f['variant_id'] ?? null,
                        'product_name' => $rawProdName ?: 'Item',
                        'hsn_code' => $f['hsn'] ?? $f['hsn_code'] ?? '',
                        'unit' => $f['unit'] ?? '',
                        'original_qty' => $soldQty,
                        'already_returned_qty' => $returnedQty,
                        'remaining_returnable_qty' => $remaining,
                        'unit_price' => ((float) ($f['unitPricePaise'] ?? $f['unit_price_paise'] ?? 0)) / 100.0 ?: (float) ($f['sellingPrice'] ?? 0),
                        'tax_rate' => ((float) ($f['gstRateBps'] ?? $f['gst_rate_bps'] ?? 0)) / 100.0,
                    ];
                }
            }

            if (empty($items) && ! empty($invFields['items'])) {
                foreach ($invFields['items'] as $item) {
                    $soldQty = (float) ($item['quantity'] ?? 0);
                    $returnedQty = (float) ($item['returnedQty'] ?? 0);
                    $remaining = max(0.0, $soldQty - $returnedQty);

                    $rawProdName = $item['name'] ?? $item['variantName'] ?? $item['productName'] ?? $item['product_name'] ?? $item['itemName'] ?? $item['item_name'] ?? null;
                    if (! $rawProdName || strcasecmp(trim($rawProdName), 'Item') === 0) {
                        $vId = $item['variantId'] ?? $item['productId'] ?? null;
                        if ($vId && str_starts_with($vId, 'VAR-')) {
                            $var = DB::table('product_variants')->where('id', $vId)->first();
                            if ($var) {
                                $vp = json_decode((string) $var->payload, true) ?: [];
                                $vf = $vp['fields'] ?? [];
                                $pName = $vf['productName'] ?? $vf['name'] ?? null;
                                $vName = $vf['variantName'] ?? $vf['displayName'] ?? null;
                                if ($pName) {
                                    $rawProdName = $pName . ($vName && $vName !== $pName ? " • {$vName}" : '');
                                }
                            }
                        }
                    }

                    $items[] = [
                        'original_bill_item_id' => $item['itemId'] ?? null,
                        'product_id' => $item['productId'] ?? '',
                        'variant_id' => $item['variantId'] ?? null,
                        'product_name' => $rawProdName ?: 'Item',
                        'hsn_code' => $item['hsn'] ?? '',
                        'unit' => $item['unit'] ?? '',
                        'original_qty' => $soldQty,
                        'already_returned_qty' => $returnedQty,
                        'remaining_returnable_qty' => $remaining,
                        'unit_price' => ((float) ($item['unitPricePaise'] ?? 0)) / 100.0,
                        'tax_rate' => ((float) ($item['gstRateBps'] ?? 0)) / 100.0,
                    ];
                }
            }

            return ApiResponse::success([
                'bill_id' => $id,
                'bill_type' => 'sale_return',
                'bill_no' => $invFields['invoiceNumber'] ?? $invoice->id,
                'bill_date' => $invFields['createdAt'] ?? $invoice->created_at,
                'party_id' => $invFields['customerId'] ?? '',
                'party_name' => $invFields['customerName'] ?? 'Customer',
                'party_phone' => $invFields['customerPhone'] ?? '',
                'party_gstin' => $invFields['customerGstin'] ?? '',
                'party_state_code' => $invFields['customerStateCode'] ?? '',
                'items' => $items,
            ], 'Returnable bill items retrieved.');
        }

        // 2. Check purchase bills
        $purchaseBill = null;
        if (DB::getSchemaBuilder()->hasTable('purchase_bills')) {
            $purchaseBill = DB::table('purchase_bills')->where('id', $id)->first();
        }

        if ($purchaseBill) {
            $businessId = $purchaseBill->business_id ?? null;
            if ($businessId && ! $user->canAccessBusiness($businessId) && ! $user->isAdmin()) {
                return ApiResponse::error('Unauthorized access to purchase bill.', 403, null, 'FORBIDDEN');
            }

            $pbPayload = ! empty($purchaseBill->payload) ? (json_decode($purchaseBill->payload, true) ?: []) : [];
            $pbFields = $pbPayload['fields'] ?? [];

            $items = [];
            if (DB::getSchemaBuilder()->hasTable('purchase_bill_items')) {
                $dbItems = DB::table('purchase_bill_items')
                    ->where('business_id', $businessId)
                    ->where(function ($q) use ($id) {
                        $q->where('payload->fields->purchaseBillId', $id)
                            ->orWhere('id', 'like', "{$id}_%");
                    })
                    ->get();

                foreach ($dbItems as $dbItem) {
                    $p = json_decode($dbItem->payload ?? '{}', true) ?: [];
                    $f = $p['fields'] ?? [];
                    $purchasedQty = (float) ($f['orderedQuantity'] ?? $dbItem->quantity ?? 0);
                    $returnedQty = (float) ($dbItem->returned_qty ?? $f['returnedQty'] ?? 0);
                    $remaining = max(0.0, $purchasedQty - $returnedQty);

                    $rawProdName = $f['name'] ?? $f['variantName'] ?? $f['productName'] ?? $f['product_name'] ?? $f['itemName'] ?? $f['item_name'] ?? null;
                    if (! $rawProdName || strcasecmp(trim($rawProdName), 'Item') === 0) {
                        $vId = $f['variantId'] ?? $f['variant_id'] ?? $f['productId'] ?? $f['product_id'] ?? null;
                        if ($vId && str_starts_with($vId, 'VAR-')) {
                            $var = DB::table('product_variants')->where('id', $vId)->first();
                            if ($var) {
                                $vp = json_decode((string) $var->payload, true) ?: [];
                                $vf = $vp['fields'] ?? [];
                                $pName = $vf['productName'] ?? $vf['name'] ?? null;
                                $vName = $vf['variantName'] ?? $vf['displayName'] ?? null;
                                if ($pName) {
                                    $rawProdName = $pName . ($vName && $vName !== $pName ? " • {$vName}" : '');
                                }
                            }
                        }
                    }

                    $items[] = [
                        'original_bill_item_id' => $dbItem->id,
                        'product_id' => $f['productId'] ?? '',
                        'variant_id' => $f['variantId'] ?? null,
                        'product_name' => $rawProdName ?: 'Item',
                        'hsn_code' => $f['hsnCode'] ?? '',
                        'unit' => $f['unit'] ?? '',
                        'original_qty' => $purchasedQty,
                        'already_returned_qty' => $returnedQty,
                        'remaining_returnable_qty' => $remaining,
                        'unit_price' => (float) ($f['rate'] ?? $f['unitPrice'] ?? 0),
                        'tax_rate' => (float) ($f['taxRate'] ?? 0),
                    ];
                }
            }

            return ApiResponse::success([
                'bill_id' => $id,
                'bill_type' => 'purchase_return',
                'bill_no' => $pbFields['purchaseBillNumber'] ?? $purchaseBill->id,
                'bill_date' => $pbFields['billDate'] ?? $purchaseBill->created_at,
                'party_id' => $pbFields['supplierId'] ?? '',
                'party_name' => $pbFields['supplierName'] ?? 'Supplier',
                'party_phone' => $pbFields['supplierPhone'] ?? '',
                'party_gstin' => $pbFields['supplierGstin'] ?? '',
                'party_state_code' => $pbFields['supplierStateCode'] ?? '',
                'items' => $items,
            ], 'Returnable bill items retrieved.');
        }

        return ApiResponse::error('Bill not found.', 404, null, 'NOT_FOUND');
    }
}
