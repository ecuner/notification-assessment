<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MetricsRequest;
use App\Http\Resources\MetricsResource;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    public function __invoke(MetricsRequest $request): JsonResponse
    {
        return (new MetricsResource($request->validated('filter', [])))->response();
    }
}
