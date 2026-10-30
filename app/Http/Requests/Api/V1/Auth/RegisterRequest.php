<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'username' => ['nullable', 'string', 'alpha_dash:ascii', 'max:50', 'unique:users,username'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
