<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Api\V1\Auth\CurrentPasswordRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Liamsinami\ApiEssentials\ApiResponse;
use Liamsinami\ApiEssentials\Enums\ApiErrorCode;
use Symfony\Component\HttpFoundation\Response;

#[Group('Two-Factor Authentication')]
final class TwoFactorController extends ApiController
{
    /**
     * Start two-factor setup: returns the secret, QR code and recovery codes. Confirm with a code to activate.
     */
    public function enable(CurrentPasswordRequest $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->alreadyEnabled();
        }

        // Force a fresh secret so an abandoned, unconfirmed setup is never reused.
        $enable($user, force: true);

        return ApiResponse::success(
            data: [
                'secret_key' => Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret),
                'otpauth_url' => $user->twoFactorQrCodeUrl(),
                'qr_code_svg' => $user->twoFactorQrCodeSvg(),
                'recovery_codes' => $user->recoveryCodes(),
            ],
            message: 'Scan the QR code, then confirm with a code from your authenticator app.',
        );
    }

    /**
     * Activate two-factor authentication with a code from the authenticator app.
     */
    public function confirm(ConfirmTwoFactorRequest $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->alreadyEnabled();
        }

        $confirm($user, (string) $request->validated('code'));

        return ApiResponse::success(
            message: 'Two-factor authentication is now enabled.',
        );
    }

    /**
     * Turn off two-factor authentication.
     */
    public function disable(CurrentPasswordRequest $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $disable($user);

        return ApiResponse::success(
            message: 'Two-factor authentication has been disabled.',
        );
    }

    /**
     * Replace all recovery codes with a new set.
     */
    public function recoveryCodes(CurrentPasswordRequest $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return ApiResponse::error(
                message: 'Two-factor authentication is not enabled.',
                status: Response::HTTP_CONFLICT,
                errorCode: ApiErrorCode::Conflict,
            );
        }

        $generate($user);

        return ApiResponse::success(
            data: ['recovery_codes' => $user->recoveryCodes()],
            message: 'New recovery codes generated. The old codes no longer work.',
        );
    }

    private function alreadyEnabled(): JsonResponse
    {
        return ApiResponse::error(
            message: 'Two-factor authentication is already enabled.',
            status: Response::HTTP_CONFLICT,
            errorCode: ApiErrorCode::Conflict,
        );
    }
}
