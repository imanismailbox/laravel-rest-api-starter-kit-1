<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

use App\Enums\ApiErrorCode;
use Illuminate\Testing\TestResponse;

TestResponse::macro('assertApiSuccess', function (?string $message = null, int $status = 200): TestResponse {
    /** @var TestResponse $this */
    $this->assertStatus($status)
        ->assertJsonPath('success', true);

    if ($message !== null) {
        $this->assertJsonPath('message', $message);
    }

    return $this;
});

TestResponse::macro('assertApiError', function (ApiErrorCode|string $errorCode, ?int $status = null): TestResponse {
    /** @var TestResponse $this */
    if ($status !== null) {
        $this->assertStatus($status);
    }

    $expectedCode = $errorCode instanceof ApiErrorCode ? $errorCode->value : $errorCode;

    return $this->assertJsonPath('success', false)
        ->assertJsonPath('error_code', $expectedCode);
});

TestResponse::macro('assertApiValidationError', function (?string $field = null): TestResponse {
    /** @var TestResponse $this */
    $this->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error_code', ApiErrorCode::ValidationError->value);

    if ($field !== null) {
        $this->assertJsonStructure([
            'errors' => [$field],
        ]);
    }

    return $this;
});
