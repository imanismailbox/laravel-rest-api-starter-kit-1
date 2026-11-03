<?php

declare(strict_types=1);

return [
    // Convention lookups used by the Scramble extension for `index` routes.
    'definition_namespace' => 'App\\Query\\Definitions',
    'resource_namespace' => 'App\\Http\\Resources',

    // Top-level query params (`?search=`) accepted as shorthand for `filter[search]`.
    'direct_filters' => ['search', 'trashed'],
];
