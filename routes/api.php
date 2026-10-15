<?php

use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClinicSettingsController;
use App\Http\Controllers\Api\V1\StaffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API privée /api/v1
|--------------------------------------------------------------------------
|
| Préfixe `api` appliqué par bootstrap/app.php. Session SPA Sanctum (cookie du sous-domaine + CSRF,
| jeton obtenu par GET /api/sanctum/csrf-cookie). Toute URL inconnue sous /api répond en JSON 404 dans
| l'enveloppe du fichier 08, jamais par la coquille SPA (routes/spa.php).
*/

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:eeo-login-ip')
        ->name('api.auth.login');

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('auth/reauthenticate', [AuthController::class, 'reauthenticate'])
            ->middleware('throttle:eeo-reauthenticate')
            ->name('api.auth.reauthenticate');
        // Même limite que la réauthentification (même compte, compteur partagé) : une session laissée
        // ouverte ne permet pas de deviner le mot de passe actuel sans limite.
        Route::post('auth/password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:eeo-reauthenticate')
            ->name('api.auth.password');

        // Paramètres du cabinet : gérante seulement (contrat 23, « Policy gérante »). Les autres profils
        // reçoivent le nom, le fuseau et la monnaie du cabinet dans GET /auth/me.
        Route::get('clinic/settings', [ClinicSettingsController::class, 'show'])
            ->middleware('can:users.manage')
            ->name('api.clinic.settings.show');
        Route::patch('clinic/settings', [ClinicSettingsController::class, 'update'])
            ->middleware(['can:users.manage', 'reauthenticated'])
            ->name('api.clinic.settings.update');

        // Journal d'audit append-only : lecture seule, permission audit.read (gérante), limitée au cabinet.
        Route::get('audit-events', [AuditEventController::class, 'index'])
            ->middleware('can:audit.read')
            ->name('api.audit-events.index');

        // Comptes du personnel : gérante seulement (users.manage), mutations après réauthentification.
        Route::middleware('can:users.manage')->group(function (): void {
            Route::get('staff', [StaffController::class, 'index'])->name('api.staff.index');
            Route::get('staff/{id}', [StaffController::class, 'show'])->name('api.staff.show');

            Route::middleware('reauthenticated')->group(function (): void {
                Route::post('staff', [StaffController::class, 'store'])->name('api.staff.store');
                Route::patch('staff/{id}', [StaffController::class, 'update'])->name('api.staff.update');
                Route::post('staff/{id}/permissions', [StaffController::class, 'permissions'])->name('api.staff.permissions');
                Route::post('staff/{id}/password', [StaffController::class, 'resetPassword'])->name('api.staff.password');
            });
        });
    });
});
