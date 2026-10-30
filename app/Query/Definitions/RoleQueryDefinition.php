<?php

declare(strict_types=1);

namespace App\Query\Definitions;

use App\Query\Attributes\QueryDefinition;
use Spatie\Permission\Models\Role;

#[QueryDefinition(paginatePerpage: 15, allowFilter: ['name', 'guard_name'], allowSort: ['name', 'id', 'created_at'], defaultSort: 'id', allowInclude: ['permissions'], searchable: ['name'], allowField: ['id', 'name', 'guard_name', 'created_at', 'updated_at'], disablePaginate: true)]
final class RoleQueryDefinition extends BaseQueryDefinition
{
    public static function model(): string
    {
        return Role::class;
    }
}
