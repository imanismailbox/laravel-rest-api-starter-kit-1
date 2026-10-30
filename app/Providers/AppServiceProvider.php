<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Fortify;
use Override;
use Spatie\Permission\Models\Role;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        // Fortify only supplies the two-factor engine; all auth endpoints live in routes/api.php.
        Fortify::ignoreRoutes();

        if (! (bool) config('scramble.enabled', true)) {
            Scramble::ignoreDefaultRoutes();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureAuthLinks();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(
            ! app()->isProduction(),
        );

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Point emailed links at the API (verification) or the client app (password reset).
     */
    private function configureAuthLinks(): void
    {
        VerifyEmail::createUrlUsing(static function (User $user): string {
            $url = URL::temporarySignedRoute(
                'api.v1.auth.verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                ['id' => $user->getKey(), 'hash' => $user->emailVerificationHash()],
            );
            $frontend = config('app.frontend_url');

            return is_string($frontend) && $frontend !== ''
                ? mb_rtrim($frontend, '/').'/verify-email?url='.urlencode($url)
                : $url;
        });

        ResetPassword::createUrlUsing(static function (User $user, string $token): string {
            $frontend = config('app.frontend_url');
            $base = is_string($frontend) && $frontend !== '' ? $frontend : (string) config('app.url');

            return mb_rtrim($base, '/').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
    }

    /**
     * Configure authorization gates.
     */
    private function configureAuthorization(): void
    {
        Gate::define('viewApiDocs', static fn (?User $user): bool => $user?->can('api_docs.view') ?? false);
        Gate::define('grant-permissions', static fn (User $actor, array $permissions): bool => $actor->hasRole(UserRole::Admin->value)
            || collect($permissions)->every(static fn (string $permission): bool => $actor->hasPermissionTo($permission)));
        Gate::define('grant-roles', static fn (User $actor, array $roles): bool => $actor->hasRole(UserRole::Admin->value)
            || (! in_array(UserRole::Admin->value, $roles, true)
                && Gate::forUser($actor)->allows('grant-permissions', [
                    Role::query()->whereIn('name', $roles)->with('permissions')->get()->flatMap->permissions->pluck('name')->unique()->all(),
                ])));
        Gate::define('manage-user', static fn (User $actor, User $target): bool => $actor->hasRole(UserRole::Admin->value) || ! $target->hasRole(UserRole::Admin->value));
    }
}
