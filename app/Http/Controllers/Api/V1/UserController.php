<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    /**
     * Stream user profile photo from private storage.
     */
    public function photo(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $user = User::findOrFail($id);

        if (! $user->photo_path || ! Storage::disk('private')->exists($user->photo_path)) {
            return ApiResponse::error('Profile photo not found.', 404, null, 'PHOTO_NOT_FOUND');
        }

        return Storage::disk('private')->response($user->photo_path);
    }
}
