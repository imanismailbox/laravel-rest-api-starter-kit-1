<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ActivityLogResource;
use App\Query\Definitions\ActivityLogQueryDefinition;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liamsinami\ApiEssentials\ApiResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

#[Group('Activity Log')]
final class ActivityLogController extends ApiController
{
    protected string $model = Activity::class;

    protected string $resource = ActivityLogResource::class;

    protected string $indexMessage = 'Activity logs retrieved successfully.';

    /**
     * Display a paginated list of activity logs.
     */
    public function index(Request $request): Response
    {
        return $this->handleIndex(ActivityLogQueryDefinition::class, $request);
    }

    /**
     * Display the specified activity log.
     */
    public function show(Activity $activityLog): JsonResponse
    {
        $activityLog->loadMissing(['causer', 'subject']);

        return ApiResponse::success(
            data: new ActivityLogResource($activityLog),
            message: 'Activity log retrieved successfully.',
        );
    }

    /**
     * Eager load relations on activity log queries to eliminate N+1 queries.
     *
     * @param  QueryBuilder<Model>  $query
     * @param  class-string  $definition
     * @return QueryBuilder<Model>
     */
    protected function augmentIndexQuery(QueryBuilder $query, string $definition, Request $request): QueryBuilder
    {
        $query->getEloquentBuilder()->with(['causer', 'subject']);

        return $query;
    }
}
