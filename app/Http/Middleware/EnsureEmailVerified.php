<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Liamsinami\ApiEssentials\ApiResponse;
use Liamsinami\ApiEssentials\Enums\ApiErrorCode;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEmailVerified
{
    /**
     * Block unverified users when the `require_email_verification` setting is on.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ((bool) Setting::get('require_email_verification', false)
            && $user instanceof MustVerifyEmail
            && ! $user->hasVerifiedEmail()) {
            return ApiResponse::forbidden(
                message: 'Your email address is not verified. Please verify your email to continue.',
                errorCode: ApiErrorCode::EmailNotVerified,
            );
        }

        return $next($request);
    }
}
