<?php

declare(strict_types=1);

namespace Liamsinami\QueryKit\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

final class QueryExport implements FromQuery, WithChunkReading, WithCustomChunkSize, WithHeadings, WithMapping
{
    /**
     * @var array<int, string>|null
     */
    private ?array $columns = null;

    /**
     * @param  Builder<Model>  $query
     */
    public function __construct(
        public readonly Builder $query,
    ) {}

    /**
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        return $this->query;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function headings(): array
    {
        return array_map(
            static fn (string $column): string => Str::of($column)->replace('_', ' ')->title()->value(),
            $this->resolveColumns(),
        );
    }

    public function map(mixed $row): array
    {
        if (! $row instanceof Model) {
            return (array) $row;
        }

        $mapped = [];
        $attributes = $row->getAttributes();

        foreach ($this->resolveColumns() as $column) {
            if (array_key_exists($column, $attributes)) {
                $mapped[] = $attributes[$column];

                continue;
            }

            $mapped[] = $this->extractRelationValue($row->relationLoaded($column) ? $row->getRelation($column) : null);
        }

        return $mapped;
    }

    /**
     * @return array<int, string>
     */
    private function resolveColumns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $sample = (clone $this->query)->first();

        if ($sample instanceof Model) {
            $hidden = $sample->getHidden();
            $attrs = array_diff(array_keys($sample->getAttributes()), $hidden);
            $this->columns = array_values($attrs);

            return $this->columns;
        }

        $model = $this->query->getModel();

        $this->columns = $model->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($model->getTable());

        return $this->columns;
    }

    private function extractRelationValue(mixed $relation): mixed
    {
        if ($relation instanceof Model) {
            return $this->extractModelName($relation);
        }

        if ($relation instanceof Collection) {
            return $relation
                ->map(fn (mixed $item): ?string => $item instanceof Model ? $this->extractModelName($item) : (is_string($item) ? $item : null))
                ->filter()
                ->implode(', ');
        }

        return null;
    }

    private function extractModelName(Model $model): string
    {
        $name = $model->getAttribute('name') ?? $model->getAttribute('label') ?? $model->getKey();

        return (string) $name;
    }
}
