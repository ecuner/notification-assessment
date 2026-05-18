<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MetricsRequest;
use App\Http\Resources\MetricsResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    #[HeaderParameter('X-Correlation-ID', 'Correlation ID for tracing & debugging purposes. Random will be assigned if you do not pass any.', type: 'string')]
    #[HeaderParameter('X-API-Key', 'API key for notification API authentication.', type: 'string', required: true)]
    public function __invoke(MetricsRequest $request): JsonResponse
    {
        return (new MetricsResource($request->validated('filter', [])))->response();
    }
}
