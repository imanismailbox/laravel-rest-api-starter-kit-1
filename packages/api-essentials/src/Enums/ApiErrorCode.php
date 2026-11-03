<?php

declare(strict_types=1);

namespace Liamsinami\ApiEssentials\Enums;

enum ApiErrorCode: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case Conflict = 'CONFLICT';
    case BadRequest = 'BAD_REQUEST';
    case ServerError = 'INTERNAL_SERVER_ERROR';
}
