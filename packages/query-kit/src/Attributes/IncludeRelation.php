<?php

declare(strict_types=1);

namespace Liamsinami\QueryKit\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class IncludeRelation
{
    public function __construct(public string $name) {}
}
