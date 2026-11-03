<?php

declare(strict_types=1);

namespace Liamsinami\ApiEssentials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BatchOperationRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function ids(): array
    {
        /** @var array<int, string> $ids */
        $ids = $this->validated('ids');

        return $ids;
    }
}
