<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

function currentTwoFactorCode(User $user): string
{
    $code = (new Google2FA)->getCurrentOtp(Fortify::currentEncrypter()->decrypt((string) $user->fresh()->two_factor_secret));
    // Fortify rejects a code that was already used in this time window; tests reuse the window.
    Cache::forget('fortify.2fa_codes.'.md5($code));

    return $code;
}

function enableTwoFactor(User $user): array
{
    Sanctum::actingAs($user);
    $setup = test()->postJson(route('api.v1.auth.two-factor.enable'), ['current_password' => 'Password123!'])->assertOk();
    test()->postJson(route('api.v1.auth.two-factor.confirm'), ['code' => currentTwoFactorCode($user)])->assertOk();
    app('auth')->forgetGuards();

    return $setup->json('data.recovery_codes');
}

// Password reset

test('forgot password sends a reset link to the frontend without revealing unknown emails', function (): void {
    Notification::fake();
    config(['app.frontend_url' => 'https://app.example.com']);
    $user = User::factory()->create();

    $known = $this->postJson(route('api.v1.auth.password.forgot'), ['email' => mb_strtoupper($user->email)])->assertOk();
    $unknown = $this->postJson(route('api.v1.auth.password.forgot'), ['email' => 'nobody@example.com'])->assertOk();

    expect($known->json('message'))->toBe($unknown->json('message'));
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, 'https://app.example.com/reset-password?token=')
            && str_contains($url, 'email='.urlencode($user->email));
    });
});

test('reset password changes the password and revokes every token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('stolen')->plainTextToken;

    $this->postJson(route('api.v1.auth.password.reset'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'ResetPassword123!',
        'password_confirmation' => 'ResetPassword123!',
    ])->assertOk();

    expect(Hash::check('ResetPassword123!', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
    $this->withToken($token)->getJson(route('api.v1.auth.me'))->assertUnauthorized();
});

test('reset password rejects an invalid token', function (): void {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.password.reset'), [
        'token' => 'invalid',
        'email' => $user->email,
        'password' => 'ResetPassword123!',
        'password_confirmation' => 'ResetPassword123!',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

// Email verification

test('the emailed signed link verifies the account without a session', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.auth.verification.send'))->assertOk();

    $url = null;
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url): bool {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    $this->app['auth']->forgetGuards();
    $this->getJson($url)->assertOk();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('verification links pass through the frontend when configured', function (): void {
    Notification::fake();
    config(['app.frontend_url' => 'https://app.example.com']);
    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class, fn (VerifyEmail $notification): bool => str_starts_with(
        $notification->toMail($user)->actionUrl,
        'https://app.example.com/verify-email?url='.urlencode(config('app.url')),
    ));
});

test('tampered verification links are rejected', function (): void {
    $user = User::factory()->unverified()->create();

    $this->getJson(route('api.v1.auth.verification.verify', ['id' => $user->id, 'hash' => $user->emailVerificationHash()]))->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('requiring verification blocks unverified users but leaves profile and resend open', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->unverified()->create();
    $user->givePermissionTo('users.view');
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.users.index'))->assertOk();

    Setting::set('require_email_verification', true, type: 'boolean');

    $this->getJson(route('api.v1.users.index'))->assertForbidden()->assertJsonPath('error_code', 'EMAIL_NOT_VERIFIED');
    $this->getJson(route('api.v1.auth.me'))->assertOk();
    $this->postJson(route('api.v1.auth.verification.send'))->assertOk();
});

// Two-factor authentication

test('two factor setup requires the current password and a valid code to confirm', function (): void {
    $user = User::factory()->create(['password' => 'Password123!']);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.auth.two-factor.enable'), ['current_password' => 'wrong'])->assertUnprocessable();

    $this->postJson(route('api.v1.auth.two-factor.enable'), ['current_password' => 'Password123!'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['secret_key', 'otpauth_url', 'qr_code_svg', 'recovery_codes']]);
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    $this->postJson(route('api.v1.auth.two-factor.confirm'), ['code' => '000000'])->assertUnprocessable();
    $this->postJson(route('api.v1.auth.two-factor.confirm'), ['code' => currentTwoFactorCode($user)])->assertOk();
    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->postJson(route('api.v1.auth.two-factor.enable'), ['current_password' => 'Password123!'])->assertConflict();
});

test('login with two factor returns a challenge instead of a token', function (): void {
    $user = User::factory()->create(['password' => 'Password123!']);
    enableTwoFactor($user);

    $response = $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'Password123!'])
        ->assertOk()
        ->assertJsonPath('data.two_factor', true);

    expect($response->json('data.token'))->toBeNull()
        ->and($user->tokens()->count())->toBe(0);

    $this->postJson(route('api.v1.auth.two-factor.challenge'), [
        'challenge_token' => $response->json('data.challenge_token'),
        'code' => currentTwoFactorCode($user),
    ])->assertCreated()->assertJsonStructure(['data' => ['token', 'user']]);

    expect($user->tokens()->count())->toBe(1);
});

test('two factor challenges are single use and lock after five wrong codes', function (): void {
    $user = User::factory()->create(['password' => 'Password123!']);
    enableTwoFactor($user);
    $challenge = fn (): string => $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'Password123!'])->json('data.challenge_token');

    $token = $challenge();
    $this->postJson(route('api.v1.auth.two-factor.challenge'), ['challenge_token' => $token, 'code' => currentTwoFactorCode($user)])->assertCreated();
    $this->postJson(route('api.v1.auth.two-factor.challenge'), ['challenge_token' => $token, 'code' => currentTwoFactorCode($user)])->assertUnprocessable();

    $token = $challenge();
    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.v1.auth.two-factor.challenge'), ['challenge_token' => $token, 'code' => '000000'])->assertUnprocessable();
    }
    $this->postJson(route('api.v1.auth.two-factor.challenge'), ['challenge_token' => $token, 'code' => currentTwoFactorCode($user)])->assertUnprocessable();
    expect($user->tokens()->count())->toBe(1);
});

