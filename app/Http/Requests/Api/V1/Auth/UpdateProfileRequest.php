<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Concerns\NormalizesEmail;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends FormRequest
{
    use NormalizesEmail;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'username' => [
                'sometimes',
                'nullable',
                'string',
                'alpha_dash:ascii',
                'max:50',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
        ];
    }
}
