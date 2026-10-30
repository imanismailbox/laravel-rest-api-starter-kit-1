<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case User = 'user';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string>
     */
    public static function immutableRoles(): array
    {
        return [
            self::Admin->value,
            self::User->value,
        ];
    }

    public static function isImmutable(string $name): bool
    {
        return in_array($name, self::immutableRoles(), true);
    }

    /**
     * @return array<string>
     */
    public static function allPermissions(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(
                static fn (self $role): array => $role->permissions(),
                self::cases(),
            ),
        )));
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::User => 'User',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Akses penuh ke semua fitur sistem',
            self::User => 'Pengguna standar aplikasi',
        };
    }

    /**
     * @return array<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => [
                'users.view',
                'users.create',
                'users.edit',
                'users.delete',
                'roles.view',
                'roles.create',
                'roles.edit',
                'roles.delete',
                'permissions.view',
                'settings.view',
                'settings.edit',
                'backups.view',
                'backups.create',
                'activity_logs.view',
                'api_docs.view',
            ],
            self::User => [],
        };
    }
}
