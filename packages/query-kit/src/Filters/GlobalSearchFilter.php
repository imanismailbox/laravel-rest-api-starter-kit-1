<?php

declare(strict_types=1);

namespace Liamsinami\QueryKit\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class GlobalSearchFilter implements Filter
{
    /**
     * @param  array<int, string>  $columns
     */
    public function __construct(
        private array $columns,
    ) {}

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        unset($property);

        $search = mb_trim(is_array($value) ? implode(',', $value) : (string) $value);

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($search): void {
            foreach ($this->columns as $column) {
                $this->applyColumnSearch($searchQuery, $column, $search);
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyColumnSearch(Builder $query, string $column, string $search): void
    {
        if (! str_contains($column, '.')) {
            $this->applyMatch($query, 'orWhere', $column, $search);

            return;
        }

        $query->orWhereHas(Str::beforeLast($column, '.'), function (Builder $relationQuery) use ($column, $search): void {
            $this->applyMatch($relationQuery, 'where', Str::afterLast($column, '.'), $search);
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyMatch(Builder $query, string $method, string $column, string $search): void
    {
        $query->{$method}($column, $this->operator($query), "%{$search}%");
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function operator(Builder $query): string
    {
        return $query->getModel()->getConnection()->getDriverName() === 'pgsql'
            ? 'ILIKE'
            : 'LIKE';
    }
}
