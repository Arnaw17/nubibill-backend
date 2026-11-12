<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\CategoryConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryConfigurationController extends Controller
{
    public function __construct(private CategoryConfigurationService $configService) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('No active business selected or unauthorized.', 403, null, 'FORBIDDEN');
        }

        $user = $request->user();
        $data = $this->configService->getConfiguration(
            $businessId,
            $user?->tenant_id ?? null,
            $user?->id ?? null
        );

        return ApiResponse::success($data, 'Category configuration loaded.');
    }

    public function storeGravelOption(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'attribute_type' => 'required|string',
            'name' => 'required|string|max:120',
            'sort_order' => 'nullable|integer',
        ]);

        $businessId = $this->resolveBusinessId($request, $validated['business_id'] ?? null);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $user = $request->user();
        $option = $this->configService->addGravelOption(
            $businessId,
            $validated['attribute_type'],
            $validated['name'],
            $validated['sort_order'] ?? null,
            $user?->tenant_id ?? null,
            $user?->id ?? null
        );

        return ApiResponse::success($option, 'Gravels option added.', 201);
    }

    public function updateGravelOption(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'name' => 'required|string|max:120',
            'sort_order' => 'nullable|integer',
        ]);

        $businessId = $this->resolveBusinessId($request, $validated['business_id'] ?? null);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $option = $this->configService->updateGravelOption(
            $businessId,
            $id,
            $validated['name'],
            $validated['sort_order'] ?? null,
            $request->user()?->id ?? null
        );

        return ApiResponse::success($option, 'Gravels option updated.');
    }

    public function destroyGravelOption(Request $request, string $id): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $this->configService->deleteGravelOption($businessId, $id, $request->user()?->id ?? null);

        return ApiResponse::success(null, 'Gravels option deleted.');
    }

    public function storeAccessoryField(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'name' => 'required|string|max:120',
            'field_type' => 'nullable|string',
            'fieldType' => 'nullable|string',
            'required' => 'nullable|boolean',
            'default_value' => 'nullable',
            'defaultValue' => 'nullable',
            'options' => 'nullable',
            'measurement_unit_id' => 'nullable|string',
            'measurementUnitId' => 'nullable|string',
            'measurement_unit_symbol' => 'nullable|string',
            'measurementUnitSymbol' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'sortOrder' => 'nullable|integer',
            'level' => 'nullable|string',
        ]);

        $businessId = $this->resolveBusinessId($request, $validated['business_id'] ?? null);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $user = $request->user();
        $field = $this->configService->addAccessoryField(
            $businessId,
            $validated,
            $user?->tenant_id ?? null,
            $user?->id ?? null
        );

        return ApiResponse::success($field, 'Accessory field added.', 201);
    }

    public function updateAccessoryField(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'name' => 'nullable|string|max:120',
            'field_type' => 'nullable|string',
            'fieldType' => 'nullable|string',
            'required' => 'nullable|boolean',
            'default_value' => 'nullable',
            'defaultValue' => 'nullable',
            'options' => 'nullable',
            'measurement_unit_id' => 'nullable|string',
            'measurementUnitId' => 'nullable|string',
            'measurement_unit_symbol' => 'nullable|string',
            'measurementUnitSymbol' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'sortOrder' => 'nullable|integer',
            'level' => 'nullable|string',
        ]);

        $businessId = $this->resolveBusinessId($request, $validated['business_id'] ?? null);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $field = $this->configService->updateAccessoryField(
            $businessId,
            $id,
            $validated,
            $request->user()?->id ?? null
        );

        return ApiResponse::success($field, 'Accessory field updated.');
    }

    public function destroyAccessoryField(Request $request, string $id): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $this->configService->deleteAccessoryField($businessId, $id, $request->user()?->id ?? null);

        return ApiResponse::success(null, 'Accessory field deleted.');
    }

    public function reorderAccessoryFields(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'field_ids' => 'required|array|min:1',
            'field_ids.*' => 'required|string',
        ]);

        $businessId = $this->resolveBusinessId($request, $validated['business_id'] ?? null);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $this->configService->reorderAccessoryFields(
            $businessId,
            $validated['field_ids'],
            $request->user()?->id ?? null
        );

        return ApiResponse::success(null, 'Accessory fields reordered.');
    }

    public function listUnits(Request $request): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        return ApiResponse::success($this->configService->listUnits($businessId), 'Measurement units loaded.');
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'name' => 'required|string|max:80',
            'symbol' => 'required|string|max:30',
            'display_name' => 'nullable|string|max:80',
            'displayName' => 'nullable|string|max:80',
            'allow_fractional' => 'nullable|boolean',
            'allowFractional' => 'nullable|boolean',
            'decimal_precision' => 'nullable|integer|min:0|max:4',
            'decimalPrecision' => 'nullable|integer|min:0|max:4',
            'measurement_dimension' => 'nullable|string|max:30',
            'measurementDimension' => 'nullable|string|max:30',
        ]);

        $businessId = $this->resolveBusinessId($request, $validated['business_id'] ?? null);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $user = $request->user();
        $unit = $this->configService->addCustomUnit(
            $businessId,
            $validated,
            $user?->tenant_id ?? null,
            $user?->id ?? null
        );

        return ApiResponse::success($unit, 'Measurement unit created.', 201);
    }

    public function updateUnit(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => 'nullable|string',
            'name' => 'nullable|string|max:80',
            'symbol' => 'nullable|string|max:30',
            'display_name' => 'nullable|string|max:80',
            'displayName' => 'nullable|string|max:80',
            'allow_fractional' => 'nullable|boolean',
            'allowFractional' => 'nullable|boolean',
            'decimal_precision' => 'nullable|integer|min:0|max:4',
            'decimalPrecision' => 'nullable|integer|min:0|max:4',
            'measurement_dimension' => 'nullable|string|max:30',
            'measurementDimension' => 'nullable|string|max:30',
        ]);

        $businessId = $this->resolveBusinessId($request, $validated['business_id'] ?? null);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $unit = $this->configService->updateCustomUnit(
            $businessId,
            $id,
            $validated,
            $request->user()?->id ?? null
        );

        return ApiResponse::success($unit, 'Measurement unit updated.');
    }

    public function destroyUnit(Request $request, string $id): JsonResponse
    {
        $businessId = $this->resolveBusinessId($request);
        if (! $businessId) {
            return ApiResponse::error('Unauthorized.', 403, null, 'FORBIDDEN');
        }

        $this->configService->deleteCustomUnit($businessId, $id, $request->user()?->id ?? null);

        return ApiResponse::success(null, 'Measurement unit deleted.');
    }

    private function resolveBusinessId(Request $request, ?string $explicit = null): ?string
    {
        $businessId = $explicit
            ?? $request->query('business_id')
            ?? $request->query('businessId')
            ?? $request->input('business_id')
            ?? $request->input('businessId')
            ?? $request->header('X-Business-Id');

        $user = $request->user();
        if (! $businessId && $user) {
            $businessId = $user->defaultBusinessId();
        }
        if (! $businessId || ($user && ! $user->canAccessBusiness((string) $businessId))) {
            return null;
        }

        return (string) $businessId;
    }
}
