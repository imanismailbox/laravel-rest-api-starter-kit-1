<?php

declare(strict_types=1);

namespace Liamsinami\ApiEssentials;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Liamsinami\ApiEssentials\Enums\ApiErrorCode;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    /**
     * Build a standardized success response.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = Response::HTTP_OK,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status, $headers);
    }

    /**
     * Build a 201 Created success response.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function created(
        mixed $data = null,
        ?string $message = 'Resource created successfully.',
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        return self::success($data, $message, Response::HTTP_CREATED, $meta, $headers);
    }

    /**
     * Build a 204 No Content response.
     */
    public static function noContent(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Build a paginated success response.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string|null  $resourceClass
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        ?string $resourceClass = null,
        ?string $message = null,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        $items = $paginator->items();

        if ($resourceClass !== null && method_exists($resourceClass, 'collection')) {
            $items = $resourceClass::collection($items);
        }

        $paginationMeta = [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];

        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $items,
            'meta' => array_merge($paginationMeta, $meta),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];

        return response()->json($payload, Response::HTTP_OK, $headers);
    }

    /**
     * Build a standardized error response.
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $message,
        int $status = Response::HTTP_BAD_REQUEST,
        ?ApiErrorCode $errorCode = null,
        array $errors = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode?->value,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status, $headers);
    }

    /**
     * Build a 422 Validation error response.
     *
     * @param  array<string, mixed>  $errors
     */
    public static function validation(
        array $errors,
        string $message = 'The given data was invalid.',
    ): JsonResponse {
        return self::error(
            message: $message,
            status: Response::HTTP_UNPROCESSABLE_ENTITY,
            errorCode: ApiErrorCode::ValidationError,
            errors: $errors,
        );
    }

    /**
     * Build a 401 Unauthenticated error response.
     */
    public static function unauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return self::error(
            message: $message,
            status: Response::HTTP_UNAUTHORIZED,
            errorCode: ApiErrorCode::Unauthenticated,
        );
    }

    /**
     * Build a 403 Forbidden error response.
     */
    public static function forbidden(
        string $message = 'This action is unauthorized.',
        ?ApiErrorCode $errorCode = ApiErrorCode::Forbidden,
    ): JsonResponse {
        return self::error(
            message: $message,
            status: Response::HTTP_FORBIDDEN,
            errorCode: $errorCode,
        );
    }

    /**
     * Build a 404 Resource Not Found error response.
     */
    public static function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return self::error(
            message: $message,
            status: Response::HTTP_NOT_FOUND,
            errorCode: ApiErrorCode::ResourceNotFound,
        );
    }

    /**
     * Build a 500 Internal Server Error response.
     */
    public static function serverError(string $message = 'Internal server error occurred.'): JsonResponse
    {
        return self::error(
            message: $message,
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
            errorCode: ApiErrorCode::ServerError,
        );
    }
}
