<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MetricsResource;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return (new MetricsResource)->response();
    }
}
