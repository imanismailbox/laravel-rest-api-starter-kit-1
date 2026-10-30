<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    use NormalizesEmail;

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
            'email' => ['required', 'string', 'email'],
        ];
    }
}
