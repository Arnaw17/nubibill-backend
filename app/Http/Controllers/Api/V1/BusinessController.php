<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\CreateBusinessRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessController extends Controller
{
    /**
     * List all businesses accessible by the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (string) $user->id;
        $isStaff = strtoupper((string) $user->role) === 'STAFF';
        $assignedBusinessIds = $isStaff ? $user->accessibleBusinessIds() : [];

        $rows = DB::table('businesses')
            ->where('is_deleted', 0)
            ->get();

        $businesses = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            if (is_array($fields)) {
                $fields = SyncController::decodeFields($fields);
            }

            $ownerUid = (string) ($fields['ownerUid'] ?? $row->owner_uid ?? '');

            // Non-admin users only see businesses they own or are assigned to
            $businessId = (string) ($fields['businessId'] ?? $row->business_id ?? $row->id);
            if (! $user->isAdmin() && $ownerUid !== $userId && ! in_array($businessId, $assignedBusinessIds, true)) {
                continue;
            }

            $statusVal = strtolower((string) ($fields['status'] ?? $row->status ?? 'active'));
            $isActive = $fields['isActive'] ?? ($statusVal === 'active');
            if (! $isActive) {
                continue;
            }

            $businessName = (string) ($fields['businessName'] ?? '');
            $normalizedName = (string) ($fields['normalizedBusinessName'] ?? strtolower(trim(preg_replace('/\s+/', ' ', $businessName))));

            $businesses[] = [
                'businessId' => $businessId,
                'ownerUid' => $ownerUid,
                'businessName' => $businessName,
                'normalizedBusinessName' => $normalizedName,
                'businessTypeId' => (string) ($fields['businessTypeId'] ?? $fields['businessType'] ?? ''),
                'businessTypeName' => (string) ($fields['businessTypeName'] ?? $fields['businessType'] ?? 'General'),
                'email' => $fields['email'] ?? null,
                'phone' => $fields['phone'] ?? null,
                'address' => $fields['address'] ?? null,
                'gstin' => $fields['gstin'] ?? null,
                'gstinVerified' => (bool) ($fields['gstinVerified'] ?? false),
                ...self::extractLogoMetadata($fields),
                ...self::extractSignatureMetadata($fields),
                'isActive' => true,
                'status' => 'active',
                'createdAt' => $fields['createdAt'] ?? $row->created_at,
                'updatedAt' => $fields['updatedAt'] ?? $row->updated_at,
            ];
        }

        return ApiResponse::success($businesses, 'Businesses retrieved successfully.');
    }

    /**
     * Create a new business and initialize its primary default outlet.
     */
    public function store(CreateBusinessRequest $request): JsonResponse
    {
        $user = $request->user();
        $ownerUid = (string) $user->id;

        $businessName = trim($request->getBusinessName());
        $normalizedName = strtolower(preg_replace('/\s+/', ' ', $businessName));
        $businessTypeId = trim($request->getBusinessTypeId());
        $email = strtolower(trim((string) $request->email));
        $phone = trim((string) $request->phone);
        $address = trim((string) $request->address);
        $gstin = $request->gstin ? strtoupper(trim((string) $request->gstin)) : null;

        Log::info("POST /api/v1/businesses attempt by User ID: {$user->id} ({$user->email}), Name: {$businessName}");

        // 1. Verify business type exists
        $typeRow = DB::table('business_types')
            ->where('id', $businessTypeId)
            ->where('is_deleted', 0)
            ->first();

        $businessTypeName = 'General';
        if ($typeRow) {
            $typePayload = json_decode($typeRow->payload, true) ?: [];
            $businessTypeName = $typePayload['fields']['name'] ?? $typeRow->id;
        }

        // 2. Check for duplicate business name for this owner
        $existing = DB::table('businesses')
            ->where('is_deleted', 0)
            ->get();

        foreach ($existing as $row) {
            $p = json_decode($row->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            if (($f['ownerUid'] ?? '') === $ownerUid) {
                $existingNormalized = $f['normalizedBusinessName'] ?? strtolower(trim(preg_replace('/\s+/', ' ', $f['businessName'] ?? '')));
                if ($existingNormalized === $normalizedName) {
                    return ApiResponse::error(
                        'A business with this name already exists.',
                        422,
                        ['business_name' => ['A business with this name already exists.']],
                        'BUSINESS_NAME_DUPLICATE'
                    );
                }
            }
        }

        // 3. Generate standard Business ID & Outlet ID
        $businessId = 'BUS-'.strtoupper(Str::random(12));
        $outletId = 'OUT-'.strtoupper(Str::random(12));
        $now = now()->toISOString();
        $nowDb = now();

        $logoPath = null;
        $logoUrl = null;
        $logoVersion = null;
        $logoHash = null;
        $logoMimeType = null;
        $logoSizeBytes = null;
        $logoWidth = null;
        $logoHeight = null;
        $logoHistory = [];

        $initialLogoBytes = null;
        if ($request->hasFile('logo') && $request->file('logo')->isValid()) {
            $initialLogoBytes = file_get_contents($request->file('logo')->getRealPath()) ?: null;
        } elseif ($request->filled('logo_base64')) {
            $base64 = (string) $request->input('logo_base64');
            if (str_contains($base64, ',')) {
                $base64 = substr($base64, strpos($base64, ',') + 1);
            }
            $decoded = base64_decode($base64, true);
            if ($decoded !== false && $decoded !== '') {
                $initialLogoBytes = $decoded;
            }
        }

        if ($initialLogoBytes !== null) {
            $mime = $this->detectImageMimeType($initialLogoBytes);
            $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/webp' => 'webp'];
            $ext = $mime !== null ? ($extMap[$mime] ?? 'png') : 'png';
            $logoVersion = now()->format('YmdHis').'_'.Str::lower(Str::random(6));
            $logoPath = "business_logos/{$businessId}_{$logoVersion}.{$ext}";
            Storage::disk('public')->put($logoPath, $initialLogoBytes);
            $logoUrl = route('api.v1.businesses.logo', ['id' => $businessId], false).'?v='.$logoVersion;
            $logoHash = hash('sha256', $initialLogoBytes);
            $logoMimeType = $mime === 'image/jpg' ? 'image/jpeg' : $mime;
            $logoSizeBytes = strlen($initialLogoBytes);
            $imgInfo = @getimagesizefromstring($initialLogoBytes);
            if (is_array($imgInfo)) {
                $logoWidth = (int) ($imgInfo[0] ?? 0) ?: null;
                $logoHeight = (int) ($imgInfo[1] ?? 0) ?: null;
            }
            $logoHistory[$logoVersion] = [
                'version' => $logoVersion,
                'path' => $logoPath,
                'url' => $logoUrl,
                'hash' => $logoHash,
                'mimeType' => $logoMimeType,
                'sizeBytes' => $logoSizeBytes,
                'width' => $logoWidth,
                'height' => $logoHeight,
                'uploadedAt' => $now,
            ];
        } elseif ($request->filled('logo_url')) {
            $logoUrl = (string) $request->input('logo_url');
        }

        $businessFields = [
            'businessId' => $businessId,
            'ownerUid' => $ownerUid,
            'businessName' => $businessName,
            'normalizedBusinessName' => $normalizedName,
            'businessTypeId' => $businessTypeId,
            'businessTypeName' => $businessTypeName,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'gstin' => $gstin,
            'gstinVerified' => false,
            'logoPath' => $logoPath,
            'logoUrl' => $logoUrl,
            'logoVersion' => $logoVersion,
            'logoHash' => $logoHash,
            'logoMimeType' => $logoMimeType,
            'logoSizeBytes' => $logoSizeBytes,
            'logoWidth' => $logoWidth,
            'logoHeight' => $logoHeight,
            'logoUpdatedAt' => $logoPath !== null ? $now : null,
            'logoHistory' => $logoHistory,
            'signaturePath' => null,
            'signatureUrl' => null,
            'signatureVersion' => null,
            'signatureHash' => null,
            'signatureMimeType' => null,
            'signatureSizeBytes' => null,
            'signatureWidth' => null,
            'signatureHeight' => null,
            'signatureUpdatedAt' => null,
            'signatureEnabled' => false,
            'signatoryName' => null,
            'signatoryDesignation' => null,
            'signatureHistory' => [],
            'isActive' => true,
            'status' => 'active',
            'createdAt' => $now,
            'updatedAt' => $now,
        ];

        DB::transaction(function () use (
            $businessId,
            $ownerUid,
            $normalizedName,
            $businessFields,
            $nowDb
        ) {
            // Save Business
            DB::table('businesses')->insert([
                'id' => $businessId,
                'document_path' => "businesses/$businessId",
                'business_id' => $businessId,
                'outlet_id' => null,
                'status' => 'active',
                'is_deleted' => 0,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
                'payload' => json_encode([
                    '_documentId' => $businessId,
                    '_documentPath' => "businesses/$businessId",
                    'fields' => $businessFields,
                ]),
            ]);

            // Reserve Business Name
            $reservationId = rtrim(strtr(base64_encode("$ownerUid|$normalizedName"), '+/', '-_'), '=');
            DB::table('business_name_reservations')->updateOrInsert(
                ['id' => $reservationId],
                [
                    'document_path' => "business_name_reservations/$reservationId",
                    'status' => 'active',
                    'is_deleted' => 0,
                    'created_at' => $nowDb,
                    'updated_at' => $nowDb,
                    'payload' => json_encode([
                        '_documentId' => $reservationId,
                        '_documentPath' => "business_name_reservations/$reservationId",
                        'fields' => [
                            'ownerUid' => $ownerUid,
                            'normalizedBusinessName' => $normalizedName,
                            'createdAt' => $nowDb->toISOString(),
                        ],
                    ]),
                ]
            );
        });

        return ApiResponse::success([
            'business' => $businessFields,
            'outlet' => null,
        ], 'Business created successfully.', 201);
    }

    /**
     * Show single business.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $row = DB::table('businesses')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;
        if (is_array($fields)) {
            $fields = SyncController::decodeFields($fields);
        }

        $ownerUid = (string) ($fields['ownerUid'] ?? $row->owner_uid ?? '');
        if (! $user->isAdmin() && $ownerUid !== (string) $user->id
            && ! $this->employeeHasBusiness($user, $id)) {
            return ApiResponse::error('Unauthorized access to this business.', 403);
        }

        return ApiResponse::success($fields, 'Business retrieved successfully.');
    }

    /**
     * Get all outlets for a given business.
     */
    public function outlets(Request $request, string $businessId): JsonResponse
    {
        $user = $request->user();
        $userId = (string) $user->id;

        // Verify business ownership
        $businessRow = DB::table('businesses')
            ->where('id', $businessId)
            ->where('is_deleted', 0)
            ->first();

        if (! $businessRow) {
            return ApiResponse::error('Business not found.', 404);
        }

        $bPayload = json_decode((string) $businessRow->payload, true) ?: [];
        $bFields = $bPayload['fields'] ?? $bPayload;
        if (is_array($bFields)) {
            $bFields = SyncController::decodeFields($bFields);
        }
        $employee = $this->employeeFieldsForBusiness($user, $businessId);
        $bOwnerUid = (string) ($bFields['ownerUid'] ?? $businessRow->owner_uid ?? '');
        if (! $user->isAdmin() && $bOwnerUid !== $userId && $employee === null) {
            return ApiResponse::error('Unauthorized access to this business.', 403);
        }
        $businessOwnerUid = $bOwnerUid ?: (string) ($businessRow->owner_uid ?? $userId);

        if (! \Illuminate\Support\Facades\Schema::hasTable('outlets')) {
            return ApiResponse::success([], 'Outlets retrieved successfully.');
        }

        $rows = DB::table('outlets')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        $outlets = [];
        foreach ($rows as $row) {
            $p = json_decode((string) $row->payload, true) ?: [];
            $f = $p['fields'] ?? $p;
            if (is_array($f)) {
                $f = SyncController::decodeFields($f);
            }
            $outletId = (string) ($f['outletId'] ?? $row->outlet_id ?? $row->id);
            if ($employee !== null) {
                $empOutletIds = (array) ($employee['outletIds'] ?? (isset($employee['outletId']) ? [$employee['outletId']] : []));
                if (! in_array($outletId, array_map('strval', $empOutletIds), true)) {
                    continue;
                }
            }
            $statusVal = strtolower((string) ($f['status'] ?? $row->status ?? 'active'));
            $isActive = $f['isActive'] ?? ($statusVal === 'active');
            if ($isActive) {
                $enabledCategories = $f['enabledCategories'] ?? ($p['fields']['enabledCategories'] ?? null);
                if (is_string($enabledCategories)) {
                    $decoded = json_decode($enabledCategories, true);
                    if (is_array($decoded)) {
                        $enabledCategories = $decoded;
                    }
                }
                $extractedCategories = [];
                $flattenCategories = function ($val) use (&$flattenCategories, &$extractedCategories) {
                    if (is_array($val)) {
                        if (isset($val['arrayValue']['values'])) {
                            $flattenCategories($val['arrayValue']['values']);
                        } elseif (isset($val['values'])) {
                            $flattenCategories($val['values']);
                        } elseif (isset($val['stringValue'])) {
                            $extractedCategories[] = (string) $val['stringValue'];
                        } else {
                            foreach ($val as $sub) {
                                $flattenCategories($sub);
                            }
                        }
                    } elseif (is_string($val) && trim($val) !== '') {
                        $extractedCategories[] = trim($val);
                    }
                };
                $flattenCategories($enabledCategories);
                $finalCategories = array_values(array_unique($extractedCategories));

                $outlets[] = [
                    'outletId' => $outletId,
                    'businessId' => $businessId,
                    'ownerUid' => (string) ($f['ownerUid'] ?? $businessOwnerUid),
                    'tenantId' => (string) ($f['tenantId'] ?? $businessOwnerUid),
                    'outletName' => (string) ($f['outletName'] ?? 'Main Outlet'),
                    'outletTypeId' => (string) ($f['outletTypeId'] ?? 'BST-GENERAL'),
                    'outletTypeName' => (string) ($f['outletTypeName'] ?? 'General Outlet'),
                    'address' => $f['address'] ?? null,
                    'contactNumber' => $f['contactNumber'] ?? null,
                    'isMainOutlet' => (bool) ($f['isMainOutlet'] ?? false),
                    'enableGST' => (bool) ($f['enableGST'] ?? false),
                    'enabledCategories' => $finalCategories,
                    'isActive' => true,
                    'status' => 'active',
                ];
            }
        }

        return ApiResponse::success($outlets, 'Outlets retrieved successfully.');
    }

    private function employeeHasBusiness(User $user, string $businessId): bool
    {
        return $this->employeeFieldsForBusiness($user, $businessId) !== null;
    }

    /** @return array<string, mixed>|null */
    private function employeeFieldsForBusiness(User $user, string $businessId): ?array
    {
        if (strtoupper((string) $user->role) !== 'STAFF') {
            return null;
        }

        foreach (DB::table('employees')->where('is_deleted', 0)->get() as $employeeRow) {
            $payload = json_decode((string) $employeeRow->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;
            if (is_array($fields)) {
                $fields = SyncController::decodeFields($fields);
            }
            $empUid = (string) ($fields['uid'] ?? '');
            $empBizIds = (array) ($fields['businessIds'] ?? (isset($fields['businessId']) ? [$fields['businessId']] : []));
            if ($empUid === (string) $user->id && in_array($businessId, array_map('strval', $empBizIds), true)) {
                return $fields;
            }
        }

        return null;
    }

    /**
     * Create a new outlet for the specified business.
     */
    public function storeOutlet(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $userId = (string) $user->id;
        $businessId = $id;

        // 1. Verify business existence and ownership
        $businessRow = DB::table('businesses')
            ->where('id', $businessId)
            ->where('is_deleted', 0)
            ->first();

        if (! $businessRow) {
            return ApiResponse::error('Business not found.', 404);
        }

        $bPayload = json_decode($businessRow->payload, true) ?: [];
        $ownerUid = (string) ($bPayload['fields']['ownerUid'] ?? $businessRow->owner_uid ?? '');
        if (! $user->isAdmin() && $ownerUid !== $userId) {
            return ApiResponse::error('Unauthorized to create outlets for this business.', 403);
        }

        // 2. Validate input
        $request->validate([
            'outlet_name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'outletName' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'outlet_type_id' => ['sometimes', 'required', 'string'],
            'outletTypeId' => ['sometimes', 'required', 'string'],
            'contact_number' => ['sometimes', 'required', 'string', 'min:10', 'max:15'],
            'contactNumber' => ['sometimes', 'required', 'string', 'min:10', 'max:15'],
            'address' => ['required', 'string', 'min:3'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/i'],
            'is_main_outlet' => ['nullable', 'boolean'],
            'isMainOutlet' => ['nullable', 'boolean'],
        ]);

        $outletName = trim((string) ($request->input('outlet_name') ?? $request->input('outletName')));
        $outletTypeId = trim((string) ($request->input('outlet_type_id') ?? $request->input('outletTypeId')));
        $contactNumber = trim((string) ($request->input('contact_number') ?? $request->input('contactNumber')));
        $address = trim((string) $request->input('address'));
        $gstin = $request->filled('gstin') ? strtoupper(trim((string) $request->input('gstin'))) : null;

        // 3. Resolve outlet sub-type name
        $subTypeRow = DB::table('business_sub_types')
            ->where('id', $outletTypeId)
            ->first();

        $outletTypeName = 'General Outlet';
        if ($subTypeRow) {
            $subTypePayload = json_decode($subTypeRow->payload ?? '', true) ?: [];
            $outletTypeName = $subTypePayload['fields']['name'] ?? 'General Outlet';
        }

        // 4. Determine if main outlet
        $existingCount = DB::table('outlets')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->count();

        $isMainOutlet = $request->boolean('is_main_outlet', $request->boolean('isMainOutlet', $existingCount === 0));

        // 5. Generate outlet ID and timestamps
        $outletId = 'OUT-'.strtoupper(Str::random(12));
        $now = now()->toISOString();
        $nowDb = now();

        $outletFields = [
            'outletId' => $outletId,
            'businessId' => $businessId,
            'ownerUid' => $ownerUid,
            'tenantId' => $ownerUid,
            'outletName' => $outletName,
            'outletCode' => $outletId,
            'outletTypeId' => $outletTypeId,
            'outletTypeName' => $outletTypeName,
            'address' => $address,
            'contactNumber' => $contactNumber,
            'gstin' => $gstin,
            'gstinVerified' => false,
            'isMainOutlet' => $isMainOutlet,
            'enableGST' => ! empty($gstin),
            'isActive' => true,
            'status' => 'active',
            'createdAt' => $now,
            'updatedAt' => $now,
        ];

        DB::table('outlets')->insert([
            'id' => $outletId,
            'document_path' => "outlets/$outletId",
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'status' => 'active',
            'is_deleted' => 0,
            'created_at' => $nowDb,
            'updated_at' => $nowDb,
            'payload' => json_encode([
                '_documentId' => $outletId,
                '_documentPath' => "outlets/$outletId",
                'fields' => $outletFields,
            ]),
        ]);

        Log::info("Outlet created: {$outletId} ('{$outletName}') for Business {$businessId} by User {$userId}");

        return ApiResponse::success([
            'outlet' => $outletFields,
        ], 'Outlet created successfully.', 201);
    }

    /**
     * Update an existing outlet.
     */
    public function updateOutlet(Request $request, string $id, string $outletId): JsonResponse
    {
        $user = $request->user();
        $businessId = $id;

        $outletRow = DB::table('outlets')
            ->where('id', $outletId)
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->first();

        if (! $outletRow) {
            return ApiResponse::error('Outlet not found.', 404);
        }

        $payload = json_decode($outletRow->payload ?? '', true) ?: [];
        $fields = $payload['fields'] ?? [];

        if ($request->has('enabledCategories') || $request->has('enabled_categories')) {
            $raw = $request->input('enabledCategories') ?? $request->input('enabled_categories');
            $cats = [];
            if (is_array($raw)) {
                $cats = array_values(array_filter(array_map('strval', $raw)));
            } elseif (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $cats = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : array_values(array_filter(array_map('trim', explode(',', $raw))));
            }
            $fields['enabledCategories'] = $cats;
        }

        if ($request->has('outlet_name') || $request->has('outletName')) {
            $fields['outletName'] = trim((string) ($request->input('outlet_name') ?? $request->input('outletName')));
        }
        if ($request->has('address')) {
            $fields['address'] = trim((string) $request->input('address'));
        }
        if ($request->has('contact_number') || $request->has('contactNumber')) {
            $fields['contactNumber'] = trim((string) ($request->input('contact_number') ?? $request->input('contactNumber')));
        }
        if ($request->has('gstin')) {
            $fields['gstin'] = strtoupper(trim((string) $request->input('gstin')));
        }

        $nowIso = now()->toISOString();
        $fields['updatedAt'] = $nowIso;
        $fields['outletId'] = $outletId;
        $payload['fields'] = $fields;

        DB::table('outlets')
            ->where('id', $outletId)
            ->update([
                'payload' => json_encode($payload),
                'updated_at' => now(),
            ]);

        Log::info("Outlet updated: {$outletId} for Business {$businessId} by User {$user->id}");

        return ApiResponse::success([
            'outlet' => $fields,
        ], 'Outlet updated successfully.');
    }

    /**
     * Get business settings.
     */
    public function settings(Request $request, string $id): JsonResponse
    {
        $row = DB::table('businesses')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (is_array($fields)) {
            $fields = SyncController::decodeFields($fields);
        }

        $settings = $fields['settings'] ?? [
            'businessId' => $id,
            'invoicePrefix' => 'INV-',
            'nextInvoiceNumber' => 1,
            'defaultPaymentMode' => 'CASH',
            'enableGST' => true,
            'printFormat' => 'THERMAL_3INCH',
            'termsAndConditions' => 'Thank you for your business!',
        ];

        $logoMeta = self::extractLogoMetadata($fields);
        $sigMeta = self::extractSignatureMetadata($fields);
        $settings = array_merge($settings, $logoMeta, $sigMeta);

        return ApiResponse::success($settings, 'Business settings retrieved successfully.');
    }

    /**
     * Update business settings.
     */
    public function saveSettings(Request $request, string $id): JsonResponse
    {
        $row = DB::table('businesses')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (is_array($fields)) {
            $fields = SyncController::decodeFields($fields);
        }

        $newSettings = $request->all();
        $fields['settings'] = array_merge($fields['settings'] ?? [], $newSettings);

        $signatureUpdates = [];
        if (array_key_exists('signatureEnabled', $newSettings)) {
            $signatureUpdates['signatureEnabled'] = (bool) filter_var(
                $newSettings['signatureEnabled'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        }
        if (array_key_exists('signatoryName', $newSettings)) {
            $trimmedName = trim((string) ($newSettings['signatoryName'] ?? ''));
            $signatureUpdates['signatoryName'] = $trimmedName === '' ? null : Str::limit($trimmedName, 160, '');
        }
        if (array_key_exists('signatoryDesignation', $newSettings)) {
            $trimmedDesig = trim((string) ($newSettings['signatoryDesignation'] ?? ''));
            $signatureUpdates['signatoryDesignation'] = $trimmedDesig === '' ? null : Str::limit($trimmedDesig, 160, '');
        }
        if (! empty($signatureUpdates)) {
            $nowIso = now()->toISOString();
            $fields = array_merge($fields, $signatureUpdates, ['updatedAt' => $nowIso]);
            $fields['settings'] = array_merge($fields['settings'], $signatureUpdates);
            $this->syncBusinessSettingsFields($id, $signatureUpdates, $nowIso);
        }

        $payload['fields'] = $fields;

        DB::table('businesses')->where('id', $id)->update([
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ]);

        return ApiResponse::success(
            array_merge($fields['settings'], self::extractSignatureMetadata($fields)),
            'Business settings saved successfully.'
        );
    }

    /**
     * Upload or replace a business logo (multipart 'logo' or 'logo_base64').
     */
    public function uploadLogo(Request $request, string $id): JsonResponse
    {
        return $this->uploadBusinessAsset($request, $id, [
            'kind' => 'logo',
            'fileKey' => 'logo',
            'base64Key' => 'logo_base64',
            'directory' => 'business_logos',
            'routeName' => 'api.v1.businesses.logo',
            'historyKey' => 'logoHistory',
            'codePrefix' => 'LOGO',
            'label' => 'Logo',
            'minWidth' => 64,
            'minHeight' => 64,
        ]);
    }

    /**
     * Remove the active business logo while retaining historical logo versions for past bills.
     */
    public function deleteLogo(Request $request, string $id): JsonResponse
    {
        return $this->deleteBusinessAsset($request, $id, 'logo', 'Business logo removed successfully.');
    }

    /**
     * Stream business logo image (supports optional immutable ?v= or ?version= for historical documents).
     */
    public function logo(Request $request, string $id): StreamedResponse|JsonResponse
    {
        return $this->streamBusinessAsset($request, $id, 'logo', 'logoHistory', 'Business logo');
    }

    /**
     * Upload or replace an authorised signature image (multipart 'signature' or 'signature_base64').
     */
    public function uploadSignature(Request $request, string $id): JsonResponse
    {
        $extra = [];
        if ($request->has('signatureEnabled')) {
            $extra['signatureEnabled'] = $request->boolean('signatureEnabled');
        } else {
            $extra['signatureEnabled'] = true;
        }
        if ($request->has('signatoryName')) {
            $name = trim((string) ($request->input('signatoryName') ?? ''));
            $extra['signatoryName'] = $name === '' ? null : Str::limit($name, 160, '');
        }
        if ($request->has('signatoryDesignation')) {
            $desig = trim((string) ($request->input('signatoryDesignation') ?? ''));
            $extra['signatoryDesignation'] = $desig === '' ? null : Str::limit($desig, 160, '');
        }

        return $this->uploadBusinessAsset($request, $id, [
            'kind' => 'signature',
            'fileKey' => 'signature',
            'base64Key' => 'signature_base64',
            'directory' => 'business_signatures',
            'routeName' => 'api.v1.businesses.signature',
            'historyKey' => 'signatureHistory',
            'codePrefix' => 'SIGNATURE',
            'label' => 'Signature',
            'minWidth' => 48,
            'minHeight' => 24,
        ], $extra);
    }

    /**
     * Save signature toggle & signatory metadata (Authorised Signatory Name & Designation).
     */
    public function saveSignatureSettings(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $row = DB::table('businesses')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (is_array($fields)) {
            $fields = SyncController::decodeFields($fields);
        }

        $ownerUid = (string) ($fields['ownerUid'] ?? $row->owner_uid ?? '');
        if (! $user->isAdmin() && $ownerUid !== (string) $user->id
            && ! $this->employeeHasBusiness($user, $id)) {
            return ApiResponse::error('Unauthorized access to this business.', 403);
        }

        $updates = [];
        if ($request->has('signatureEnabled')) {
            $updates['signatureEnabled'] = $request->boolean('signatureEnabled');
        }
        if ($request->has('signatoryName')) {
            $trimmed = trim((string) ($request->input('signatoryName') ?? ''));
            $updates['signatoryName'] = $trimmed === '' ? null : Str::limit($trimmed, 160, '');
        }
        if ($request->has('signatoryDesignation')) {
            $trimmed = trim((string) ($request->input('signatoryDesignation') ?? ''));
            $updates['signatoryDesignation'] = $trimmed === '' ? null : Str::limit($trimmed, 160, '');
        }

        $nowIso = now()->toISOString();
        $fields = array_merge($fields, $updates, ['updatedAt' => $nowIso]);
        $fields['settings'] = array_merge(is_array($fields['settings'] ?? null) ? $fields['settings'] : ['businessId' => $id], $updates);
        $payload['fields'] = $fields;

        DB::table('businesses')->where('id', $id)->update([
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ]);

        $this->syncBusinessSettingsFields($id, $updates, $nowIso);

        return ApiResponse::success(self::extractSignatureMetadata($fields), 'Signature settings updated successfully.');
    }

    /**
     * Remove the active signature while retaining historical signature versions for past bills.
     */
    public function deleteSignature(Request $request, string $id): JsonResponse
    {
        return $this->deleteBusinessAsset(
            $request,
            $id,
            'signature',
            'Authorised signature removed successfully.',
            ['signatureEnabled' => false]
        );
    }

    /**
     * Stream authorised signature image (supports optional immutable ?v= or ?version= for historical documents).
     */
    public function signature(Request $request, string $id): StreamedResponse|JsonResponse
    {
        return $this->streamBusinessAsset($request, $id, 'signature', 'signatureHistory', 'Authorised signature');
    }

    private function uploadBusinessAsset(Request $request, string $id, array $cfg, array $extraFields = []): JsonResponse
    {
        $user = $request->user();
        $row = DB::table('businesses')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (is_array($fields)) {
            $fields = SyncController::decodeFields($fields);
        }

        $ownerUid = (string) ($fields['ownerUid'] ?? $row->owner_uid ?? '');
        if (! $user->isAdmin() && $ownerUid !== (string) $user->id
            && ! $this->employeeHasBusiness($user, $id)) {
            return ApiResponse::error('Unauthorized access to this business.', 403);
        }

        $fileKey = $cfg['fileKey'];
        $base64Key = $cfg['base64Key'];
        $codePrefix = $cfg['codePrefix'];
        $label = $cfg['label'];
        $labelLower = strtolower($label);
        $prefix = $cfg['kind'];
        $minW = (int) $cfg['minWidth'];
        $minH = (int) $cfg['minHeight'];

        $rawBytes = null;
        if ($request->hasFile($fileKey)) {
            $file = $request->file($fileKey);
            if (! $file->isValid()) {
                return ApiResponse::error("Uploaded {$labelLower} file is invalid.", 422, [$fileKey => ["Uploaded {$labelLower} file is invalid."]], "{$codePrefix}_INVALID_FILE");
            }
            $rawBytes = file_get_contents($file->getRealPath());
        } elseif ($request->filled($base64Key)) {
            $base64 = (string) $request->input($base64Key);
            if (str_contains($base64, ',')) {
                $base64 = substr($base64, strpos($base64, ',') + 1);
            }
            $decoded = base64_decode($base64, true);
            if ($decoded === false) {
                return ApiResponse::error('Invalid base64 image payload.', 422, [$base64Key => ['Invalid base64 image payload.']], "{$codePrefix}_INVALID_BASE64");
            }
            $rawBytes = $decoded;
        } else {
            return ApiResponse::error("Please provide a {$labelLower} image file.", 422, [$fileKey => ["A PNG, JPG, JPEG, or WEBP {$labelLower} image is required."]], "{$codePrefix}_REQUIRED");
        }

        if ($rawBytes === false || $rawBytes === '') {
            return ApiResponse::error("{$label} image file is empty.", 422, [$fileKey => ["{$label} image file is empty."]], "{$codePrefix}_EMPTY");
        }

        $maxSizeBytes = 2 * 1024 * 1024; // 2 MB
        $sizeBytes = strlen($rawBytes);
        if ($sizeBytes > $maxSizeBytes) {
            return ApiResponse::error(
                "{$label} image exceeds maximum allowed size of 2 MB.",
                422,
                [$fileKey => ["{$label} file size must not exceed 2 MB."]],
                "{$codePrefix}_TOO_LARGE"
            );
        }

        $mimeType = $this->detectImageMimeType($rawBytes);
        $allowedMimes = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/webp' => 'webp',
        ];

        if ($mimeType === null || ! isset($allowedMimes[$mimeType])) {
            return ApiResponse::error(
                "Unsupported {$labelLower} format. Allowed formats: PNG, JPG, JPEG, WEBP.",
                422,
                [$fileKey => ["{$label} must be a valid PNG, JPG, JPEG, or WEBP image."]],
                "{$codePrefix}_INVALID_FORMAT"
            );
        }

        $imageInfo = @getimagesizefromstring($rawBytes);
        if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
            return ApiResponse::error(
                'Unable to decode image dimensions. Please upload a valid raster image.',
                422,
                [$fileKey => ['Invalid or corrupted image content.']],
                "{$codePrefix}_CORRUPTED"
            );
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        if ($width < $minW || $height < $minH || $width > 4096 || $height > 4096) {
            return ApiResponse::error(
                "{$label} dimensions must be between {$minW}x{$minH} and 4096x4096 pixels.",
                422,
                [$fileKey => ["Image width ({$minW}-4096 px) and height ({$minH}-4096 px) out of range."]],
                "{$codePrefix}_INVALID_DIMENSIONS"
            );
        }

        $ext = $allowedMimes[$mimeType];
        $version = now()->format('YmdHis').'_'.Str::lower(Str::random(6));
        $assetPath = "{$cfg['directory']}/{$id}_{$version}.{$ext}";
        Storage::disk('public')->put($assetPath, $rawBytes);

        $hash = hash('sha256', $rawBytes);
        $assetUrl = route($cfg['routeName'], ['id' => $id], false).'?v='.$version;
        $nowIso = now()->toISOString();
        $normalizedMime = $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType;

        $assetMeta = array_merge([
            "{$prefix}Path" => $assetPath,
            "{$prefix}Url" => $assetUrl,
            "{$prefix}Version" => $version,
            "{$prefix}Hash" => $hash,
            "{$prefix}MimeType" => $normalizedMime,
            "{$prefix}SizeBytes" => $sizeBytes,
            "{$prefix}Width" => $width,
            "{$prefix}Height" => $height,
            "{$prefix}UpdatedAt" => $nowIso,
        ], $extraFields);

        $historyKey = $cfg['historyKey'];
        $history = is_array($fields[$historyKey] ?? null) ? $fields[$historyKey] : [];
        $history[$version] = [
            'version' => $version,
            'path' => $assetPath,
            'url' => $assetUrl,
            'hash' => $hash,
            'mimeType' => $normalizedMime,
            'sizeBytes' => $sizeBytes,
            'width' => $width,
            'height' => $height,
            'uploadedAt' => $nowIso,
        ];

        $fields = array_merge($fields, $assetMeta, [
            $historyKey => $history,
            'updatedAt' => $nowIso,
        ]);
        $fields['settings'] = array_merge(is_array($fields['settings'] ?? null) ? $fields['settings'] : ['businessId' => $id], $assetMeta);
        $payload['fields'] = $fields;

        DB::table('businesses')->where('id', $id)->update([
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ]);

        $this->syncBusinessSettingsFields($id, $assetMeta, $nowIso);

        Log::info("Business {$prefix} uploaded: {$id} v={$version} ({$width}x{$height}, {$sizeBytes} bytes) by User {$user->id}");

        $responseData = $prefix === 'signature'
            ? array_merge($assetMeta, self::extractSignatureMetadata($fields))
            : $assetMeta;

        return ApiResponse::success($responseData, "Business {$prefix} uploaded successfully.");
    }

    private function deleteBusinessAsset(Request $request, string $id, string $prefix, string $message, array $extraFields = []): JsonResponse
    {
        $user = $request->user();
        $row = DB::table('businesses')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (is_array($fields)) {
            $fields = SyncController::decodeFields($fields);
        }

        $ownerUid = (string) ($fields['ownerUid'] ?? $row->owner_uid ?? '');
        if (! $user->isAdmin() && $ownerUid !== (string) $user->id
            && ! $this->employeeHasBusiness($user, $id)) {
            return ApiResponse::error('Unauthorized access to this business.', 403);
        }

        $nowIso = now()->toISOString();
        $clearedMeta = array_merge([
            "{$prefix}Path" => null,
            "{$prefix}Url" => null,
            "{$prefix}Version" => null,
            "{$prefix}Hash" => null,
            "{$prefix}MimeType" => null,
            "{$prefix}SizeBytes" => null,
            "{$prefix}Width" => null,
            "{$prefix}Height" => null,
            "{$prefix}UpdatedAt" => $nowIso,
        ], $extraFields);

        $fields = array_merge($fields, $clearedMeta, ['updatedAt' => $nowIso]);
        if (is_array($fields['settings'] ?? null)) {
            $fields['settings'] = array_merge($fields['settings'], $clearedMeta);
        }
        $payload['fields'] = $fields;

        DB::table('businesses')->where('id', $id)->update([
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ]);

        $this->syncBusinessSettingsFields($id, $clearedMeta, $nowIso);

        Log::info("Business {$prefix} removed: {$id} by User {$user->id}");

        $responseData = $prefix === 'signature'
            ? array_merge($clearedMeta, self::extractSignatureMetadata($fields))
            : $clearedMeta;

        return ApiResponse::success($responseData, $message);
    }

    private function streamBusinessAsset(Request $request, string $id, string $prefix, string $historyKey, string $label): StreamedResponse|JsonResponse
    {
        $row = DB::table('businesses')->where('id', $id)->first();
        if (! $row) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (is_array($fields)) {
            $fields = SyncController::decodeFields($fields);
        }

        $requestedVersion = trim((string) ($request->query('v') ?? $request->query('version') ?? ''));
        $assetPath = null;
        $mimeType = null;
        $isVersioned = false;

        if ($requestedVersion !== '') {
            if (! preg_match('/^[A-Za-z0-9._-]{3,80}$/', $requestedVersion)) {
                return ApiResponse::error("Invalid {$prefix} version identifier.", 400);
            }
            $isVersioned = true;
            $history = is_array($fields[$historyKey] ?? null) ? $fields[$historyKey] : [];
            if (isset($history[$requestedVersion]) && is_array($history[$requestedVersion])) {
                $assetPath = $history[$requestedVersion]['path'] ?? null;
                $mimeType = $history[$requestedVersion]['mimeType'] ?? null;
            } elseif (($fields["{$prefix}Version"] ?? null) === $requestedVersion) {
                $assetPath = $fields["{$prefix}Path"] ?? null;
                $mimeType = $fields["{$prefix}MimeType"] ?? null;
            }
        } else {
            $assetPath = $fields["{$prefix}Path"] ?? null;
            $mimeType = $fields["{$prefix}MimeType"] ?? null;
        }

        if (! $assetPath || ! Storage::disk('public')->exists($assetPath)) {
            return ApiResponse::error("{$label} not found.", 404);
        }

        $headers = [
            'Cache-Control' => $isVersioned ? 'public, max-age=31536000, immutable' : 'no-cache, must-revalidate',
        ];
        if (! empty($mimeType)) {
            $headers['Content-Type'] = $mimeType;
        }

        return Storage::disk('public')->response($assetPath, null, $headers);
    }

    /**
     * Resolve the active logo snapshot fields to stamp onto newly created bills/returns.
     *
     * @return array{hasBusinessLogoSnapshot: bool, businessLogoUrl: ?string, businessLogoPath: ?string, businessLogoVersion: ?string, businessLogoHash: ?string}
     */
    public static function resolveActiveLogoSnapshot(?string $businessId): array
    {
        $empty = [
            'hasBusinessLogoSnapshot' => true,
            'businessLogoUrl' => null,
            'businessLogoPath' => null,
            'businessLogoVersion' => null,
            'businessLogoHash' => null,
        ];

        $businessId = trim((string) $businessId);
        if ($businessId === '') {
            return $empty;
        }

        $row = DB::table('businesses')
            ->where('id', $businessId)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return $empty;
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (is_array($fields)) {
            $fields = SyncController::decodeFields($fields);
        }

        $logoPath = ! empty($fields['logoPath']) ? (string) $fields['logoPath'] : null;
        $logoUrl = ! empty($fields['logoUrl']) ? (string) $fields['logoUrl'] : null;
        $logoVersion = ! empty($fields['logoVersion']) ? (string) $fields['logoVersion'] : null;
        $logoHash = ! empty($fields['logoHash']) ? (string) $fields['logoHash'] : null;

        if ($logoUrl === null && $logoPath !== null) {
            $logoUrl = route('api.v1.businesses.logo', ['id' => $businessId], false)
                .($logoVersion ? ('?v='.$logoVersion) : '');
        }

        return [
            'hasBusinessLogoSnapshot' => true,
            'businessLogoUrl' => $logoUrl,
            'businessLogoPath' => $logoPath,
            'businessLogoVersion' => $logoVersion,
            'businessLogoHash' => $logoHash,
        ];
    }

    /**
     * Resolve the active signature snapshot fields to stamp onto newly created bills/returns.
     *
     * @return array{hasBusinessSignatureSnapshot: bool, businessSignatureEnabled: bool, businessSignatureUrl: ?string, businessSignaturePath: ?string, businessSignatureVersion: ?string, businessSignatureHash: ?string, businessSignatoryName: ?string, businessSignatoryDesignation: ?string}
     */
    public static function resolveActiveSignatureSnapshot(?string $businessId): array
    {
        $empty = [
            'hasBusinessSignatureSnapshot' => true,
            'businessSignatureEnabled' => false,
            'businessSignatureUrl' => null,
            'businessSignaturePath' => null,
            'businessSignatureVersion' => null,
            'businessSignatureHash' => null,
            'businessSignatoryName' => null,
            'businessSignatoryDesignation' => null,
        ];

        $businessId = trim((string) $businessId);
        if ($businessId === '') {
            return $empty;
        }

        $row = DB::table('businesses')
            ->where('id', $businessId)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return $empty;
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        if (is_array($fields)) {
            $fields = SyncController::decodeFields($fields);
        }

        $sigPath = ! empty($fields['signaturePath']) ? (string) $fields['signaturePath'] : null;
        $enabled = (bool) ($fields['signatureEnabled'] ?? false) && $sigPath !== null;
        if (! $enabled) {
            return $empty;
        }

        $sigUrl = ! empty($fields['signatureUrl']) ? (string) $fields['signatureUrl'] : null;
        $sigVersion = ! empty($fields['signatureVersion']) ? (string) $fields['signatureVersion'] : null;
        $sigHash = ! empty($fields['signatureHash']) ? (string) $fields['signatureHash'] : null;

        if ($sigUrl === null) {
            $sigUrl = route('api.v1.businesses.signature', ['id' => $businessId], false)
                .($sigVersion ? ('?v='.$sigVersion) : '');
        }

        return [
            'hasBusinessSignatureSnapshot' => true,
            'businessSignatureEnabled' => true,
            'businessSignatureUrl' => $sigUrl,
            'businessSignaturePath' => $sigPath,
            'businessSignatureVersion' => $sigVersion,
            'businessSignatureHash' => $sigHash,
            'businessSignatoryName' => ! empty($fields['signatoryName']) ? (string) $fields['signatoryName'] : null,
            'businessSignatoryDesignation' => ! empty($fields['signatoryDesignation']) ? (string) $fields['signatoryDesignation'] : null,
        ];
    }

    /**
     * Extract public logo metadata from decoded business fields.
     */
    public static function extractLogoMetadata(array $fields): array
    {
        return [
            'logoPath' => $fields['logoPath'] ?? null,
            'logoUrl' => $fields['logoUrl'] ?? null,
            'logoVersion' => $fields['logoVersion'] ?? null,
            'logoHash' => $fields['logoHash'] ?? null,
            'logoMimeType' => $fields['logoMimeType'] ?? null,
            'logoSizeBytes' => isset($fields['logoSizeBytes']) ? (int) $fields['logoSizeBytes'] : null,
            'logoWidth' => isset($fields['logoWidth']) ? (int) $fields['logoWidth'] : null,
            'logoHeight' => isset($fields['logoHeight']) ? (int) $fields['logoHeight'] : null,
            'logoUpdatedAt' => $fields['logoUpdatedAt'] ?? null,
        ];
    }

    /**
     * Extract public signature metadata from decoded business fields.
     */
    public static function extractSignatureMetadata(array $fields): array
    {
        $sigPath = $fields['signaturePath'] ?? null;

        return [
            'signaturePath' => $sigPath,
            'signatureUrl' => $fields['signatureUrl'] ?? null,
            'signatureVersion' => $fields['signatureVersion'] ?? null,
            'signatureHash' => $fields['signatureHash'] ?? null,
            'signatureMimeType' => $fields['signatureMimeType'] ?? null,
            'signatureSizeBytes' => isset($fields['signatureSizeBytes']) ? (int) $fields['signatureSizeBytes'] : null,
            'signatureWidth' => isset($fields['signatureWidth']) ? (int) $fields['signatureWidth'] : null,
            'signatureHeight' => isset($fields['signatureHeight']) ? (int) $fields['signatureHeight'] : null,
            'signatureUpdatedAt' => $fields['signatureUpdatedAt'] ?? null,
            'signatureEnabled' => (bool) ($fields['signatureEnabled'] ?? false) && ! empty($sigPath),
            'signatoryName' => $fields['signatoryName'] ?? null,
            'signatoryDesignation' => $fields['signatoryDesignation'] ?? null,
        ];
    }

    private function detectImageMimeType(string $bytes): ?string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($bytes);
            if (is_string($detected) && $detected !== '') {
                return strtolower($detected);
            }
        }

        $info = @getimagesizefromstring($bytes);
        if (is_array($info) && ! empty($info['mime'])) {
            return strtolower((string) $info['mime']);
        }

        return null;
    }

    private function syncBusinessSettingsFields(string $businessId, array $meta, string $nowIso): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('business_settings')) {
            return;
        }

        $settingsRow = DB::table('business_settings')
            ->where(function ($q) use ($businessId) {
                $q->where('id', $businessId)->orWhere('business_id', $businessId);
            })
            ->where('is_deleted', 0)
            ->first();

        if (! $settingsRow) {
            return;
        }

        $sPayload = json_decode((string) $settingsRow->payload, true) ?: [];
        $sFields = $sPayload['fields'] ?? [];
        if (is_array($sFields)) {
            $sFields = SyncController::decodeFields($sFields);
        }

        $sFields = array_merge($sFields, $meta, ['updatedAt' => $nowIso]);
        $sPayload['fields'] = $sFields;

        DB::table('business_settings')->where('id', $settingsRow->id)->update([
            'payload' => json_encode($sPayload),
            'updated_at' => now(),
        ]);
    }
}
