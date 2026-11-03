<?php

declare(strict_types=1);

namespace Liamsinami\ApiEssentials\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Liamsinami\ApiEssentials\ApiResponse;
use Liamsinami\ApiEssentials\Enums\ApiErrorCode;
use Symfony\Component\HttpFoundation\Response;

final class EnforceTransportSecurity
{
    /**
     * Enforce HTTPS and attach HSTS headers based on configuration.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('api-essentials.force_https', false) && ! $request->isSecure()) {
            return ApiResponse::error(
                message: 'HTTPS connection is required.',
                status: Response::HTTP_BAD_REQUEST,
                errorCode: ApiErrorCode::BadRequest,
            );
        }

        $response = $next($request);

        if ((bool) config('api-essentials.hsts.enabled', false) && $request->isSecure()) {
            $maxAge = max((int) config('api-essentials.hsts.max_age', 31536000), 0);
            $directives = ["max-age={$maxAge}"];

            if ((bool) config('api-essentials.hsts.include_subdomains', true)) {
                $directives[] = 'includeSubDomains';
            }

            if ((bool) config('api-essentials.hsts.preload', false)) {
                $directives[] = 'preload';
            }

            $response->headers->set('Strict-Transport-Security', implode('; ', $directives));
        }

        return $response;
    }
}
