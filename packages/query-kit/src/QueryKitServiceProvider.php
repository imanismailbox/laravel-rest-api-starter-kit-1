<?php

declare(strict_types=1);

namespace Liamsinami\QueryKit;

use Illuminate\Support\ServiceProvider;
use Override;

final class QueryKitServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/query-kit.php', 'query-kit');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/query-kit.php' => config_path('query-kit.php')], 'query-kit-config');
    }
}
