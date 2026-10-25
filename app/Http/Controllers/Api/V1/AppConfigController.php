<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppConfigController extends Controller
{
    /**
     * Get system application configuration (public endpoint).
     */
    public function show(): JsonResponse
    {
        $row = DB::table('app_config')
            ->where('id', 'system')
            ->where('is_deleted', 0)
            ->first();

        if (! $row) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'isActive' => true,
                    'maintenanceTitle' => 'System Maintenance',
                    'maintenanceMessage' => null,
                    'maintenanceImageUrl' => null,
                    'updatedAt' => now()->toIso8601String(),
                ],
            ]);
        }

        $payload = json_decode($row->payload, true);
        $fields = $payload['fields'] ?? [];

        return response()->json([
            'status' => 'success',
            'data' => [
                'isActive' => (bool) ($fields['isActive'] ?? true),
                'maintenanceTitle' => $fields['maintenanceTitle'] ?? 'System Maintenance',
                'maintenanceMessage' => $fields['maintenanceMessage'] ?? null,
                'maintenanceImageUrl' => $fields['maintenanceImageUrl'] ?? null,
                'updatedAt' => $fields['updatedAt'] ?? $row->updated_at,
            ],
        ]);
    }

    /**
     * Update maintenance mode configuration (Admin only).
     */
    public function updateMaintenance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'maintenance_title' => ['nullable', 'string', 'max:255'],
            'maintenance_message' => ['nullable', 'string', 'max:1000'],
            'maintenance_image_url' => ['nullable', 'url', 'max:500'],
        ]);

        $row = DB::table('app_config')->where('id', 'system')->first();
        $now = now()->toIso8601String();

        $payload = $row ? json_decode($row->payload, true) : [];
        $fields = $payload['fields'] ?? [];

        $fields['isActive'] = (bool) $validated['is_active'];
        $fields['updatedAt'] = $now;

        if (array_key_exists('maintenance_title', $validated)) {
            $fields['maintenanceTitle'] = $validated['maintenance_title'];
        }
        if (array_key_exists('maintenance_message', $validated)) {
            $fields['maintenanceMessage'] = $validated['maintenance_message'];
        }
        if (array_key_exists('maintenance_image_url', $validated)) {
            $fields['maintenanceImageUrl'] = $validated['maintenance_image_url'];
        }

        $payload['fields'] = $fields;
        $payload['_documentId'] = 'system';
        $payload['_documentPath'] = 'app_config/system';

        if ($row) {
            DB::table('app_config')->where('id', 'system')->update([
                'payload' => json_encode($payload),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('app_config')->insert([
                'id' => 'system',
                'document_path' => 'app_config/system',
                'is_deleted' => 0,
                'payload' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Maintenance configuration updated successfully.',
            'data' => [
                'isActive' => $fields['isActive'],
                'maintenanceTitle' => $fields['maintenanceTitle'] ?? null,
                'maintenanceMessage' => $fields['maintenanceMessage'] ?? null,
                'maintenanceImageUrl' => $fields['maintenanceImageUrl'] ?? null,
                'updatedAt' => $now,
            ],
        ]);
    }
}
