<?php

declare(strict_types=1);

use App\Actions\Attachment\CreateAttachment;
use App\Actions\Auth\DeleteAvatar;
use App\Actions\Auth\UploadAvatar;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    Sanctum::actingAs($this->owner);
});

test('other users cannot read download or delete an attachment', function (): void {
    $attachment = $this->owner->attachFile(UploadedFile::fake()->create('private.pdf', 1, 'application/pdf'), isPrivate: true);
    Sanctum::actingAs($this->other);

    $this->getJson(route('api.v1.attachments.show', $attachment))->assertForbidden();
    $this->getJson(route('api.v1.attachments.download', $attachment))->assertForbidden();
    $this->deleteJson(route('api.v1.attachments.destroy', $attachment))->assertForbidden();
    expect($attachment->fresh())->not->toBeNull();
    Storage::disk('local')->assertExists($attachment->path);
});

test('administrators can access an attachment owned by another user', function (): void {
    $attachment = $this->owner->attachFile(UploadedFile::fake()->create('document.pdf', 1, 'application/pdf'));
    $this->other->assignRole(UserRole::Admin);
    Sanctum::actingAs($this->other);

    $this->getJson(route('api.v1.attachments.show', $attachment))->assertOk();
    $this->getJson(route('api.v1.attachments.download', $attachment))->assertOk();
    $this->deleteJson(route('api.v1.attachments.destroy', $attachment))->assertOk();
});

test('private uploads never use the public disk', function (): void {
    $response = $this->postJson(route('api.v1.attachments.store'), [
        'file' => UploadedFile::fake()->create('invoice.pdf', 1, 'application/pdf'),
        'is_private' => true,
    ])->assertCreated();

    $path = $response->json('data.path');
    expect($response->json('data.disk'))->toBe('local');
    Storage::disk('local')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
});

test('attachment operations cannot change another users links', function (): void {
    $attachment = $this->owner->attachFile(UploadedFile::fake()->create('document.pdf', 1, 'application/pdf'));
    $this->postJson(route('api.v1.attachments.store'), [
        'file' => UploadedFile::fake()->create('new.pdf', 1, 'application/pdf'),
        'attachable_type' => User::class,
        'attachable_id' => $this->other->id,
    ])->assertForbidden();
    $this->postJson(route('api.v1.users.attachments.sync', $this->other), [
        'attachment_ids' => [$attachment->id],
    ])->assertForbidden();
    $this->deleteJson(route('api.v1.users.attachments.detach', [$this->other, $attachment]))->assertForbidden();
    expect($this->owner->attachments()->count())->toBe(1);
});

test('sync checks every file before changing any links', function (): void {
    $mine = Attachment::factory()->create(['user_id' => $this->owner->id]);
    $theirs = Attachment::factory()->create(['user_id' => $this->other->id]);
    $this->owner->attachAttachment($mine);

    $this->postJson(route('api.v1.users.attachments.sync', $this->owner), [
        'attachment_ids' => [$mine->id, $theirs->id],
    ])->assertForbidden();
    expect($this->owner->attachments()->pluck('attachments.id')->all())->toBe([$mine->id]);

    $this->postJson(route('api.v1.users.attachments.sync', $this->owner), ['attachment_ids' => []])->assertOk();
    expect($this->owner->attachments()->count())->toBe(0);
});

test('attachment includes only list files the viewer may open', function (): void {
    $attachment = Attachment::factory()->create(['user_id' => $this->owner->id, 'is_private' => true]);
    $shared = Attachment::factory()->create(['user_id' => $this->other->id]);
    $this->owner->attachAttachment($attachment);
    $this->owner->attachAttachment($shared);

    $this->other->givePermissionTo('users.view');
    $query = ['filter' => ['email' => $this->owner->email], 'include' => 'attachments'];
    Sanctum::actingAs($this->other);

    $this->getJson(route('api.v1.users.index', $query))->assertOk()
        ->assertJsonPath('data.0.attachments.*.id', [$shared->id]);

    $this->other->assignRole(UserRole::Admin);
    expect(collect($this->getJson(route('api.v1.users.index', $query))->assertOk()->json('data.0.attachments.*.id'))->sort()->values()->all())
        ->toBe([$attachment->id, $shared->id]);
});

