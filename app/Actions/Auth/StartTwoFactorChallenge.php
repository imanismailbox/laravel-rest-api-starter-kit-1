<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final readonly class StartTwoFactorChallenge
{
    public const int TTL_SECONDS = 300;

    public static function cacheKey(string $token): string
    {
        return 'two_factor_challenge:'.hash('sha256', $token);
    }

    /**
     * Store a short-lived, single-use challenge for a user who passed the password check.
     */
    public function handle(User $user, string $deviceName): string
    {
        $token = Str::random(64);
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        Cache::put(self::cacheKey($token), [
            'user_id' => $user->id,
            'device_name' => $deviceName,
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        return $token;
    }
}
