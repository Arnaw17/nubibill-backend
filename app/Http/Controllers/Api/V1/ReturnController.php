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
        $validated['outlet_id'] = $request->input('outlet_id') ?? $request->header('X-Outlet-Id');

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

        $query = ReturnModel::with(['items', 'reason']);

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

        $return = ReturnModel::with(['items', 'reason', 'stockMovements', 'ledgerEntries', 'audits'])
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
            new ReturnResource($return->load(['items', 'reason', 'stockMovements', 'ledgerEntries'])),
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
        if ($return->business_id && DB::getSchemaBuilder()->hasTable('businesses')) {
            $busRow = DB::table('businesses')->where('id', $return->business_id)->first();
            if ($busRow) {
                if (isset($busRow->name)) {
                    $businessName = $busRow->name;
                } elseif (! empty($busRow->payload)) {
                    $payload = json_decode($busRow->payload, true) ?: [];
                    $businessName = $payload['fields']['businessName'] ?? $payload['fields']['name'] ?? $businessName;
                }
            }
        }

        $pdf = Pdf::loadView('pdf.return_bill', [
            'return' => $return,
            'businessName' => $businessName,
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

                    $items[] = [
                        'original_bill_item_id' => $dbItem->id,
                        'product_id' => $f['productId'] ?? $f['product_id'] ?? '',
                        'variant_id' => $f['variantId'] ?? $f['variant_id'] ?? null,
                        'product_name' => $f['productName'] ?? $f['product_name'] ?? 'Item',
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

                    $items[] = [
                        'original_bill_item_id' => $item['itemId'] ?? null,
                        'product_id' => $item['productId'] ?? '',
                        'variant_id' => $item['variantId'] ?? null,
                        'product_name' => $item['productName'] ?? 'Item',
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

                    $items[] = [
                        'original_bill_item_id' => $dbItem->id,
                        'product_id' => $f['productId'] ?? '',
                        'variant_id' => $f['variantId'] ?? null,
                        'product_name' => $f['productName'] ?? 'Item',
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
