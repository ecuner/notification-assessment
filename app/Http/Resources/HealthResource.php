<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthResource extends JsonApiResource
{
    public function __construct()
    {
        parent::__construct((object) []);
    }

    public function toType(Request $request): string
    {
        return 'health-checks';
    }

    public function toId(Request $request): string
    {
        return 'current';
    }

    public function toAttributes(Request $request): array
    {
        $databaseIsHealthy = $this->databaseIsHealthy();
        $cacheIsHealthy = $this->cacheIsHealthy();

        return [
            'status' => $databaseIsHealthy && $cacheIsHealthy ? 'ok' : 'degraded',
            'services' => [
                'database' => $databaseIsHealthy ? 'ok' : 'unavailable',
                'cache' => $cacheIsHealthy ? 'ok' : 'unavailable',
            ],
        ];
    }

    public function statusCode(): int
    {
        return $this->databaseIsHealthy() && $this->cacheIsHealthy() ? 200 : 503;
    }

    private function databaseIsHealthy(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheIsHealthy(): bool
    {
        try {
            Cache::put('health-check', true, 5);

            return Cache::get('health-check') === true;
        } catch (Throwable) {
            return false;
        }
    }
}
