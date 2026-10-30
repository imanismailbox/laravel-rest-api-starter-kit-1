<?php

declare(strict_types=1);

namespace App\Query\Definitions;

use Illuminate\Database\Eloquent\Model;

abstract class BaseQueryDefinition
{
    /**
     * @return class-string<Model>
     */
    abstract public static function model(): string;

    /**
     * @return array<int, string>
     */
    final public static function searchable(): array
    {
        return [];
    }
}
