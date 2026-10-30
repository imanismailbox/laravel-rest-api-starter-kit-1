<?php

declare(strict_types=1);

use App\Actions\User\ImportUsers;
use App\Enums\UserRole;
use App\Http\Resources\UserResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    Sanctum::actingAs($this->admin);
});

function importRegressionCsv(string $rows): array
{
    return resolve(ImportUsers::class)->handle(UploadedFile::fake()->createWithContent('users.csv', $rows));
}

test('imports require policy valid passwords only for new users and preserve valid rows', function (): void {
    $existing = User::factory()->create(['email' => 'existing@example.com']);
    $hash = $existing->password;
    $result = importRegressionCsv("Name,Email,Password\nMissing,missing@example.com,\nWeak,weak@example.com,short\nValid,valid@example.com,Password123!\nChanged,existing@example.com,short\n");

    expect($result['created_count'])->toBe(1)
        ->and($result['updated_count'])->toBe(1)
        ->and($result['errors'])->toHaveCount(2)
        ->and($existing->fresh()->password)->toBe($hash)
        ->and($existing->fresh()->name)->toBe('Changed')
        ->and(Hash::check('Password123!', User::query()->where('email', 'valid@example.com')->firstOrFail()->password))->toBeTrue();
    $this->assertDatabaseMissing('users', ['email' => 'missing@example.com']);
    $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
});

test('import delegates cannot promote themselves create admins or overwrite and restore administrators', function (): void {
    $delegate = User::factory()->create(['email' => 'delegate@example.com']);
    $delegate->givePermissionTo('users.create');

    $this->admin->delete();
    Sanctum::actingAs($delegate);

    $result = importRegressionCsv("Name,Email,Role,Password\nPromoted,delegate@example.com,admin,\nOverwrite,{$this->admin->email},,\nNew Admin,newadmin@example.com,admin,Password123!\nAllowed,allowed@example.com,,Password123!\n");

    expect($result['errors'])->toHaveCount(3)
        ->and($result['created_count'])->toBe(1)
        ->and($result['updated_count'])->toBe(0)
        ->and($delegate->fresh()->hasRole('admin'))->toBeFalse()
        ->and(User::withTrashed()->findOrFail($this->admin->id)->trashed())->toBeTrue();
    $this->assertDatabaseMissing('users', ['email' => 'newadmin@example.com']);
});

test('imports reject over limit sheets before any writes', function (): void {
    $rows = "Name,Email,Password\n";
    for ($i = 0; $i < 1001; $i++) {
        $rows .= "User {$i},bounded{$i}@example.com,Password123!\n";
    }

    $result = importRegressionCsv($rows);
    expect($result['created_count'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain('1000');
    expect(User::query()->where('email', 'like', 'bounded%')->count())->toBe(0);
});

test('imports accept exactly 1000 data rows across chunks', function (): void {
    $existing = User::factory()->create(['email' => 'boundary@example.com']);
    $rows = "Name,Email\n".str_repeat("Boundary,boundary@example.com\n", 1000);
    $result = importRegressionCsv($rows);
    expect($result['updated_count'])->toBe(1000)
        ->and($result['errors'])->toBe([])
        ->and($existing->fresh()->name)->toBe('Boundary');
});

test('imports reject excessively wide sheets before writes', function (): void {
    $result = importRegressionCsv(implode(',', array_fill(0, 51, 'Name'))."\n".implode(',', array_fill(0, 51, 'Value'))."\n");
    expect($result['created_count'])->toBe(0)
        ->and($result['errors'][0])->toContain('50 columns');
});

test('imports reject oversized expanded archives before reading sheets', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'workbook');
    $archive = new ZipArchive;
    try {
        $archive->open($path, ZipArchive::OVERWRITE);
        $archive->addFromString('oversized.xml', str_repeat('a', 51 * 1024 * 1024));
        $archive->close();
        $result = resolve(ImportUsers::class)->handle(new UploadedFile($path, 'users.xlsx', null, null, true));
        expect($result['created_count'])->toBe(0)
            ->and($result['errors'][0])->toContain('50 MB');
    } finally {
        unlink($path);
    }
});

