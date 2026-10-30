<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('health check api endpoint is publicly accessible and returns standard envelope', function (): void {
    $this->getJson(route('api.v1.health'))
        ->assertApiSuccess('API service is healthy and operational.')
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'status',
                'timestamp',
                'version',
                'environment',
            ],
        ])
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'healthy',
            ],
        ]);
});

test('unauthenticated request to protected api returns 401 standard json error', function (): void {
    $this->getJson(route('api.v1.user.show'))
        ->assertApiError(ApiErrorCode::Unauthenticated, 401)
        ->assertJson([
            'success' => false,
            'error_code' => 'UNAUTHENTICATED',
        ]);
});

test('authenticated user can retrieve their profile via sanctum token', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.user.show'))
        ->assertApiSuccess('User profile retrieved successfully.')
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
});
