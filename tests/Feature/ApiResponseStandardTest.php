<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

test('api response helper returns standardized success payload with meta', function (): void {
    $response = ApiResponse::success(['id' => 1], 'Success test.', 200, ['custom_meta' => true]);

    expect($response->getStatusCode())->toBe(200);

    $data = $response->getData(true);
    expect($data)->toBe([
        'success' => true,
        'message' => 'Success test.',
        'data' => ['id' => 1],
        'meta' => ['custom_meta' => true],
    ]);
});

test('api response helper returns standardized paginated payload', function (): void {
    $items = collect([['id' => 1], ['id' => 2]]);
    $paginator = new LengthAwarePaginator($items, 2, 15, 1);

    $response = ApiResponse::paginated($paginator, null, 'Paginated test.');

    expect($response->getStatusCode())->toBe(200);

    $data = $response->getData(true);
    expect($data)->toHaveKeys(['success', 'message', 'data', 'meta', 'links'])
        ->and($data['success'])->toBeTrue()
        ->and($data['meta']['total'])->toBe(2);
});

test('api response helper returns standardized error payload with error code', function (): void {
    $response = ApiResponse::error('Not found.', 404, ApiErrorCode::ResourceNotFound);

    expect($response->getStatusCode())->toBe(404);

    $data = $response->getData(true);
    expect($data)->toBe([
        'success' => false,
        'message' => 'Not found.',
        'error_code' => 'RESOURCE_NOT_FOUND',
    ]);
});

test('can authenticate with valid credentials via login endpoint', function (): void {
    $user = User::factory()->create([
        'email' => 'api.user@example.com',
        'password' => Hash::make('password123'),
    ]);

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => 'api.user@example.com',
        'password' => 'password123',
        'device_name' => 'integration-test',
    ]);

    $response->assertApiSuccess('Access token issued successfully.', 201)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'token',
                'token_type',
                'user' => [
                    'id',
                    'name',
                    'email',
                ],
            ],
        ]);

    expect($response->json('data.token'))->toBeString()
        ->and(Str::isUuid((string) $response->json('data.user.id')))->toBeTrue();
});

test('login fails with 401 when invalid credentials are provided', function (): void {
    User::factory()->create([
        'email' => 'api.user@example.com',
        'password' => Hash::make('correct-password'),
    ]);

    $this->postJson(route('api.v1.auth.login'), [
        'email' => 'api.user@example.com',
        'password' => 'wrong-password',
    ])->assertApiError(ApiErrorCode::Unauthenticated, 401)
        ->assertJson([
            'message' => 'Invalid email or password.',
        ]);
});

test('login fails with 422 standard validation error when fields are missing', function (): void {
    $this->postJson(route('api.v1.auth.login'), [])
        ->assertApiValidationError('email')
        ->assertJsonValidationErrors(['email', 'password']);
});

test('authenticated user can logout and revoke their token', function (): void {
    $user = User::factory()->create();

    $token = $user->createToken('test-device')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.auth.logout'))
        ->assertApiSuccess('Successfully logged out.');

    expect($user->fresh()->tokens)->toHaveCount(0);
});

test('not found api route returns 404 standard json envelope', function (): void {
    $this->getJson('/api/v1/non-existent-endpoint')
        ->assertApiError(ApiErrorCode::ResourceNotFound, 404)
        ->assertJson([
            'success' => false,
            'message' => 'Resource not found.',
        ]);
});

test('api requests receive X-Response-Time header from LogApiRequests middleware', function (): void {
    $response = $this->getJson(route('api.v1.health'));

    $response->assertOk();

    expect($response->headers->has('X-Response-Time'))->toBeTrue();
});
