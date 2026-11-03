<?php

declare(strict_types=1);

return [
    'definition_namespace' => 'App\\Query\\Definitions',
    'resource_namespace' => 'App\\Http\\Resources',
    'direct_filters' => ['search', 'role', 'log_name', 'event', 'subject_type', 'guard_name', 'trashed'],
];