test('SVG uploads and unsupported attachable types are rejected', function (): void {
    $this->postJson(route('api.v1.attachments.store'), [
        'file' => UploadedFile::fake()->createWithContent('attack.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->postJson(route('api.v1.attachments.store'), [
        'file' => UploadedFile::fake()->create('document.pdf', 1, 'application/pdf'),
        'attachable_type' => Setting::class,
        'attachable_id' => 'any-id',
    ])->assertUnprocessable()->assertJsonValidationErrors('attachable_type');
    expect(Attachment::query()->count())->toBe(0);
});

test('attachment description matches database length', function (): void {
    $this->postJson(route('api.v1.attachments.store'), [
        'file' => UploadedFile::fake()->create('document.pdf', 1, 'application/pdf'),
        'description' => str_repeat('a', 256),
    ])->assertUnprocessable()->assertJsonValidationErrors('description');
});

test('avatar write failure retains the original image and path', function (): void {
    $this->owner->update(['avatar' => 'avatars/old.jpg']);
    Storage::disk('public')->put('avatars/old.jpg', 'old-image');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);

    expect(fn () => resolve(UploadAvatar::class)->handle($this->owner, UploadedFile::fake()->image('new.jpg')))
        ->toThrow(RuntimeException::class, 'Thumbnail could not be stored.');
    expect($this->owner->fresh()->avatar)->toBe('avatars/old.jpg');
});

test('avatar database failure preserves old file and cleans up replacement', function (): void {
    $this->owner->update(['avatar' => 'avatars/old.jpg']);
    Storage::disk('public')->put('avatars/old.jpg', 'old-image');
    User::updating(function (User $user): void {
        throw_if($user->isDirty('avatar'), RuntimeException::class, 'Database unavailable');
    });

    expect(fn () => resolve(UploadAvatar::class)->handle($this->owner, UploadedFile::fake()->image('new.jpg')))
        ->toThrow(RuntimeException::class, 'Database unavailable');
    expect($this->owner->fresh()->avatar)->toBe('avatars/old.jpg');
    expect(Storage::disk('public')->allFiles('avatars'))->toBe(['avatars/old.jpg']);

    expect(fn () => resolve(DeleteAvatar::class)->handle($this->owner))->toThrow(RuntimeException::class);
    Storage::disk('public')->assertExists('avatars/old.jpg');
});

test('attachment database failures remove newly stored files', function (): void {
    Attachment::creating(function (): void {
        throw new RuntimeException('Database unavailable');
    });

    expect(fn () => resolve(CreateAttachment::class)->handle(
        UploadedFile::fake()->create('new.pdf', 1, 'application/pdf'),
        userId: $this->owner->id,
    ))->toThrow(RuntimeException::class, 'Database unavailable');
    expect(Storage::disk('public')->allFiles('attachments'))->toBe([]);
    expect(Attachment::query()->count())->toBe(0);
});

test('legacy private attachments are moved without changing public files', function (): void {
    $private = Attachment::factory()->create(['is_private' => true]);
    $public = Attachment::factory()->create(['is_private' => false]);
    Storage::disk('public')->put($private->path, 'private-bytes');
    Storage::disk('public')->put($public->path, 'public-bytes');

    $this->artisan('attachments:secure-private')->assertSuccessful();
    expect($private->fresh()->disk)->toBe('local');
    expect(Storage::disk('local')->get($private->path))->toBe('private-bytes');
    Storage::disk('public')->assertMissing($private->path);
    Storage::disk('public')->assertExists($public->path);
    $this->artisan('attachments:secure-private')->assertSuccessful();
});

test('legacy cleanup never deletes the only remaining file', function (): void {
    $private = Attachment::factory()->create(['is_private' => true, 'disk' => 'local']);
    Storage::disk('public')->put($private->path, 'only-copy');

    expect(fn () => $this->artisan('attachments:secure-private')->run())->toThrow(RuntimeException::class);
    Storage::disk('public')->assertExists($private->path);
});
