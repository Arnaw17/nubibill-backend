<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
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

        if (! $user->isAdmin()) {
            return ApiResponse::error('Administrator privileges required.', 403, null, 'FORBIDDEN');
        }

        return $next($request);
    }
}
