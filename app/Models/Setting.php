<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;

final class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
        'is_public',
        'description',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_public' => 'boolean',
    ];

    /**
     * Auto-detect setting type based on variable type.
     */
    public static function detectType(mixed $value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_array($value)) {
            return 'array';
        }

        return 'string';
    }

    /**
     * Retrieve current settings model records ordered.
     *
     * @return Collection<int, self>
     */
    public static function getAllModels(): Collection
    {
        return self::query()->orderBy('group')->orderBy('key')->get();
    }

    /**
     * Retrieve a single setting by key, with optional default fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = self::query()->where('key', $key)->first();

        return $setting !== null ? $setting->getCastedValue() : $default;
    }

    /**
     * Set a setting value (create or update).
     */
    public static function set(string $key, mixed $value, ?string $group = null, ?string $type = null, ?bool $isPublic = null): self
    {
        /** @var self $setting */
        $setting = self::query()->firstOrNew(['key' => $key]);

        if ($group !== null) {
            $setting->group = $group;
        } elseif (! $setting->exists) {
            $setting->group = 'general';
        }

        if ($isPublic !== null) {
            $setting->is_public = $isPublic;
        }

        $setting->setCastedValue($value, $type);
        $setting->save();

        return $setting;
    }

    /**
     * Get casted value based on type.
     */
    public function getCastedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'boolean', 'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer', 'int' => (int) $this->value,
            'float', 'double', 'number' => (float) $this->value,
            'array', 'json' => json_decode((string) $this->value, true) ?? [],
            default => (string) $this->value,
        };
    }

    /**
     * Set value serialized based on type.
     */
    public function setCastedValue(mixed $value, ?string $type = null): void
    {
        $resolvedType = $type ?? $this->type ?? self::detectType($value);
        $this->type = $resolvedType;

        if ($value === null) {
            $this->value = null;

            return;
        }

        $valid = match ($resolvedType) {
            'boolean', 'bool' => (is_bool($value) || is_int($value) || is_string($value))
                && (! is_string($value) || mb_trim($value) !== '')
                && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null,
            'integer', 'int' => (is_int($value) || is_string($value))
                && filter_var($value, FILTER_VALIDATE_INT) !== false,
            'float', 'double', 'number' => is_numeric($value) && is_finite((float) $value),
            'array', 'json' => is_array($value) || is_string($value),
            default => is_scalar($value),
        };

        if (! $valid) {
            throw new InvalidArgumentException("Invalid value for setting type {$resolvedType}.");
        }

        if (in_array($resolvedType, ['array', 'json'], true)) {
            try {
                $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
                if (! is_array($decoded)) {
                    throw new InvalidArgumentException('Setting JSON must be an array or object.');
                }
                $this->value = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Invalid setting JSON.', previous: $exception);
            }

            return;
        }

        $this->value = match ($resolvedType) {
            'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            default => (string) $value,
        };
    }
}
