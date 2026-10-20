<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Health\ServiceHealthChecker;
use Illuminate\Http\JsonResponse;

class ServiceHealthController extends Controller
{
    public function __invoke(ServiceHealthChecker $checker): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($health): array => $health->toArray(), $checker->all())]);
    }
}