test('recovery codes work once and can be regenerated', function (): void {
    $user = User::factory()->create(['password' => 'Password123!']);
    $codes = enableTwoFactor($user);
    $login = fn (): string => $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'Password123!'])->json('data.challenge_token');

    $this->postJson(route('api.v1.auth.two-factor.challenge'), ['challenge_token' => $login(), 'recovery_code' => $codes[0]])->assertCreated();
    $this->postJson(route('api.v1.auth.two-factor.challenge'), ['challenge_token' => $login(), 'recovery_code' => $codes[0]])->assertUnprocessable();

    Sanctum::actingAs($user->fresh());
    $fresh = $this->postJson(route('api.v1.auth.two-factor.recovery-codes'), ['current_password' => 'Password123!'])
        ->assertOk()->json('data.recovery_codes');
    expect($fresh)->toHaveCount(8)->not->toContain($codes[1]);
});

test('disabling two factor requires the password and restores plain login', function (): void {
    $user = User::factory()->create(['password' => 'Password123!']);
    enableTwoFactor($user);
    Sanctum::actingAs($user->fresh());

    $this->deleteJson(route('api.v1.auth.two-factor.disable'), ['current_password' => 'wrong'])->assertUnprocessable();
    $this->deleteJson(route('api.v1.auth.two-factor.disable'), ['current_password' => 'Password123!'])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'Password123!'])
        ->assertCreated()->assertJsonStructure(['data' => ['token']]);
});

// API documentation

test('api docs require basic auth with the docs permission outside local', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create(['password' => 'Password123!']);
    $admin->assignRole(UserRole::Admin->value);
    $user = User::factory()->create(['password' => 'Password123!']);

    $this->get('/docs/api')->assertUnauthorized();
    $this->withBasicAuth($user->email, 'Password123!')->get('/docs/api')->assertForbidden();
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    $this->withBasicAuth($admin->email, 'Password123!')->get('/docs/api.json')->assertOk();
});
