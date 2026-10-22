<?php

namespace Tests\Feature\Clinical;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBusProbe;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\Handlers\ValidatePrescriptionHandler;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\Commands\RecordingProbe;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Validation d'une prescription optique et nouvelles versions (C04, AT20, AT21, EE009, EE014) : par œil, des valeurs
 * explicites ou « aucune correction » ; cylindre non nul = axe exigé ; version immuable ensuite (triggers) ; document
 * PRÉPARÉ (statut PENDING) sans fichier ni mention « imprimée » ; aucune vente ni stock ; correction par nouvelle
 * version chaînée. Données fictives.
 */
final class ValidatePrescriptionTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $consultationId;

    private string $otherPatientId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [, $this->consultationId] = $this->startedExam();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    public function test_la_validation_fige_la_version_et_prepare_le_document_sans_le_generer(): void
    {
        $draft = $this->draftPrescription($this->consultationId);
        $documentId = Ulid::generate();

        $response = $this->validatePrescription($draft['id'], ['expected_version' => null, 'prescription_id' => $draft['id'], 'document_id' => $documentId])->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/PrescriptionResponse');
        $response->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.validated_by', $this->clinician->id)
            // Nom d'affichage du compte validateur, lu dans le cabinet : l'ordonnance s'imprime depuis un autre compte.
            ->assertJsonPath('data.validated_by_name', $this->clinician->display_name)
            ->assertJsonPath('data.validated_at', '2026-10-22T09:30:00.000Z')
            ->assertJsonPath('data.document_id', $documentId);
        $document = DB::table('generated_documents')->where('id', $documentId)->first();
        $this->assertNotNull($document);
        $this->assertSame(['OPTICAL_PRESCRIPTION', $draft['id'], 'PENDING', 1, null, null], [$document->document_type, $document->prescription_id, $document->status, (int) $document->revision_no, $document->file_id, $document->generated_at], 'Le document est préparé ; sa génération suit le commit et appartient au moteur de documents.');
        $this->assertSame('optical_prescription:'.$draft['id'], $document->source_key);
        $this->assertSame('optical_prescription.v1', $document->template_version, 'Convention <type>.v<n> du registre des modèles (contracts/compatibility.json).');
        $this->assertSame(ValidatePrescriptionHandler::TEMPLATE_VERSION, $document->template_version);
    }

    public function test_les_effets_sont_l_audit_sans_valeur_et_l_evenement_minimal_sans_vente_ni_stock(): void
    {
        $draft = $this->draftPrescription($this->consultationId, ['notes' => 'SENTINELLE-NOTE-VALIDEE-KD9']);
        $counts = [DB::table('invoices')->count(), DB::table('invoice_lines')->count(), DB::table('stock_movements')->count()];

        $this->validatePrescription($draft['id'])->assertOk();

        $audit = DB::table('audit_events')->where('action', 'prescription.validated')->where('target_id', $draft['id'])->first();
        $this->assertNotNull($audit);
        $this->assertSame(['document_id', 'origin', 'series_id', 'version_no'], collect(json_decode((string) $audit->sanitized_diff, true))->keys()->sort()->values()->all());
        $event = DB::table('outbox_events')->where('clinic_id', $this->clinicId)->where('event_type', 'PrescriptionValidated')->first();
        $this->assertNotNull($event);
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame(['consultation_id', 'document_id', 'origin', 'patient_id', 'prescription_id', 'validated_at', 'version_no'], collect($payload)->keys()->sort()->values()->all());
        $this->assertStringNotContainsString('SENTINELLE-NOTE-VALIDEE-KD9', $this->sideEffectsDump());
        $this->assertSame($counts, [DB::table('invoices')->count(), DB::table('invoice_lines')->count(), DB::table('stock_movements')->count()], 'Prescrire ne vend rien.');
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string}>
     */
    public static function incompleteEyes(): array
    {
        return [
            'œil droit sans valeur ni mention' => [[['eye' => 'OD'], ['eye' => 'OG', 'sphere' => '-1.00']], 'eyes.0.no_correction'],
            'œil gauche sans valeur ni mention' => [[['eye' => 'OD', 'sphere' => '-1.00'], ['eye' => 'OG']], 'eyes.1.no_correction'],
            'cylindre sans axe' => [[['eye' => 'OD', 'sphere' => '-1.00', 'cylinder' => '-0.75'], ['eye' => 'OG', 'sphere' => '-1.00']], 'eyes.0.axis'],
            'cylindre positif sans axe' => [[['eye' => 'OD', 'sphere' => '-1.00'], ['eye' => 'OG', 'cylinder' => '+0.50']], 'eyes.1.axis'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $eyes
     */
    #[DataProvider('incompleteEyes')]
    public function test_une_prescription_incomplete_ne_se_valide_pas(array $eyes, string $fragment): void
    {
        $rows = array_map(fn (array $eye): array => $this->rxEye($eye['eye'], $eye['sphere'] ?? null, $eye['cylinder'] ?? null), $eyes);
        $draft = $this->draftPrescription($this->consultationId, ['eyes' => $rows]);

        $this->assertFieldError($this->validatePrescription($draft['id']), $fragment);

        $this->assertSame('DRAFT', DB::table('optical_prescriptions')->where('id', $draft['id'])->value('status'));
        $this->assertSame(0, DB::table('generated_documents')->count());
    }

    public function test_aucune_correction_est_une_valeur_explicite_et_un_cylindre_nul_n_exige_pas_d_axe(): void
    {
        $draft = $this->draftPrescription($this->consultationId, ['eyes' => [$this->rxEye('OD', '0.00', '0.00'), $this->rxEye('OG', null, null, null, null, true)]]);

        $this->validatePrescription($draft['id'])->assertOk()->assertJsonPath('data.status', 'VALIDATED');
    }

    public function test_une_prescription_validee_ne_se_revalide_pas_et_reste_immuable_dans_la_base(): void
    {
        $validated = $this->validatedPrescription($this->consultationId);

        $this->validatePrescription($validated['id'])->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');

        foreach ([
            static fn () => DB::table('optical_prescriptions')->where('id', $validated['id'])->update(['notes' => 'Réécrite']),
            static fn () => DB::table('optical_prescription_eyes')->where('prescription_id', $validated['id'])->update(['sphere' => '-5.00']),
            static fn () => DB::table('optical_prescription_eyes')->where('prescription_id', $validated['id'])->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Une prescription validée et ses yeux sont immuables (triggers de la base).');
            } catch (QueryException) {
                // Attendu.
            }
        }
        $this->assertSame(1, DB::table('generated_documents')->where('prescription_id', $validated['id'])->count());
    }

    public function test_l_identifiant_du_document_doit_etre_neuf_et_le_chemin_coincider(): void
    {
        $first = $this->validatedPrescription($this->consultationId);
        [, $other] = $this->secondExam();
        $draft = $this->draftPrescription($other, ['patient_id' => $this->otherPatientId]);

        $this->assertFieldError($this->validatePrescription($draft['id'], ['expected_version' => null, 'prescription_id' => $draft['id'], 'document_id' => $first['document_id']]), 'document_id');
        $this->assertFieldError($this->validatePrescription($draft['id'], ['expected_version' => null, 'prescription_id' => $first['id'], 'document_id' => Ulid::generate()]), 'prescription_id');
        $this->assertFieldError($this->validatePrescription($draft['id'], ['expected_version' => 1, 'prescription_id' => $draft['id'], 'document_id' => Ulid::generate()]), 'expected_version');
        $this->assertSame('DRAFT', DB::table('optical_prescriptions')->where('id', $draft['id'])->value('status'));
    }

    public function test_un_rejeu_de_la_meme_cle_ne_prepare_qu_un_document(): void
    {
        $draft = $this->draftPrescription($this->consultationId);
        $body = ['expected_version' => null, 'prescription_id' => $draft['id'], 'document_id' => Ulid::generate()];
        $key = Ulid::generate();

        $this->validatePrescription($draft['id'], $body, null, $key)->assertOk();
        $this->validatePrescription($draft['id'], $body, null, $key)->assertOk()->assertJsonPath('data.status', 'VALIDATED');

        $this->assertSame(1, DB::table('generated_documents')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'PrescriptionValidated')->count());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roles(): array
    {
        return ['gérante' => ['MANAGER', 200], 'professionnel' => ['CLINICIAN', 200], 'accueil' => ['RECEPTION', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('roles')]
    public function test_seuls_la_gerante_et_le_professionnel_valident(string $role, int $status): void
    {
        $draft = $this->draftPrescription($this->consultationId);
        $user = $this->makeStaff($this->clinicId, $role);

        $this->validatePrescription($draft['id'], null, $user)->assertStatus($status);

        $this->assertSame($status === 200 ? 'VALIDATED' : 'DRAFT', DB::table('optical_prescriptions')->where('id', $draft['id'])->value('status'));
    }

    public function test_sans_le_droit_de_finaliser_la_validation_est_refusee_et_un_autre_cabinet_repond_introuvable(): void
    {
        $draft = $this->draftPrescription($this->consultationId);

        $this->denyPermission($this->clinician, 'clinical.complete');
        $this->validatePrescription($draft['id'])->assertStatus(403);
        $foreign = $this->makeStaff($this->makeClinic(), 'CLINICIAN', 'autre.pro.validation');
        $this->validatePrescription($draft['id'], null, $foreign)->assertNotFound();

        $this->assertSame('DRAFT', DB::table('optical_prescriptions')->where('id', $draft['id'])->value('status'));
    }

    public function test_une_nouvelle_version_part_de_la_derniere_version_validee_de_la_serie(): void
    {
        $v1 = $this->validatedPrescription($this->consultationId);
        $body = $this->rxBody($this->consultationId, ['series_id' => $v1['series_id'], 'version_no' => 2, 'supersedes_id' => $v1['id'], 'notes' => 'Correction de l\'axe (fictif)']);

        $v2 = $this->createPrescription($body)->assertCreated()->json('data');

        $this->assertSame([2, $v1['id'], $v1['series_id'], 'DRAFT'], [$v2['version_no'], $v2['supersedes_id'], $v2['series_id'], $v2['status']]);
        $this->assertSame('VALIDATED', DB::table('optical_prescriptions')->where('id', $v1['id'])->value('status'), 'La version précédente reste intacte et lisible.');

        $list = $this->actingAsFresh($this->clinician)->getJson('/api/v1/consultations/'.$this->consultationId.'/prescriptions')->assertOk();
        $this->assertSame([2, 1], array_column($list->json('data'), 'version_no'), 'La plus récente d\'abord.');
    }

    public function test_une_nouvelle_version_est_refusee_si_la_precedente_est_un_brouillon_ou_n_est_plus_la_derniere(): void
    {
        $draft = $this->draftPrescription($this->consultationId);
        $this->createPrescription($this->rxBody($this->consultationId, ['series_id' => $draft['series_id'], 'version_no' => 2, 'supersedes_id' => $draft['id']]))
            ->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');

        $this->validatePrescription($draft['id'])->assertOk();
        $v2 = $this->createPrescription($this->rxBody($this->consultationId, ['series_id' => $draft['series_id'], 'version_no' => 2, 'supersedes_id' => $draft['id']]))->assertCreated()->json('data');
        $this->validatePrescription($v2['id'])->assertOk();

        $this->createPrescription($this->rxBody($this->consultationId, ['series_id' => $draft['series_id'], 'version_no' => 2, 'supersedes_id' => $draft['id']]))
            ->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
        $this->createPrescription($this->rxBody($this->consultationId, ['series_id' => $draft['series_id'], 'version_no' => 2, 'supersedes_id' => Ulid::generate()]))->assertNotFound();
    }

    public function test_une_nouvelle_version_garde_serie_patient_origine_consultation_et_numero_suivant(): void
    {
        $v1 = $this->validatedPrescription($this->consultationId);
        $base = ['series_id' => $v1['series_id'], 'version_no' => 2, 'supersedes_id' => $v1['id']];
        [, $other] = $this->secondExam();

        $this->assertFieldError($this->createPrescription($this->rxBody($this->consultationId, [...$base, 'series_id' => Ulid::generate()])), 'series_id');
        $this->assertFieldError($this->createPrescription($this->rxBody($this->consultationId, [...$base, 'version_no' => 3])), 'version_no');
        $this->assertFieldError($this->createPrescription($this->rxBody($other, [...$base, 'patient_id' => $this->otherPatientId])), 'consultation_id');
        $this->assertFieldError($this->createPrescription($this->rxBody($this->consultationId, [...$base, 'origin' => 'EXTERNAL', 'consultation_id' => null, 'external_prescriber' => 'Dr Fictif', 'external_prescribed_on' => '2026-10-01'])), 'origin');
        $this->assertSame(1, DB::table('optical_prescriptions')->where('series_id', $v1['series_id'])->count());
    }

    public function test_le_chemin_de_la_nouvelle_version_doit_designer_la_prescription_remplacee(): void
    {
        $v1 = $this->validatedPrescription($this->consultationId);
        $body = $this->rxBody($this->consultationId, ['series_id' => $v1['series_id'], 'version_no' => 2, 'supersedes_id' => $v1['id']]);

        $response = $this->actingAsFresh($this->clinician)->withHeaders(['Idempotency-Key' => Ulid::generate()])->postJson('/api/v1/prescriptions/'.Ulid::generate().'/revisions', $body);

        $this->assertFieldError($response, 'supersedes_id');
    }

    public function test_la_nouvelle_version_exige_le_droit_de_corriger(): void
    {
        $v1 = $this->validatedPrescription($this->consultationId);
        $this->denyPermission($this->clinician, 'clinical.amend');

        $this->createPrescription($this->rxBody($this->consultationId, ['series_id' => $v1['series_id'], 'version_no' => 2, 'supersedes_id' => $v1['id']]))->assertStatus(403);
        $this->assertSame(1, DB::table('optical_prescriptions')->count());
    }

    public function test_une_ordonnance_externe_se_valide_et_se_corrige_par_version_sans_consultation(): void
    {
        $external = $this->createPrescription($this->rxExternalBody())->assertCreated()->json('data');
        $this->validatePrescription($external['id'])->assertOk()->assertJsonPath('data.status', 'VALIDATED');

        $v2 = $this->createPrescription($this->rxExternalBody(['series_id' => $external['series_id'], 'version_no' => 2, 'supersedes_id' => $external['id']]))->assertCreated()->json('data');

        $this->assertSame(['EXTERNAL', null, 2], [$v2['origin'], $v2['consultation_id'], $v2['version_no']]);
        $event = DB::table('outbox_events')->where('event_type', 'PrescriptionValidated')->first();
        $this->assertNotNull($event);
        $this->assertNull(json_decode((string) $event->payload, true)['consultation_id']);
    }

    /**
     * @return array<string, array{0: Checkpoint}>
     */
    public static function checkpoints(): array
    {
        $cases = [];
        foreach (Checkpoint::cases() as $checkpoint) {
            $cases[$checkpoint->value] = [$checkpoint];
        }

        return $cases;
    }

    #[DataProvider('checkpoints')]
    public function test_un_echec_a_n_importe_quel_point_annule_la_validation_entiere(Checkpoint $failAt): void
    {
        $draft = $this->draftPrescription($this->consultationId);
        $body = ['expected_version' => null, 'prescription_id' => $draft['id'], 'document_id' => Ulid::generate()];
        $before = [DB::table('generated_documents')->count(), DB::table('outbox_events')->count(), DB::table('audit_events')->count()];
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->validatePrescription($draft['id'], $body);
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (\RuntimeException) {
            // Attendu : la transaction est annulée en entier.
        }

        $this->assertSame($before, [DB::table('generated_documents')->count(), DB::table('outbox_events')->count(), DB::table('audit_events')->count()]);
        $this->assertSame('DRAFT', DB::table('optical_prescriptions')->where('id', $draft['id'])->value('status'));

        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->validatePrescription($draft['id'], $body)->assertOk();
    }

    public function test_un_poste_hors_ligne_ou_synchronise_est_refuse(): void
    {
        $draft = $this->draftPrescription($this->consultationId);
        $payload = ['prescription_id' => $draft['id'], 'document_id' => Ulid::generate()];

        foreach ([CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC')), $this->syncContextFor($this->clinician)] as $context) {
            try {
                $this->app->make(ValidatePrescriptionHandler::class)->handle(new Command('ValidatePrescription', $payload, $draft['id']), $context, new TransactionLocks($this->clinicId), null);
                $this->fail('Une source autre que la route directe devait être refusée.');
            } catch (BusinessError $error) {
                $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
            }
        }
        $this->assertSame('DRAFT', DB::table('optical_prescriptions')->where('id', $draft['id'])->value('status'));
    }

    /**
     * Second examen démarré (autre patient) : [visite, consultation].
     *
     * @return array{0: string, 1: string}
     */
    private function secondExam(): array
    {
        $this->otherPatientId = $this->seedPatient($this->clinicId);
        $visitId = $this->seedVisit([], $this->clinicId, $this->otherPatientId);
        $body = $this->startBody($visitId, ['patient_id' => $this->otherPatientId]);
        $this->startConsultation($visitId, $body)->assertCreated();

        return [$visitId, (string) $body['consultation_id']];
    }
}
