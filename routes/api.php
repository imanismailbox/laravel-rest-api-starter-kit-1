<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\FcmController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->as('api.v1.')->group(function (): void {
    // Health Check
    Route::get('/health', HealthController::class)->name('health');

    // Public Settings
    Route::get('/settings/public', [SettingController::class, 'public'])->name('settings.public');

    // Auth (Public)
    Route::prefix('auth')->as('auth.')->middleware('throttle:10,1')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login', [AuthController::class, 'login'])->name('login');
        Route::post('/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->name('two-factor.challenge');
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
        Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('signed')
            ->name('verification.verify');
    });

    // Attachment downloads also accept `?pat=` so plain links (img src, <a href>) work without headers.
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download')
        ->middleware(['pat', 'auth:sanctum', 'verified.api']);

    // Authenticated, open to unverified accounts so they can still verify or log out
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', [AuthController::class, 'me'])->name('user.show');
        Route::prefix('auth')->as('auth.')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('/email/resend', [EmailVerificationController::class, 'send'])
                ->middleware('throttle:6,1')
                ->name('verification.send');
        });
    });

    // Authenticated; blocked for unverified accounts when the `require_email_verification` setting is on
    Route::middleware(['auth:sanctum', 'verified.api'])->group(function (): void {
        Route::prefix('auth')->as('auth.')->group(function (): void {
            Route::put('/me', [AuthController::class, 'updateProfile'])->name('me.update');
            Route::put('/me/password', [AuthController::class, 'updatePassword'])->name('me.password.update');
            Route::post('/me/avatar', [AuthController::class, 'uploadAvatar'])->name('me.avatar.upload');
            Route::delete('/me/avatar', [AuthController::class, 'deleteAvatar'])->name('me.avatar.delete');

            Route::post('/two-factor', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
            Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
            Route::delete('/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
            Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
        });

        // User Management
        Route::get('/users/template', [UserController::class, 'template'])->name('users.template')->middleware('can:users.view');
        Route::post('/users/import', [UserController::class, 'import'])->name('users.import')->middleware('can:users.create');
        Route::post('/users/batch-restore', [UserController::class, 'batchRestore'])->name('users.batch_restore')->middleware('can:users.edit');
        Route::delete('/users/batch-force', [UserController::class, 'batchForceDestroy'])->name('users.batch_force_destroy')->middleware('can:users.delete');
        Route::delete('/users/batch', [UserController::class, 'batchDestroy'])->name('users.batch_destroy')->middleware('can:users.delete');
        Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore')->middleware('can:users.edit');
        Route::delete('/users/{id}/force', [UserController::class, 'forceDestroy'])->name('users.force_destroy')->middleware('can:users.delete');
        Route::apiResource('users', UserController::class)->middlewareFor(['index', 'show'], 'can:users.view')
            ->middlewareFor('store', 'can:users.create')
            ->middlewareFor('update', 'can:users.edit')
            ->middlewareFor('destroy', 'can:users.delete');

        // Role & Permission
        Route::apiResource('roles', RoleController::class)->middlewareFor(['index', 'show'], 'can:roles.view')
            ->middlewareFor('store', 'can:roles.create')
            ->middlewareFor('update', 'can:roles.edit')
            ->middlewareFor('destroy', 'can:roles.delete');
        Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index')->middleware('can:permissions.view');

        // Settings (Admin / Settings Permission)
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index')->middleware('can:settings.view');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update')->middleware('can:settings.edit');

        // Activity Logs
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index')->middleware('can:activity_logs.view');
        Route::get('/activity-logs/{activityLog}', [ActivityLogController::class, 'show'])->name('activity-logs.show')->middleware('can:activity_logs.view');

        // Attachments
        Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
        Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

        // User Attachments
        Route::post('/users/{user}/attachments', [UserController::class, 'syncAttachments'])->name('users.attachments.sync');
        Route::delete('/users/{user}/attachments/{attachment}', [UserController::class, 'detachAttachment'])->name('users.attachments.detach');

        // FCM Device Tokens & Notifications
        Route::get('/fcm/tokens', [FcmController::class, 'index'])->name('fcm.tokens.index');
        Route::post('/fcm/tokens', [FcmController::class, 'store'])->name('fcm.tokens.store');
        Route::delete('/fcm/tokens', [FcmController::class, 'destroy'])->name('fcm.tokens.destroy');
        Route::post('/fcm/test', [FcmController::class, 'sendTestNotification'])->name('fcm.test');
    });
});
