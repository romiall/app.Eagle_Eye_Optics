<?php

use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClinicSettingsController;
use App\Http\Controllers\Api\V1\ConsultationController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\VisitController;
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

        // Patients (P01, P02) : module « patients », ouvert par l'exploitant (EEO_ENABLED_MODULES) ; fermé,
        // chaque route répond 404 comme si elle n'existait pas. Écritures par le chemin central de commandes.
        Route::middleware('module:patients')->group(function (): void {
            Route::get('patients', [PatientController::class, 'index'])
                ->middleware(['can:patient.read', 'throttle:eeo-patient-search'])
                ->name('api.patients.index');
            // La réponse d'une écriture est la fiche complète : elle exige aussi patient.read (une dérogation
            // qui retire la lecture ne se contourne pas par l'écriture).
            Route::post('patients', [PatientController::class, 'store'])
                ->middleware(['can:patient.create', 'can:patient.read'])
                ->name('api.patients.store');
            Route::get('patients/{id}', [PatientController::class, 'show'])
                ->middleware('can:patient.read')
                ->whereUlid('id')
                ->name('api.patients.show');
            Route::patch('patients/{id}', [PatientController::class, 'update'])
                ->middleware(['can:patient.update', 'can:patient.read'])
                ->whereUlid('id')
                ->name('api.patients.update');
        });

        // Visites et file du jour (P03) : module « today ». La file se lit avec l'une ou l'autre des
        // permissions listées ; l'enregistrement d'une arrivée exige visit.register.
        Route::middleware('module:today')->group(function (): void {
            Route::get('visits', [VisitController::class, 'index'])
                ->middleware('any-permission:visit.register,agenda.read')
                ->name('api.visits.index');
            Route::post('visits', [VisitController::class, 'store'])
                ->middleware('can:visit.register')
                ->name('api.visits.store');
        });

        // Consultation clinique (C01) : module « consultations ». Données cliniques : accueil et caisse n'ont
        // aucune de ces permissions (403 même pour une consultation existante). Le démarrage et l'écriture d'une
        // rubrique rendent la consultation (état courant, qu'un rejeu de la même clé relit), ils exigent donc aussi
        // clinical.read : une dérogation qui retire la lecture ne se contourne pas par l'écriture. L'identifiant
        // de rubrique est contrôlé par le contrôleur (422), non par la route, pour que l'erreur soit lisible.
        Route::middleware('module:consultations')->group(function (): void {
            Route::post('visits/{id}/consultations', [ConsultationController::class, 'start'])
                ->middleware(['can:clinical.write', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.consultations.start');
            Route::get('consultations/{id}', [ConsultationController::class, 'show'])
                ->middleware('can:clinical.read')
                ->whereUlid('id')
                ->name('api.consultations.show');
            Route::patch('consultations/{id}/steps/{step}', [ConsultationController::class, 'saveStep'])
                ->middleware(['can:clinical.write', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.consultations.steps.save');
        });
    });
});
