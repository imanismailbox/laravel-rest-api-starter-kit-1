<?php

declare(strict_types=1);

namespace Liamsinami\ApiEssentials\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liamsinami\ApiEssentials\ApiResponse;
use Liamsinami\ApiEssentials\Enums\ApiErrorCode;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ForceJsonResponse
{
    /**
     * Ensure all API requests have Accept header set to application/json and convert raw errors.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            return $response;
        }

        // Allow file downloads and streamed responses to pass through untouched
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        // Convert raw HTTP error responses (>= 400) to standardized JSON
        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            return ApiResponse::error(
                message: Response::$statusTexts[$response->getStatusCode()] ?? 'An HTTP error occurred.',
                status: $response->getStatusCode(),
                errorCode: ApiErrorCode::BadRequest,
            );
        }

        return $response;
    }
}
