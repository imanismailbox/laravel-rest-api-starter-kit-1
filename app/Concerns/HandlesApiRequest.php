<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Liamsinami\ApiEssentials\ApiResponse;
use Liamsinami\QueryKit\Builders\QueryBuilderFactory;
use Liamsinami\QueryKit\Exports\DispatchExport;
use Liamsinami\QueryKit\Support\QueryContext;
use Liamsinami\QueryKit\Support\QueryDefinitionParser;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

trait HandlesApiRequest
{
    /**
     * @param  class-string  $definition
     */
    protected function handleIndex(string $definition, Request $request): Response
    {
        $context = QueryContext::fromRequest($request);
        $parsed = QueryDefinitionParser::parse($definition);

        if ($context->wantsExport() && ! $parsed['disable_export']) {
            return resolve(DispatchExport::class)->handle($definition, $context);
        }

        $resourceClass = $this->resolveResourceClass();
        $query = $this->augmentIndexQuery(
            QueryBuilderFactory::make($definition, $context),
            $definition,
            $request,
        );

        $defaultPagination = ! $parsed['disable_paginate'];

        if (! $context->wantsPagination($defaultPagination)) {
            $limit = min(max((int) ($request->query('limit') ?? 30), 1), 100);
            $items = $query->take($limit)->get();
            $collection = method_exists($resourceClass, 'collection')
                ? $resourceClass::collection($items)
                : $items;

            return ApiResponse::success(
                data: $collection,
                message: $this->indexSuccessMessage($definition),
            );
        }

        $perPage = $context->perPage($parsed['paginate_perpage'] ?? 15);
        $paginator = $query
            ->paginate($perPage)
            ->appends($context->params);

        return ApiResponse::paginated(
            paginator: $paginator,
            resourceClass: $resourceClass,
            message: $this->indexSuccessMessage($definition),
        );
    }

    /**
     * @return class-string<JsonResource>
     */
    protected function resolveResourceClass(): string
    {
        if (property_exists($this, 'resource') && is_string($this->resource) && is_a($this->resource, JsonResource::class, true)) {
            return $this->resource;
        }

        return JsonResource::class;
    }

    /**
     * @param  class-string  $definition
     */
    protected function indexSuccessMessage(string $definition): string
    {
        if (property_exists($this, 'indexMessage') && is_string($this->indexMessage)) {
            return $this->indexMessage;
        }

        $base = str_replace('QueryDefinition', '', class_basename($definition));
        $plural = Str::plural(Str::headline($base));

        return "{$plural} retrieved successfully.";
    }

    /**
     * @param  QueryBuilder<Model>  $query
     * @param  class-string  $definition
     * @return QueryBuilder<Model>
     */
    protected function augmentIndexQuery(QueryBuilder $query, string $definition, Request $request): QueryBuilder
    {
        return $query;
    }
}
