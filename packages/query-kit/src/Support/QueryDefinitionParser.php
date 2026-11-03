<?php

declare(strict_types=1);

namespace Liamsinami\QueryKit\Support;

use Illuminate\Support\Collection;
use Liamsinami\QueryKit\Attributes\DisableExport;
use Liamsinami\QueryKit\Attributes\DisableFilter;
use Liamsinami\QueryKit\Attributes\DisableInclude;
use Liamsinami\QueryKit\Attributes\DisablePaginate;
use Liamsinami\QueryKit\Attributes\DisableSort;
use Liamsinami\QueryKit\Attributes\Filter;
use Liamsinami\QueryKit\Attributes\IncludeRelation;
use Liamsinami\QueryKit\Attributes\QueryDefinition;
use Liamsinami\QueryKit\Attributes\Sort;
use ReflectionClass;

final class QueryDefinitionParser
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $cache = [];

    /**
     * Clear parsed definitions cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * @param  class-string  $definition
     * @return array{filters: Collection<int, Filter>, sorts: Collection<int, string>, includes: Collection<int, string>, fields: Collection<int, string>, disable_paginate: bool, disable_filter: bool, disable_sort: bool, disable_include: bool, disable_export: bool, paginate_perpage: int, default_sort: string, searchable: array<int, string>}
     */
    public static function parse(string $definition): array
    {
        if (isset(self::$cache[$definition])) {
            /** @var array{filters: Collection<int, Filter>, sorts: Collection<int, string>, includes: Collection<int, string>, fields: Collection<int, string>, disable_paginate: bool, disable_filter: bool, disable_sort: bool, disable_include: bool, disable_export: bool, paginate_perpage: int, default_sort: string, searchable: array<int, string>} $cached */
            $cached = self::$cache[$definition];

            return $cached;
        }

        $ref = new ReflectionClass($definition);
        $attr = $ref->getAttributes(QueryDefinition::class)[0] ?? null;
        /** @var QueryDefinition|null $attrConfig */
        $attrConfig = $attr?->newInstance();

        // Retrieve data from QueryDefinition attribute (if available)
        $filters = collect($attrConfig->allowFilter ?? [])
            ->map(fn (string $name): Filter => new Filter($name));

        $sorts = collect($attrConfig->allowSort ?? []);
        $includes = collect($attrConfig->allowInclude ?? []);
        $fields = collect($attrConfig->allowField ?? []);

        // Merge with individual repeatable attributes
        $filters = $filters->merge(
            collect($ref->getAttributes(Filter::class))
                ->map(fn ($a): Filter => $a->newInstance())
        )->unique('name');

        $sorts = $sorts->merge(
            collect($ref->getAttributes(Sort::class))
                ->map(fn ($a) => $a->newInstance()->name)
        )->unique();

        $includes = $includes->merge(
            collect($ref->getAttributes(IncludeRelation::class))
                ->map(fn ($a) => $a->newInstance()->name)
        )->unique();

        // Handle Disable flags
        $disablePaginate = ($attrConfig->disablePaginate ?? false) || count($ref->getAttributes(DisablePaginate::class)) > 0;
        $disableFilter = ($attrConfig->disableFilter ?? false) || count($ref->getAttributes(DisableFilter::class)) > 0;
        $disableSort = ($attrConfig->disableSort ?? false) || count($ref->getAttributes(DisableSort::class)) > 0;
        $disableInclude = ($attrConfig->disableInclude ?? false) || count($ref->getAttributes(DisableInclude::class)) > 0;
        $disableExport = ($attrConfig->disableExport ?? false) || count($ref->getAttributes(DisableExport::class)) > 0;

        return self::$cache[$definition] = [
            'filters' => $filters,
            'sorts' => $sorts,
            'includes' => $includes,
            'fields' => $fields,
            'disable_paginate' => $disablePaginate,
            'disable_filter' => $disableFilter,
            'disable_sort' => $disableSort,
            'disable_include' => $disableInclude,
            'disable_export' => $disableExport,
            'paginate_perpage' => $attrConfig->paginatePerpage ?? 15,
            'default_sort' => $attrConfig->defaultSort ?? '-created_at',
            'searchable' => ($attrConfig->searchable ?? []) !== []
                ? $attrConfig->searchable
                : (method_exists($definition, 'searchable') ? $definition::searchable() : []),
        ];
    }
}