test('imports only read the first spreadsheet sheet', function (): void {
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([
        ['Name', 'Email', 'Password'],
        ['First', 'firstsheet@example.com', 'Password123!'],
    ]);
    $book->createSheet()->fromArray([
        ['Name', 'Email', 'Password'],
        ...array_fill(0, 1002, ['Second', 'secondsheet@example.com', 'Password123!']),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'import');
    try {
        new Xlsx($book)->save($path);
        $result = resolve(ImportUsers::class)->handle(new UploadedFile($path, 'users.xlsx', null, null, true));
        expect($result['created_count'])->toBe(1)->and($result['errors'])->toBe([]);
        $this->assertDatabaseMissing('users', ['email' => 'secondsheet@example.com']);
    } finally {
        unlink($path);
        $book->disconnectWorksheets();
    }
});

test('sparse user queries safely return selected attributes', function (): void {
    $response = $this->getJson(route('api.v1.users.index', ['fields' => ['users' => 'name']]))->assertOk();
    expect(array_keys($response->json('data.0')))->toBe(['id', 'name']);
});

test('sparse resources preserve explicitly loaded roles permissions and attachments', function (): void {
    $user = User::query()->select(['id', 'name'])->findOrFail($this->admin->id);
    $user->load(['roles.permissions', 'permissions', 'attachments']);

    $data = new UserResource($user)->resolve(Request::create('/'));
    expect($data)->toHaveKeys(['id', 'name', 'roles', 'permissions', 'attachments'])
        ->not->toHaveKeys(['email', 'avatar', 'avatar_url', 'created_at', 'updated_at'])
        ->and($data['roles'])->toBe(['admin'])
        ->and($data['permissions'])->not->toBeEmpty();
});

test('sparse API queries preserve explicitly requested includes', function (string $include): void {
    $response = $this->getJson(route('api.v1.users.index', [
        'fields' => ['users' => 'name'],
        'include' => $include,
    ]))->assertOk();
    expect($response->json('data.0'))->toHaveKey($include);
})->with(['roles', 'permissions', 'attachments']);

test('malformed query shapes return validation errors', function (array $params): void {
    $this->getJson(route('api.v1.users.index', $params))->assertStatus(422);
})->with([
    'scalar filter' => [['filter' => 'broken', 'search' => 'test']],
    'direct search array' => [['search' => ['bad']]],
    'filter search array' => [['filter' => ['search' => ['bad']]]],
    'nested filter' => [['filter' => ['role' => ['nested' => ['bad']]]]],
    'include array' => [['include' => ['roles']]],
    'sort array' => [['sort' => ['name']]],
    'fields string' => [['fields' => 'name']],
    'fields nested' => [['fields' => ['users' => ['name']]]],
    'page array' => [['page' => [1]]],
    'per page string' => [['per_page' => 'bad']],
    'paginate array' => [['paginate' => [true]]],
    'export array' => [['export' => [true]]],
]);

test('empty search parameters still mean no search', function (): void {
    $this->getJson(route('api.v1.users.index', ['search' => '']))->assertOk();
});

test('search with a comma remains valid after query builder normalization', function (): void {
    User::factory()->create(['name' => 'Surname, Given']);
    $this->getJson(route('api.v1.users.index', ['search' => 'Surname, Given']))
        ->assertOk()->assertJsonPath('meta.total', 1);
});

test('redundant user migration rollback preserves baseline columns and data', function (): void {
    $this->admin->update(['username' => 'retained']);
    $this->admin->delete();

    $migration = require database_path('migrations/2026_08_31_080900_add_soft_deletes_and_username_to_users_table.php');
    $migration->up();
    $migration->down();
    expect(Schema::hasColumn('users', 'username'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'deleted_at'))->toBeTrue();
    $user = User::withTrashed()->findOrFail($this->admin->id);
    expect($user->username)->toBe('retained')->and($user->trashed())->toBeTrue();
});
