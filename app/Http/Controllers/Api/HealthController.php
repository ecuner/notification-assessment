<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HealthResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    // #[HeaderParameter('X-Correlation-ID', 'Correlation ID for tracing & debugging purposes. Random will be assigned if you do not pass any.', type: 'string')]
    #[Response(
        200,
        'Healthy response.',
        type: 'array{data: array{type: string, id: string, attributes: array{status: string, services: array{database: string, cache: string}}}}'
    )]
    #[Response(
        503,
        'Degraded response.',
        type: 'array{data: array{type: string, id: string, attributes: array{status: string, services: array{database: string, cache: string}}}}'
    )]
    public function __invoke(): JsonResponse
    {
        $resource = new HealthResource;

        return $resource->response()->setStatusCode($resource->statusCode());
    }
}
