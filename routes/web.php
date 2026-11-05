<?php

use App\Filament\Auth\Pages\EmailLogin;
use App\Filament\Auth\Pages\PhoneLogin;
use App\Http\Controllers\AttendancePhotoController;
use App\Http\Controllers\CoreSsoController;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Passwordless login, the same pages as gais-web, dnd-web and helpdesk-web.
Route::get('phone-login', PhoneLogin::class)
    ->middleware([
        DisableBladeIconComponents::class,
        DispatchServingFilamentEvent::class,
    ])
    ->name('phone-login');

Route::get('email-login', EmailLogin::class)
    ->middleware([
        DisableBladeIconComponents::class,
        DispatchServingFilamentEvent::class,
    ])
    ->name('email-login');

// Magic link from the login email. Opening the URL alone does not use the token; see EmailLogin::$magicToken.
Route::get('email-login/link/{token}', EmailLogin::class)
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware([
        DisableBladeIconComponents::class,
        DispatchServingFilamentEvent::class,
    ])
    ->name('email-login.link');

Route::get('/auth/redirect', [CoreSsoController::class, 'redirect'])->name('sso.redirect');
Route::get('/auth/callback', [CoreSsoController::class, 'callback'])->name('sso.callback');

// Selfies for the panel's "Presensi Mandiri": the controller requires the panel (web guard) login and
// answers with an upload token instead of the storage path (see App\Support\SelfieUploadToken).
Route::post('/upload-selfie', [AttendancePhotoController::class, 'upload'])->name('upload.selfie');
