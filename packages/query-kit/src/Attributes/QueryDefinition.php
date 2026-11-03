<?php

declare(strict_types=1);

namespace Liamsinami\QueryKit\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class QueryDefinition
{
    /**
     * @param  array<int, string>  $allowFilter
     * @param  array<int, string>  $allowSort
     * @param  array<int, string>  $allowInclude
     * @param  array<int, string>  $searchable
     * @param  array<int, string>  $allowField
     */
    public function __construct(
        public int $paginatePerpage = 15,
        public array $allowFilter = [],
        public array $allowSort = [],
        public ?string $defaultSort = '-created_at',
        public array $allowInclude = [],
        public array $searchable = [],
        public array $allowField = [],
        public bool $disableFilter = false,
        public bool $disableSort = false,
        public bool $disablePaginate = false,
        public bool $disableInclude = false,
        public bool $disableExport = false,
    ) {}
}
