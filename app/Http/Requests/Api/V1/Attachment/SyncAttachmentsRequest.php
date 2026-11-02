<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Attachment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class SyncAttachmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAttachments', $this->route('user'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'attachment_ids' => ['present', 'array', 'max:100'],
            'attachment_ids.*' => ['required', 'integer', 'distinct', 'exists:attachments,id'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function attachmentIds(): array
    {
        /** @var array<int, int> $ids */
        $ids = $this->validated('attachment_ids');

        return $ids;
    }
}
