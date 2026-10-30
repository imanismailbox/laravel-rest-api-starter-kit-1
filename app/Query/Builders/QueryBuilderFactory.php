<?php

declare(strict_types=1);

namespace App\Query\Builders;

use App\Query\Definitions\BaseQueryDefinition;
use App\Query\Filters\GlobalSearchFilter;
use App\Query\Support\QueryContext;
use App\Query\Support\QueryDefinitionParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\Includes\IncludeInterface;
use Spatie\QueryBuilder\QueryBuilder;
use Throwable;

final class QueryBuilderFactory
{
    /**
     * @var array<string, array<int, string>>
     */
    private static array $tableColumns = [];

    /**
     * @param  class-string  $definition
     */
    public static function make(string $definition, QueryContext $context): QueryBuilder
    {
        $parsed = QueryDefinitionParser::parse($definition);

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = is_subclass_of($definition, BaseQueryDefinition::class)
            ? $definition::model()
            : $definition;

        $allowedFilters = [
            ...collect($parsed['filters'])
                ->map(function ($filter) {
                    $name = $filter->name;

                    if ($name === 'role') {
                        return AllowedFilter::callback('role', function (Builder $query, mixed $value): void {
                            $query->whereHas('roles', fn (Builder $q) => $q->where('name', $value));
                        });
                    }

                    if (self::shouldUseExactFilter($name)) {
                        return AllowedFilter::exact($name);
                    }

                    return AllowedFilter::partial($name);
                })
                ->all(),
            AllowedFilter::custom('search', new GlobalSearchFilter($parsed['searchable'])),
        ];

        // If the model supports SoftDeletes, allow filtering by trashed status
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $allowedFilters[] = AllowedFilter::trashed();
        }

        // Dynamic key fields addition to allowed fields list to support relationships
        $allowedFields = $parsed['fields']->toArray();
        $includes = $parsed['includes']->toArray();

        $rootColumns = self::getTableColumns($modelClass);
        $rootKeys = array_filter($rootColumns, fn ($col) => $col === 'id' || str_ends_with($col, '_id'));
        foreach ($rootKeys as $key) {
            $allowedFields[] = $key;
        }

        foreach ($includes as $include) {
            $relatedModelClass = self::resolveRelatedModel($modelClass, $include);
            if ($relatedModelClass) {
                $relatedColumns = self::getTableColumns($relatedModelClass);
                $relatedKeys = array_filter($relatedColumns, fn ($col) => $col === 'id' || str_ends_with($col, '_id'));
                foreach ($relatedKeys as $key) {
                    $allowedFields[] = "{$include}.{$key}";
                }
            }
        }
        $allowedFields = array_unique($allowedFields);

        // Modify requested fields to always select primary and foreign keys if allowed
        $request = $context->toRequest();
        $fields = $request->query('fields');
        if (is_array($fields) && ! empty($fields)) {
            $modifiedFields = $fields;
            $tableName = (new $modelClass)->getTable();

            foreach ($fields as $resource => $columns) {
                if (! is_string($columns) || empty($columns)) {
                    continue;
                }

                $cols = array_map('trim', explode(',', $columns));

                if ($resource === $tableName) {
                    foreach ($rootKeys as $key) {
                        if (in_array($key, $allowedFields, true) && ! in_array($key, $cols, true)) {
                            $cols[] = $key;
                        }
                    }
                } else {
                    $relatedModelClass = self::resolveRelatedModel($modelClass, $resource);
                    if ($relatedModelClass) {
                        $relatedColumns = self::getTableColumns($relatedModelClass);
                        $relatedKeys = array_filter($relatedColumns, fn ($col) => $col === 'id' || str_ends_with($col, '_id'));
                        foreach ($relatedKeys as $key) {
                            $fullKey = "{$resource}.{$key}";
                            if (in_array($fullKey, $allowedFields, true) && ! in_array($key, $cols, true)) {
                                $cols[] = $key;
                            }
                        }
                    }
                }

                $modifiedFields[$resource] = implode(',', $cols);
            }
            $request->query->set('fields', $modifiedFields);
        }

        $query = QueryBuilder::for($modelClass, $request);

        if (! empty($allowedFields)) {
            $query->allowedFields(...$allowedFields);
        }

        $allowedIncludes = [];
        foreach ($parsed['includes'] as $include) {
            $parts = explode('.', $include);
            $currentModel = $modelClass;
            $isRelation = true;

            foreach ($parts as $part) {
                if (! method_exists($currentModel, $part)) {
                    $isRelation = false;
                    break;
                }
                try {
                    $relation = (new $currentModel)->$part();
                    if ($relation instanceof Relation) {
                        $currentModel = get_class($relation->getRelated());
                    } else {
                        $isRelation = false;
                        break;
                    }
                } catch (Throwable) {
                    $isRelation = false;
                    break;
                }
            }

            if ($isRelation) {
                $allowedIncludes[] = $include;
            } else {
                if (in_array($include, $rootColumns, true)) {
                    $allowedIncludes[] = AllowedInclude::custom($include, new class implements IncludeInterface
                    {
                        public function __invoke(Builder $query, string $relation): void
                        {
                            // No-op
                        }
                    });
                }
            }
        }

        return $query
            ->allowedFilters(...$allowedFilters)
            ->allowedSorts(...$parsed['sorts']->toArray())
            ->allowedIncludes(...$allowedIncludes)
            ->defaultSort($parsed['default_sort']);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return array<int, string>
     */
    private static function getTableColumns(string $modelClass): array
    {
        $instance = new $modelClass();
        $table = $instance->getTable();
        self::$tableColumns[$table] ??= $instance->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($table);

        return self::$tableColumns[$table];
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return class-string<\Illuminate\Database\Eloquent\Model>|null
     */
    private static function resolveRelatedModel(string $modelClass, string $relationPath): ?string
    {
        $parts = explode('.', $relationPath);
        $currentModel = $modelClass;

        foreach ($parts as $part) {
            if (! method_exists($currentModel, $part)) {
                return null;
            }
            try {
                $relation = (new $currentModel)->$part();
                if ($relation instanceof Relation) {
                    $currentModel = get_class($relation->getRelated());
                } else {
                    return null;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return $currentModel;
    }

    private static function shouldUseExactFilter(string $filterName): bool
    {
        $column = Str::contains($filterName, '.')
            ? Str::afterLast($filterName, '.')
            : $filterName;

        return $column === 'id' || Str::endsWith($column, '_id');
    }
}
