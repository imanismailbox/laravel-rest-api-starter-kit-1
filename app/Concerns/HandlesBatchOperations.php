<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait HandlesBatchOperations
{
    /**
     * Batch Delete (Soft delete if model uses SoftDeletes, or permanent delete otherwise).
     *
     * @param  array<int, string>  $ids
     * @param  class-string<Model>|Model|null  $model
     */
    public function batchDestroyInternal(array $ids, string|Model|null $model = null): JsonResponse
    {
        $instance = $this->resolveBatchModel($model);

        $deletedCount = DB::transaction(fn (): int => $instance->newQuery()
            ->whereIn($instance->getKeyName(), $ids)
            ->get()
            ->each(fn (Model $item) => $this->authorizeBatchItem($item))
            ->filter(fn (Model $item): bool => (bool) $item->delete())
            ->count());

        $modelName = class_basename($instance);
        $plural = \Illuminate\Support\Str::plural(\Illuminate\Support\Str::lower($modelName));

        return ApiResponse::success(
            data: ['deleted_count' => $deletedCount],
            message: "Successfully deleted {$deletedCount} {$plural}.",
        );
    }

    /**
     * Batch Restore soft-deleted records.
     *
     * @param  array<int, string>  $ids
     * @param  class-string<Model>|Model|null  $model
     */
    public function batchRestoreInternal(array $ids, string|Model|null $model = null): JsonResponse
    {
        $instance = $this->resolveBatchModel($model);

        $restoredCount = DB::transaction(function () use ($instance, $ids): int {
            $query = $instance->newQuery();

            if (in_array(SoftDeletes::class, class_uses_recursive($instance), true)) {
                $query->onlyTrashed();
            }

            return $query->whereIn($instance->getKeyName(), $ids)
                ->get()
                ->each(fn (Model $item) => $this->authorizeBatchItem($item))
                ->filter(fn (Model $item): bool => method_exists($item, 'restore') && $item->restore())
                ->count();
        });

        return ApiResponse::success(
            data: ['restored_count' => $restoredCount],
            message: "Successfully restored {$restoredCount} resources.",
        );
    }

    /**
     * Batch Force Delete (Permanent removal from database).
     *
     * @param  array<int, string>  $ids
     * @param  class-string<Model>|Model|null  $model
     */
    public function batchForceDestroyInternal(array $ids, string|Model|null $model = null): JsonResponse
    {
        $instance = $this->resolveBatchModel($model);

        $deletedCount = DB::transaction(function () use ($instance, $ids): int {
            $query = $instance->newQuery();

            if (in_array(SoftDeletes::class, class_uses_recursive($instance), true)) {
                $query->withTrashed();
            }

            return $query->whereIn($instance->getKeyName(), $ids)
                ->get()
                ->each(fn (Model $item) => $this->authorizeBatchItem($item))
                ->filter(fn (Model $item): bool => (bool) (method_exists($item, 'forceDelete') ? $item->forceDelete() : $item->delete()))
                ->count();
        });

        return ApiResponse::success(
            data: ['deleted_count' => $deletedCount],
            message: "Successfully permanently deleted {$deletedCount} resources.",
        );
    }

    /**
     * Authorize a single record before a batch operation changes it.
     */
    protected function authorizeBatchItem(Model $item): void {}

    private function resolveBatchModel(string|Model|null $model): Model
    {
        $modelClass = $model ?? (property_exists($this, 'model') ? $this->model : null);

        if (is_string($modelClass)) {
            $modelClass = new $modelClass();
        }

        throw_unless($modelClass instanceof Model, RuntimeException::class, 'Invalid model provided for Batch Operations.');

        return $modelClass;
    }
}
