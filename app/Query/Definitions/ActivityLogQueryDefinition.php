<?php

declare(strict_types=1);

namespace App\Query\Definitions;

use Liamsinami\QueryKit\Attributes\QueryDefinition;
use Liamsinami\QueryKit\Definitions\BaseQueryDefinition;
use Spatie\Activitylog\Models\Activity;

#[QueryDefinition(paginatePerpage: 15, allowFilter: ['log_name', 'event', 'subject_type', 'causer_id'], allowSort: ['id', 'created_at'], defaultSort: '-id', allowInclude: ['causer', 'subject'], searchable: ['description', 'log_name', 'event'], allowField: ['id', 'log_name', 'description', 'event', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'created_at', 'updated_at'])]
final class ActivityLogQueryDefinition extends BaseQueryDefinition
{
    public static function model(): string
    {
        return Activity::class;
    }
}
