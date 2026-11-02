<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\User;
use App\Services\ImageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

test('user can upload a standalone attachment file (two-step upload)', function (): void {
    $file = UploadedFile::fake()->create('contract.pdf', 1024, 'application/pdf');

    $response = $this->postJson(route('api.v1.attachments.store'), [
        'file' => $file,
        'name' => 'Custom Contract.pdf',
        'description' => 'Signed PDF contract',
        'is_private' => false,
    ])->assertApiSuccess('Attachment uploaded successfully.', 201);

    $attachmentId = $response->json('data.id');
    expect($attachmentId)->not->toBeNull()
        ->and($response->json('data.name'))->toBe('Custom Contract.pdf')
        ->and($response->json('data.extension'))->toBe('pdf')
        ->and($response->json('data.type'))->toBe('document')
        ->and($response->json('data.mime'))->toBe('application/pdf')
        ->and($response->json('data.is_private'))->toBe(false)
        ->and($response->json('data.is_image'))->toBe(false);

    $attachment = Attachment::query()->findOrFail($attachmentId);
    Storage::disk('public')->assertExists($attachment->path);
});

test('user can upload multiple files at once using files array', function (): void {
    $file1 = UploadedFile::fake()->create('doc1.pdf', 500, 'application/pdf');
    $file2 = UploadedFile::fake()->create('doc2.pdf', 600, 'application/pdf');

    $response = $this->postJson(route('api.v1.attachments.store'), [
        'files' => [$file1, $file2],
        'description' => 'Batch files',
    ])->assertApiSuccess('Attachments uploaded successfully.', 201);

    $data = $response->json('data');
    expect($data)->toBeArray()
        ->and(count($data))->toBe(2)
        ->and($data[0]['name'])->toBe('doc1.pdf')
        ->and($data[1]['name'])->toBe('doc2.pdf');
});

test('user can upload geospatial / geometry files like geojson and kml', function (): void {
    $geoFile = UploadedFile::fake()->createWithContent('region_boundary.geojson', json_encode([
        'type' => 'FeatureCollection',
        'features' => [],
    ]));

    $response = $this->postJson(route('api.v1.attachments.store'), [
        'file' => $geoFile,
    ])->assertApiSuccess('Attachment uploaded successfully.', 201);

    expect($response->json('data.extension'))->toBe('geojson')
        ->and($response->json('data.type'))->toBe('data');
});

test('upload image triggers auto-resize and dimensions check', function (): void {
    // Create an image exceeding max 1920px (e.g. 2400 x 1800)
    $file = UploadedFile::fake()->image('huge_photo.jpg', 2400, 1800);

    $response = $this->postJson(route('api.v1.attachments.store'), [
        'file' => $file,
    ])->assertApiSuccess('Attachment uploaded successfully.', 201);

    $path = $response->json('data.path');
    Storage::disk('public')->assertExists($path);

    // Verify stored image dimensions were resized to max_width <= 1920
    $fullPath = Storage::disk('public')->path($path);
    [$width, $height] = getimagesize($fullPath);
    expect($width)->toBeLessThanOrEqual(1920)
        ->and($response->json('data.type'))->toBe('image')
        ->and($response->json('data.is_image'))->toBe(true);
});

test('standalone ImageService can be used for direct model attributes like featured image or avatar thumbnail', function (): void {
    /** @var ImageService $imageService */
    $imageService = resolve(ImageService::class);
    $file = UploadedFile::fake()->image('banner.jpg', 2200, 1200);

    // 1. Resize & store
    $path = $imageService->store($file, 'posts/featured', maxWidth: 1200);
    Storage::disk('public')->assertExists($path);

    [$w, $h] = getimagesize(Storage::disk('public')->path($path));
    expect($w)->toBeLessThanOrEqual(1200);

    // 2. Store thumbnail (square 300x300)
    $thumbPath = $imageService->storeThumbnail($file, 'posts/thumbs', size: 300);
    Storage::disk('public')->assertExists($thumbPath);

    [$tw, $th] = getimagesize(Storage::disk('public')->path($thumbPath));
    expect($tw)->toBe(300)->and($th)->toBe(300);

    // 3. Delete
    $deleted = $imageService->delete($path);
    expect($deleted)->toBeTrue();
    Storage::disk('public')->assertMissing($path);
});

