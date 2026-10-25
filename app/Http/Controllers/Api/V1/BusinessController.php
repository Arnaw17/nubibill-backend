<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\CreateBusinessRequest;
use App\Http\Responses\ApiResponse;
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

        $rows = DB::table('businesses')
            ->where('is_deleted', 0)
            ->get();

        $businesses = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? [];

            $ownerUid = (string) ($fields['ownerUid'] ?? $row->owner_uid ?? '');

            // Non-admin users only see businesses they own
            if (! $user->isAdmin() && $ownerUid !== $userId) {
                continue;
            }

            $isActive = $fields['isActive'] ?? (($fields['status'] ?? $row->status ?? 'active') === 'active');
            if (! $isActive) {
                continue;
            }

            $businessId = (string) ($fields['businessId'] ?? $row->business_id ?? $row->id);
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
                'logoUrl' => $fields['logoUrl'] ?? null,
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
        $businessId = 'BUS-' . strtoupper(Str::random(12));
        $outletId = 'OUT-' . strtoupper(Str::random(12));
        $now = now()->toISOString();
        $nowDb = now();

        $logoPath = null;
        $logoUrl = null;

        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('business_logos', 'public');
            $logoUrl = route('api.v1.businesses.logo', ['id' => $businessId]);
        } elseif ($request->filled('logo_base64')) {
            $base64 = (string) $request->input('logo_base64');
            if (str_contains($base64, ',')) {
                $base64 = substr($base64, strpos($base64, ',') + 1);
            }
            $decoded = base64_decode($base64);
            if ($decoded !== false) {
                $filename = 'business_logos/' . $businessId . '.png';
                Storage::disk('public')->put($filename, $decoded);
                $logoPath = $filename;
                $logoUrl = route('api.v1.businesses.logo', ['id' => $businessId]);
            }
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

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];

        if (! $user->isAdmin() && ($fields['ownerUid'] ?? '') !== (string) $user->id) {
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

        $bPayload = json_decode($businessRow->payload, true) ?: [];
        if (! $user->isAdmin() && ($bPayload['fields']['ownerUid'] ?? '') !== $userId) {
            return ApiResponse::error('Unauthorized access to this business.', 403);
        }

        $rows = DB::table('outlets')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        $outlets = [];
        foreach ($rows as $row) {
            $p = json_decode($row->payload, true) ?: [];
            $f = $p['fields'] ?? [];
            $isActive = $f['isActive'] ?? (($f['status'] ?? $row->status ?? 'active') === 'active');
            if ($isActive) {
                $outlets[] = [
                    'outletId' => (string) ($f['outletId'] ?? $row->outlet_id ?? $row->id),
                    'businessId' => $businessId,
                    'ownerUid' => (string) ($f['ownerUid'] ?? $userId),
                    'tenantId' => (string) ($f['tenantId'] ?? $userId),
                    'outletName' => (string) ($f['outletName'] ?? 'Main Outlet'),
                    'outletTypeId' => (string) ($f['outletTypeId'] ?? 'BST-GENERAL'),
                    'outletTypeName' => (string) ($f['outletTypeName'] ?? 'General Outlet'),
                    'address' => $f['address'] ?? null,
                    'contactNumber' => $f['contactNumber'] ?? null,
                    'isMainOutlet' => (bool) ($f['isMainOutlet'] ?? false),
                    'enableGST' => (bool) ($f['enableGST'] ?? false),
                    'isActive' => true,
                    'status' => 'active',
                ];
            }
        }

        return ApiResponse::success($outlets, 'Outlets retrieved successfully.');
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
        $outletId = 'OUT-' . strtoupper(Str::random(12));
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
     * Get business settings.
     */
    public function settings(Request $request, string $id): JsonResponse
    {
        $row = DB::table('businesses')->where('id', $id)->where('is_deleted', 0)->first();
        if (! $row) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode($row->payload, true) ?: [];
        $settings = $payload['fields']['settings'] ?? [
            'businessId' => $id,
            'invoicePrefix' => 'INV-',
            'nextInvoiceNumber' => 1,
            'defaultPaymentMode' => 'CASH',
            'enableGST' => true,
            'printFormat' => 'THERMAL_3INCH',
            'termsAndConditions' => 'Thank you for your business!',
        ];

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

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? [];
        $newSettings = $request->all();
        $fields['settings'] = array_merge($fields['settings'] ?? [], $newSettings);
        $payload['fields'] = $fields;

        DB::table('businesses')->where('id', $id)->update([
            'payload' => json_encode($payload),
            'updated_at' => now(),
        ]);

        return ApiResponse::success($fields['settings'], 'Business settings saved successfully.');
    }

    /**
     * Stream business logo image.
     */
    public function logo(string $id): StreamedResponse|JsonResponse
    {
        $row = DB::table('businesses')->where('id', $id)->first();
        if (! $row) {
            return ApiResponse::error('Business not found.', 404);
        }

        $payload = json_decode($row->payload, true) ?: [];
        $logoPath = $payload['fields']['logoPath'] ?? null;

        if (! $logoPath || ! Storage::disk('public')->exists($logoPath)) {
            return ApiResponse::error('Business logo not found.', 404);
        }

        return Storage::disk('public')->response($logoPath);
    }
}
