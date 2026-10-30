<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lowercase the email before validation so unique checks match the stored value.
 *
 * @mixin FormRequest
 */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(mb_trim($email))]);
        }
    }
}
