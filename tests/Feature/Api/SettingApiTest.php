<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);

    $this->user = User::factory()->create();
    $this->user->assignRole(UserRole::User->value);
});

test('admin can retrieve all application settings', function (): void {
    Sanctum::actingAs($this->admin);

    $response = $this->getJson(route('api.v1.settings.index'))
        ->assertApiSuccess('Application settings retrieved successfully.');

    expect($response->json('data'))->toBeArray()
        ->and(count($response->json('data')))->toBeGreaterThanOrEqual(10);
});

test('non admin user cannot retrieve settings', function (): void {
    Sanctum::actingAs($this->user);

    $this->getJson(route('api.v1.settings.index'))
        ->assertForbidden();
});

test('unauthenticated guest can retrieve public settings', function (): void {
    $response = $this->getJson(route('api.v1.settings.public'))
        ->assertApiSuccess('Public application settings retrieved successfully.');

    $publicKeys = collect($response->json('data'))->pluck('key')->all();

    expect($publicKeys)->toContain('app_name', 'allow_registration', 'timezone')
        ->and($publicKeys)->not->toContain('mail_from_address');
});

test('admin can update application settings', function (): void {
    Sanctum::actingAs($this->admin);

    $payload = [
        'settings' => [
            'app_name' => 'Custom ERP App',
            'allow_registration' => false,
            'password_min_length' => 10,
            'custom_tags' => ['api', 'v1', 'enterprise'],
        ],
    ];

    $response = $this->putJson(route('api.v1.settings.update'), $payload)
        ->assertApiSuccess('Application settings updated successfully.');

    expect(Setting::get('app_name'))->toBe('Custom ERP App')
        ->and(Setting::get('allow_registration'))->toBe(false)
        ->and(Setting::get('password_min_length'))->toBe(10)
        ->and(Setting::get('custom_tags'))->toBe(['api', 'v1', 'enterprise']);
});

test('non admin user cannot update settings', function (): void {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.v1.settings.update'), [
        'settings' => ['app_name' => 'Hacked App'],
    ])->assertForbidden();
});

test('registration is blocked when allow_registration setting is false', function (): void {
    Setting::set('allow_registration', false);

    $response = $this->postJson(route('api.v1.auth.register'), [
        'name' => 'New User',
        'email' => 'blocked@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertForbidden();

    expect($response->json('message'))->toContain('disabled by administrator');
});

test('registration succeeds when allow_registration setting is true', function (): void {
    Setting::set('allow_registration', true);

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Allowed User',
        'email' => 'allowed@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertCreated();
});
