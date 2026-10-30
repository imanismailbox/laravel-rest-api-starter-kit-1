<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['name' => 'System Admin']);
    $this->admin->assignRole(UserRole::Admin->value);
    Sanctum::actingAs($this->admin);
});

test('can list paginated users with search and role filter', function (): void {
    $userA = User::factory()->create(['name' => 'Alice Wonder', 'email' => 'alice@example.com']);
    $userA->assignRole(UserRole::Admin->value);

    User::factory()->create(['name' => 'Bob Builder', 'email' => 'bob@example.com']);

    $response = $this->getJson(route('api.v1.users.index', ['search' => 'Alice']))
        ->assertApiSuccess('Users retrieved successfully.')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.email', 'alice@example.com');

    $filterSearchResponse = $this->getJson(route('api.v1.users.index', ['filter' => ['search' => 'Alice']]))
        ->assertApiSuccess('Users retrieved successfully.')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.email', 'alice@example.com');

    $roleResponse = $this->getJson(route('api.v1.users.index', ['role' => 'admin']))
        ->assertApiSuccess('Users retrieved successfully.');

    expect($roleResponse->json('meta.total'))->toBeGreaterThanOrEqual(2);

    $sortedResponse = $this->getJson(route('api.v1.users.index', ['sort' => 'name']))
        ->assertApiSuccess('Users retrieved successfully.');

    expect($sortedResponse->json('data.0.name'))->toBe('Alice Wonder');
});

test('roles and permissions are omitted on user list by default and included via query parameter', function (): void {
    $user = User::factory()->create(['name' => 'Included User']);
    $user->assignRole(UserRole::Admin->value);

    // Default without includes: roles and permissions are omitted
    $defaultResponse = $this->getJson(route('api.v1.users.index', ['search' => 'Included User']))
        ->assertApiSuccess('Users retrieved successfully.');

    expect($defaultResponse->json('data.0'))->not->toHaveKey('roles')
        ->and($defaultResponse->json('data.0'))->not->toHaveKey('permissions');

    // With include=roles: roles are present
    $includedResponse = $this->getJson(route('api.v1.users.index', ['search' => 'Included User', 'include' => 'roles']))
        ->assertApiSuccess('Users retrieved successfully.');

    expect($includedResponse->json('data.0.roles'))->toBe(['admin']);
});

test('can create a new user via api', function (): void {
    $payload = [
        'name' => 'Charlie Chaplin',
        'email' => 'charlie@example.com',
        'password' => 'Password123!',
        'email_verified' => true,
        'roles' => ['admin'],
    ];

    $response = $this->postJson(route('api.v1.users.store'), $payload);

    $response->assertApiSuccess('User created successfully.', 201)
        ->assertJsonPath('data.name', 'Charlie Chaplin')
        ->assertJsonPath('data.email', 'charlie@example.com')
        ->assertJsonPath('data.roles', ['admin']);

    $this->assertDatabaseHas('users', ['email' => 'charlie@example.com']);
});

test('can retrieve single user details', function (): void {
    $user = User::factory()->create(['name' => 'David Hasselhoff']);
    $user->assignRole(UserRole::Admin->value);

    $this->getJson(route('api.v1.users.show', $user))
        ->assertApiSuccess('User retrieved successfully.')
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'David Hasselhoff')
        ->assertJsonPath('data.roles', ['admin']);
});

test('can update user details and roles', function (): void {
    $user = User::factory()->create(['name' => 'Evan Old']);

    $this->putJson(route('api.v1.users.update', $user), [
        'name' => 'Evan New',
        'roles' => ['admin'],
    ])->assertApiSuccess('User updated successfully.')
        ->assertJsonPath('data.name', 'Evan New')
        ->assertJsonPath('data.roles', ['admin']);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Evan New',
    ]);
});

