<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run --only-db')->daily()->at('01:30');
Schedule::command('backup:run')->weeklyOn(0, '02:00');
Schedule::command('backup:monitor')->daily()->at('06:00');
Schedule::command('activitylog:clean')->daily()->at('03:00');
Schedule::command('sanctum:prune-expired --hours=24')->daily()->at('03:15');
