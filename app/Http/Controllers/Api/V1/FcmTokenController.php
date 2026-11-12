<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\UserDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FcmTokenController extends Controller
{
    /**
     * Register or refresh the authenticated user's device FCM token.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'nullable|string|in:android,ios,web',
            'device_name' => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:32',
        ]);

        $user = $request->user();
        $platform = strtolower((string) ($validated['platform'] ?? 'android'));

        $deviceToken = UserDeviceToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => (string) $user->id,
                'platform' => $platform,
                'device_name' => $validated['device_name'] ?? $request->header('User-Agent'),
                'app_version' => $validated['app_version'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return ApiResponse::success([
            'id' => $deviceToken->id,
            'platform' => $deviceToken->platform,
            'last_used_at' => $deviceToken->last_used_at?->toISOString(),
        ], 'Device notification token registered.');
    }

    /**
     * Unregister a device FCM token on logout or notification opt-out.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:512',
        ]);

        $deleted = UserDeviceToken::query()
            ->where('user_id', (string) $request->user()->id)
            ->where('token', $validated['token'])
            ->delete();

        return ApiResponse::success([
            'deleted' => $deleted > 0,
        ], 'Device notification token removed.');
    }
}