test('image storage derives the output format from JPEG contents', function (string $method): void {
    $source = UploadedFile::fake()->image('photo.jpg', 600, 400);
    $file = new UploadedFile($source->getPathname(), 'photo.jfif', 'image/jpeg', null, true);

    $path = resolve(ImageService::class)->{$method}($file);
    $image = getimagesize(Storage::disk('public')->path($path));

    expect(pathinfo($path, PATHINFO_EXTENSION))->toBe('jpg')
        ->and($image['mime'])->toBe('image/jpeg')
        ->and([$image[0], $image[1]])->toBe($method === 'storeThumbnail' ? [300, 300] : [600, 400]);
})->with(['store', 'storeThumbnail']);

test('user can upload a JPEG avatar with a jfif filename', function (): void {
    $source = UploadedFile::fake()->image('photo.jpg', 600, 400);
    $file = new UploadedFile($source->getPathname(), 'photo.jfif', 'image/jpeg', null, true);

    $response = $this->postJson(route('api.v1.auth.me.avatar.upload'), ['avatar' => $file])
        ->assertApiSuccess('Avatar uploaded successfully.');

    $path = $response->json('data.avatar');
    $image = getimagesize(Storage::disk('public')->path($path));
    expect($this->user->fresh()->avatar)->toBe($path)
        ->and(pathinfo($path, PATHINFO_EXTENSION))->toBe('jpg')
        ->and($image['mime'])->toBe('image/jpeg')
        ->and([$image[0], $image[1]])->toBe([500, 500]);
});

test('upload attachment can directly link to an attachable model in one step', function (): void {
    $targetUser = $this->user;
    $file = UploadedFile::fake()->image('avatar.png', 200, 200);

    $response = $this->postJson(route('api.v1.attachments.store'), [
        'file' => $file,
        'attachable_type' => User::class,
        'attachable_id' => $targetUser->id,
        'name' => 'User Avatar',
    ])->assertApiSuccess('Attachment uploaded successfully.', 201);

    $attachmentId = $response->json('data.id');
    expect($targetUser->attachments()->where('attachments.id', $attachmentId)->exists())->toBeTrue();
});

