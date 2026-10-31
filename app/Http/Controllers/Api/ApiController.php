<?php

namespace App\Http\Controllers\Api;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController
{
    /** The JSON body as an array, or a friendly error. */
    protected function body(Request $request): array
    {
        $data = json_decode($request->getContent() ?: 'null', true);
        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw ApiError::invalid('Send the request body as JSON.');
        }

        return $data;
    }

    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function limit(Request $request, int $default = 100): int
    {
        return min(max((int) $request->query('limit', $default), 1), 500);
    }
}
