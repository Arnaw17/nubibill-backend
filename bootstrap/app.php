<?php

use App\Http\Middleware\CheckAppMaintenance;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            CheckAppMaintenance::class,
        ], append: [
            'throttle:api',
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureAdmin::class,
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return ApiResponse::error(
                    'The given data was invalid.',
                    422,
                    $e->errors(),
                    null
                );
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::error(
                    'Unauthenticated.',
                    401,
                    null,
                    'UNAUTHORIZED'
                );
            }

            if ($e instanceof AccessDeniedHttpException || $e instanceof AuthorizationException) {
                return ApiResponse::error(
                    $e->getMessage() ?: 'Forbidden.',
                    403,
                    null,
                    'FORBIDDEN'
                );
            }

            if ($e instanceof NotFoundHttpException || $e instanceof ModelNotFoundException) {
                return ApiResponse::error(
                    'Resource not found.',
                    404,
                    null,
                    'NOT_FOUND'
                );
            }

            if ($e instanceof ThrottleRequestsException || $e instanceof TooManyRequestsHttpException) {
                return ApiResponse::error(
                    $e->getMessage() ?: 'Too many requests. Please wait before retrying.',
                    429,
                    null,
                    'THROTTLED'
                );
            }

            if ($e instanceof HttpExceptionInterface) {
                return ApiResponse::error(
                    $e->getMessage(),
                    $e->getStatusCode(),
                    null,
                    null
                );
            }

            return null;
        });
    })->create();
