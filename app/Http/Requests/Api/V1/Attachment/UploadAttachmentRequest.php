<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Attachment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UploadAttachmentRequest extends FormRequest
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
        $maxSize = (int) config('attachment.max_size', 20480);
        $allowedExtensions = implode(',', config('attachment.allowed_extensions', []));
        $extensionRule = $allowedExtensions !== '' ? "extensions:{$allowedExtensions}" : 'string';

        return [
            'file' => ['required_without:files', 'file', "max:{$maxSize}", $extensionRule],
            'files' => ['required_without:file', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', "max:{$maxSize}", $extensionRule],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_private' => ['sometimes', 'boolean'],
            'attachable_type' => ['sometimes', 'string', 'max:255'],
            'attachable_id' => ['required_with:attachable_type', 'string'],
        ];
    }

    /**
     * Get array of all uploaded files (single or multiple).
     *
     * @return array<int, UploadedFile>
     */
    public function uploadedFiles(): array
    {
        if ($this->hasFile('files')) {
            /** @var array<int, UploadedFile> $files */
            $files = $this->file('files');

            return array_filter($files, fn ($file): bool => $file instanceof UploadedFile);
        }

        if ($this->hasFile('file')) {
            /** @var UploadedFile $file */
            $file = $this->file('file');

            return [$file];
        }

        return [];
    }

    /**
     * Resolve the optional attachable model instance.
     *
     * @throws ValidationException
     */
    public function resolveAttachable(): ?Model
    {
        $type = $this->input('attachable_type');
        $id = $this->input('attachable_id');

        if (! $type || ! $id) {
            return null;
        }

        $type = (string) $type;
        $id = (string) $id;

        /** @var class-string<Model>|null $modelClass */
        $modelClass = Relation::getMorphedModel($type) ?? ($type === User::class ? User::class : null);

        if ($modelClass !== User::class) {
            throw ValidationException::withMessages([
                'attachable_type' => ["Invalid attachable type [{$type}]."],
            ]);
        }

        /** @var Model|null $model */
        $model = $modelClass::query()->find($id);

        if (! $model) {
            throw ValidationException::withMessages([
                'attachable_id' => ["Target attachable resource [{$id}] not found."],
            ]);
        }

        Gate::authorize('updateAttachments', $model);

        return $model;
    }

    /**
     * Prepare inputs for validation (handling multipart/form-data boolean strings).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_private')) {
            $converted = filter_var($this->input('is_private'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($converted !== null) {
                $this->merge(['is_private' => $converted]);
            }
        }
    }
}
