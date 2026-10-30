<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

final readonly class CompleteTwoFactorChallenge
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(private TwoFactorAuthenticationProvider $provider) {}

    /**
     * Check a TOTP or recovery code against a pending challenge.
     *
     * @return array{user: User, device_name: string}
     *
     * @throws ValidationException
     */
    public function handle(string $token, ?string $code, ?string $recoveryCode): array
    {
        $key = StartTwoFactorChallenge::cacheKey($token);

        // Pull first so a challenge can never be completed twice, even by concurrent requests.
        /** @var array{user_id: string, device_name: string, attempts: int, expires_at: int}|null $challenge */
        $challenge = Cache::pull($key);
        $user = is_array($challenge) ? User::query()->find($challenge['user_id']) : null;

        if ($challenge === null || ! $user instanceof User || ! $user->hasEnabledTwoFactorAuthentication()) {
            throw $this->invalid();
        }

        if (! $this->passes($user, $code, $recoveryCode)) {
            $challenge['attempts']++;

            if ($challenge['attempts'] < self::MAX_ATTEMPTS) {
                Cache::put($key, $challenge, Date::createFromTimestamp($challenge['expires_at']));
            }

            throw $this->invalid();
        }

        return ['user' => $user, 'device_name' => $challenge['device_name']];
    }

    private function passes(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if (is_string($code) && $code !== '') {
            return $this->provider->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $code);
        }

        if (is_string($recoveryCode) && in_array($recoveryCode, $user->recoveryCodes(), true)) {
            $user->replaceRecoveryCode($recoveryCode);

            return true;
        }

        return false;
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => ['The two-factor code is invalid or the challenge has expired. Please log in again.'],
        ]);
    }
}
