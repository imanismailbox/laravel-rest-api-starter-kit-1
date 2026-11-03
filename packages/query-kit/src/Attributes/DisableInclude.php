<?php

declare(strict_types=1);

namespace Liamsinami\QueryKit\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DisableInclude {}
