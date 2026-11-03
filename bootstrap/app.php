<?php

declare(strict_types=1);

use App\Http\Middleware\AccessTokenMiddleware;
use App\Http\Middleware\EnsureEmailVerified;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Liamsinami\ApiEssentials\Exceptions\ApiExceptions;
use Liamsinami\ApiEssentials\Http\Middleware\EnforceTransportSecurity;
use Liamsinami\ApiEssentials\Http\Middleware\ForceJsonResponse;
use Liamsinami\ApiEssentials\Http\Middleware\LogApiRequests;

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
    ->withExceptions(ApiExceptions::register(...))
    ->create();
