<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function error(string $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ], $status);
    }

    public static function paginated(array $items, int $page, int $size, int $total): JsonResponse
    {
        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }
}

