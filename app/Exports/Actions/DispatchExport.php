<?php

declare(strict_types=1);

namespace App\Exports\Actions;

use App\Exports\Exporters\QueryExport;
use App\Query\Builders\QueryBuilderFactory;
use App\Query\Support\QueryContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
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