test('can delete a user (soft delete)', function (): void {
    $user = User::factory()->create();

    $this->deleteJson(route('api.v1.users.destroy', $user))
        ->assertApiSuccess('User deleted successfully.');

    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

test('can batch delete multiple users (soft delete)', function (): void {
    $users = User::factory()->count(3)->create();
    $ids = $users->pluck('id')->all();

    $this->deleteJson(route('api.v1.users.batch_destroy'), [
        'ids' => $ids,
    ])->assertApiSuccess('Successfully deleted 3 users.')
        ->assertJsonPath('data.deleted_count', 3);

    foreach ($ids as $id) {
        $this->assertSoftDeleted('users', ['id' => $id]);
    }
});

test('can restore a soft deleted user', function (): void {
    $user = User::factory()->create();
    $user->delete();

    $this->postJson(route('api.v1.users.restore', $user->id))
        ->assertApiSuccess('User restored successfully.')
        ->assertJsonPath('data.id', $user->id);

    $this->assertNotSoftDeleted('users', ['id' => $user->id]);
});

test('can batch restore soft deleted users', function (): void {
    $users = User::factory()->count(2)->create();
    $ids = $users->pluck('id')->all();
    foreach ($users as $u) {
        $u->delete();
    }

    $this->postJson(route('api.v1.users.batch_restore'), ['ids' => $ids])
        ->assertApiSuccess('Successfully restored 2 resources.')
        ->assertJsonPath('data.restored_count', 2);

    foreach ($ids as $id) {
        $this->assertNotSoftDeleted('users', ['id' => $id]);
    }
});

test('can permanently force delete a user', function (): void {
    $user = User::factory()->create();
    $user->delete();

    $this->deleteJson(route('api.v1.users.force_destroy', $user->id))
        ->assertApiSuccess('User permanently deleted.');

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('can filter users by trashed status', function (): void {
    $active = User::factory()->create(['name' => 'Active User']);
    $trashed = User::factory()->create(['name' => 'Trashed User']);
    $trashed->delete();

    $onlyTrashed = $this->getJson(route('api.v1.users.index', ['filter' => ['trashed' => 'only']]))
        ->assertApiSuccess('Users retrieved successfully.');

    expect($onlyTrashed->json('meta.total'))->toBe(1);
    expect($onlyTrashed->json('data.0.id'))->toBe($trashed->id);

    $withTrashed = $this->getJson(route('api.v1.users.index', ['filter' => ['trashed' => 'with']]))
        ->assertApiSuccess('Users retrieved successfully.');

    expect($withTrashed->json('meta.total'))->toBeGreaterThanOrEqual(2);
});

test('can export users to spreadsheet', function (): void {
    $response = $this->get(route('api.v1.users.index', ['export' => 'true']));

    $response->assertOk();

    expect($response->headers->get('content-type'))
        ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('can bulk import users from spreadsheet', function (): void {
    $csvContent = "Name,Email,Username,Role,Password\nJohn Doe,john.imported@example.com,johndoe,admin,Password123!\nJane Smith,jane.imported@example.com,janesmith,admin,Password123!\n";
    $file = UploadedFile::fake()->createWithContent('users.csv', $csvContent);

    $response = $this->postJson(route('api.v1.users.import'), [
        'file' => $file,
    ])->assertApiSuccess('Bulk user import completed.')
        ->assertJsonPath('data.created_count', 2)
        ->assertJsonPath('data.errors', []);

    $this->assertDatabaseHas('users', ['email' => 'john.imported@example.com', 'username' => 'johndoe']);
    $this->assertDatabaseHas('users', ['email' => 'jane.imported@example.com', 'username' => 'janesmith']);
});

test('can download user import spreadsheet template', function (): void {
    $response = $this->get(route('api.v1.users.template'));

    $response->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('content-disposition'))
        ->toContain('users_import_template.xlsx');
});

test('regular users cannot access user management endpoints', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.users.index'))
        ->assertForbidden();

    $this->postJson(route('api.v1.users.store'), [])
        ->assertForbidden();
});
