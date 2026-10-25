<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewDocumentRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    /**
     * Update user activation status.
     * Admin can only set ACTIVE if Aadhaar and PAN are APPROVED and email is verified.
     */
    public function updateUserStatus(UpdateUserStatusRequest $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $targetStatus = strtoupper($request->status);

        if ($targetStatus === 'ACTIVE') {
            if (! $user->email_verified_at) {
                return ApiResponse::error(
                    'Cannot activate user whose email has not been verified.',
                    422,
                    null,
                    'ACTIVATION_PREREQUISITE_FAILED'
                );
            }

            $aadhaar = $user->aadhaarDocument;
            if (! $aadhaar || ! $aadhaar->isApproved()) {
                return ApiResponse::error(
                    'Cannot activate user until their Aadhaar card is approved.',
                    422,
                    null,
                    'ACTIVATION_PREREQUISITE_FAILED'
                );
            }

            $pan = $user->panDocument;
            if (! $pan || ! $pan->isApproved()) {
                return ApiResponse::error(
                    'Cannot activate user until their PAN card is approved.',
                    422,
                    null,
                    'ACTIVATION_PREREQUISITE_FAILED'
                );
            }

            $user->status = 'ACTIVE';
            $user->activated_at = now();
            $user->activated_by = $request->user()->id;
            $user->save();
        } else {
            // INACTIVE
            $user->status = 'INACTIVE';
            $user->save();

            // Revoke all tokens so user cannot continue sessions
            $user->tokens()->delete();
        }

        return ApiResponse::success(
            $user->toApiArray(),
            "User status updated to {$user->status}."
        );
    }

    /**
     * Review a user document (Approve or Reject).
     */
    public function reviewDocument(ReviewDocumentRequest $request, string $userId, string $docId): JsonResponse
    {
        $document = UserDocument::where('user_id', $userId)->where('id', $docId)->firstOrFail();
        $status = strtoupper($request->status);

        $document->status = $status;
        $document->reviewed_by = $request->user()->id;
        $document->reviewed_at = now();

        if ($status === 'REJECTED') {
            $document->reject_reason = $request->reject_reason;
        } else {
            $document->reject_reason = null;
        }

        // Store document number if provided
        if ($request->filled('number')) {
            $number = (string) $request->number;
            if ($document->type === 'AADHAAR') {
                // Rule: Do NOT store the full Aadhaar number; at most the last 4 digits
                $clean = preg_replace('/\D/', '', $number);
                $last4 = substr($clean, -4);
                $document->number_encrypted = 'XXXX-XXXX-' . $last4;
            } else {
                // Rule: Encrypt PAN number if stored (handled by encrypted cast on model)
                $document->number_encrypted = trim($number);
            }
        }

        $document->save();

        return ApiResponse::success(
            $document,
            "Document marked as {$status}."
        );
    }

    /**
     * Stream user document file securely from private disk.
     */
    public function streamDocumentFile(Request $request, string $userId, string $docId): StreamedResponse|JsonResponse
    {
        $document = UserDocument::where('user_id', $userId)->where('id', $docId)->firstOrFail();

        if (! Storage::disk('private')->exists($document->file_path)) {
            return ApiResponse::error('Document file not found on server.', 404, null, 'FILE_NOT_FOUND');
        }

        return Storage::disk('private')->response($document->file_path);
    }
}
