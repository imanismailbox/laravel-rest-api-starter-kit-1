<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Http\Middleware\AccessTokenMiddleware;
use App\Http\Middleware\EnforceTransportSecurity;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiRequests;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'pat' => AccessTokenMiddleware::class,
            'verified.api' => EnsureEmailVerified::class,
        ]);

        $middleware->trustProxies(at: '*');

        // `pat` must turn the query token into a header before auth runs.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: AccessTokenMiddleware::class);

        $middleware->prependToGroup('api', ForceJsonResponse::class);
        $middleware->appendToGroup('api', LogApiRequests::class);
        $middleware->appendToGroup('api', EnforceTransportSecurity::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(static function (ValidationException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::validation($exception->errors(), $exception->getMessage());
        });

        $exceptions->render(static function (AuthenticationException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::unauthorized($exception->getMessage() ?: 'Unauthenticated.');
        });

        $exceptions->render(static function (AuthorizationException|AccessDeniedHttpException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::forbidden($exception->getMessage() ?: 'This action is unauthorized.');
        });

        $exceptions->render(static function (ModelNotFoundException|NotFoundHttpException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::notFound('Resource not found.');
        });

        $exceptions->render(static function (MethodNotAllowedHttpException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: 'Method not allowed.',
                status: Response::HTTP_METHOD_NOT_ALLOWED,
                errorCode: ApiErrorCode::BadRequest,
            );
        });

        $exceptions->render(static function (TooManyRequestsHttpException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $headers = [];
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;
            if ($retryAfter !== null) {
                $headers['Retry-After'] = (string) $retryAfter;
            }

            return ApiResponse::error(
                message: $exception->getMessage() ?: 'Too many requests. Please try again later.',
                status: Response::HTTP_TOO_MANY_REQUESTS,
                errorCode: ApiErrorCode::RateLimitExceeded,
                headers: $headers,
            );
        });
    })->create();
