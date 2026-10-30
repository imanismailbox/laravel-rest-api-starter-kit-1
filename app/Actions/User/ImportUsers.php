<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use ZipArchive;

final readonly class ImportUsers
{
    /**
     * @return array{created_count: int, updated_count: int, errors: array<int, string>}
     */
    public function handle(UploadedFile $file): array
    {
        $results = [
            'created_count' => 0,
            'updated_count' => 0,
            'errors' => [],
        ];

        try {
            $archive = new ZipArchive;
            if ($archive->open($file->getRealPath()) === true) {
                try {
                    $expandedSize = 0;
                    for ($i = 0; $i < $archive->numFiles; $i++) {
                        $entry = $archive->statIndex($i);
                        if ($entry === false) {
                            throw new RuntimeException('Cannot read workbook archive metadata.');
                        }
                        $expandedSize += $entry['size'];
                        if ($expandedSize > 50 * 1024 * 1024) {
                            $results['errors'][] = 'The unpacked workbook must not exceed 50 MB.';

                            return $results;
                        }
                    }
                } finally {
                    $archive->close();
                }
            }

            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $info = $reader->listWorksheetInfo($file->getRealPath());
            if (($info[0]['totalRows'] ?? 0) > 1001) {
                $results['errors'][] = 'The first sheet must contain at most 1000 data rows.';

                return $results;
            }

            if (($info[0]['totalColumns'] ?? 0) > 50) {
                $results['errors'][] = 'The first sheet must contain at most 50 columns.';

                return $results;
            }

            $import = new class implements \Maatwebsite\Excel\Concerns\ToArray, \Maatwebsite\Excel\Concerns\WithChunkReading, \Maatwebsite\Excel\Concerns\WithMultipleSheets
            {
                /** @var array<int, array<int, mixed>> */
                public array $rows = [];

                public function array(array $array): void
                {
                    array_push($this->rows, ...$array);
                }

                public function chunkSize(): int
                {
                    return 250;
                }

                public function sheets(): array
                {
                    return [0 => $this];
                }
            };

            Excel::import($import, $file);
            $rows = $import->rows;
        } catch (Exception $e) {
            $results['errors'][] = 'Failed to read spreadsheet: '.$e->getMessage();

            return $results;
        }

        if ($rows === []) {
            $results['errors'][] = 'The spreadsheet is empty.';

            return $results;
        }

        $header = array_shift($rows);
        if (! is_array($header)) {
            $results['errors'][] = 'Invalid spreadsheet header.';

            return $results;
        }

        $headerKeys = array_map(fn (mixed $col): string => mb_strtolower(mb_trim((string) $col)), $header);

        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 2;
            if (! is_array($row)) {
                continue;
            }

            $props = [];
            foreach ($headerKeys as $colIndex => $key) {
                if ($key !== '') {
                    $props[$key] = $row[$colIndex] ?? null;
                }
            }

            // Skip empty rows
            if (empty(array_filter($props, fn (mixed $v): bool => $v !== null && $v !== ''))) {
                continue;
            }

            $name = $this->extractValue($props, ['name', 'nama', 'full_name', 'fullname']);
            $email = $this->extractValue($props, ['email', 'email_address', 'surel']);
            $email = $email !== null ? mb_strtolower(mb_trim($email)) : null;
            $username = $this->extractValue($props, ['username', 'user_name']);
            $password = $this->extractValue($props, ['password', 'pass', 'kata_sandi']);
            $rolesStr = $this->extractValue($props, ['role', 'roles', 'peran']);

            if (! $name || ! $email) {
                $results['errors'][] = "Row {$rowNumber}: Name and email are required.";

                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results['errors'][] = "Row {$rowNumber}: Invalid email format ({$email}).";

                continue;
            }

            try {
                DB::transaction(function () use ($name, $email, $username, $password, $rolesStr, &$results): void {
                    $existingUser = User::withTrashed()->where('email', $email)->lockForUpdate()->first();

                    $roles = $rolesStr ? array_filter(array_map('trim', explode(',', $rolesStr))) : [];
                    $validRoles = \Spatie\Permission\Models\Role::whereIn('name', $roles)->pluck('name')->all();
                    Gate::authorize('grant-roles', [$validRoles]);

                    if ($existingUser) {
                        Gate::authorize('manage-user', $existingUser);
                        if ($existingUser->trashed()) {
                            $existingUser->restore();
                        }

                        $existingUser->update([
                            'name' => $name,
                            'username' => $username ?: $existingUser->username,
                        ]);

                        if ($validRoles !== []) {
                            $existingUser->syncRoles($validRoles);
                        }

                        $results['updated_count']++;
                    } else {
                        Validator::make(['password' => $password], [
                            'password' => ['required', 'string', Password::defaults()],
                        ])->validate();

                        /** @var User $newUser */
                        $newUser = User::query()->create([
                            'name' => $name,
                            'email' => $email,
                            'username' => $username,
                            'password' => Hash::make($password),
                            'email_verified_at' => now(),
                        ]);

                        if ($validRoles !== []) {
                            $newUser->syncRoles($validRoles);
                        }

                        $results['created_count']++;
                    }
                });
            } catch (Exception $e) {
                $results['errors'][] = "Row {$rowNumber} ({$email}): ".$e->getMessage();
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  array<int, string>  $keys
     */
    private function extractValue(array $props, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($props[$k]) && (string) $props[$k] !== '' && mb_strtolower((string) $props[$k]) !== 'null') {
                return (string) $props[$k];
            }
        }

        return null;
    }
}
