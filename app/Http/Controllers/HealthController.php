<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'OurSpace API is healthy.',
            'data' => [
                'status' => 'ok',
                'service' => 'OurSpace API',
                'timestamp' => now()->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
