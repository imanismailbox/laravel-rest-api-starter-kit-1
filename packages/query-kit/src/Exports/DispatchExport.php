<?php

declare(strict_types=1);

namespace Liamsinami\QueryKit\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Liamsinami\QueryKit\Builders\QueryBuilderFactory;
use Liamsinami\QueryKit\Support\QueryContext;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

final class DispatchExport
{
    /**
     * @param  class-string  $definition
     */
    public function handle(string $definition, QueryContext $context): Response
    {
        $query = QueryBuilderFactory::make($definition, $context);

        /** @var Builder<Model> $eloquentBuilder */
        $eloquentBuilder = $query->getEloquentBuilder();

        $filename = sprintf('%s-%s.xlsx', Str::kebab(class_basename($definition)), now()->format('Y-m-d_His'));

        return Excel::download(new QueryExport($eloquentBuilder), $filename);
    }
}
