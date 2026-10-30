<?php

declare(strict_types=1);

namespace App\Actions\Setting;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class UpdateSettings
{
    /**
     * @param  array<string, mixed>  $settingsData
     * @return Collection<int, Setting>
     */
    public function handle(array $settingsData): Collection
    {
        DB::transaction(function () use ($settingsData): void {
            foreach ($settingsData as $key => $value) {
                Setting::set(
                    key: (string) $key,
                    value: $value,
                );
            }
        });

        return Setting::query()->orderBy('group')->orderBy('key')->get();
    }
}
