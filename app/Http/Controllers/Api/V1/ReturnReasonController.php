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

        $reasons = ReturnReason::where('is_active', true)
            ->where(function ($q) use ($user) {
                $q->where('is_system', true)
                    ->orWhere('user_id', $user->id);
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
