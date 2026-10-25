<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Return a standardized successful JSON response.
     *
     * @param mixed $data
     * @param string $message
     * @param int $status
     * @param string|null $code
     * @return JsonResponse
     */
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        ?string $code = null
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
            'code' => $code,
        ], $status);
    }

    /**
     * Return a standardized error JSON response.
     *
     * @param string $message
     * @param int $status
     * @param mixed $errors
     * @param string|null $code
     * @param mixed $data
     * @return JsonResponse
     */
    public static function error(
        string $message = 'Error',
        int $status = 400,
        mixed $errors = null,
        ?string $code = null,
        mixed $data = null
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'code' => $code,
        ], $status);
    }
}
