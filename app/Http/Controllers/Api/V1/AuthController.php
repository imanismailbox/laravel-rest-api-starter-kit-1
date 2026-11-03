<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\CompleteTwoFactorChallenge;
use App\Actions\Auth\CreateApiToken;
use App\Actions\Auth\DeleteAvatar;
use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\RevokeApiToken;
use App\Actions\Auth\StartTwoFactorChallenge;
use App\Actions\Auth\UpdatePassword;
use App\Actions\Auth\UpdateProfile;
use App\Actions\Auth\UploadAvatar;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\TwoFactorChallengeRequest;
use App\Http\Requests\Api\V1\Auth\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Requests\Api\V1\Auth\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Models\Setting;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Liamsinami\ApiEssentials\ApiResponse;

#[Group('Auth')]
final class AuthController extends ApiController
{
    /**
     * Register a new user and issue an API token.
     */
    public function register(RegisterRequest $request, RegisterUser $action, CreateApiToken $tokenAction): JsonResponse
    {
        if (! (bool) Setting::get('allow_registration', true)) {
            return ApiResponse::forbidden('Registration is currently disabled by administrator.');
        }

        $user = $action->handle($request->validated());

        return $this->tokenResponse($tokenAction, $user, 'registration', 'User registered successfully.');
    }

    /**
     * Authenticate user credentials (using email or username) and issue an API token.
     *
     * Accounts with two-factor enabled get `two_factor: true` and a `challenge_token` instead;
     * send it with a code to `POST /auth/two-factor-challenge` within 5 minutes.
     */
    public function login(LoginRequest $request, CreateApiToken $action, StartTwoFactorChallenge $challenge): JsonResponse
    {
        $identifier = (string) ($request->validated('login')
            ?? $request->validated('username')
            ?? $request->validated('email'));

        $user = User::query()
            ->where(function ($query) use ($identifier): void {
                $query->where('email', mb_strtolower($identifier))
                    ->orWhere('username', $identifier);
            })
            ->first();

        if (! $user || ! Hash::check((string) $request->validated('password'), $user->password)) {
            return ApiResponse::unauthorized('Invalid email or password.');
        }

        $deviceName = (string) ($request->validated('device_name') ?: 'api-client');

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return ApiResponse::success(
                data: [
                    'two_factor' => true,
                    'challenge_token' => $challenge->handle($user, $deviceName),
                    'expires_in' => StartTwoFactorChallenge::TTL_SECONDS,
                ],
                message: 'Two-factor authentication code required.',
            );
        }

        return $this->tokenResponse($action, $user, $deviceName, 'Access token issued successfully.');
    }

    /**
     * Finish a two-factor login with an authenticator code or a recovery code and issue an API token.
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request, CompleteTwoFactorChallenge $challenge, CreateApiToken $action): JsonResponse
    {
        $result = $challenge->handle(
            token: (string) $request->validated('challenge_token'),
            code: $request->validated('code'),
            recoveryCode: $request->validated('recovery_code'),
        );

        return $this->tokenResponse($action, $result['user'], $result['device_name'], 'Access token issued successfully.');
    }

    /**
     * Get the authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['roles', 'permissions']);

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'User profile retrieved successfully.',
        );
    }

    /**
     * Update the authenticated user profile.
     */
    public function updateProfile(UpdateProfileRequest $request, UpdateProfile $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user = $action->handle($user, $request->validated());
        $user->loadMissing(['roles', 'permissions']);

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'User profile updated successfully.',
        );
    }

    /**
     * Update the authenticated user password.
     */
    public function updatePassword(UpdatePasswordRequest $request, UpdatePassword $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, (string) $request->validated('password'));

        return ApiResponse::success(
            message: 'Password updated successfully.',
        );
    }

    /**
     * Upload and update user avatar image.
     */
    public function uploadAvatar(UploadAvatarRequest $request, UploadAvatar $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var UploadedFile $file */
        $file = $request->file('avatar');

        $user = $action->handle($user, $file);
        $user->loadMissing(['roles', 'permissions']);

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'Avatar uploaded successfully.',
        );
    }

    /**
     * Delete user avatar image.
     */
    public function deleteAvatar(Request $request, DeleteAvatar $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user = $action->handle($user);
        $user->loadMissing(['roles', 'permissions']);

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'Avatar deleted successfully.',
        );
    }

    /**
     * Revoke current access token (logout).
     */
    public function logout(Request $request, RevokeApiToken $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        return ApiResponse::success(
            message: 'Successfully logged out.',
        );
    }

    private function tokenResponse(CreateApiToken $action, User $user, string $deviceName, string $message): JsonResponse
    {
        $user->loadMissing(['roles', 'permissions']);

        return ApiResponse::created(
            data: [
                'token' => $action->handle($user, $deviceName),
                'token_type' => 'Bearer',
                'user' => new UserResource($user),
            ],
            message: $message,
        );
    }
}
