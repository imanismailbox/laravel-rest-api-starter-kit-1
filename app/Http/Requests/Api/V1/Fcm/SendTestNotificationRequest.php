<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Fcm;

use Illuminate\Foundation\Http\FormRequest;

final class SendTestNotificationRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:1000'],
            'data' => ['sometimes', 'array'],
            'data.*' => ['string'],
            'image' => ['sometimes', 'nullable', 'url'],
        ];
    }
}
