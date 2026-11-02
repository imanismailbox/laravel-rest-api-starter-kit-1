<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Setting;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'settings' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->input('settings');
            if (! is_array($data)) {
                return;
            }

            $existing = Setting::query()->whereIn('key', array_keys($data))->get()->keyBy('key');
            foreach ($data as $key => $value) {
                $setting = $existing->get($key) ?? new Setting;
                try {
                    $setting->setCastedValue($value);
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('settings.'.$key, $exception->getMessage());
                }
            }
        });
    }

    /**
     * Get settings data to update.
     *
     * @return array<string, mixed>
     */
    public function settingsData(): array
    {
        return $this->validated('settings', []);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('settings')) {
            $this->merge(['settings' => $this->except(['_token', '_method'])]);
        }
    }
}
