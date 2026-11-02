<?php

declare(strict_types=1);

namespace App\Query\Definitions;

use App\Models\User;
use App\Query\Attributes\QueryDefinition;

#[QueryDefinition(paginatePerpage: 15, allowFilter: ['name', 'email', 'username', 'role'], allowSort: ['name', 'email', 'username', 'created_at'], defaultSort: '-created_at', allowInclude: ['roles', 'permissions', 'roles.permissions', 'attachments'], searchable: ['name', 'email', 'username'], allowField: ['id', 'name', 'username', 'email', 'created_at', 'updated_at'])]
final class UserQueryDefinition extends BaseQueryDefinition
{
    public static function model(): string
    {
        return User::class;
    }
}
