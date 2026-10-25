<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupplierController extends Controller
{
    /**
     * List suppliers for a business with balance and search filter.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));
        $statusFilter = strtoupper((string) ($request->query('status') ?? 'ACTIVE'));

        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        if (! $businessId) {
            return ApiResponse::success([], 'No active business selected.');
        }

        // 1. Fetch supplier balances for this business
        $balanceRows = DB::table('supplier_balances')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        $balancesBySupplier = [];
        foreach ($balanceRows as $bRow) {
            $bPayload = json_decode($bRow->payload, true) ?: [];
            $bFields = $bPayload['fields'] ?? [];
            $sId = (string) ($bFields['supplierId'] ?? '');
            if (! $sId && str_contains($bRow->id, '_')) {
                $sId = explode('_', $bRow->id)[0];
            }
            if ($sId) {
                $balancesBySupplier[$sId] = [
                    'totalPayable' => (int) ($bFields['totalPayable'] ?? 0),
                    'unallocatedAdvance' => (int) ($bFields['unallocatedAdvance'] ?? 0),
                    'unallocatedReturnCredit' => (int) ($bFields['unallocatedReturnCredit'] ?? 0),
                    'unallocatedSupplierCredit' => (int) ($bFields['unallocatedSupplierCredit'] ?? 0),
                ];
            }
        }

        // 2. Fetch business relations from supplier_businesses
        $relationRows = DB::table('supplier_businesses')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        $relationsBySupplier = [];
        $supplierIds = [];
        foreach ($relationRows as $rRow) {
            $rPayload = json_decode($rRow->payload, true) ?: [];
            $rFields = $rPayload['fields'] ?? [];
            $sId = (string) ($rFields['supplierId'] ?? '');
            if (! $sId && str_contains($rRow->id, '_')) {
                $sId = explode('_', $rRow->id)[0];
            }
            if ($sId) {
                $supplierIds[] = $sId;
                $relationsBySupplier[$sId] = [
                    'creditLimit' => (int) ($rFields['creditLimit'] ?? 0),
                    'paymentTerms' => (int) ($rFields['paymentTerms'] ?? $rFields['paymentTermsDays'] ?? 0),
                    'openingBalance' => (int) ($rFields['openingBalance'] ?? 0),
                    'preferred' => (bool) ($rFields['preferred'] ?? false),
                    'status' => (string) ($rFields['status'] ?? $rRow->status ?? 'ACTIVE'),
                ];
            }
        }

        // 3. Fetch suppliers
        $suppliersQuery = DB::table('suppliers')
            ->where('is_deleted', 0);

        if (! empty($supplierIds)) {
            $suppliersQuery->where(function ($q) use ($supplierIds, $businessId) {
                $q->whereIn('id', $supplierIds)
                    ->orWhere('business_id', $businessId);
            });
        } else {
            $suppliersQuery->where('business_id', $businessId);
        }

        if ($statusFilter !== 'ALL') {
            $suppliersQuery->where(function ($q) use ($statusFilter) {
                $q->where('status', $statusFilter)
                    ->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.status'))) = ?", [$statusFilter]);
            });
        }

        $suppliers = $suppliersQuery->orderBy('created_at', 'desc')->get();

        $data = [];
        foreach ($suppliers as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];

            $name = (string) ($fields['name'] ?? '');
            $contactName = (string) ($fields['contactName'] ?? $fields['contactPerson'] ?? '');
            $mobile = (string) ($fields['mobile'] ?? $fields['phone'] ?? '');
            $email = (string) ($fields['email'] ?? '');
            $gstin = (string) ($fields['gstin'] ?? '');
            $pan = (string) ($fields['pan'] ?? '');
            $stateCode = (string) ($fields['stateCode'] ?? '');
            $supplierType = (string) ($fields['supplierType'] ?? 'Supplier');
            $address = (string) ($fields['address'] ?? '');
            $status = (string) ($fields['status'] ?? $row->status ?? 'ACTIVE');
            $tenantId = (string) ($fields['tenantId'] ?? $fields['ownerUid'] ?? '');

            if ($search !== '') {
                $searchLower = strtolower($search);
                $matches = str_contains(strtolower($name), $searchLower)
                    || str_contains(strtolower($mobile), $searchLower)
                    || str_contains(strtolower($gstin), $searchLower)
                    || str_contains(strtolower($contactName), $searchLower);
                if (! $matches) {
                    continue;
                }
            }

            $rel = $relationsBySupplier[$row->id] ?? [
                'creditLimit' => 0,
                'paymentTerms' => 0,
                'openingBalance' => 0,
                'preferred' => false,
                'status' => $status,
            ];

            $bal = $balancesBySupplier[$row->id] ?? [
                'totalPayable' => 0,
                'unallocatedAdvance' => 0,
            ];

            $data[] = [
                'supplierId' => $row->id,
                'tenantId' => $tenantId,
                'businessId' => $businessId,
                'name' => $name,
                'contactPerson' => $contactName ?: null,
                'contactName' => $contactName ?: null,
                'mobile' => $mobile ?: null,
                'phone' => $mobile ?: null,
                'whatsapp' => $fields['whatsapp'] ?? null,
                'email' => $email ?: null,
                'gstin' => $gstin ?: null,
                'pan' => $pan ?: null,
                'stateCode' => $stateCode ?: null,
                'supplierType' => $supplierType,
                'address' => $address ?: null,
                'creditLimit' => $rel['creditLimit'],
                'paymentTerms' => $rel['paymentTerms'],
                'paymentTermsDays' => $rel['paymentTerms'],
                'openingBalance' => $rel['openingBalance'],
                'preferred' => $rel['preferred'],
                'totalPayable' => $bal['totalPayable'],
                'unallocatedAdvance' => $bal['unallocatedAdvance'],
                'status' => $status,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        }

        return ApiResponse::success($data, 'Suppliers retrieved successfully.');
    }

    /**
     * Show single supplier profile and financials.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        $row = DB::table('suppliers')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Supplier not found.', 'NOT_FOUND', 404);
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];

        // Check relation
        $relRow = DB::table('supplier_businesses')
            ->where('business_id', $businessId)
            ->where(function ($q) use ($id) {
                $q->where('id', "{$id}_{$id}")
                    ->orWhere('id', 'LIKE', "{$id}_%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.supplierId')) = ?", [$id]);
            })
            ->where('is_deleted', 0)
            ->first();

        $relFields = $relRow ? (json_decode($relRow->payload, true)['fields'] ?? []) : [];

        // Check balance
        $balRow = DB::table('supplier_balances')
            ->where('business_id', $businessId)
            ->where(function ($q) use ($id) {
                $q->where('id', 'LIKE', "{$id}_%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.supplierId')) = ?", [$id]);
            })
            ->first();

        $balFields = $balRow ? (json_decode($balRow->payload, true)['fields'] ?? []) : [];

        $data = [
            'supplierId' => $row->id,
            'tenantId' => $fields['tenantId'] ?? $fields['ownerUid'] ?? '',
            'businessId' => $businessId,
            'name' => $fields['name'] ?? '',
            'contactPerson' => $fields['contactName'] ?? $fields['contactPerson'] ?? null,
            'mobile' => $fields['mobile'] ?? $fields['phone'] ?? null,
            'email' => $fields['email'] ?? null,
            'gstin' => $fields['gstin'] ?? null,
            'pan' => $fields['pan'] ?? null,
            'stateCode' => $fields['stateCode'] ?? null,
            'supplierType' => $fields['supplierType'] ?? 'Supplier',
            'address' => $fields['address'] ?? null,
            'creditLimit' => (int) ($relFields['creditLimit'] ?? 0),
            'paymentTerms' => (int) ($relFields['paymentTerms'] ?? $relFields['paymentTermsDays'] ?? 0),
            'openingBalance' => (int) ($relFields['openingBalance'] ?? 0),
            'preferred' => (bool) ($relFields['preferred'] ?? false),
            'totalPayable' => (int) ($balFields['totalPayable'] ?? 0),
            'unallocatedAdvance' => (int) ($balFields['unallocatedAdvance'] ?? 0),
            'status' => $fields['status'] ?? $row->status ?? 'ACTIVE',
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];

        return ApiResponse::success($data, 'Supplier profile retrieved successfully.');
    }

    /**
     * Register a new supplier.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'required|string',
            'name' => 'required|string|min:2|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:20',
            'whatsapp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'gstin' => 'nullable|string|max:15',
            'pan' => 'nullable|string|max:10',
            'state_code' => 'nullable|string|max:10',
            'supplier_type' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:500',
            'credit_limit' => 'nullable|integer|min:0',
            'payment_terms_days' => 'nullable|integer|min:0',
            'opening_balance' => 'nullable|integer|min:0',
            'preferred' => 'nullable|boolean',
        ]);

        $businessId = $validated['business_id'];
        $name = trim($validated['name']);
        $mobile = ! empty($validated['mobile']) ? preg_replace('/\D/', '', $validated['mobile']) : (! empty($validated['phone']) ? preg_replace('/\D/', '', $validated['phone']) : null);
        $gstin = ! empty($validated['gstin']) ? strtoupper(trim($validated['gstin'])) : null;
        $email = ! empty($validated['email']) ? strtolower(trim($validated['email'])) : null;
        $pan = ! empty($validated['pan']) ? strtoupper(trim($validated['pan'])) : null;
        $contactPerson = $validated['contact_person'] ?? $validated['contact_name'] ?? null;
        $creditLimit = (int) ($validated['credit_limit'] ?? 0);
        $paymentTerms = (int) ($validated['payment_terms_days'] ?? 0);
        $openingBalance = (int) ($validated['opening_balance'] ?? 0);
        $preferred = (bool) ($validated['preferred'] ?? false);

        // Check duplicate name or GSTIN within this business
        $existing = DB::table('suppliers')
            ->where('is_deleted', 0)
            ->where(function ($q) use ($name, $gstin, $mobile) {
                $q->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.name'))) = ?", [strtolower($name)]);
                if ($gstin) {
                    $q->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.gstin'))) = ?", [$gstin]);
                }
                if ($mobile) {
                    $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.mobile')) = ?", [$mobile]);
                }
            })
            ->first();

        if ($existing) {
            return ApiResponse::error(
                'A supplier with this name, GSTIN, or contact number already exists.',
                'VALIDATION_FAILED',
                422,
                ['name' => ['A supplier with this name, GSTIN, or contact number already exists.']]
            );
        }

        $supplierId = 'SUP-' . strtoupper(Str::random(32));
        $supplierBusinessId = "{$supplierId}_{$businessId}";
        $now = now()->toIso8601String();
        $dbNow = now();
        $userId = $request->user()?->id ?? 'SYSTEM';

        $supplierFields = [
            'supplierId' => $supplierId,
            'tenantId' => $userId,
            'ownerUid' => $userId,
            'name' => $name,
            'contactName' => $contactPerson,
            'mobile' => $mobile,
            'whatsapp' => $validated['whatsapp'] ?? null,
            'email' => $email,
            'gstin' => $gstin,
            'pan' => $pan,
            'stateCode' => $validated['state_code'] ?? null,
            'supplierType' => $validated['supplier_type'] ?? 'Supplier',
            'address' => $validated['address'] ?? null,
            'status' => 'ACTIVE',
            'createdAt' => $now,
            'createdBy' => $userId,
            'updatedAt' => $now,
            'updatedBy' => $userId,
        ];

        $relationFields = [
            'supplierBusinessId' => $supplierBusinessId,
            'tenantId' => $userId,
            'ownerUid' => $userId,
            'supplierId' => $supplierId,
            'businessId' => $businessId,
            'creditLimit' => $creditLimit,
            'paymentTerms' => $paymentTerms,
            'openingBalance' => $openingBalance,
            'preferred' => $preferred,
            'status' => 'ACTIVE',
            'createdAt' => $now,
            'createdBy' => $userId,
            'updatedAt' => $now,
            'updatedBy' => $userId,
        ];

        $balanceFields = [
            'supplierBusinessId' => $supplierBusinessId,
            'tenantId' => $userId,
            'ownerUid' => $userId,
            'businessId' => $businessId,
            'supplierId' => $supplierId,
            'totalPayable' => $openingBalance,
            'unallocatedAdvance' => 0,
            'unallocatedReturnCredit' => 0,
            'unallocatedSupplierCredit' => 0,
            'updatedAt' => $now,
        ];

        DB::transaction(function () use (
            $supplierId,
            $supplierBusinessId,
            $businessId,
            $supplierFields,
            $relationFields,
            $balanceFields,
            $openingBalance,
            $now,
            $dbNow,
            $userId
        ) {
            // 1. Insert into suppliers
            DB::table('suppliers')->insert([
                'id' => $supplierId,
                'document_path' => "suppliers/{$supplierId}",
                'business_id' => $businessId,
                'outlet_id' => '',
                'status' => 'ACTIVE',
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $supplierFields]),
            ]);

            // 2. Insert into supplier_businesses
            DB::table('supplier_businesses')->insert([
                'id' => $supplierBusinessId,
                'document_path' => "supplier_businesses/{$supplierBusinessId}",
                'business_id' => $businessId,
                'outlet_id' => '',
                'status' => 'ACTIVE',
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $relationFields]),
            ]);

            // 3. Insert into supplier_balances
            DB::table('supplier_balances')->insert([
                'id' => $supplierBusinessId,
                'document_path' => "supplier_balances/{$supplierBusinessId}",
                'business_id' => $businessId,
                'outlet_id' => '',
                'status' => 'ACTIVE',
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $balanceFields]),
            ]);

            // 4. Ledger opening balance entry if applicable
            if ($openingBalance > 0) {
                $ledgerId = "OPENING_{$supplierBusinessId}";
                $ledgerFields = [
                    'ledgerEntryId' => $ledgerId,
                    'tenantId' => $userId,
                    'businessId' => $businessId,
                    'supplierBusinessId' => $supplierBusinessId,
                    'supplierId' => $supplierId,
                    'entryType' => 'OPENING_BALANCE',
                    'referenceType' => 'OPENING_BALANCE',
                    'referenceId' => $supplierBusinessId,
                    'debit' => 0,
                    'credit' => $openingBalance,
                    'transactionDate' => $now,
                    'createdAt' => $now,
                    'createdBy' => $userId,
                    'isReversal' => false,
                ];

                DB::table('supplier_ledger_entries')->insert([
                    'id' => $ledgerId,
                    'document_path' => "supplier_ledger_entries/{$ledgerId}",
                    'business_id' => $businessId,
                    'outlet_id' => '',
                    'status' => 'POSTED',
                    'created_at' => $dbNow,
                    'updated_at' => $dbNow,
                    'is_deleted' => 0,
                    'payload' => json_encode(['fields' => $ledgerFields]),
                ]);
            }
        });

        $data = [
            'supplierId' => $supplierId,
            'businessId' => $businessId,
            'name' => $name,
            'contactPerson' => $contactPerson,
            'mobile' => $mobile,
            'email' => $email,
            'gstin' => $gstin,
            'pan' => $pan,
            'stateCode' => $validated['state_code'] ?? null,
            'supplierType' => $validated['supplier_type'] ?? 'Supplier',
            'address' => $validated['address'] ?? null,
            'creditLimit' => $creditLimit,
            'paymentTerms' => $paymentTerms,
            'paymentTermsDays' => $paymentTerms,
            'openingBalance' => $openingBalance,
            'preferred' => $preferred,
            'totalPayable' => $openingBalance,
            'unallocatedAdvance' => 0,
            'status' => 'ACTIVE',
        ];

        return ApiResponse::success(['supplier' => $data], 'Supplier created successfully.', 201);
    }

    /**
     * Update supplier profile and relation terms.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'name' => 'required|string|min:2|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:20',
            'whatsapp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'gstin' => 'nullable|string|max:15',
            'pan' => 'nullable|string|max:10',
            'state_code' => 'nullable|string|max:10',
            'supplier_type' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:500',
            'credit_limit' => 'nullable|integer|min:0',
            'payment_terms_days' => 'nullable|integer|min:0',
            'preferred' => 'nullable|boolean',
        ]);

        $row = DB::table('suppliers')->where('id', $id)->first();
        if (! $row) {
            return ApiResponse::error('Supplier not found.', 'NOT_FOUND', 404);
        }

        $businessId = $validated['business_id'] ?? $row->business_id;
        $name = trim($validated['name']);
        $mobile = ! empty($validated['mobile']) ? preg_replace('/\D/', '', $validated['mobile']) : (! empty($validated['phone']) ? preg_replace('/\D/', '', $validated['phone']) : null);
        $gstin = ! empty($validated['gstin']) ? strtoupper(trim($validated['gstin'])) : null;
        $email = ! empty($validated['email']) ? strtolower(trim($validated['email'])) : null;

        // Check duplicate on other suppliers
        $conflict = DB::table('suppliers')
            ->where('id', '!=', $id)
            ->where('is_deleted', 0)
            ->where(function ($q) use ($name, $gstin) {
                $q->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.name'))) = ?", [strtolower($name)]);
                if ($gstin) {
                    $q->orWhereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.gstin'))) = ?", [$gstin]);
                }
            })
            ->first();

        if ($conflict) {
            return ApiResponse::error(
                'Another supplier with this name or GSTIN already exists.',
                'VALIDATION_FAILED',
                422,
                ['name' => ['Another supplier with this name or GSTIN already exists.']]
            );
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        $now = now()->toIso8601String();

        $fields['name'] = $name;
        $fields['contactName'] = $validated['contact_person'] ?? $validated['contact_name'] ?? ($fields['contactName'] ?? null);
        if ($mobile !== null) $fields['mobile'] = $mobile;
        if (isset($validated['whatsapp'])) $fields['whatsapp'] = $validated['whatsapp'];
        if ($email !== null) $fields['email'] = $email;
        if ($gstin !== null) $fields['gstin'] = $gstin;
        if (isset($validated['pan'])) $fields['pan'] = strtoupper(trim($validated['pan']));
        if (isset($validated['state_code'])) $fields['stateCode'] = trim($validated['state_code']);
        if (isset($validated['supplier_type'])) $fields['supplierType'] = trim($validated['supplier_type']);
        if (isset($validated['address'])) $fields['address'] = trim($validated['address']);
        $fields['updatedAt'] = $now;

        $payload['fields'] = $fields;

        DB::table('suppliers')->where('id', $id)->update([
            'updated_at' => now(),
            'payload' => json_encode($payload),
        ]);

        // Update relation if exists
        $relRow = DB::table('supplier_businesses')
            ->where('business_id', $businessId)
            ->where(function ($q) use ($id) {
                $q->where('id', 'LIKE', "{$id}_%")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.supplierId')) = ?", [$id]);
            })
            ->first();

        if ($relRow) {
            $relPayload = json_decode($relRow->payload, true) ?: [];
            $relFields = $relPayload['fields'] ?? [];
            if (isset($validated['credit_limit'])) $relFields['creditLimit'] = (int) $validated['credit_limit'];
            if (isset($validated['payment_terms_days'])) {
                $relFields['paymentTerms'] = (int) $validated['payment_terms_days'];
                $relFields['paymentTermsDays'] = (int) $validated['payment_terms_days'];
            }
            if (isset($validated['preferred'])) $relFields['preferred'] = (bool) $validated['preferred'];
            $relFields['updatedAt'] = $now;
            $relPayload['fields'] = $relFields;

            DB::table('supplier_businesses')->where('id', $relRow->id)->update([
                'updated_at' => now(),
                'payload' => json_encode($relPayload),
            ]);
        }

        return ApiResponse::success([
            'supplier' => [
                'supplierId' => $id,
                'name' => $name,
                'mobile' => $fields['mobile'] ?? null,
                'email' => $fields['email'] ?? null,
                'gstin' => $fields['gstin'] ?? null,
                'status' => $fields['status'] ?? 'ACTIVE',
            ],
        ], 'Supplier updated successfully.');
    }

    /**
     * Soft delete a supplier.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $supplier = DB::table('suppliers')->where('id', $id)->first();
        if (! $supplier) {
            return ApiResponse::error('Supplier not found.', 'NOT_FOUND', 404);
        }

        DB::transaction(function () use ($id) {
            DB::table('suppliers')->where('id', $id)->update([
                'is_deleted' => 1,
                'status' => 'INACTIVE',
                'updated_at' => now(),
            ]);

            DB::table('supplier_businesses')
                ->where('id', 'LIKE', "{$id}_%")
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.supplierId')) = ?", [$id])
                ->update([
                    'is_deleted' => 1,
                    'status' => 'INACTIVE',
                    'updated_at' => now(),
                ]);
        });

        return ApiResponse::success(null, 'Supplier deleted successfully.');
    }

    /**
     * Get chronological supplier ledger entries.
     */
    public function ledger(Request $request, string $id): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        // Auto-backfill any purchase_bills for this supplier that might be missing from supplier_ledger_entries
        $existingBills = DB::table('supplier_ledger_entries')
            ->where(function ($q) use ($id) {
                $q->where('payload->fields->supplierId', $id)
                    ->orWhere('payload->fields->supplier_id', $id)
                    ->orWhere('id', 'LIKE', "%_{$id}_%")
                    ->orWhere('id', 'LIKE', "OPENING_{$id}_%");
            })
            ->pluck('payload')
            ->map(function ($p) {
                $dec = json_decode($p, true) ?: [];
                return $dec['fields']['referenceId'] ?? null;
            })
            ->filter()
            ->values()
            ->all();

        $missingBills = DB::table('purchase_bills')
            ->where('payload->fields->supplierId', $id)
            ->whereNotIn('id', $existingBills)
            ->where('is_deleted', 0)
            ->get();

        foreach ($missingBills as $mb) {
            $mbPayload = json_decode($mb->payload, true) ?: [];
            $mbFields = $mbPayload['fields'] ?? [];
            $grandTotal = (int) ($mbFields['grandTotal'] ?? $mb->grand_total ?? 0);
            $created = $mbFields['createdAt'] ?? $mb->created_at ?? now()->toIso8601String();
            $supBizId = $mbFields['supplierBusinessId'] ?? "{$id}_{$businessId}";
            $ledgerBillId = "PURCHASE_BILL_{$mb->id}_1";

            $entryFields = [
                'ledgerEntryId' => $ledgerBillId,
                'tenantId' => $mbFields['tenantId'] ?? $mb->tenant_id ?? '',
                'businessId' => $businessId,
                'outletId' => $mbFields['outletId'] ?? $mb->outlet_id ?? '',
                'supplierId' => $id,
                'supplierBusinessId' => $supBizId,
                'entryType' => 'PURCHASE_BILL',
                'referenceType' => 'PURCHASE_BILL',
                'referenceId' => $mb->id,
                'debit' => 0,
                'credit' => $grandTotal,
                'transactionDate' => $created,
                'createdAt' => $created,
                'createdBy' => $mbFields['createdBy'] ?? 'SYSTEM',
                'isReversal' => false,
            ];

            DB::table('supplier_ledger_entries')->insertOrIgnore([
                'id' => $ledgerBillId,
                'document_path' => "supplier_ledger_entries/{$ledgerBillId}",
                'business_id' => $businessId,
                'outlet_id' => $mbFields['outletId'] ?? $mb->outlet_id ?? '',
                'status' => 'POSTED',
                'created_at' => $created,
                'updated_at' => $created,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $entryFields]),
            ]);
        }

        $query = DB::table('supplier_ledger_entries')
            ->where(function ($q) use ($id) {
                $q->where('payload->fields->supplierId', $id)
                    ->orWhere('payload->fields->supplier_id', $id)
                    ->orWhere('id', 'LIKE', "%_{$id}_%")
                    ->orWhere('id', 'LIKE', "OPENING_{$id}_%");
            });

        if ($businessId) {
            $query->where(function ($q) use ($businessId) {
                $q->where('business_id', $businessId)->orWhereNull('business_id');
            });
        }

        $entries = $query->orderBy('created_at', 'asc')->get();

        $billIds = [];
        $paymentIds = [];
        foreach ($entries as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];
            $refId = $fields['referenceId'] ?? '';
            $entryType = $fields['entryType'] ?? '';
            if ($refId) {
                if ($entryType === 'PURCHASE_BILL' || ($fields['credit'] ?? 0) > 0) {
                    $billIds[] = $refId;
                } else {
                    $paymentIds[] = $refId;
                }
            }
        }

        $billsMap = [];
        if (! empty($billIds)) {
            $bRows = DB::table('purchase_bills')->whereIn('id', array_unique($billIds))->get();
            foreach ($bRows as $b) {
                $bPayload = json_decode($b->payload, true) ?: [];
                $billsMap[$b->id] = $bPayload['fields'] ?? [];
            }
        }

        $paymentsMap = [];
        if (! empty($paymentIds)) {
            $pRows = DB::table('payments')->whereIn('id', array_unique($paymentIds))->get();
            foreach ($pRows as $p) {
                $pPayload = json_decode($p->payload, true) ?: [];
                $paymentsMap[$p->id] = $pPayload['fields'] ?? [];
            }
        }

        $data = $entries->map(function ($row) use ($billsMap, $paymentsMap) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];
            $refId = $fields['referenceId'] ?? '';
            $entryType = $fields['entryType'] ?? 'PURCHASE_BILL';
            $debit = (int) ($fields['debit'] ?? 0);
            $credit = (int) ($fields['credit'] ?? 0);

            $voucherNo = $fields['voucherNumber'] ?? null;
            $paymentMode = $fields['paymentMode'] ?? null;
            $referenceNo = $fields['referenceNo'] ?? null;
            $itemsSummary = null;
            $particulars = $fields['particulars'] ?? null;

            if ($entryType === 'PURCHASE_BILL' || $credit > 0) {
                $bill = $billsMap[$refId] ?? null;
                if ($bill) {
                    $voucherNo = $bill['supplierInvoiceNumber'] ?? $bill['purchaseNumber'] ?? $refId;
                    $items = $bill['items'] ?? [];
                    $names = [];
                    foreach ($items as $item) {
                        $pName = $item['productName'] ?? '';
                        if ($pName) $names[] = $pName;
                    }
                    if (! empty($names)) {
                        $itemsSummary = implode(', ', array_slice($names, 0, 3));
                    }
                    $particulars = $voucherNo ? "Purchase Bill #{$voucherNo}" . ($itemsSummary ? " ({$itemsSummary})" : '') : 'Purchase Bill';
                } else {
                    $voucherNo = $refId;
                    $particulars = $refId ? "Purchase Bill #{$refId}" : 'Purchase Bill';
                }
            } elseif ($entryType === 'PAYMENT' || $entryType === 'SUPPLIER_PAYMENT' || $debit > 0) {
                $payment = $paymentsMap[$refId] ?? null;
                if ($payment) {
                    $paymentMode = $payment['paymentMethod'] ?? 'CASH';
                    $referenceNo = $payment['referenceNumber'] ?? null;
                    $particulars = $referenceNo ? "Payment ({$paymentMode}) Ref: {$referenceNo}" : "Supplier Payment ({$paymentMode})";
                } else {
                    $voucherNo = $refId;
                    $particulars = $refId ? "Supplier Payment #{$refId}" : 'Supplier Payment';
                }
            }

            return [
                'id' => $row->id,
                'ledgerEntryId' => $fields['ledgerEntryId'] ?? $row->id,
                'businessId' => $row->business_id,
                'outletId' => $fields['outletId'] ?? $row->outlet_id ?? '',
                'supplierId' => $fields['supplierId'] ?? '',
                'entryType' => $entryType,
                'referenceType' => $fields['referenceType'] ?? $entryType,
                'referenceId' => $refId,
                'debit' => $debit,
                'credit' => $credit,
                'transactionDate' => $fields['transactionDate'] ?? $row->created_at,
                'createdAt' => $fields['createdAt'] ?? $row->created_at,
                'createdBy' => $fields['createdBy'] ?? 'SYSTEM',
                'status' => $row->status ?? 'POSTED',
                'notes' => $fields['notes'] ?? null,
                'voucherNumber' => $voucherNo,
                'paymentMode' => $paymentMode,
                'referenceNo' => $referenceNo,
                'itemsSummary' => $itemsSummary,
                'particulars' => $particulars,
            ];
        });

        return ApiResponse::success($data, 'Supplier ledger entries retrieved successfully.');
    }

    /**
     * Record payment to a supplier.
     */
    public function recordPayment(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'required|string',
            'outlet_id' => 'nullable|string',
            'purchase_bill_id' => 'nullable|string',
            'amount' => 'required|integer|min:1',
            'payment_mode' => 'nullable|string|in:CASH,UPI,BANK_TRANSFER,CHEQUE,CARD',
            'reference_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $businessId = $validated['business_id'];
        $outletId = $validated['outlet_id'] ?? '';
        $purchaseBillId = $request->input('purchase_bill_id') ?? $request->input('purchaseBillId');
        $amount = (int) $validated['amount'];
        $mode = $validated['payment_mode'] ?? 'CASH';
        $refNo = $validated['reference_no'] ?? null;
        $notes = $validated['notes'] ?? null;
        $userId = $request->user()?->id ?? 'SYSTEM';
        $now = now()->toIso8601String();
        $dbNow = now();

        $supplierBusinessId = "{$id}_{$businessId}";

        return DB::transaction(function () use (
            $id,
            $supplierBusinessId,
            $businessId,
            $outletId,
            $purchaseBillId,
            $amount,
            $mode,
            $refNo,
            $notes,
            $userId,
            $now,
            $dbNow
        ) {
            // 1. Lock and update balance
            $balRow = DB::table('supplier_balances')
                ->where('business_id', $businessId)
                ->where(function ($q) use ($id, $supplierBusinessId) {
                    $q->where('id', $supplierBusinessId)
                        ->orWhere('payload->fields->supplierId', $id);
                })
                ->lockForUpdate()
                ->first();

            $currentPayable = 0;
            $currentAdvance = 0;

            if ($balRow) {
                $bPayload = json_decode($balRow->payload, true) ?: [];
                $bFields = $bPayload['fields'] ?? [];
                $currentPayable = (int) ($bFields['totalPayable'] ?? 0);
                $currentAdvance = (int) ($bFields['unallocatedAdvance'] ?? 0);

                if ($amount <= $currentPayable) {
                    $currentPayable -= $amount;
                } else {
                    $excess = $amount - $currentPayable;
                    $currentPayable = 0;
                    $currentAdvance += $excess;
                }

                $bFields['totalPayable'] = $currentPayable;
                $bFields['unallocatedAdvance'] = $currentAdvance;
                $bFields['updatedAt'] = $now;
                $bPayload['fields'] = $bFields;

                DB::table('supplier_balances')->where('id', $balRow->id)->update([
                    'updated_at' => $dbNow,
                    'payload' => json_encode($bPayload),
                ]);
            }

            // 1b. Update purchase bill if specified
            $settledBills = [];
            if ($purchaseBillId) {
                $pbRow = DB::table('purchase_bills')
                    ->where('id', $purchaseBillId)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->first();
                if ($pbRow) {
                    $pbPayload = json_decode($pbRow->payload, true) ?: [];
                    $pbFields = $pbPayload['fields'] ?? [];
                    $pbGrand = (int) ($pbFields['grandTotal'] ?? 0);
                    $pbOldPaid = (int) ($pbFields['paidAmount'] ?? 0);
                    $pbNewPaid = min($pbGrand, $pbOldPaid + $amount);
                    $pbOutstanding = max(0, $pbGrand - $pbNewPaid);
                    $pbFields['paidAmount'] = $pbNewPaid;
                    $pbFields['outstandingAmount'] = $pbOutstanding;
                    $pbFields['paymentStatus'] = $pbNewPaid >= $pbGrand ? 'PAID' : ($pbNewPaid > 0 ? 'PARTIAL' : 'UNPAID');
                    $pbFields['updatedAt'] = $now;
                    $pbPayload['fields'] = $pbFields;
                    DB::table('purchase_bills')->where('id', $purchaseBillId)->update([
                        'updated_at' => $dbNow,
                        'payload' => json_encode($pbPayload),
                    ]);
                    $settledBills[] = [
                        'purchaseBillId' => $purchaseBillId,
                        'supplierInvoiceNumber' => $pbFields['supplierInvoiceNumber'] ?? '',
                        'allocatedAmount' => $amount,
                        'outstandingBefore' => max(0, $pbGrand - $pbOldPaid),
                        'outstandingAfter' => $pbOutstanding,
                    ];
                }
            }

            // 2. Insert into payments
            $paymentId = 'PAY-' . strtoupper(Str::random(24));
            $paymentFields = [
                'paymentId' => $paymentId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'supplierId' => $id,
                'purchaseBillId' => $purchaseBillId,
                'amount' => $amount,
                'paymentMethod' => $mode,
                'referenceNumber' => $refNo,
                'notes' => $notes,
                'createdBy' => $userId,
                'createdAt' => $now,
            ];

            DB::table('payments')->insert([
                'id' => $paymentId,
                'document_path' => "payments/{$paymentId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'COMPLETED',
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $paymentFields]),
            ]);

            // 3. Insert into supplier_ledger_entries (debit reduces payable)
            $ledgerId = 'SUPPLIER_PAYMENT_' . strtoupper(Str::random(24));
            $ledgerFields = [
                'ledgerEntryId' => $ledgerId,
                'tenantId' => $userId,
                'businessId' => $businessId,
                'outletId' => $outletId,
                'supplierBusinessId' => $supplierBusinessId,
                'supplierId' => $id,
                'entryType' => 'PAYMENT',
                'referenceType' => $purchaseBillId ? 'PURCHASE_PAYMENT' : 'PAYMENT',
                'referenceId' => $paymentId,
                'purchaseBillId' => $purchaseBillId,
                'debit' => $amount,
                'credit' => 0,
                'transactionDate' => $now,
                'createdAt' => $now,
                'createdBy' => $userId,
                'isReversal' => false,
                'notes' => $notes,
            ];

            DB::table('supplier_ledger_entries')->insert([
                'id' => $ledgerId,
                'document_path' => "supplier_ledger_entries/{$ledgerId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'POSTED',
                'created_at' => $dbNow,
                'updated_at' => $dbNow,
                'is_deleted' => 0,
                'payload' => json_encode(['fields' => $ledgerFields]),
            ]);

            return ApiResponse::success([
                'payment' => [
                    'id' => $paymentId,
                    'paymentId' => $paymentId,
                    'voucherNumber' => 'VCH-' . strtoupper(substr($paymentId, -8)),
                    'amount' => $amount,
                    'amountPaidPaise' => $amount,
                    'previousDuePaise' => $currentPayable + $amount,
                    'remainingDuePaise' => $currentPayable,
                    'unallocatedAdvancePaise' => $currentAdvance,
                    'paymentMode' => $mode,
                    'paymentMethod' => $mode,
                    'referenceNumber' => $refNo,
                    'referenceNo' => $refNo,
                    'paymentDate' => $now,
                    'supplierId' => $id,
                    'settledBills' => $settledBills,
                ],
                'balance' => [
                    'totalPayable' => $currentPayable,
                    'unallocatedAdvance' => $currentAdvance,
                ],
            ], 'Supplier payment recorded successfully.');
        });
    }
}
