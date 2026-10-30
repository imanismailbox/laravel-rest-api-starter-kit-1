<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    Sanctum::actingAs($admin);
});

test('settings API preserves typed values in nested and direct payloads', function (bool $nested): void {
    Setting::set('enabled', true);
    Setting::set('count', 1);
    Setting::set('ratio', 1.5);
    Setting::set('tags', []);
    $data = ['enabled' => 'false', 'count' => '12', 'ratio' => '2.5', 'tags' => '{"tag":"api"}', 'new_setting' => false];

    $this->putJson(route('api.v1.settings.update'), $nested ? ['settings' => $data] : $data)->assertOk();

    expect(Setting::get('enabled'))->toBeFalse()
        ->and(Setting::get('count'))->toBe(12)
        ->and(Setting::get('ratio'))->toBe(2.5)
        ->and(Setting::get('tags'))->toBe(['tag' => 'api'])
        ->and(Setting::get('new_setting'))->toBeFalse();
})->with([true, false]);

test('settings API rejects malformed values before any writes', function (string $type, mixed $value, bool $nested): void {
    Setting::set('target', null, type: $type);
    Setting::set('unchanged', 'before');
    $data = ['unchanged' => 'after', 'target' => $value];

    $this->putJson(route('api.v1.settings.update'), $nested ? ['settings' => $data] : $data)
        ->assertApiValidationError('settings.target');

    expect(Setting::get('target'))->toBeNull()
        ->and(Setting::get('unchanged'))->toBe('before');
})->with([
    ['boolean', 'invalid', true], ['boolean', [], false], ['boolean', 2, false],
    ['integer', '1.5', true], ['integer', true, false],
    ['float', 'nan', false], ['string', [], true],
    ['array', '{broken', true], ['json', 'false', false], ['array', 123, false],
]);

test('null clears a typed setting without changing its type', function (string $type): void {
    Setting::set('nullable', null, type: $type);
    $this->putJson(route('api.v1.settings.update'), ['nullable' => null])->assertOk();

    expect(Setting::get('nullable', 'fallback'))->toBeNull()
        ->and(Setting::where('key', 'nullable')->first()->type)->toBe($type);
})->with(['boolean', 'integer', 'float', 'string', 'array', 'json']);

test('direct setting callers normalize boolean strings and preserve argument order', function (): void {
    Setting::set('enabled', 'false', 'preferences', 'boolean', true);
    expect(Setting::get('enabled'))->toBeFalse();
    Setting::set('enabled', 'true');
    expect(Setting::get('enabled'))->toBeTrue();
    Setting::set('enabled', '0');
    expect(Setting::get('enabled'))->toBeFalse()
        ->and(Setting::getAllModels()->first()->group)->toBe('preferences')
        ->and(Setting::getAllModels()->first()->is_public)->toBeTrue();
});

test('direct setting callers reject malformed typed values', function (string $type, mixed $value): void {
    Setting::set('target', null, type: $type);
    expect(fn () => Setting::set('target', $value))->toThrow(InvalidArgumentException::class);
    expect(Setting::get('target'))->toBeNull();
})->with([
    ['boolean', 'invalid'], ['boolean', ''], ['boolean', '   '], ['integer', 'oops'], ['float', []],
    ['array', '{broken'], ['json', 'null'], ['string', []],
]);

test('settings reads ignore stale legacy cache entries and database changes are immediately visible', function (): void {
    Setting::set('visible', 'old', isPublic: true);
    $stale = Setting::getAllModels();
    Cache::put(Setting::CACHE_KEY, collect(['visible' => 'stale']));
    Cache::put(Setting::CACHE_MODELS_KEY, $stale);

    Setting::where('key', 'visible')->update(['value' => 'new', 'is_public' => false]);

    expect(Setting::get('visible'))->toBe('new')
        ->and(Setting::getAllCached()->get('visible'))->toBe('new')
        ->and(Setting::getAllModels()->first()->value)->toBe('new');
    $this->getJson(route('api.v1.settings.public'))->assertOk()->assertJsonCount(0, 'data');

    Setting::clearCache();
    expect(Cache::has(Setting::CACHE_KEY))->toBeFalse()
        ->and(Cache::has(Setting::CACHE_MODELS_KEY))->toBeFalse();
});
