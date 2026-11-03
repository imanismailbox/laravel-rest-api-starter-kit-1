<?php

declare(strict_types=1);

namespace Liamsinami\QueryKit\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class QueryParameters
{
    /**
     * @param  class-string  $definition
     */
    public function __construct(
        public string $definition,
    ) {}
}
