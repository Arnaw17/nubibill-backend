<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReturnReasonResource;
use App\Http\Responses\ApiResponse;
use App\Models\ReturnReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReturnReasonController extends Controller
{
    /**
     * List all active return reasons (system defaults and user custom reasons).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (ReturnReason::count() === 0) {
            $defaultReasons = [
                ['name' => 'Damaged / Defective', 'code' => 'DAMAGED', 'is_system' => true, 'is_active' => true],
                ['name' => 'Wrong Item Delivered', 'code' => 'WRONG_ITEM', 'is_system' => true, 'is_active' => true],
                ['name' => 'Expired Product', 'code' => 'EXPIRED', 'is_system' => true, 'is_active' => true],
                ['name' => 'Customer Changed Mind', 'code' => 'CUSTOMER_CHANGED_MIND', 'is_system' => true, 'is_active' => true],
                ['name' => 'Quality Not as Expected', 'code' => 'QUALITY_ISSUE', 'is_system' => true, 'is_active' => true],
                ['name' => 'Other', 'code' => 'OTHER', 'is_system' => true, 'is_active' => true],
            ];
            foreach ($defaultReasons as $r) {
                ReturnReason::firstOrCreate(['code' => $r['code']], $r);
            }
        }

        $reasons = ReturnReason::where('is_active', true)
            ->where(function ($q) use ($user) {
                $q->where('is_system', true);
                if ($user) {
                    $q->orWhere('user_id', $user->id);
                }
            })
            ->orderBy('is_system', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        return ApiResponse::success(
            ReturnReasonResource::collection($reasons),
            'Return reasons retrieved successfully.'
        );
    }

    /**
     * Create a user custom return reason.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:150',
        ]);

        $code = strtoupper(Str::slug($validated['name'], '_'));

        $reason = ReturnReason::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'code' => $code,
            'name' => $validated['name'],
            'is_system' => false,
            'is_active' => true,
        ]);

        return ApiResponse::success(
            new ReturnReasonResource($reason),
            'Return reason created successfully.',
            201
        );
    }
}
