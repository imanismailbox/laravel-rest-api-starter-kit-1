<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Setting\GetSettings;
use App\Actions\Setting\UpdateSettings;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Setting\UpdateSettingsRequest;
use App\Http\Resources\SettingResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liamsinami\ApiEssentials\ApiResponse;

#[Group('Setting')]
final class SettingController extends ApiController
{
    /**
     * Get all application settings (Admin only).
     */
    public function index(Request $request, GetSettings $action): JsonResponse
    {
        $group = $request->query('group');
        $group = is_string($group) ? $group : null;

        $settings = $action->handle(group: $group);

        return ApiResponse::success(
            data: SettingResource::collection($settings),
            message: 'Application settings retrieved successfully.',
        );
    }

    /**
     * Update application settings (Admin only).
     */
    public function update(UpdateSettingsRequest $request, UpdateSettings $action): JsonResponse
    {
        $settings = $action->handle($request->settingsData());

        return ApiResponse::success(
            data: SettingResource::collection($settings),
            message: 'Application settings updated successfully.',
        );
    }

    /**
     * Get public application settings (No authentication required).
     */
    public function public(Request $request, GetSettings $action): JsonResponse
    {
        $group = $request->query('group');
        $group = is_string($group) ? $group : null;

        $settings = $action->handle(group: $group, isPublic: true);

        return ApiResponse::success(
            data: SettingResource::collection($settings),
            message: 'Public application settings retrieved successfully.',
        );
    }
}
