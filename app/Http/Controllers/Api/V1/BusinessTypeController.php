<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessTypeController extends Controller
{
    /**
     * List all active business types.
     */
    public function types(): JsonResponse
    {
        $rows = DB::table('business_types')
            ->where('is_deleted', 0)
            ->get();

        $types = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            $fields = $payload['fields'] ?? [];
            $id = $fields['id'] ?? $fields['businessTypeId'] ?? $row->id;
            $name = $fields['name'] ?? $id;
            $isActive = $fields['isActive'] ?? (($fields['status'] ?? 'ACTIVE') === 'ACTIVE');
            $displayOrder = $fields['displayOrder'] ?? 0;
            $description = $fields['description'] ?? null;

            if ($isActive) {
                $types[] = [
                    'id' => (string) $id,
                    'name' => (string) $name,
                    'isActive' => true,
                    'displayOrder' => (int) $displayOrder,
                    'description' => $description,
                ];
            }
        }

        usort($types, function ($a, $b) {
            if ($a['displayOrder'] === $b['displayOrder']) {
                return strcasecmp($a['name'], $b['name']);
            }

            return $a['displayOrder'] <=> $b['displayOrder'];
        });

        return ApiResponse::success($types, 'Business types retrieved successfully.');
    }

    /**
     * List active business sub-types, optionally filtered by business_type_id.
     */
    public function subTypes(Request $request): JsonResponse
    {
        $businessTypeId = $request->query('business_type_id') ?? $request->query('businessTypeId');

        $rows = DB::table('business_sub_types')
            ->where('is_deleted', 0)
            ->get();

        $subTypes = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            $fields = $payload['fields'] ?? [];
            $id = $fields['id'] ?? $fields['businessSubTypeId'] ?? $row->id;
            $typeId = $fields['businessTypeId'] ?? '';
            $name = $fields['name'] ?? $id;
            $isActive = $fields['isActive'] ?? (($fields['status'] ?? 'ACTIVE') === 'ACTIVE');
            $displayOrder = $fields['displayOrder'] ?? 0;
            $description = $fields['description'] ?? null;

            if ($isActive) {
                if ($businessTypeId && strtoupper($typeId) !== strtoupper($businessTypeId)) {
                    continue;
                }
                $subTypes[] = [
                    'id' => (string) $id,
                    'businessTypeId' => (string) $typeId,
                    'name' => (string) $name,
                    'isActive' => true,
                    'displayOrder' => (int) $displayOrder,
                    'description' => $description,
                ];
            }
        }

        usort($subTypes, function ($a, $b) {
            if ($a['displayOrder'] === $b['displayOrder']) {
                return strcasecmp($a['name'], $b['name']);
            }

            return $a['displayOrder'] <=> $b['displayOrder'];
        });

        return ApiResponse::success($subTypes, 'Business sub types retrieved successfully.');
    }
}
