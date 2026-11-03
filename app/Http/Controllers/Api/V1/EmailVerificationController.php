<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liamsinami\ApiEssentials\ApiResponse;

#[Group('Auth')]
final class EmailVerificationController extends ApiController
{
    /**
     * Verify an email address from the signed link in the verification email. No login is needed.
     */
    public function verify(string $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        if (! hash_equals($user->emailVerificationHash(), $hash)) {
            return ApiResponse::forbidden('This verification link is invalid.');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return ApiResponse::success(
            message: 'Email address verified successfully.',
        );
    }

    /**
     * Send a new verification email to the authenticated user.
     */
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(message: 'Email address is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::success(
            message: 'Verification link sent.',
        );
    }
}
