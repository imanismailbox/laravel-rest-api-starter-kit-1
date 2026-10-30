<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\FcmController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserController;

arch()->preset()->php();
arch()->preset()->security();
arch()->preset()->laravel()->ignoring([
    AttachmentController::class,
    AuthController::class,
    EmailVerificationController::class,
    FcmController::class,
    PasswordResetController::class,
    SettingController::class,
    TwoFactorController::class,
    UserController::class,
]);
