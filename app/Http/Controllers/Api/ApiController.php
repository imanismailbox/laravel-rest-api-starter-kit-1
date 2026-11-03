<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Concerns\HandlesApiRequest;
use App\Http\Controllers\Controller;
use Liamsinami\ApiEssentials\Concerns\HandlesBatchOperations;

abstract class ApiController extends Controller
{
    use HandlesApiRequest;
    use HandlesBatchOperations;
}
