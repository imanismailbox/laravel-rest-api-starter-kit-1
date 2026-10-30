<?php

declare(strict_types=1);

use App\Actions\User\UpdateUser;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('demo administrator seeding is disabled outside local and testing', function (string $environment): void {
    $originalEnvironment = $this->app->environment();
    $this->app->instance('env', $environment);

    try {
        (new AdminUserSeeder)->run();
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    } finally {
        $this->app->instance('env', $originalEnvironment);
    }
})->with(['production', 'staging']);

test('demo administrator seeding does not promote or overwrite an existing account', function (): void {
    $user = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Ordinary User']);
    $user->assignRole(UserRole::User->value);
    $password = $user->password;

    $this->seed(AdminUserSeeder::class);

    expect($user->fresh()->hasRole(UserRole::Admin->value))->toBeFalse()
        ->and($user->fresh()->name)->toBe('Ordinary User')
        ->and($user->fresh()->password)->toBe($password);
});

test('demo administrator seeding creates an administrator in testing and is idempotent', function (): void {
    $this->seed(AdminUserSeeder::class);
    $this->seed(AdminUserSeeder::class);

    $user = User::query()->where('email', 'admin@example.com')->sole();
    expect($user->hasRole(UserRole::Admin->value))->toBeTrue();
});

test('confirmed two factor accounts cannot obtain an API token using only a password', function (): void {
    $user = User::factory()->create(['password' => 'Password123!']);
    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'Password123!',
    ])->assertForbidden()->assertJsonPath('message', 'Two-factor authentication is enabled. API login is unavailable until a two-factor challenge API is supported.');

    expect($user->tokens()->count())->toBe(0);
});

test('unconfirmed two factor setup does not block API password login', function (): void {
    $user = User::factory()->create(['password' => 'Password123!']);
    $user->forceFill(['two_factor_secret' => encrypt('secret')])->save();

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'Password123!',
    ])->assertCreated();
});

test('limited user delegates cannot assign administrator roles', function (): void {
    $delegate = User::factory()->create();
    $delegate->givePermissionTo(['users.create', 'users.edit']);
    Sanctum::actingAs($delegate);

    $this->postJson(route('api.v1.users.store'), [
        'name' => 'Escalated',
        'email' => 'escalated@example.com',
        'password' => 'Password123!',
        'roles' => ['admin'],
    ])->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'escalated@example.com']);

    $this->putJson(route('api.v1.users.update', $delegate), ['roles' => ['admin']])->assertForbidden();
    expect($delegate->fresh()->hasRole('admin'))->toBeFalse();
});

test('limited edit delegates cannot change administrator passwords or remove administrator roles', function (): void {
    $delegate = User::factory()->create();
    $delegate->givePermissionTo('users.edit');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $oldPassword = $admin->password;
    Sanctum::actingAs($delegate);

    $this->putJson(route('api.v1.users.update', $admin), ['password' => 'TakenOver123!'])->assertForbidden();
    $this->putJson(route('api.v1.users.update', $admin), ['roles' => ['user']])->assertForbidden();

    expect($admin->fresh()->password)->toBe($oldPassword)
        ->and($admin->fresh()->hasRole('admin'))->toBeTrue();
});

test('administrator protection checks current roles rather than a stale model', function (): void {
    $delegate = User::factory()->create();
    $delegate->givePermissionTo('users.edit');
    $target = User::factory()->create();
    $target->load('roles');
    User::query()->findOrFail($target->id)->assignRole('admin');
    Sanctum::actingAs($delegate);

    expect(fn () => app(UpdateUser::class)->handle($target, ['password' => 'TakenOver123!']))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    expect($target->fresh()->password)->toBe($target->password);
});

test('limited delegates can still create and update nonadministrator roles', function (): void {
    $delegate = User::factory()->create();
    $delegate->givePermissionTo(['users.create', 'users.edit']);
    Sanctum::actingAs($delegate);

    $response = $this->postJson(route('api.v1.users.store'), [
        'name' => 'Ordinary',
        'email' => 'ordinary@example.com',
        'password' => 'Password123!',
        'roles' => ['user'],
    ])->assertCreated();

    $this->putJson(route('api.v1.users.update', $response->json('data.id')), [
        'name' => 'Edited',
        'roles' => [],
    ])->assertOk()->assertJsonPath('data.name', 'Edited');
});

test('API password changes revoke every existing personal token', function (): void {
    $user = User::factory()->create(['password' => 'OldPassword123!']);
    $token = $user->createToken('current')->plainTextToken;
    $user->createToken('other-device');

    $this->withToken($token)->putJson(route('api.v1.auth.me.password.update'), [
        'current_password' => 'OldPassword123!',
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(0);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(route('api.v1.auth.me'))->assertUnauthorized();
});

test('password reset invalidates previously issued API tokens', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('stolen')->plainTextToken;
    $resetToken = Password::createToken($user);

    $this->post(route('password.update'), [
        'email' => $user->email,
        'token' => $resetToken,
        'password' => 'ResetPassword123!',
        'password_confirmation' => 'ResetPassword123!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login', absolute: false));

    expect(Hash::check('ResetPassword123!', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
    $this->withToken($token)->getJson(route('api.v1.auth.me'))->assertUnauthorized();
});

test('administrator password edits revoke tokens but unrelated edits do not', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $target = User::factory()->create();
    $target->createToken('existing');
    Sanctum::actingAs($admin);

    $this->putJson(route('api.v1.users.update', $target), ['name' => 'Renamed'])->assertOk();
    expect($target->tokens()->count())->toBe(1);

    $this->putJson(route('api.v1.users.update', $target), ['password' => 'ChangedPassword123!'])->assertOk();
    expect($target->tokens()->count())->toBe(0);
});

test('web registration respects the disabled registration setting', function (): void {
    Setting::set('allow_registration', false);

    $this->post(route('register.store'), [
        'name' => 'Blocked',
        'email' => 'blocked@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    $this->assertGuest();
});

test('unverified accounts cannot access the web dashboard', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('API registration sends verification email and leaves the account unverified', function (): void {
    Notification::fake();

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Verify Me',
        'email' => 'verify@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertCreated();

    $user = User::query()->where('email', 'verify@example.com')->sole();
    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
});
