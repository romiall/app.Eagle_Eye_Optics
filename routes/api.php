<?php

use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashDayReportController;
use App\Http\Controllers\Api\V1\CashSessionController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ClinicSettingsController;
use App\Http\Controllers\Api\V1\ConsultationController;
use App\Http\Controllers\Api\V1\ConsultationListController;
use App\Http\Controllers\Api\V1\CorrectionController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PatientActivityController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PrescriptionController;
use App\Http\Controllers\Api\V1\ReceiptController;
use App\Http\Controllers\Api\V1\SalesSettingsController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\TaxRuleController;
use App\Http\Controllers\Api\V1\VisitController;
use App\Http\Controllers\Api\V1\VisualAcuityScaleController;
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
        // Identité du cabinet imprimée sur les documents (en-tête, pied) : tout profil qui produit un document
        // (l'un des droits d'un document financier ou clinique suffit). Ne dépend d'aucun module.
        Route::get('clinic/document-identity', [ClinicSettingsController::class, 'documentIdentity'])
            ->middleware('any-permission:invoice.read,invoice.create,invoice.issue,payment.collect,cash.own_session,cash.manage,credit_note.issue,refund.execute,finance.global.read,clinical.read,clinical.export')
            ->name('api.clinic.document-identity');

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
            // Activité du patient (onglet « Activité » de la fiche), lecture seule : visites avec patient.read ;
            // examens et ordonnances seulement avec clinical.read, pièces financières seulement avec invoice.read
            // (sections décidées par le contrôleur, jamais mentionnées sans le droit).
            Route::get('patients/{id}/activity', [PatientActivityController::class, 'index'])
                ->middleware('can:patient.read')
                ->whereUlid('id')
                ->name('api.patients.activity');
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

        // Ventes et caisse (B01 à B05) : module « sales », ouvert par l'exploitant ; fermé, chaque route répond 404.
        // Toute écriture passe par le chemin central de commandes. Les permissions des routes d'écriture sont celles
        // du contrat (`x-permission`, l'une OU l'autre pour le brouillon, l'émission et l'encaissement : le profil
        // « frais d'examen » n'a que `exam_fee.collect` et reste limité à l'article des frais d'examen).
        Route::middleware('module:sales')->group(function (): void {
            // Catalogue et règles fiscales (B01) : recherche pour les lignes, gestion par la gérante.
            Route::get('catalog', [CatalogController::class, 'index'])
                ->middleware('can:catalog.read')
                ->name('api.catalog.index');
            Route::post('catalog/items', [CatalogController::class, 'store'])
                ->middleware('can:catalog.manage')
                ->name('api.catalog.items.store');
            Route::patch('catalog/items/{id}', [CatalogController::class, 'update'])
                ->middleware('can:catalog.manage')
                ->whereUlid('id')
                ->name('api.catalog.items.update');
            Route::get('tax-rules', [TaxRuleController::class, 'index'])
                ->middleware('can:catalog.manage')
                ->name('api.tax-rules.index');
            Route::post('tax-rules', [TaxRuleController::class, 'store'])
                ->middleware(['can:catalog.manage', 'reauthenticated'])
                ->name('api.tax-rules.store');
            Route::patch('tax-rules/{id}', [TaxRuleController::class, 'update'])
                ->middleware(['can:catalog.manage', 'reauthenticated'])
                ->whereUlid('id')
                ->name('api.tax-rules.update');

            // Brouillons, émission immuable et encaissement (B02, B03, B04).
            Route::get('invoices', [InvoiceController::class, 'index'])
                ->middleware('can:invoice.read')
                ->name('api.invoices.index');
            Route::post('invoices', [InvoiceController::class, 'store'])
                ->middleware('any-permission:invoice.create,exam_fee.collect')
                ->name('api.invoices.store');
            Route::get('invoices/{id}', [InvoiceController::class, 'show'])
                ->middleware('can:invoice.read')
                ->whereUlid('id')
                ->name('api.invoices.show');
            Route::patch('invoices/{id}', [InvoiceController::class, 'update'])
                ->middleware('any-permission:invoice.create,exam_fee.collect')
                ->whereUlid('id')
                ->name('api.invoices.update');
            Route::post('invoices/{id}/issue', [InvoiceController::class, 'issue'])
                ->middleware('any-permission:invoice.issue,exam_fee.collect')
                ->whereUlid('id')
                ->name('api.invoices.issue');
            Route::post('invoices/{id}/payments', [InvoiceController::class, 'pay'])
                ->middleware('any-permission:payment.collect,exam_fee.collect')
                ->whereUlid('id')
                ->name('api.invoices.payments.store');
            // Abandon d'un brouillon (fichier 03) : même droit que sa modification (créateur ou gérante, en ligne).
            Route::post('invoices/{id}/abandon', [InvoiceController::class, 'abandon'])
                ->middleware('any-permission:invoice.create,exam_fee.collect')
                ->whereUlid('id')
                ->name('api.invoices.abandon');
            // Règles de vente du compte connecté (plafond de remise, modes à référence obligatoire) : affichage seulement.
            Route::get('sales/settings', [SalesSettingsController::class, 'show'])
                ->middleware('any-permission:invoice.read,invoice.create,payment.collect,exam_fee.collect')
                ->name('api.sales.settings');
            Route::get('receipts/{id}', [ReceiptController::class, 'show'])
                ->middleware('can:invoice.read')
                ->whereUlid('id')
                ->name('api.receipts.show');

            // Corrections des pièces émises (B07, B08) : pièces liées, jamais de suppression ni de modification d'un
            // paiement, d'un reçu ou d'une facture. Permission propre à chaque opération, approbation exacte de la gérante
            // (ou exécution directe par elle) et réauthentification récente.
            Route::post('payments/{id}/reversals', [CorrectionController::class, 'reverse'])
                ->middleware(['can:payment.reverse', 'reauthenticated'])
                ->whereUlid('id')
                ->name('api.payments.reversals.store');
            Route::post('invoices/{id}/credit-notes', [CorrectionController::class, 'creditNote'])
                ->middleware(['can:credit_note.issue', 'reauthenticated'])
                ->whereUlid('id')
                ->name('api.invoices.credit-notes.store');
            Route::post('invoices/{id}/refunds', [CorrectionController::class, 'refund'])
                ->middleware(['can:refund.execute', 'reauthenticated'])
                ->whereUlid('id')
                ->name('api.invoices.refunds.store');

            // Caisse (B05) : la caisse principale, la session ouverte du compte, ouverture et clôture.
            Route::get('cash-registers', [CashSessionController::class, 'registers'])
                ->middleware('can:cash.own_session')
                ->name('api.cash-registers.index');
            Route::get('cash-sessions/current', [CashSessionController::class, 'current'])
                ->middleware('can:cash.own_session')
                ->name('api.cash-sessions.current');
            Route::post('cash-sessions', [CashSessionController::class, 'open'])
                ->middleware('can:cash.own_session')
                ->name('api.cash-sessions.open');
            Route::post('cash-sessions/{id}/close', [CashSessionController::class, 'close'])
                ->middleware('can:cash.own_session')
                ->whereUlid('id')
                ->name('api.cash-sessions.close');
            // Sessions de tous les postes et caissières (gérante), relevé d'une session (la sienne, ou toute session pour
            // la gérante : contrôlé par le contrôleur) et caisse du jour (portée selon finance.global.read).
            Route::get('cash-sessions', [CashSessionController::class, 'index'])
                ->middleware('can:cash.manage')
                ->name('api.cash-sessions.index');
            Route::get('cash-sessions/{id}/report', [CashSessionController::class, 'report'])
                ->middleware('can:cash.own_session')
                ->whereUlid('id')
                ->name('api.cash-sessions.report');
            Route::get('reports/cash-day', [CashDayReportController::class, 'show'])
                ->middleware('any-permission:finance.global.read,cash.own_session')
                ->name('api.reports.cash-day');
        });

        // Approbations exactes (B06) : module « settings » (gestion gérante, fichier 04). Une demande porte le contenu exact
        // de l'opération ; la gérante lit toutes les demandes, un autre compte seulement les siennes ; la décision exige
        // une réauthentification récente (423 sinon).
        Route::middleware('module:settings')->group(function (): void {
            Route::get('approvals', [ApprovalController::class, 'index'])
                ->middleware('any-permission:approval.request,approval.decide')
                ->name('api.approvals.index');
            Route::post('approvals', [ApprovalController::class, 'store'])
                ->middleware('can:approval.request')
                ->name('api.approvals.store');
            Route::get('approvals/{id}', [ApprovalController::class, 'show'])
                ->middleware('any-permission:approval.request,approval.decide')
                ->whereUlid('id')
                ->name('api.approvals.show');
            Route::post('approvals/{id}/decision', [ApprovalController::class, 'decide'])
                ->middleware(['can:approval.decide', 'reauthenticated'])
                ->whereUlid('id')
                ->name('api.approvals.decide');
        });

        // Stock (I00) : module « stock ». La réserve n'a pas de route : c'est un service interne de l'émission.
        Route::middleware('module:stock')->group(function (): void {
            Route::get('stock/availability', [StockController::class, 'availability'])
                ->middleware('can:stock.read')
                ->name('api.stock.availability');
            Route::get('stock/locations', [StockController::class, 'locations'])
                ->middleware('can:stock.read')
                ->name('api.stock.locations');
            Route::post('stock/opening', [StockController::class, 'opening'])
                ->middleware(['can:stock.adjust', 'reauthenticated'])
                ->name('api.stock.opening');
        });

        // Consultation clinique (C01 à C04) : module « consultations ». Données cliniques : accueil et caisse n'ont
        // aucune de ces permissions (403 même pour une consultation existante). Le démarrage, l'enregistrement d'une
        // rubrique, la finalisation, la correction et les écritures de prescription rendent la ressource (état courant,
        // qu'un rejeu de la même clé relit), ils exigent donc aussi clinical.read : une dérogation qui retire la lecture
        // ne se contourne pas par l'écriture. L'identifiant de rubrique est contrôlé par le contrôleur (422), non par la
        // route, pour que l'erreur soit lisible. Les lectures d'un dossier sont limitées en débit par compte.
        Route::middleware('module:consultations')->group(function (): void {
            // Liste des examens (écran « Consultations ») : patient, date, état, professionnel ; aucune valeur
            // clinique. Même limite de débit que les lectures de dossier (la liste donne les identifiants).
            Route::get('consultations', [ConsultationListController::class, 'index'])
                ->middleware(['can:clinical.read', 'throttle:eeo-consultation-read'])
                ->name('api.consultations.index');
            Route::get('visual-acuity-scales', [VisualAcuityScaleController::class, 'index'])
                ->middleware('can:clinical.read')
                ->name('api.visual-acuity-scales.index');
            Route::post('visits/{id}/consultations', [ConsultationController::class, 'start'])
                ->middleware(['can:clinical.write', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.consultations.start');
            Route::get('consultations/{id}', [ConsultationController::class, 'show'])
                ->middleware(['can:clinical.read', 'throttle:eeo-consultation-read'])
                ->whereUlid('id')
                ->name('api.consultations.show');
            Route::patch('consultations/{id}/steps/{step}', [ConsultationController::class, 'saveStep'])
                ->middleware(['can:clinical.write', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.consultations.steps.save');
            Route::post('consultations/{id}/complete', [ConsultationController::class, 'complete'])
                ->middleware(['can:clinical.complete', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.consultations.complete');
            Route::post('consultations/{id}/amendments', [ConsultationController::class, 'amend'])
                ->middleware(['can:clinical.amend', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.consultations.amend');
            Route::get('consultations/{id}/revisions/{revision_no}', [ConsultationController::class, 'revision'])
                ->middleware(['can:clinical.read', 'throttle:eeo-consultation-read'])
                ->whereUlid('id')
                ->whereNumber('revision_no')
                ->name('api.consultations.revisions.show');

            // Prescriptions optiques (C04) : brouillon, nouvelle version, validation.
            Route::get('consultations/{id}/prescriptions', [PrescriptionController::class, 'index'])
                ->middleware(['can:clinical.read', 'throttle:eeo-consultation-read'])
                ->whereUlid('id')
                ->name('api.prescriptions.index');
            Route::post('consultations/{id}/prescriptions', [PrescriptionController::class, 'store'])
                ->middleware(['can:clinical.write', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.prescriptions.store');
            Route::post('prescriptions/external', [PrescriptionController::class, 'storeExternal'])
                ->middleware(['can:clinical.write', 'can:clinical.read'])
                ->name('api.prescriptions.store-external');
            Route::get('prescriptions/{id}', [PrescriptionController::class, 'show'])
                ->middleware(['can:clinical.read', 'throttle:eeo-consultation-read'])
                ->whereUlid('id')
                ->name('api.prescriptions.show');
            Route::patch('prescriptions/{id}', [PrescriptionController::class, 'update'])
                ->middleware(['can:clinical.write', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.prescriptions.update');
            Route::post('prescriptions/{id}/revisions', [PrescriptionController::class, 'revise'])
                ->middleware(['can:clinical.amend', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.prescriptions.revise');
            Route::post('prescriptions/{id}/validate', [PrescriptionController::class, 'validatePrescription'])
                ->middleware(['can:clinical.complete', 'can:clinical.read'])
                ->whereUlid('id')
                ->name('api.prescriptions.validate');
        });
    });
});
