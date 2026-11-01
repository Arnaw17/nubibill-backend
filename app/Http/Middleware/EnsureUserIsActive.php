<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401, null, 'UNAUTHORIZED');
        }

        if (! $user->isActive()) {
            // Revoke all tokens so the deactivated user's tokens stop working immediately
            $user->tokens()->delete();

            return ApiResponse::error(
                'Your account is inactive and awaiting admin approval.',
                403,
                null,
                'ACCOUNT_INACTIVE'
            );
        }

        return $next($request);
    }
}
