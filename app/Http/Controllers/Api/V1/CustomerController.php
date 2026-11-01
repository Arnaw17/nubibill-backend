<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * List customers for a business with balances and search filter.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));
        $phone = trim((string) ($request->query('phone') ?? ''));
        $statusFilter = strtoupper((string) ($request->query('status') ?? 'ACTIVE'));

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        // 1. Fetch customer balances for this business
        $balanceRows = DB::table('customer_balances')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->when($outletId, fn ($query) => $query->where(function ($scope) use ($outletId) {
                $scope->where('outlet_id', $outletId)->orWhereNull('outlet_id')->orWhere('outlet_id', '');
            }))
            ->get();

        $balancesByCustomer = [];
        foreach ($balanceRows as $bRow) {
            $bPayload = json_decode($bRow->payload, true) ?: [];
            $bFields = $bPayload['fields'] ?? [];
            $cId = (string) ($bFields['customerId'] ?? '');
            if ($cId) {
                $balancesByCustomer[$cId] = [
                    'duePaise' => (int) ($bFields['duePaise'] ?? 0),
                    'advancePaise' => (int) ($bFields['advancePaise'] ?? 0),
                ];
            }
        }

        // 2. Fetch customers for this business
        $query = DB::table('customers')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId) {
            $query->where(function ($scope) use ($outletId) {
                $scope->where('outlet_id', $outletId)->orWhereNull('outlet_id')->orWhere('outlet_id', '');
            });
        }

        if ($statusFilter !== 'ALL') {
            $query->where(function ($q) use ($statusFilter) {
                $q->where('status', $statusFilter)
                    ->orWhereNull('status')
                    ->orWhere('status', '');
            });
        }

        $rows = $query->get();

        $customers = [];
        $searchLower = strtolower($search);
        $phoneNorm = preg_replace('/\D/', '', $phone);

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];

            $cId = (string) ($fields['customerId'] ?? $row->id);
            $name = (string) ($fields['customerName'] ?? $fields['name'] ?? '');
            $cPhone = (string) ($fields['phone'] ?? '');
            $cPhoneNorm = preg_replace('/\D/', '', $cPhone);
            $gstin = $fields['gstin'] ?? null;
            $address = $fields['address'] ?? null;
            $stateCode = $fields['stateCode'] ?? null;
            $creditLimit = isset($fields['creditLimitPaise']) ? (int) $fields['creditLimitPaise'] : null;

            // Phone exact filter
            if ($phoneNorm !== '' && ! str_contains($cPhoneNorm, $phoneNorm)) {
                continue;
            }

            // Keyword search
            if ($searchLower !== '') {
                $matches = str_contains(strtolower($name), $searchLower)
                    || str_contains($cPhoneNorm, $searchLower)
                    || ($gstin && str_contains(strtolower($gstin), $searchLower))
                    || ($address && str_contains(strtolower($address), $searchLower));

                if (! $matches) {
                    continue;
                }
            }

            $bal = $balancesByCustomer[$cId] ?? [
                'duePaise' => (int) ($fields['duePaise'] ?? 0),
                'advancePaise' => (int) ($fields['advancePaise'] ?? 0),
            ];

            $customers[] = [
                'customerId' => $cId,
                'businessId' => $businessId,
                'name' => $name,
                'customerName' => $name,
                'nameLower' => strtolower($name),
                'phone' => $cPhone,
                'email' => $fields['email'] ?? null,
                'gstin' => $gstin,
                'address' => $address,
                'stateCode' => $stateCode,
                'creditLimitPaise' => $creditLimit,
                'duePaise' => $bal['duePaise'],
                'advancePaise' => $bal['advancePaise'],
                'status' => (string) ($fields['status'] ?? 'ACTIVE'),
                'createdAt' => $fields['createdAt'] ?? $row->created_at,
                'updatedAt' => $fields['updatedAt'] ?? $row->updated_at,
            ];
        }

        return ApiResponse::success($customers, 'Customers retrieved successfully.');
    }

    /**
     * Show single customer by ID with balance.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $row = DB::table('customers')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Customer not found.', 404);
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        $businessId = (string) ($fields['businessId'] ?? $row->business_id ?? '');

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness($businessId)) {
            return ApiResponse::error('Unauthorized access to this customer.', 403, null, 'FORBIDDEN');
        }

        // Fetch balance
        $balRow = DB::table('customer_balances')
            ->where('payload->fields->customerId', $id)
            ->first();

        $duePaise = (int) ($fields['duePaise'] ?? 0);
        $advancePaise = (int) ($fields['advancePaise'] ?? 0);

        if ($balRow) {
            $bp = json_decode($balRow->payload, true) ?: [];
            $bf = $bp['fields'] ?? [];
            $duePaise = (int) ($bf['duePaise'] ?? $duePaise);
            $advancePaise = (int) ($bf['advancePaise'] ?? $advancePaise);
        }

        $name = (string) ($fields['customerName'] ?? $fields['name'] ?? '');

        $customer = [
            'customerId' => $id,
            'businessId' => $businessId,
            'name' => $name,
            'customerName' => $name,
            'nameLower' => strtolower($name),
            'phone' => (string) ($fields['phone'] ?? ''),
            'email' => $fields['email'] ?? null,
            'gstin' => $fields['gstin'] ?? null,
            'address' => $fields['address'] ?? null,
            'stateCode' => $fields['stateCode'] ?? null,
            'creditLimitPaise' => isset($fields['creditLimitPaise']) ? (int) $fields['creditLimitPaise'] : null,
            'duePaise' => $duePaise,
            'advancePaise' => $advancePaise,
            'status' => (string) ($fields['status'] ?? 'ACTIVE'),
            'createdAt' => $fields['createdAt'] ?? $row->created_at,
            'updatedAt' => $fields['updatedAt'] ?? $row->updated_at,
        ];

        return ApiResponse::success($customer, 'Customer retrieved successfully.');
    }

    /**
     * Create a new customer profile.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (string) $user->id;

        $request->validate([
            'business_id' => ['sometimes', 'required', 'string'],
            'businessId' => ['sometimes', 'required', 'string'],
            'outlet_id' => ['nullable', 'string'],
            'outletId' => ['nullable', 'string'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'customer_name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[6-9][0-9]{9}$/'],
            'email' => ['nullable', 'email'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/i'],
            'address' => ['nullable', 'string', 'max:500'],
            'state_code' => ['nullable', 'string', 'max:5'],
            'stateCode' => ['nullable', 'string', 'max:5'],
            'credit_limit_paise' => ['nullable', 'integer', 'min:0'],
            'creditLimitPaise' => ['nullable', 'integer', 'min:0'],
            'opening_balance_paise' => ['nullable', 'integer'],
            'openingBalancePaise' => ['nullable', 'integer'],
        ]);

        $businessId = trim((string) ($request->input('business_id') ?? $request->input('businessId') ?? ''));
        $outletId = $request->input('outlet_id') ?? $request->input('outletId');
        $name = trim((string) ($request->input('name') ?? $request->input('customer_name') ?? ''));
        $phone = preg_replace('/\D/', '', (string) $request->input('phone'));
        $email = $request->filled('email') ? strtolower(trim((string) $request->input('email'))) : null;
        $gstin = $request->filled('gstin') ? strtoupper(trim((string) $request->input('gstin'))) : null;
        $address = $request->filled('address') ? trim((string) $request->input('address')) : null;
        $stateCode = $request->input('state_code') ?? $request->input('stateCode');
        $creditLimitPaise = $request->input('credit_limit_paise') ?? $request->input('creditLimitPaise');
        $openingBalance = (int) ($request->input('opening_balance_paise') ?? $request->input('openingBalancePaise') ?? 0);

        // Check phone duplication within the same business
        $duplicate = DB::table('customers')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->where(function ($q) use ($phone) {
                $q->where('payload->fields->phone', $phone)
                    ->orWhere('payload->fields->phone', "+91$phone");
            })
            ->first();

        if ($duplicate) {
            return ApiResponse::error('A customer with this phone number already exists.', 422, 'CUSTOMER_PHONE_DUPLICATE', [
                'phone' => ['A customer with this phone number already exists.'],
            ]);
        }

        $customerId = 'CUS-'.strtoupper(Str::random(12));
        $duePaise = $openingBalance > 0 ? $openingBalance : 0;
        $advancePaise = $openingBalance < 0 ? abs($openingBalance) : 0;
        $now = now()->toISOString();
        $nowDb = now();

        $customerFields = [
            'customerId' => $customerId,
            'businessId' => $businessId,
            'outletId' => $outletId,
            'tenantId' => $userId,
            'name' => $name,
            'customerName' => $name,
            'nameLower' => strtolower($name),
            'phone' => $phone,
            'email' => $email,
            'gstin' => $gstin,
            'address' => $address,
            'stateCode' => $stateCode,
            'creditLimitPaise' => $creditLimitPaise ? (int) $creditLimitPaise : null,
            'duePaise' => $duePaise,
            'advancePaise' => $advancePaise,
            'status' => 'ACTIVE',
            'createdAt' => $now,
            'updatedAt' => $now,
            'createdBy' => $userId,
            'updatedBy' => $userId,
        ];

        DB::transaction(function () use (
            $customerId,
            $businessId,
            $outletId,
            $userId,
            $nowDb,
            $now,
            $customerFields,
            $duePaise,
            $advancePaise,
            $openingBalance
        ) {
            // 1. Insert customer record
            DB::table('customers')->insert([
                'id' => $customerId,
                'document_path' => "customers/$customerId",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'ACTIVE',
                'is_deleted' => 0,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
                'payload' => json_encode([
                    '_documentId' => $customerId,
                    '_documentPath' => "customers/$customerId",
                    'fields' => $customerFields,
                ]),
            ]);

            // 2. Insert initial balance record
            $balanceDocId = "{$businessId}_{$outletId}_{$customerId}";
            DB::table('customer_balances')->updateOrInsert(
                ['id' => $balanceDocId],
                [
                    'document_path' => "customer_balances/$balanceDocId",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $nowDb,
                    'updated_at' => $nowDb,
                    'payload' => json_encode([
                        '_documentId' => $balanceDocId,
                        '_documentPath' => "customer_balances/$balanceDocId",
                        'fields' => [
                            'customerId' => $customerId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'tenantId' => $userId,
                            'duePaise' => $duePaise,
                            'advancePaise' => $advancePaise,
                            'updatedAt' => $now,
                        ],
                    ]),
                ]
            );

            // 3. If opening balance != 0, insert opening ledger entry
            if ($openingBalance !== 0) {
                $ledgerId = 'LED-'.strtoupper(Str::random(16));
                $debitPaise = $openingBalance > 0 ? $openingBalance : 0;
                $creditPaise = $openingBalance < 0 ? abs($openingBalance) : 0;

                DB::table('customer_ledger_entries')->insert([
                    'id' => $ledgerId,
                    'document_path' => "customer_ledger_entries/$ledgerId",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $nowDb,
                    'updated_at' => $nowDb,
                    'payload' => json_encode([
                        '_documentId' => $ledgerId,
                        '_documentPath' => "customer_ledger_entries/$ledgerId",
                        'fields' => [
                            'ledgerId' => $ledgerId,
                            'customerId' => $customerId,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'tenantId' => $userId,
                            'description' => 'Opening Balance',
                            'debitPaise' => $debitPaise,
                            'creditPaise' => $creditPaise,
                            'balancePaise' => $openingBalance,
                            'immutable' => true,
                            'createdAt' => $now,
                            'updatedAt' => $now,
                        ],
                    ]),
                ]);
            }
        });

        Log::info("Customer created: {$customerId} ('{$name}') for Business {$businessId}");

        return ApiResponse::success([
            'customer' => $customerFields,
        ], 'Customer created successfully.', 201);
    }

    /**
     * Update customer profile.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $userId = (string) $user->id;

        $row = DB::table('customers')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Customer not found.', 404);
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        $businessId = (string) ($fields['businessId'] ?? $row->business_id ?? '');

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness($businessId)) {
            return ApiResponse::error('Unauthorized access to this customer.', 403, null, 'FORBIDDEN');
        }

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'customer_name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'regex:/^[6-9][0-9]{9}$/'],
            'email' => ['nullable', 'email'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/i'],
            'address' => ['nullable', 'string', 'max:500'],
            'state_code' => ['nullable', 'string', 'max:5'],
            'stateCode' => ['nullable', 'string', 'max:5'],
            'credit_limit_paise' => ['nullable', 'integer', 'min:0'],
            'creditLimitPaise' => ['nullable', 'integer', 'min:0'],
        ]);

        $name = trim((string) ($request->input('name') ?? $request->input('customer_name') ?? $fields['name'] ?? $fields['customerName']));
        $phone = $request->has('phone') ? preg_replace('/\D/', '', (string) $request->input('phone')) : ($fields['phone'] ?? '');

        // Check phone duplicate on other customer
        if ($phone) {
            $duplicate = DB::table('customers')
                ->where('business_id', $businessId)
                ->where('id', '!=', $id)
                ->where('is_deleted', 0)
                ->where(function ($q) use ($phone) {
                    $q->where('payload->fields->phone', $phone)
                        ->orWhere('payload->fields->phone', "+91$phone");
                })
                ->first();

            if ($duplicate) {
                return ApiResponse::error('A customer with this phone number already exists.', 422, 'CUSTOMER_PHONE_DUPLICATE', [
                    'phone' => ['A customer with this phone number already exists.'],
                ]);
            }
        }

        $email = $request->has('email') ? ($request->filled('email') ? strtolower(trim((string) $request->input('email'))) : null) : ($fields['email'] ?? null);
        $gstin = $request->has('gstin') ? ($request->filled('gstin') ? strtoupper(trim((string) $request->input('gstin'))) : null) : ($fields['gstin'] ?? null);
        $address = $request->has('address') ? ($request->filled('address') ? trim((string) $request->input('address')) : null) : ($fields['address'] ?? null);
        $stateCode = $request->input('state_code') ?? $request->input('stateCode') ?? ($fields['stateCode'] ?? null);
        $creditLimit = $request->has('credit_limit_paise') || $request->has('creditLimitPaise')
            ? (int) ($request->input('credit_limit_paise') ?? $request->input('creditLimitPaise'))
            : ($fields['creditLimitPaise'] ?? null);

        $now = now()->toISOString();
        $nowDb = now();

        $updatedFields = array_merge($fields, [
            'name' => $name,
            'customerName' => $name,
            'nameLower' => strtolower($name),
            'phone' => $phone,
            'email' => $email,
            'gstin' => $gstin,
            'address' => $address,
            'stateCode' => $stateCode,
            'creditLimitPaise' => $creditLimit,
            'updatedAt' => $now,
            'updatedBy' => $userId,
        ]);

        DB::table('customers')->where('id', $id)->update([
            'updated_at' => $nowDb,
            'payload' => json_encode([
                '_documentId' => $id,
                '_documentPath' => "customers/$id",
                'fields' => $updatedFields,
            ]),
        ]);

        return ApiResponse::success([
            'customer' => $updatedFields,
        ], 'Customer updated successfully.');
    }

    /**
     * Soft delete customer.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = DB::table('customers')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Customer not found.', 404);
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
            return ApiResponse::error('Unauthorized to delete this customer.', 403, null, 'FORBIDDEN');
        }

        $nowDb = now();

        DB::table('customers')
            ->where('id', $id)
            ->update([
                'is_deleted' => 1,
                'status' => 'INACTIVE',
                'updated_at' => $nowDb,
            ]);

        return ApiResponse::success(null, 'Customer deleted successfully.');
    }

    /**
     * Get Customer Khata (Credit Ledger) entries.
     */
    public function ledger(Request $request, string $id): JsonResponse
    {
        $customer = DB::table('customers')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $customer) {
            return ApiResponse::error('Customer not found.', 404);
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $customer->business_id)) {
            return ApiResponse::error('Unauthorized access to this customer ledger.', 403, null, 'FORBIDDEN');
        }

        $rows = DB::table('customer_ledger_entries')
            ->where('payload->fields->customerId', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        $entries = [];
        foreach ($rows as $row) {
            $p = json_decode($row->payload, true) ?: [];
            $f = $p['fields'] ?? [];

            $entries[] = [
                'ledgerId' => (string) ($f['ledgerId'] ?? $row->id),
                'customerId' => $id,
                'invoiceId' => $f['invoiceId'] ?? null,
                'paymentId' => $f['paymentId'] ?? null,
                'description' => (string) ($f['description'] ?? 'Transaction'),
                'debitPaise' => (int) ($f['debitPaise'] ?? 0),
                'creditPaise' => (int) ($f['creditPaise'] ?? 0),
                'balancePaise' => (int) ($f['balancePaise'] ?? 0),
                'createdAt' => $f['createdAt'] ?? $row->created_at,
            ];
        }

        return ApiResponse::success($entries, 'Customer ledger entries retrieved successfully.');
    }

    /**
     * Record a customer due collection or advance payment.
     */
    public function recordPayment(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $userId = (string) $user->id;

        $request->validate([
            'amount_paise' => ['required', 'integer', 'min:1'],
            'payment_mode' => ['nullable', 'string', 'in:CASH,UPI,CARD,CHEQUE,BANK_TRANSFER'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $amountPaise = (int) $request->input('amount_paise');
        $paymentMode = strtoupper((string) ($request->input('payment_mode') ?? 'CASH'));
        $refNumber = $request->input('reference_number');
        $notes = $request->input('notes') ?? 'Due Collection Payment';

        $customerRow = DB::table('customers')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

        if (! $customerRow) {
            return ApiResponse::error('Customer not found.', 404);
        }

        if ($user && ! $user->canAccessBusiness((string) $customerRow->business_id)) {
            return ApiResponse::error('Unauthorized access to this customer.', 403, null, 'FORBIDDEN');
        }

        $cPayload = json_decode($customerRow->payload, true) ?: [];
        $cFields = $cPayload['fields'] ?? [];
        $businessId = (string) ($cFields['businessId'] ?? $customerRow->business_id ?? '');
        $outletId = (string) ($cFields['outletId'] ?? $customerRow->outlet_id ?? '');

        $now = now()->toISOString();
        $nowDb = now();

        $result = DB::transaction(function () use (
            $id,
            $businessId,
            $outletId,
            $userId,
            $amountPaise,
            $paymentMode,
            $refNumber,
            $notes,
            $now,
            $nowDb,
            $cFields
        ) {
            // 1. Get current balance
            $balRow = DB::table('customer_balances')
                ->where('payload->fields->customerId', $id)
                ->lockForUpdate()
                ->first();

            $currDue = (int) ($cFields['duePaise'] ?? 0);
            $currAdvance = (int) ($cFields['advancePaise'] ?? 0);

            if ($balRow) {
                $bp = json_decode($balRow->payload, true) ?: [];
                $bf = $bp['fields'] ?? [];
                $currDue = (int) ($bf['duePaise'] ?? $currDue);
                $currAdvance = (int) ($bf['advancePaise'] ?? $currAdvance);
            }

            // 2. Apply payment to balance
            if ($amountPaise <= $currDue) {
                $newDue = $currDue - $amountPaise;
                $newAdvance = $currAdvance;
            } else {
                $remaining = $amountPaise - $currDue;
                $newDue = 0;
                $newAdvance = $currAdvance + $remaining;
            }

            // Update balance record
            $balanceDocId = $balRow ? $balRow->id : "{$businessId}_{$outletId}_{$id}";
            DB::table('customer_balances')->updateOrInsert(
                ['id' => $balanceDocId],
                [
                    'document_path' => "customer_balances/$balanceDocId",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'ACTIVE',
                    'is_deleted' => 0,
                    'created_at' => $nowDb,
                    'updated_at' => $nowDb,
                    'payload' => json_encode([
                        '_documentId' => $balanceDocId,
                        '_documentPath' => "customer_balances/$balanceDocId",
                        'fields' => [
                            'customerId' => $id,
                            'businessId' => $businessId,
                            'outletId' => $outletId,
                            'tenantId' => $userId,
                            'duePaise' => $newDue,
                            'advancePaise' => $newAdvance,
                            'updatedAt' => $now,
                        ],
                    ]),
                ]
            );

            // 3. Create payment record
            $paymentId = 'PAY-'.strtoupper(Str::random(12));
            DB::table('payments')->insert([
                'id' => $paymentId,
                'document_path' => "payments/$paymentId",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'COMPLETED',
                'is_deleted' => 0,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
                'payload' => json_encode([
                    '_documentId' => $paymentId,
                    '_documentPath' => "payments/$paymentId",
                    'fields' => [
                        'paymentId' => $paymentId,
                        'customerId' => $id,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'tenantId' => $userId,
                        'amountPaise' => $amountPaise,
                        'paymentMode' => $paymentMode,
                        'referenceNumber' => $refNumber,
                        'notes' => $notes,
                        'status' => 'COMPLETED',
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ],
                ]),
            ]);

            // 4. Create ledger entry
            $ledgerId = 'LED-'.strtoupper(Str::random(16));
            DB::table('customer_ledger_entries')->insert([
                'id' => $ledgerId,
                'document_path' => "customer_ledger_entries/$ledgerId",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'ACTIVE',
                'is_deleted' => 0,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
                'payload' => json_encode([
                    '_documentId' => $ledgerId,
                    '_documentPath' => "customer_ledger_entries/$ledgerId",
                    'fields' => [
                        'ledgerId' => $ledgerId,
                        'customerId' => $id,
                        'businessId' => $businessId,
                        'outletId' => $outletId,
                        'paymentId' => $paymentId,
                        'tenantId' => $userId,
                        'description' => "Payment Received ({$paymentMode})",
                        'debitPaise' => 0,
                        'creditPaise' => $amountPaise,
                        'balancePaise' => $newDue - $newAdvance,
                        'immutable' => true,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ],
                ]),
            ]);

            return [
                'duePaise' => $newDue,
                'advancePaise' => $newAdvance,
                'paymentId' => $paymentId,
                'ledgerId' => $ledgerId,
            ];
        });

        return ApiResponse::success($result, 'Payment recorded and ledger updated successfully.');
    }
}
