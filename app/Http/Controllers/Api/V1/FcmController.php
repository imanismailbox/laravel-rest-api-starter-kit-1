<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fcm\RegisterFcmToken;
use App\Actions\Fcm\RevokeFcmToken;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Fcm\RegisterFcmTokenRequest;
use App\Http\Requests\Api\V1\Fcm\RevokeFcmTokenRequest;
use App\Http\Requests\Api\V1\Fcm\SendTestNotificationRequest;
use App\Http\Resources\FcmTokenResource;
use App\Models\User;
use App\Notifications\GenericFcmNotification;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liamsinami\ApiEssentials\ApiResponse;

#[Group('FCM Notifications')]
final class FcmController extends ApiController
{
    /**
     * List current user's registered FCM device tokens.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            data: FcmTokenResource::collection($user->fcmTokens),
            message: 'Device tokens retrieved successfully.',
        );
    }

    /**
     * Register or update an FCM device token.
     */
    public function store(RegisterFcmTokenRequest $request, RegisterFcmToken $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $fcmToken = $action->handle(
            user: $user,
            token: $request->validated('token'),
            deviceType: $request->validated('device_type', 'android'),
            deviceName: $request->validated('device_name'),
        );

        return ApiResponse::created(
            data: new FcmTokenResource($fcmToken),
            message: 'Device token registered successfully.',
        );
    }

    /**
     * Revoke an FCM device token (e.g. on logout).
     */
    public function destroy(RevokeFcmTokenRequest $request, RevokeFcmToken $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle(
            user: $user,
            token: $request->validated('token'),
        );

        return ApiResponse::success(
            message: 'Device token revoked successfully.',
        );
    }

    /**
     * Send a test push notification to user's registered devices.
     */
    public function sendTestNotification(SendTestNotificationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->fcmTokens()->count() === 0) {
            return ApiResponse::error(
                message: 'No registered device tokens found for this user.',
                status: 422,
            );
        }

        $user->notify(new GenericFcmNotification(
            title: $request->validated('title'),
            body: $request->validated('body'),
            data: $request->validated('data', []),
            image: $request->validated('image'),
        ));

        return ApiResponse::success(
            message: 'Test notification dispatched successfully.',
        );
    }
}
