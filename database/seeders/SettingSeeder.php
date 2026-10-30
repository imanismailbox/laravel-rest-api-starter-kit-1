<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

final class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaults = [
            // General Group
            [
                'key' => 'app_name',
                'value' => 'API Starter',
                'group' => 'general',
                'type' => 'string',
                'is_public' => true,
                'description' => 'Application display name.',
            ],
            [
                'key' => 'app_description',
                'value' => 'Production-Ready Laravel REST API Starter',
                'group' => 'general',
                'type' => 'string',
                'is_public' => true,
                'description' => 'Application short description.',
            ],
            [
                'key' => 'app_logo',
                'value' => null,
                'group' => 'general',
                'type' => 'string',
                'is_public' => true,
                'description' => 'Application logo image path or URL.',
            ],
            [
                'key' => 'contact_email',
                'value' => 'admin@example.com',
                'group' => 'general',
                'type' => 'string',
                'is_public' => true,
                'description' => 'Primary contact / support email.',
            ],

            // Auth Group
            [
                'key' => 'allow_registration',
                'value' => true,
                'group' => 'auth',
                'type' => 'boolean',
                'is_public' => true,
                'description' => 'Enable or disable new user registration.',
            ],
            [
                'key' => 'require_email_verification',
                'value' => false,
                'group' => 'auth',
                'type' => 'boolean',
                'is_public' => true,
                'description' => 'Block unverified accounts from most API endpoints until they verify their email.',
            ],
            [
                'key' => 'password_min_length',
                'value' => 8,
                'group' => 'auth',
                'type' => 'integer',
                'is_public' => true,
                'description' => 'Minimum password length required.',
            ],

            // Localization Group
            [
                'key' => 'timezone',
                'value' => 'UTC',
                'group' => 'localization',
                'type' => 'string',
                'is_public' => true,
                'description' => 'System default timezone.',
            ],
            [
                'key' => 'date_format',
                'value' => 'Y-m-d',
                'group' => 'localization',
                'type' => 'string',
                'is_public' => true,
                'description' => 'Default date format.',
            ],

            // Mail Group
            [
                'key' => 'mail_from_name',
                'value' => 'API Starter',
                'group' => 'mail',
                'type' => 'string',
                'is_public' => false,
                'description' => 'Default sender name for outgoing emails.',
            ],
            [
                'key' => 'mail_from_address',
                'value' => 'hello@example.com',
                'group' => 'mail',
                'type' => 'string',
                'is_public' => false,
                'description' => 'Default sender email address for outgoing emails.',
            ],
        ];

        foreach ($defaults as $item) {
            Setting::set(
                key: $item['key'],
                value: $item['value'],
                group: $item['group'],
                type: $item['type'],
                isPublic: $item['is_public']
            );
        }
    }
}
