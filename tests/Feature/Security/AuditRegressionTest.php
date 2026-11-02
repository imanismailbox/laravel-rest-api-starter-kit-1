<?php

declare(strict_types=1);

use App\Actions\User\ImportUsers;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
});

test('delete delegates cannot delete restore or force delete administrators', function (): void {
    $delegate = User::factory()->create();
    $delegate->givePermissionTo(['users.delete', 'users.edit']);
    Sanctum::actingAs($delegate);

    $this->deleteJson(route('api.v1.users.destroy', $this->admin))->assertForbidden();
    $this->deleteJson(route('api.v1.users.force_destroy', $this->admin->id))->assertForbidden();
    $this->deleteJson(route('api.v1.users.batch_destroy'), ['ids' => [$this->admin->id]])->assertForbidden();
    $this->deleteJson(route('api.v1.users.batch_force_destroy'), ['ids' => [$this->admin->id]])->assertForbidden();
    expect($this->admin->fresh()->trashed())->toBeFalse();

    $this->admin->delete();
    $this->postJson(route('api.v1.users.restore', $this->admin->id))->assertForbidden();
    $this->postJson(route('api.v1.users.batch_restore'), ['ids' => [$this->admin->id]])->assertForbidden();
    expect(User::withTrashed()->findOrFail($this->admin->id)->trashed())->toBeTrue();
});

test('delete delegates can still delete ordinary users', function (): void {
    $delegate = User::factory()->create();
    $delegate->givePermissionTo('users.delete');

    $target = User::factory()->create();
    Sanctum::actingAs($delegate);

    $this->deleteJson(route('api.v1.users.batch_destroy'), ['ids' => [$target->id]])
        ->assertOk()->assertJsonPath('data.deleted_count', 1);
});

test('batch operations reject more than 100 ids', function (): void {
    Sanctum::actingAs($this->admin);

    $ids = array_map(fn (int $i): string => (string) $i, range(1, 101));
    $this->deleteJson(route('api.v1.users.batch_destroy'), ['ids' => $ids])->assertUnprocessable();
});

test('role delegates cannot grant permissions they do not hold', function (): void {
    $delegate = User::factory()->create();
    $delegate->givePermissionTo(['roles.create', 'roles.edit', 'users.view']);
    Sanctum::actingAs($delegate);

    $this->postJson(route('api.v1.roles.store'), ['name' => 'ops', 'permissions' => ['settings.edit']])->assertForbidden();
    $this->assertDatabaseMissing('roles', ['name' => 'ops']);

    $this->postJson(route('api.v1.roles.store'), ['name' => 'viewer', 'permissions' => ['users.view']])->assertCreated();
    $viewer = Role::findByName('viewer', 'web');
    $this->putJson(route('api.v1.roles.update', $viewer), ['permissions' => ['users.delete']])->assertForbidden();
    expect($viewer->fresh()->permissions->pluck('name')->all())->toBe(['users.view']);
});

test('user delegates cannot assign roles with permissions they do not hold', function (): void {
    Role::create(['name' => 'ops', 'guard_name' => 'web'])->givePermissionTo('settings.edit');
    $delegate = User::factory()->create(['email' => 'delegate@example.com']);
    $delegate->givePermissionTo(['users.create', 'users.edit']);
    Sanctum::actingAs($delegate);

    $this->putJson(route('api.v1.users.update', $delegate), ['roles' => ['ops']])->assertForbidden();
    $result = resolve(ImportUsers::class)->handle(UploadedFile::fake()->createWithContent('users.csv', "Name,Email,Role\nMe,delegate@example.com,ops\n"));

    expect($result['updated_count'])->toBe(0)
        ->and($delegate->fresh()->hasRole('ops'))->toBeFalse();
});

test('custom roles can be assigned through the user api', function (): void {
    Role::create(['name' => 'editor', 'guard_name' => 'web']);
    Sanctum::actingAs($this->admin);

    $this->postJson(route('api.v1.users.store'), [
        'name' => 'Editor',
        'email' => 'editor@example.com',
        'password' => 'Password123!',
        'roles' => ['editor'],
    ])->assertCreated()->assertJsonPath('data.roles', ['editor']);
});

test('api tokens expire', function (): void {
    expect(config('sanctum.expiration'))->toBeInt()->toBeGreaterThan(0);
});

test('pat query tokens only authenticate attachment downloads', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();
    $token = $user->createToken('pat-test')->plainTextToken;
    $attachment = Attachment::factory()->create(['user_id' => $user->id, 'disk' => 'public']);
    Storage::disk('public')->put($attachment->path, 'content');

    $this->getJson('/api/v1/user?pat='.$token)->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->get(route('api.v1.attachments.download', $attachment).'?pat='.$token)->assertOk();
});

test('emails are case insensitive for registration and login', function (): void {
    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Bob',
        'email' => 'Bob@Example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertCreated()->assertJsonPath('data.user.email', 'bob@example.com');

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Bob Again',
        'email' => 'bob@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertUnprocessable();

    $this->postJson(route('api.v1.auth.login'), ['email' => 'BOB@example.com', 'password' => 'Password123!'])->assertCreated();
});

test('force deleting a user removes their avatar and uploaded files', function (): void {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);
    $user = User::factory()->create(['avatar' => 'avatars/me.jpg']);
    Storage::disk('public')->put('avatars/me.jpg', 'avatar');
    $attachment = Attachment::factory()->create(['user_id' => $user->id, 'disk' => 'public']);
    Storage::disk('public')->put($attachment->path, 'file');
    Sanctum::actingAs($this->admin);

    $this->deleteJson(route('api.v1.users.force_destroy', $user->id))->assertOk();

    Storage::disk('public')->assertMissing('avatars/me.jpg');
    Storage::disk('public')->assertMissing($attachment->path);
    $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
});

test('activity log and expired tokens are pruned on a schedule', function (): void {
    $commands = collect(resolve(Schedule::class)->events())->pluck('command')->implode(' ');

    expect($commands)->toContain('activitylog:clean')
        ->and($commands)->toContain('sanctum:prune-expired');
});
