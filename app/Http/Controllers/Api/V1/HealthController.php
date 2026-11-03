<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Liamsinami\ApiEssentials\ApiResponse;

#[Group('Health')]
final class HealthController extends ApiController
{
    /**
     * Get API status and metadata.
     */
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            data: [
                'status' => 'healthy',
                'timestamp' => now()->toIso8601String(),
                'version' => config('scramble.info.version', '1.0.0'),
                'environment' => app()->environment(),
            ],
            message: 'API service is healthy and operational.',
        );
    }
}