test('upload attachment validates required file or files array', function (): void {
    $this->postJson(route('api.v1.attachments.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('user can retrieve attachment details', function (): void {
    $file = UploadedFile::fake()->image('photo.jpg', 600, 400);
    $attachment = $this->user->attachFile($file, 'Profile Banner');

    $response = $this->getJson(route('api.v1.attachments.show', $attachment))
        ->assertApiSuccess('Attachment retrieved successfully.');

    expect($response->json('data.id'))->toBe($attachment->id)
        ->and($response->json('data.name'))->toBe('photo.jpg')
        ->and($response->json('data.description'))->toBe('Profile Banner')
        ->and($response->json('data.is_image'))->toBe(true);
});

test('user can download attachment file', function (): void {
    $file = UploadedFile::fake()->create('invoice.pdf', 500, 'application/pdf');
    $attachment = $this->user->attachFile($file, 'Invoice');

    $response = $this->get(route('api.v1.attachments.download', $attachment));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('invoice.pdf');
});

test('user can delete attachment and remove file from storage and pivot', function (): void {
    $file = UploadedFile::fake()->create('remove_me.pdf', 200, 'application/pdf');
    $attachment = $this->user->attachFile($file);

    Storage::disk('public')->assertExists($attachment->path);
    expect($this->user->attachments()->where('attachments.id', $attachment->id)->exists())->toBeTrue();

    $this->deleteJson(route('api.v1.attachments.destroy', $attachment))
        ->assertApiSuccess('Attachment deleted successfully.');

    $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    $this->assertDatabaseMissing('attachables', ['attachment_id' => $attachment->id]);
    Storage::disk('public')->assertMissing($attachment->path);
});

test('user can sync attachments to a model via endpoint', function (): void {
    $att1 = Attachment::factory()->create(['user_id' => $this->user->id]);
    $att2 = Attachment::factory()->create(['user_id' => $this->user->id]);

    $response = $this->postJson(route('api.v1.users.attachments.sync', $this->user), [
        'attachment_ids' => [$att1->id, $att2->id],
    ])->assertApiSuccess('User attachments synchronized successfully.');

    expect(count($response->json('data')))->toBe(2);
    expect($this->user->attachments()->count())->toBe(2);
});

test('user can detach an attachment from a model without deleting the physical file', function (): void {
    $file = UploadedFile::fake()->create('shared_doc.pdf', 100, 'application/pdf');
    $attachment = $this->user->attachFile($file);

    Storage::disk('public')->assertExists($attachment->path);

    $this->deleteJson(route('api.v1.users.attachments.detach', [$this->user, $attachment]))
        ->assertApiSuccess('Attachment unlinked from user successfully.');

    // Pivot record detached, but attachment master & file still exists
    expect($this->user->attachments()->where('attachments.id', $attachment->id)->exists())->toBeFalse();
    $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    Storage::disk('public')->assertExists($attachment->path);
});

test('can eager load attachments using declarative query include', function (): void {
    $this->user->assignRole(UserRole::Admin->value);

    $file1 = UploadedFile::fake()->create('doc1.pdf', 100, 'application/pdf');
    $file2 = UploadedFile::fake()->create('doc2.pdf', 150, 'application/pdf');

    $this->user->attachFile($file1);
    $this->user->attachFile($file2);

    // Default without includes: attachments not present
    $defaultResponse = $this->getJson(route('api.v1.users.index', ['search' => $this->user->name]))
        ->assertApiSuccess('Users retrieved successfully.');

    expect($defaultResponse->json('data.0'))->not->toHaveKey('attachments');

    // With include=attachments: attachments present
    $includedResponse = $this->getJson(route('api.v1.users.index', [
        'search' => $this->user->name,
        'include' => 'attachments',
    ]))->assertApiSuccess('Users retrieved successfully.');

    expect($includedResponse->json('data.0.attachments'))->toBeArray()
        ->and(count($includedResponse->json('data.0.attachments')))->toBe(2);
});

test('model HasAttachment helper methods work as expected', function (): void {
    $att1 = Attachment::factory()->create();
    $att2 = Attachment::factory()->create();
    $att3 = Attachment::factory()->create();

    $this->user->attachAttachments([$att1->id, $att2->id]);
    expect($this->user->attachments()->count())->toBe(2);

    $this->user->syncAttachments([$att3->id]);
    expect($this->user->attachments()->count())->toBe(1)
        ->and($this->user->attachment()?->id)->toBe($att3->id);

    $this->user->detachAttachments();
    expect($this->user->attachments()->count())->toBe(0);

    // attachFiles helper
    $file1 = UploadedFile::fake()->create('doc_a.pdf', 100, 'application/pdf');
    $file2 = UploadedFile::fake()->create('doc_b.pdf', 100, 'application/pdf');
    $created = $this->user->attachFiles([$file1, $file2]);

    expect($created->count())->toBe(2);
    expect($this->user->attachments()->count())->toBe(2);
});

test('attachment upload accepts string boolean values for is_private from form data', function (string $value, bool $expected): void {
    $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

    $response = $this->postJson(route('api.v1.attachments.store'), [
        'file' => $file,
        'name' => 'Test Boolean.pdf',
        'is_private' => $value,
    ])->assertApiSuccess('Attachment uploaded successfully.', 201);

    expect($response->json('data.is_private'))->toBe($expected);
})->with([
    ['true', true],
    ['false', false],
    ['1', true],
    ['0', false],
]);
