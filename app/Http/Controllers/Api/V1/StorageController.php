<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorageController extends Controller
{
    /**
     * List all storage locations (Godowns, Racks, Shelves) with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $type = $request->query('type');
        $parentLocationId = $request->query('parent_location_id') ?? $request->query('parentLocationId');
        $status = strtoupper((string) ($request->query('status') ?? 'ALL'));

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business found or unauthorized.');
        }

        $query = DB::table('storage_locations')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                    ->orWhereNull('outlet_id');
            });
        }

        if ($status !== 'ALL') {
            $query->where('status', $status);
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        $locations = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $itemType = strtoupper($fields['type'] ?? $row->status ?? '');
            if ($type && strtoupper($type) !== $itemType) {
                continue;
            }

            if ($parentLocationId) {
                $parent = $fields['parentLocationId'] ?? $fields['godownId'] ?? $fields['rackId'] ?? null;
                if ($parent !== $parentLocationId) {
                    continue;
                }
            }

            $locations[] = [
                'storageLocationId' => (string) $row->id,
                'id' => (string) $row->id,
                'businessId' => (string) ($row->business_id ?? $fields['businessId'] ?? ''),
                'tenantId' => (string) ($fields['tenantId'] ?? ''),
                'outletId' => $row->outlet_id ?? $fields['outletId'] ?? null,
                'name' => (string) ($fields['name'] ?? $fields['godownName'] ?? ''),
                'code' => (string) ($fields['code'] ?? $fields['godownCode'] ?? ''),
                'type' => $itemType,
                'status' => (string) ($row->status ?? $fields['status'] ?? 'ACTIVE'),
                'parentLocationId' => $fields['parentLocationId'] ?? $fields['godownId'] ?? $fields['rackId'] ?? null,
                'parentLocationType' => $fields['parentLocationType'] ?? null,
                'parentLocationName' => $fields['parentLocationName'] ?? null,
                'parentLocationCode' => $fields['parentLocationCode'] ?? null,
                'rackNumber' => isset($fields['rackNumber']) ? (int) $fields['rackNumber'] : null,
                'godownScope' => $fields['godownScope'] ?? ($row->outlet_id ? 'OUTLET' : 'CENTRAL'),
                'description' => $fields['description'] ?? null,
                'address' => $fields['address'] ?? null,
                'addressDetails' => $fields['addressDetails'] ?? null,
                'capacity' => $fields['capacity'] ?? null,
                'contact' => $fields['contact'] ?? null,
                'godownId' => $fields['godownId'] ?? null,
                'rackId' => $fields['rackId'] ?? null,
                'version' => (int) ($fields['version'] ?? 1),
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        }

        return ApiResponse::success($locations, 'Storage locations retrieved successfully.');
    }

    /**
     * Get a single storage location by ID.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $row = DB::table('storage_locations')
            ->where('id', $id)
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return ApiResponse::error('Storage location not found.', 404, null, 'NOT_FOUND');
        }

        $user = $request->user();
        if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
            return ApiResponse::error('Unauthorized access to this storage location.', 403, null, 'FORBIDDEN');
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;

        $location = [
            'storageLocationId' => (string) $row->id,
            'id' => (string) $row->id,
            'businessId' => (string) ($row->business_id ?? $fields['businessId'] ?? ''),
            'tenantId' => (string) ($fields['tenantId'] ?? ''),
            'outletId' => $row->outlet_id ?? $fields['outletId'] ?? null,
            'name' => (string) ($fields['name'] ?? $fields['godownName'] ?? ''),
            'code' => (string) ($fields['code'] ?? $fields['godownCode'] ?? ''),
            'type' => strtoupper($fields['type'] ?? 'GODOWN'),
            'status' => (string) ($row->status ?? $fields['status'] ?? 'ACTIVE'),
            'parentLocationId' => $fields['parentLocationId'] ?? null,
            'parentLocationType' => $fields['parentLocationType'] ?? null,
            'parentLocationName' => $fields['parentLocationName'] ?? null,
            'parentLocationCode' => $fields['parentLocationCode'] ?? null,
            'rackNumber' => isset($fields['rackNumber']) ? (int) $fields['rackNumber'] : null,
            'godownScope' => $fields['godownScope'] ?? ($row->outlet_id ? 'OUTLET' : 'CENTRAL'),
            'description' => $fields['description'] ?? null,
            'address' => $fields['address'] ?? null,
            'addressDetails' => $fields['addressDetails'] ?? null,
            'capacity' => $fields['capacity'] ?? null,
            'contact' => $fields['contact'] ?? null,
            'godownId' => $fields['godownId'] ?? null,
            'rackId' => $fields['rackId'] ?? null,
            'version' => (int) ($fields['version'] ?? 1),
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];

        return ApiResponse::success($location, 'Storage location details retrieved.');
    }

    /**
     * Create a storage location (Godown, Rack, or Shelf).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'outlet_id' => 'nullable|string',
            'outletId' => 'nullable|string',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'type' => 'required|string|in:GODOWN,RACK,SHELF,godown,rack,shelf',
            'godownScope' => 'nullable|string|in:CENTRAL,OUTLET,central,outlet',
            'description' => 'nullable|string',
            'address' => 'nullable',
            'addressDetails' => 'nullable|array',
            'capacity' => 'nullable|array',
            'contact' => 'nullable|array',
            'parentLocationId' => 'nullable|string',
            'parentLocationType' => 'nullable|string',
            'parentLocationName' => 'nullable|string',
            'parentLocationCode' => 'nullable|string',
            'rackNumber' => 'nullable|integer',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('A valid, authorized business ID is required.', 403, null, 'FORBIDDEN');
        }

        $type = strtoupper($validated['type']);
        $outletId = $validated['outlet_id'] ?? $validated['outletId'] ?? null;
        $name = trim($validated['name']);
        $code = ! empty($validated['code']) ? trim(strtoupper($validated['code'])) : null;
        $now = now()->toIso8601String();
        $nowDateTime = now();

        return DB::transaction(function () use ($businessId, $outletId, $type, $name, $code, $validated, $userId, $now, $nowDateTime) {
            $locationId = 'LOC-'.strtoupper(Str::random(32));

            // Generate or validate Code
            if ($type === 'GODOWN') {
                $scope = strtoupper($validated['godownScope'] ?? ($outletId ? 'OUTLET' : 'CENTRAL'));
                if ($scope === 'OUTLET' && empty($outletId)) {
                    return ApiResponse::error('Select an Outlet for an outlet-scoped Godown.', 422, null, 'VALIDATION_FAILED');
                }

                if (empty($code)) {
                    // Generate sequential godown code
                    $seqKey = "{$businessId}_GODOWN_SEQ";
                    $existingCount = DB::table('storage_locations')
                        ->where('business_id', $businessId)
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.type')) = 'GODOWN'")
                        ->where('is_deleted', 0)
                        ->count();

                    $seqNumber = $existingCount + 1;
                    $code = sprintf('GD-%04d', $seqNumber);

                    // Check if reserved
                    while (DB::table('godown_code_reservations')->where('business_id', $businessId)->where('id', "RES_{$businessId}_{$code}")->exists()) {
                        $seqNumber++;
                        $code = sprintf('GD-%04d', $seqNumber);
                    }
                } else {
                    // Check duplicate godown code
                    $exists = DB::table('storage_locations')
                        ->where('business_id', $businessId)
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.code')) = ?", [$code])
                        ->where('is_deleted', 0)
                        ->exists();

                    if ($exists) {
                        return ApiResponse::error("Godown code '{$code}' already exists in this business.", 422, null, 'DUPLICATE_CODE');
                    }
                }

                // Reserve the code
                DB::table('godown_code_reservations')->insert([
                    'id' => "RES_{$businessId}_{$code}",
                    'document_path' => "godown_code_reservations/RES_{$businessId}_{$code}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'RESERVED',
                    'created_at' => $nowDateTime,
                    'updated_at' => $nowDateTime,
                    'is_deleted' => 0,
                    'payload' => json_encode([
                        'fields' => [
                            'code' => $code,
                            'businessId' => $businessId,
                            'godownId' => $locationId,
                            'createdAt' => $now,
                        ],
                    ]),
                ]);
            } elseif ($type === 'RACK') {
                if (empty($code)) {
                    $code = 'RK-'.strtoupper(substr(md5($name.microtime()), 0, 6));
                }
            } else {
                if (empty($code)) {
                    $code = 'SH-'.strtoupper(substr(md5($name.microtime()), 0, 6));
                }
            }

            $fields = [
                'storageLocationId' => $locationId,
                'id' => $locationId,
                'businessId' => $businessId,
                'tenantId' => $userId,
                'outletId' => $outletId,
                'name' => $name,
                'code' => $code,
                'type' => $type,
                'status' => 'ACTIVE',
                'description' => $validated['description'] ?? null,
                'address' => is_string($validated['address'] ?? null) ? $validated['address'] : null,
                'addressDetails' => $validated['addressDetails'] ?? null,
                'godownScope' => $type === 'GODOWN' ? ($validated['godownScope'] ?? ($outletId ? 'OUTLET' : 'CENTRAL')) : null,
                'capacity' => $validated['capacity'] ?? ($type === 'GODOWN' ? ['enabled' => false] : null),
                'contact' => $validated['contact'] ?? null,
                'parentLocationId' => $validated['parentLocationId'] ?? null,
                'parentLocationType' => $validated['parentLocationType'] ?? null,
                'parentLocationName' => $validated['parentLocationName'] ?? null,
                'parentLocationCode' => $validated['parentLocationCode'] ?? null,
                'rackNumber' => $validated['rackNumber'] ?? null,
                'godownId' => $type === 'RACK' ? $validated['parentLocationId'] ?? null : null,
                'rackId' => $type === 'SHELF' ? $validated['parentLocationId'] ?? null : null,
                'version' => 1,
                'createdBy' => $userId,
                'updatedBy' => $userId,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            $payload = [
                '_documentId' => $locationId,
                '_documentPath' => "storage_locations/{$locationId}",
                'fields' => $fields,
            ];

            DB::table('storage_locations')->insert([
                'id' => $locationId,
                'document_path' => "storage_locations/{$locationId}",
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'ACTIVE',
                'created_at' => $nowDateTime,
                'updated_at' => $nowDateTime,
                'is_deleted' => 0,
                'payload' => json_encode($payload),
            ]);

            return ApiResponse::success([
                'storageLocationId' => $locationId,
                'id' => $locationId,
                'businessId' => $businessId,
                'tenantId' => $userId,
                'outletId' => $outletId,
                'name' => $name,
                'code' => $code,
                'type' => $type,
                'status' => 'ACTIVE',
                'parentLocationId' => $fields['parentLocationId'],
                'parentLocationType' => $fields['parentLocationType'],
                'parentLocationName' => $fields['parentLocationName'],
                'parentLocationCode' => $fields['parentLocationCode'],
                'rackNumber' => $fields['rackNumber'],
                'godownScope' => $fields['godownScope'],
                'description' => $fields['description'],
                'address' => $fields['address'],
                'addressDetails' => $fields['addressDetails'],
                'capacity' => $fields['capacity'],
                'contact' => $fields['contact'],
                'version' => 1,
                'createdAt' => $now,
                'updatedAt' => $now,
            ], "Storage {$type} '{$name}' created successfully.", 201);
        });
    }

    /**
     * Batch create racks for a parent godown or outlet.
     */
    public function batchCreateRacks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'businessId' => 'nullable|string',
            'parentLocationId' => 'required|string',
            'parentLocationType' => 'required|string|in:GODOWN,OUTLET,godown,outlet',
            'count' => 'required|integer|min:1|max:50',
            'prefix' => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $businessId = $validated['business_id'] ?? $validated['businessId'] ?? $request->header('X-Business-Id');
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::error('A valid, authorized business ID is required.', 403, null, 'FORBIDDEN');
        }

        $parentLocationId = $validated['parentLocationId'];
        $parentLocationType = strtoupper($validated['parentLocationType']);
        $count = (int) $validated['count'];
        $prefix = trim($validated['prefix'] ?? 'Rack');

        // Look up parent name and code
        $parentName = 'Parent';
        $parentCode = '';
        $outletId = null;

        if ($parentLocationType === 'GODOWN') {
            $godown = DB::table('storage_locations')->where('id', $parentLocationId)->where('is_deleted', 0)->first();
            $scopeOutletId = $request->input('outlet_id') ?? $request->query('outlet_id') ?? $request->header('X-Outlet-Id');
            if (! $godown || (string) $godown->business_id !== (string) $businessId || ($godown->outlet_id && $scopeOutletId && (string) $godown->outlet_id !== (string) $scopeOutletId)) {
                return ApiResponse::error('Parent godown not found.', 404, null, 'NOT_FOUND');
            }
            $gPayload = json_decode($godown->payload, true) ?: [];
            $gFields = $gPayload['fields'] ?? $gPayload;
            $parentName = $gFields['name'] ?? $gFields['godownName'] ?? 'Godown';
            $parentCode = $gFields['code'] ?? $gFields['godownCode'] ?? '';
            $outletId = $godown->outlet_id ?? $gFields['outletId'] ?? null;
        } else {
            $outlet = DB::table('outlets')->where('id', $parentLocationId)->where('is_deleted', 0)->first();
            if ($outlet) {
                $oPayload = json_decode($outlet->payload, true) ?: [];
                $oFields = $oPayload['fields'] ?? $oPayload;
                $parentName = $oFields['name'] ?? $outlet->name ?? 'Outlet';
                $parentCode = $oFields['code'] ?? $outlet->code ?? '';
                $outletId = (string) $outlet->id;
            }
        }

        return DB::transaction(function () use ($businessId, $outletId, $parentLocationId, $parentLocationType, $parentName, $parentCode, $count, $prefix, $userId) {
            // Find highest existing rack number
            $existingRacks = DB::table('storage_locations')
                ->where('business_id', $businessId)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.parentLocationId')) = ?", [$parentLocationId])
                ->where('is_deleted', 0)
                ->get();

            $maxNumber = 0;
            foreach ($existingRacks as $r) {
                $p = json_decode($r->payload, true) ?: [];
                $f = $p['fields'] ?? $p;
                $num = isset($f['rackNumber']) ? (int) $f['rackNumber'] : 0;
                if ($num > $maxNumber) {
                    $maxNumber = $num;
                }
            }

            $created = [];
            $now = now()->toIso8601String();
            $nowDateTime = now();

            for ($i = 1; $i <= $count; $i++) {
                $rackNumber = $maxNumber + $i;
                $rackName = "{$prefix} {$rackNumber}";
                $rackCode = sprintf('RK-%03d', $rackNumber);
                $locationId = 'LOC-'.strtoupper(Str::random(32));

                $fields = [
                    'storageLocationId' => $locationId,
                    'id' => $locationId,
                    'businessId' => $businessId,
                    'tenantId' => $userId,
                    'outletId' => $outletId,
                    'name' => $rackName,
                    'code' => $rackCode,
                    'type' => 'RACK',
                    'status' => 'ACTIVE',
                    'parentLocationId' => $parentLocationId,
                    'parentLocationType' => $parentLocationType,
                    'parentLocationName' => $parentName,
                    'parentLocationCode' => $parentCode,
                    'rackNumber' => $rackNumber,
                    'godownId' => $parentLocationType === 'GODOWN' ? $parentLocationId : null,
                    'version' => 1,
                    'createdBy' => $userId,
                    'updatedBy' => $userId,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ];

                $payload = [
                    '_documentId' => $locationId,
                    '_documentPath' => "storage_locations/{$locationId}",
                    'fields' => $fields,
                ];

                DB::table('storage_locations')->insert([
                    'id' => $locationId,
                    'document_path' => "storage_locations/{$locationId}",
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'status' => 'ACTIVE',
                    'created_at' => $nowDateTime,
                    'updated_at' => $nowDateTime,
                    'is_deleted' => 0,
                    'payload' => json_encode($payload),
                ]);

                $created[] = [
                    'storageLocationId' => $locationId,
                    'id' => $locationId,
                    'businessId' => $businessId,
                    'tenantId' => $userId,
                    'outletId' => $outletId,
                    'name' => $rackName,
                    'code' => $rackCode,
                    'type' => 'RACK',
                    'status' => 'ACTIVE',
                    'parentLocationId' => $parentLocationId,
                    'parentLocationType' => $parentLocationType,
                    'parentLocationName' => $parentName,
                    'parentLocationCode' => $parentCode,
                    'rackNumber' => $rackNumber,
                    'version' => 1,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ];
            }

            return ApiResponse::success($created, "Successfully created {$count} racks.", 201);
        });
    }

    /**
     * Update storage location.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'address' => 'nullable',
            'addressDetails' => 'nullable|array',
            'capacity' => 'nullable|array',
            'contact' => 'nullable|array',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE,active,inactive',
        ]);

        $user = $request->user();
        $userId = (string) $user->id;

        $row = DB::table('storage_locations')->where('id', $id)->where('is_deleted', 0)->first();
        $businessId = $request->input('business_id') ?? $request->query('business_id') ?? $request->header('X-Business-Id');
        $scopeOutletId = $request->input('outlet_id') ?? $request->query('outlet_id') ?? $request->header('X-Outlet-Id');
        if (! $row || ($businessId && (string) $row->business_id !== (string) $businessId) || ($row->outlet_id && $scopeOutletId && (string) $row->outlet_id !== (string) $scopeOutletId)) {
            return ApiResponse::error('Storage location not found.', 404, null, 'NOT_FOUND');
        }

        if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
            return ApiResponse::error('Unauthorized to access this storage location.', 403, null, 'FORBIDDEN');
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;

        $now = now()->toIso8601String();
        $nowDateTime = now();

        if (isset($validated['name'])) {
            $fields['name'] = trim($validated['name']);
            if (isset($fields['godownName'])) {
                $fields['godownName'] = $fields['name'];
            }
        }
        if (isset($validated['code'])) {
            $fields['code'] = trim(strtoupper($validated['code']));
            if (isset($fields['godownCode'])) {
                $fields['godownCode'] = $fields['code'];
            }
        }
        if (array_key_exists('description', $validated)) {
            $fields['description'] = $validated['description'];
        }
        if (array_key_exists('address', $validated)) {
            $fields['address'] = is_string($validated['address']) ? $validated['address'] : null;
        }
        if (isset($validated['addressDetails'])) {
            $fields['addressDetails'] = $validated['addressDetails'];
        }
        if (isset($validated['capacity'])) {
            $fields['capacity'] = $validated['capacity'];
        }
        if (isset($validated['contact'])) {
            $fields['contact'] = $validated['contact'];
        }
        if (isset($validated['status'])) {
            $fields['status'] = strtoupper($validated['status']);
        }

        $fields['version'] = ((int) ($fields['version'] ?? 1)) + 1;
        $fields['updatedBy'] = $userId;
        $fields['updatedAt'] = $now;

        $payload['fields'] = $fields;

        DB::table('storage_locations')->where('id', $id)->update([
            'status' => $fields['status'] ?? $row->status,
            'updated_at' => $nowDateTime,
            'payload' => json_encode($payload),
        ]);

        return ApiResponse::success([
            'storageLocationId' => (string) $row->id,
            'id' => (string) $row->id,
            'businessId' => (string) $row->business_id,
            'outletId' => $row->outlet_id,
            'name' => $fields['name'] ?? '',
            'code' => $fields['code'] ?? '',
            'type' => $fields['type'] ?? 'GODOWN',
            'status' => $fields['status'] ?? 'ACTIVE',
            'version' => $fields['version'],
            'updatedAt' => $now,
        ], 'Storage location updated successfully.');
    }

    /**
     * Toggle active/inactive status.
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:ACTIVE,INACTIVE,active,inactive',
        ]);

        $status = strtoupper($validated['status']);
        $user = $request->user();
        $userId = (string) $user->id;

        $row = DB::table('storage_locations')->where('id', $id)->where('is_deleted', 0)->first();
        $businessId = $request->input('business_id') ?? $request->query('business_id') ?? $request->header('X-Business-Id');
        $scopeOutletId = $request->input('outlet_id') ?? $request->query('outlet_id') ?? $request->header('X-Outlet-Id');
        if (! $row || ($businessId && (string) $row->business_id !== (string) $businessId) || ($row->outlet_id && $scopeOutletId && (string) $row->outlet_id !== (string) $scopeOutletId)) {
            return ApiResponse::error('Storage location not found.', 404, null, 'NOT_FOUND');
        }

        if ($user && ! $user->canAccessBusiness((string) $row->business_id)) {
            return ApiResponse::error('Unauthorized to access this storage location.', 403, null, 'FORBIDDEN');
        }

        $payload = json_decode($row->payload, true) ?: [];
        $fields = $payload['fields'] ?? $payload;

        $now = now()->toIso8601String();
        $fields['status'] = $status;
        $fields['version'] = ((int) ($fields['version'] ?? 1)) + 1;
        $fields['updatedBy'] = $userId;
        $fields['updatedAt'] = $now;

        $payload['fields'] = $fields;

        DB::table('storage_locations')->where('id', $id)->update([
            'status' => $status,
            'updated_at' => now(),
            'payload' => json_encode($payload),
        ]);

        return ApiResponse::success([
            'storageLocationId' => (string) $row->id,
            'id' => (string) $row->id,
            'status' => $status,
            'version' => $fields['version'],
            'updatedAt' => $now,
        ], "Storage location status updated to {$status}.");
    }

    /**
     * Stock buckets per location (godown, rack, outlet).
     */
    public function stockBuckets(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $locationType = strtoupper((string) ($request->query('location_type') ?? $request->query('locationType') ?? 'OUTLET'));
        $locationId = $request->query('location_id') ?? $request->query('locationId');
        $variantId = $request->query('variant_id') ?? $request->query('variantId');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business found or unauthorized.');
        }

        $query = DB::table('stock_balances')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($locationType === 'OUTLET' && $locationId) {
            $query->where('outlet_id', $locationId);
        }

        $rows = $query->get();
        $buckets = [];

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $fields = $payload['fields'] ?? $payload;

            $vId = $fields['variantId'] ?? $fields['productId'] ?? null;
            if ($variantId && $vId !== $variantId) {
                continue;
            }

            $buckets[] = [
                'id' => (string) $row->id,
                'businessId' => $businessId,
                'outletId' => $row->outlet_id ?? $fields['outletId'] ?? null,
                'locationType' => $fields['locationType'] ?? $locationType,
                'locationId' => $fields['locationId'] ?? $locationId ?? $row->outlet_id,
                'variantId' => $vId,
                'productId' => $fields['productId'] ?? $vId,
                'quantityOnHand' => (int) ($fields['quantityOnHand'] ?? $fields['currentStock'] ?? 0),
                'avgCostPaise' => (int) ($fields['avgCostPaise'] ?? 0),
                'lastPurchasePricePaise' => (int) ($fields['lastPurchasePricePaise'] ?? 0),
                'updatedAt' => $row->updated_at,
            ];
        }

        return ApiResponse::success($buckets, 'Stock buckets retrieved.');
    }
}
