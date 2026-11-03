<?php

declare(strict_types=1);

namespace Liamsinami\ApiEssentials;

use Illuminate\Support\ServiceProvider;
use Override;

final class ApiEssentialsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-essentials.php', 'api-essentials');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/api-essentials.php' => config_path('api-essentials.php')], 'api-essentials-config');
    }
}
