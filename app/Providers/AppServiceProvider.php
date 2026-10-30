<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
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
     * Configure authorization gates.
     */
    private function configureAuthorization(): void
    {
        Gate::define('viewApiDocs', static fn (?User $user): bool => $user instanceof User);
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
