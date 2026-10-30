<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Attachment\DeleteAttachment;
use App\Concerns\HasAttachment;
use App\Concerns\HasFcmTokens;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $username
 * @property string|null $avatar
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property string|null $two_factor_confirmed_at
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 *
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User query()
 * @method static Builder<static>|User whereCreatedAt($value)
 * @method static Builder<static>|User whereEmail($value)
 * @method static Builder<static>|User whereEmailVerifiedAt($value)
 * @method static Builder<static>|User whereId($value)
 * @method static Builder<static>|User whereName($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User whereRememberToken($value)
 * @method static Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static Builder<static>|User whereTwoFactorSecret($value)
 * @method static Builder<static>|User whereUpdatedAt($value)
 *
 * @mixin Model
 */
#[Fillable(['name', 'email', 'username', 'password', 'avatar', 'email_verified_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
final class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasAttachment;
    use HasFactory;
    use HasFcmTokens;
    use HasRoles;
    use HasUuids;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    /**
     * Get the full URL for the user's avatar.
     */
    public function avatarUrl(): ?string
    {
        $avatar = $this->getAttribute('avatar');

        if (! $avatar) {
            return null;
        }

        if (str_starts_with((string) $avatar, 'http://') || str_starts_with((string) $avatar, 'https://')) {
            return (string) $avatar;
        }

        $disk = (string) config('filesystems.default');

        return Storage::disk($disk)->url((string) $avatar);
    }

    /**
     * Hash used in email verification links; changing the email invalidates old links.
     */
    public function emailVerificationHash(): string
    {
        return hash('sha256', $this->getEmailForVerification());
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Get activitylog options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        self::updated(static function (User $user): void {
            if ($user->wasChanged('password')) {
                $user->tokens()->delete();
            }
        });

        // Attachment rows null their user_id on delete, so remove the user's files first.
        self::forceDeleting(static function (User $user): void {
            Attachment::query()->where('user_id', $user->id)
                ->each(static fn (Attachment $attachment): bool => app(DeleteAttachment::class)->handle($attachment));

            $avatar = $user->avatar;
            if ($avatar && ! str_starts_with($avatar, 'http')) {
                $disk = (string) config('filesystems.default');
                DB::afterCommit(static fn () => rescue(static fn () => Storage::disk($disk)->delete($avatar), report: true));
            }
        });
    }

    /**
     * Store emails lowercased so lookups and unique checks ignore case.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => mb_strtolower(mb_trim($value)));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
