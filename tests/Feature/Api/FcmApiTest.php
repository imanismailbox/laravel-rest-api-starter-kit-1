<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\FcmToken;
use App\Models\User;
use App\Notifications\GenericFcmNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

test('user can register an fcm device token', function (): void {
    $response = $this->postJson(route('api.v1.fcm.tokens.store'), [
        'token' => 'fcm_token_sample_abc123',
        'device_type' => 'android',
        'device_name' => 'Samsung Galaxy S24',
    ])->assertApiSuccess('Device token registered successfully.', 201);

    expect($response->json('data.device_type'))->toBe('android')
        ->and($response->json('data.device_name'))->toBe('Samsung Galaxy S24');

    $this->assertDatabaseHas('fcm_tokens', [
        'user_id' => $this->user->id,
        'token' => 'fcm_token_sample_abc123',
        'device_type' => 'android',
    ]);
});

test('registering existing fcm token updates ownership without creating duplicates', function (): void {
    $otherUser = User::factory()->create();
    $otherUser->registerFcmToken('shared_token_xyz', 'android', 'Old Device');

    expect(FcmToken::where('token', 'shared_token_xyz')->first()->user_id)->toBe($otherUser->id);

    // Current user registers same token (e.g. user logged in on same device)
    $this->postJson(route('api.v1.fcm.tokens.store'), [
        'token' => 'shared_token_xyz',
        'device_type' => 'android',
        'device_name' => 'New Device',
    ])->assertApiSuccess('Device token registered successfully.', 201);

    expect(FcmToken::where('token', 'shared_token_xyz')->count())->toBe(1)
        ->and(FcmToken::where('token', 'shared_token_xyz')->first()->user_id)->toBe($this->user->id);
});

test('user can list their registered fcm tokens', function (): void {
    $this->user->registerFcmToken('token_1', 'android', 'Pixel 8');
    $this->user->registerFcmToken('token_2', 'android', 'Tablet');

    $response = $this->getJson(route('api.v1.fcm.tokens.index'))
        ->assertApiSuccess('Device tokens retrieved successfully.');

    expect($response->json('data'))->toBeArray()
        ->and(count($response->json('data')))->toBe(2);
});

test('user can revoke an fcm device token', function (): void {
    $this->user->registerFcmToken('revoke_me_token', 'android');

    $this->assertDatabaseHas('fcm_tokens', ['token' => 'revoke_me_token']);

    $this->deleteJson(route('api.v1.fcm.tokens.destroy'), [
        'token' => 'revoke_me_token',
    ])->assertApiSuccess('Device token revoked successfully.');

    $this->assertDatabaseMissing('fcm_tokens', ['token' => 'revoke_me_token']);
});

test('user can dispatch a test push notification', function (): void {
    Notification::fake();

    $this->user->registerFcmToken('test_token_123', 'android');

    $this->postJson(route('api.v1.fcm.test'), [
        'title' => 'Halo dari Server!',
        'body' => 'Ini adalah notifikasi uji coba.',
        'data' => ['screen' => 'order_detail', 'order_id' => '99'],
    ])->assertApiSuccess('Test notification dispatched successfully.');

    Notification::assertSentTo(
        $this->user,
        GenericFcmNotification::class,
        function (GenericFcmNotification $notification): bool {
            return $notification->title === 'Halo dari Server!'
                && $notification->body === 'Ini adalah notifikasi uji coba.'
                && $notification->data['order_id'] === '99';
        }
    );
});

test('generic FCM notification builds the actual channel message', function (): void {
    $notification = new GenericFcmNotification(
        title: 'Order ready',
        body: 'Order 99 is ready.',
        data: ['order_id' => '99'],
        image: 'https://example.com/order.png',
    );

    expect($notification->toFcm($this->user)->toArray())->toBe([
        'data' => ['order_id' => '99'],
        'notification' => [
            'title' => 'Order ready',
            'body' => 'Order 99 is ready.',
            'image' => 'https://example.com/order.png',
        ],
    ]);
    expect((new GenericFcmNotification('Title', 'Body'))->toFcm($this->user)->toArray())
        ->toBe(['notification' => ['title' => 'Title', 'body' => 'Body']]);
});

test('test notification rejects non-string FCM data', function (mixed $value): void {
    $this->user->registerFcmToken('validation_token', 'android');
    $this->postJson(route('api.v1.fcm.test'), [
        'title' => 'Test',
        'body' => 'Body',
        'data' => ['order_id' => $value],
    ])->assertApiValidationError('data.order_id');
})->with([99, false, null, [['nested' => 'value']]]);

test('test notification fails when user has no registered devices', function (): void {
    $this->postJson(route('api.v1.fcm.test'), [
        'title' => 'Test',
        'body' => 'Body',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'No registered device tokens found for this user.');
});

test('model HasFcmTokens helper methods work properly', function (): void {
    $token1 = $this->user->registerFcmToken('token_a', 'android', 'Device A');
    $token2 = $this->user->registerFcmToken('token_b', 'ios', 'Device B');

    expect($this->user->routeNotificationForFcm())->toContain('token_a', 'token_b');

    $this->user->revokeFcmToken('token_a');
    expect($this->user->routeNotificationForFcm())->not->toContain('token_a')
        ->and($this->user->routeNotificationForFcm())->toContain('token_b');

    $this->user->revokeAllFcmTokens();
    expect($this->user->routeNotificationForFcm())->toBeEmpty();
});
