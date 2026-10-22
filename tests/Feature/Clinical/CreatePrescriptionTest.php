<?php

namespace Tests\Feature\Clinical;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBusProbe;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\Handlers\CreatePrescriptionHandler;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\Commands\RecordingProbe;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Création d'une prescription optique BROUILLON (C04, AT20, AT21, EE009, EE014) : prescription interne d'une
 * consultation, transcription d'une ordonnance externe identifiée, aucune valeur copiée ni déduite par le serveur,
 * une seule série par consultation, droits, isolation des cabinets, idempotence, retour arrière. Données fictives.
 */
final class CreatePrescriptionTest extends TestCase
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

    public function test_un_brouillon_interne_est_cree_et_relu_en_forme_canonique(): void
    {
        $body = $this->rxBody($this->consultationId, [
            'notes' => 'Port permanent conseillé (fictif)',
            'treatments' => [$this->term('ANTI_REFLECTION'), $this->term('OTHER', 'Traitement fictif')],
            'eyes' => [$this->rxEye('OG', '-1', null, null, '2', false, '22'), $this->rxEye('OD', '-1.25', '-0.5', 90)],
        ]);

        $response = $this->createPrescription($body)->assertCreated();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/PrescriptionResponse');
        $data = $response->json('data');
        $this->assertSame([$body['prescription_id'], 'DRAFT', 1, null, 'INTERNAL', $this->consultationId, null, null], [$data['id'], $data['status'], $data['version_no'], $data['supersedes_id'], $data['origin'], $data['consultation_id'], $data['validated_at'], $data['document_id']]);
        $this->assertSame(['OD', 'OG'], array_column($data['eyes'], 'eye'), 'OD puis OG, quel que soit l\'ordre reçu.');
        $this->assertSame(['-1.25', '-0.50', 90, null], [$data['eyes'][0]['sphere'], $data['eyes'][0]['cylinder'], $data['eyes'][0]['axis'], $data['eyes'][0]['addition']]);
        $this->assertSame(['-1.00', '2.00', '22.00'], [$data['eyes'][1]['sphere'], $data['eyes'][1]['addition'], $data['eyes'][1]['fitting_height']]);
        $this->assertSame(['ANTI_REFLECTION', 'OTHER'], array_column($data['treatments'], 'term_code'));
        $this->assertSame(['62.00', '31.00', '31.00', '1.50'], [$data['pd_total'], $data['pd_od'], $data['pd_og'], $data['lens_index']]);
        $this->assertSame($data, $this->readPrescription($body['prescription_id'])->json('data'));
        $this->assertSame(2, DB::table('optical_prescription_eyes')->where('prescription_id', $body['prescription_id'])->count());
        $this->assertSame(2, DB::table('optical_prescription_treatments')->where('prescription_id', $body['prescription_id'])->count());
    }

    public function test_un_brouillon_incomplet_est_admis_et_le_serveur_ne_copie_ni_ne_deduit_aucune_mesure(): void
    {
        $scale = $this->seedScale();
        $this->saveCurrent($this->consultationId, 'REFRACTION', $this->refraction(['measurements' => [$this->measurement('SUBJECTIVE', 'OD', '-3.00', '-1.00', 45, '1.50')]]))->assertOk();
        $this->saveCurrent($this->consultationId, 'CENTERING', $this->centering(['centering_id' => Ulid::generate(), 'pd_total' => '64.00']))->assertOk();
        $this->saveCurrent($this->consultationId, 'VISUAL_ACUITY', ['acuities' => [$this->acuity($scale, '8/10')]])->assertOk();

        $body = $this->rxBody($this->consultationId, [
            'usage_code' => null, 'lens_type_code' => null, 'lens_index' => null, 'pd_total' => null, 'pd_od' => null, 'pd_og' => null,
            'eyes' => [$this->rxEye('OD'), $this->rxEye('OG')],
        ]);
        $data = $this->createPrescription($body)->assertCreated()->json('data');

        foreach ($data['eyes'] as $eye) {
            $this->assertSame([null, null, null, null, false], [$eye['sphere'], $eye['cylinder'], $eye['axis'], $eye['addition'], $eye['no_correction']], 'Aucune valeur de la réfraction n\'est recopiée par le serveur.');
        }
        $this->assertSame([null, null, null], [$data['pd_total'], $data['pd_od'], $data['pd_og']], 'La DP du centrage n\'est pas recopiée.');
    }

    public function test_la_dp_monoculaire_n_est_pas_fabriquee_a_partir_du_total(): void
    {
        $data = $this->draftPrescription($this->consultationId, ['pd_total' => '62.00', 'pd_od' => null, 'pd_og' => null]);

        $this->assertSame(['62.00', null, null], [$data['pd_total'], $data['pd_od'], $data['pd_og']]);
    }

    public function test_zero_est_une_valeur_et_aucune_correction_est_une_mention_explicite(): void
    {
        $data = $this->draftPrescription($this->consultationId, ['eyes' => [$this->rxEye('OD', '0', '0', 0), $this->rxEye('OG', null, null, null, null, true)]]);

        $this->assertSame(['0.00', '0.00', 0, false], [$data['eyes'][0]['sphere'], $data['eyes'][0]['cylinder'], $data['eyes'][0]['axis'], $data['eyes'][0]['no_correction']]);
        $this->assertSame([null, true], [$data['eyes'][1]['sphere'], $data['eyes'][1]['no_correction']]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidContents(): array
    {
        return [
            'sphère à trois décimales' => [['eyes' => [self::eye('OD', ['sphere' => '1.255']), self::eye('OG')]], 'eyes.0.sphere'],
            'axe au-delà de 180' => [['eyes' => [self::eye('OD', ['cylinder' => '-1', 'axis' => 181]), self::eye('OG')]], 'eyes.0.axis'],
            'un seul œil' => [['eyes' => [self::eye('OD')]], 'eyes'],
            'deux fois le même œil' => [['eyes' => [self::eye('OD'), self::eye('OD')]], 'eyes.1.eye'],
            'aucune correction avec valeur' => [['eyes' => [self::eye('OD', ['no_correction' => true, 'sphere' => '-1']), self::eye('OG')]], 'eyes.0.no_correction'],
            'hauteur de montage nulle' => [['eyes' => [self::eye('OD', ['fitting_height' => '0']), self::eye('OG')]], 'eyes.0.fitting_height'],
            'usage inconnu' => [['usage_code' => 'NE_EXISTE_PAS'], 'usage_code'],
            'usage d\'un autre référentiel' => [['usage_code' => 'SINGLE_VISION'], 'usage_code'],
            'usage autre sans précision' => [['usage_code' => 'OTHER'], 'usage_other'],
            'précision d\'usage sans autre' => [['usage_code' => 'NEAR', 'usage_other' => 'Précision'], 'usage_other'],
            'type de verre inconnu' => [['lens_type_code' => 'NE_EXISTE_PAS'], 'lens_type_code'],
            'indice inconnu' => [['lens_index' => '1.55'], 'lens_index'],
            'indice et précision' => [['lens_index' => '1.50', 'lens_index_other' => 'Autre indice'], 'lens_index_other'],
            'DP totale nulle' => [['pd_total' => '0', 'pd_od' => null, 'pd_og' => null], 'pd_total'],
            'DP négative' => [['pd_od' => '-31'], 'pd_od'],
            'somme des DP différente sans justification' => [['pd_total' => '64.00', 'pd_od' => '31.00', 'pd_og' => '31.00'], 'pd_inconsistency_reason'],
            'justification sans DP' => [['pd_total' => null, 'pd_od' => null, 'pd_og' => null, 'pd_inconsistency_reason' => 'Sans mesure'], 'pd_inconsistency_reason'],
            'traitement inconnu' => [['treatments' => [['id' => '01J9ZXK4M2Q8R7S6T5V4W3AAAA', 'term_code' => 'NE_EXISTE_PAS', 'other_text' => null]]], 'treatments.0'],
            'traitement d\'un autre référentiel' => [['treatments' => [['id' => '01J9ZXK4M2Q8R7S6T5V4W3AAAB', 'term_code' => 'PROGRESSIVE', 'other_text' => null]]], 'treatments.0'],
            'traitement autre sans précision' => [['treatments' => [['id' => '01J9ZXK4M2Q8R7S6T5V4W3AAAC', 'term_code' => 'OTHER', 'other_text' => null]]], 'treatments.0.other_text'],
            'champ d\'ordonnance externe sur une prescription interne' => [['external_prescriber' => 'Dr Fictif'], 'external_prescriber'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function eye(string $eye, array $overrides = []): array
    {
        return [...['id' => Ulid::generate(), 'eye' => $eye, 'no_correction' => false, 'sphere' => '-1.00', 'cylinder' => null, 'axis' => null, 'addition' => null, 'fitting_height' => null], ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidContents')]
    public function test_un_contenu_invalide_est_refuse_avant_toute_ecriture(array $overrides, string $fragment): void
    {
        $before = $this->clinicalEffectCounts() + ['prescriptions' => DB::table('optical_prescriptions')->count(), 'eyes' => DB::table('optical_prescription_eyes')->count()];

        $this->assertFieldError($this->createPrescription($this->rxBody($this->consultationId, $overrides)), $fragment);

        $this->assertSame(0, DB::table('optical_prescriptions')->count());
        $this->assertSame($before['eyes'], DB::table('optical_prescription_eyes')->count());
        $this->assertSame($before['audit'], $this->clinicalEffectCounts()['audit']);
    }

    public function test_une_consultation_n_a_qu_une_serie_et_la_suite_passe_par_une_nouvelle_version(): void
    {
        $this->draftPrescription($this->consultationId);

        $second = $this->createPrescription($this->rxBody($this->consultationId));

        $second->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
        $this->assertSame(1, DB::table('optical_prescriptions')->where('consultation_id', $this->consultationId)->count());
    }

    public function test_le_corps_de_la_route_interne_est_controle_par_rapport_au_chemin(): void
    {
        $body = $this->rxBody($this->consultationId);
        $post = fn (string $path, array $payload) => $this->actingAsFresh($this->clinician)->withHeaders(['Idempotency-Key' => Ulid::generate()])->postJson($path, $payload);

        $this->assertFieldError($post('/api/v1/consultations/'.$this->consultationId.'/prescriptions', [...$body, 'consultation_id' => Ulid::generate()]), 'consultation_id');
        $this->assertFieldError($post('/api/v1/consultations/'.$this->consultationId.'/prescriptions', [...$body, 'origin' => 'EXTERNAL']), 'origin');
        $this->assertFieldError($post('/api/v1/consultations/'.$this->consultationId.'/prescriptions', [...$body, 'supersedes_id' => Ulid::generate()]), 'supersedes_id');
        $this->assertFieldError($post('/api/v1/prescriptions/external', $body), 'origin');
        $this->assertSame(0, DB::table('optical_prescriptions')->count());
    }

    public function test_la_consultation_doit_exister_dans_le_cabinet_et_appartenir_au_patient(): void
    {
        $this->createPrescription($this->rxBody(Ulid::generate()))->assertNotFound();

        $otherPatient = $this->seedPatient($this->clinicId);
        $this->assertFieldError($this->createPrescription($this->rxBody($this->consultationId, ['patient_id' => $otherPatient])), 'patient_id');
        $this->assertFieldError($this->createPrescription($this->rxBody($this->consultationId, ['patient_id' => Ulid::generate()])), 'patient_id');

        $foreign = $this->makeStaff($this->makeClinic(), 'CLINICIAN', 'autre.pro.rx');
        $this->createPrescription($this->rxBody($this->consultationId), $foreign)->assertNotFound();
        $this->assertSame(0, DB::table('optical_prescriptions')->count());
    }

    public function test_les_identifiants_et_les_numeros_sont_controles_par_champ(): void
    {
        $first = $this->draftPrescription($this->consultationId);
        [, $otherConsultation] = $this->secondExam();

        $this->assertFieldError($this->createPrescription($this->rxBody($otherConsultation, ['prescription_id' => $first['id'], 'patient_id' => $this->otherPatientId])), 'prescription_id');
        $this->assertFieldError($this->createPrescription($this->rxBody($otherConsultation, ['series_id' => $first['series_id'], 'patient_id' => $this->otherPatientId])), 'series_id');
        $this->assertFieldError($this->createPrescription($this->rxBody($otherConsultation, ['version_no' => 2, 'patient_id' => $this->otherPatientId])), 'version_no');
        $this->assertFieldError($this->createPrescription($this->rxBody($otherConsultation, ['eyes' => [$this->rxEye('OD', '-1', null, null, null, false, null, $first['eyes'][0]['id']), $this->rxEye('OG', '-1')], 'patient_id' => $this->otherPatientId])), 'eyes.0.id');
        $this->assertSame(1, DB::table('optical_prescriptions')->count());
    }

    public function test_une_ordonnance_externe_identifiee_est_transcrite_sans_consultation(): void
    {
        $body = $this->rxExternalBody(['notes' => 'Ordonnance du 1er octobre (fictive)']);

        $response = $this->createPrescription($body)->assertCreated();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/PrescriptionResponse');
        $response->assertJsonPath('data.origin', 'EXTERNAL')
            ->assertJsonPath('data.consultation_id', null)
            ->assertJsonPath('data.external_prescriber', 'Dr Prescripteur Fictif')
            ->assertJsonPath('data.external_prescribed_on', '2026-10-01')
            ->assertJsonPath('data.status', 'DRAFT');
        $this->assertSame(0, DB::table('consultations')->where('patient_id', $this->patientId)->where('id', '<>', $this->consultationId)->count(), 'Aucun examen fictif n\'est créé.');
    }

    public function test_l_ordonnance_externe_exige_son_prescripteur_et_sa_date_et_aucune_consultation(): void
    {
        $this->assertFieldError($this->createPrescription($this->rxExternalBody(['external_prescriber' => null])), 'external_prescriber');
        $this->assertFieldError($this->createPrescription($this->rxExternalBody(['external_prescribed_on' => null])), 'external_prescribed_on');
        $this->assertFieldError($this->createPrescription($this->rxExternalBody(['external_prescribed_on' => '2026-10-23'])), 'external_prescribed_on');
        $this->assertFieldError($this->createPrescription($this->rxExternalBody(['external_prescribed_on' => '2026-02-30'])), 'external_prescribed_on');
        $this->assertFieldError($this->createPrescription($this->rxExternalBody(['external_prescriber' => '   '])), 'external_prescriber');
        $this->assertFieldError($this->createPrescription($this->rxExternalBody(['consultation_id' => $this->consultationId])), 'consultation_id');
        $this->assertSame(0, DB::table('optical_prescriptions')->count());
    }

    public function test_le_fichier_de_l_ordonnance_externe_doit_exister_dans_le_cabinet(): void
    {
        $this->assertFieldError($this->createPrescription($this->rxExternalBody(['external_file_id' => Ulid::generate()])), 'external_file_id');
        $this->assertSame(0, DB::table('optical_prescriptions')->count());
    }

    public function test_un_rejeu_de_la_meme_cle_ne_cree_qu_une_prescription(): void
    {
        $body = $this->rxBody($this->consultationId);
        $key = Ulid::generate();

        $first = $this->createPrescription($body, null, $key)->assertCreated();
        $replay = $this->createPrescription($body, null, $key)->assertCreated();

        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(1, DB::table('optical_prescriptions')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'prescription.created')->count());
        $this->createPrescription([...$body, 'notes' => 'Autre charge'], null, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    public function test_l_audit_porte_l_origine_la_serie_et_la_version_sans_aucune_valeur_clinique(): void
    {
        $body = $this->rxBody($this->consultationId, ['notes' => 'SENTINELLE-NOTE-RX-PL3', 'eyes' => [$this->rxEye('OD', '-9.99'), $this->rxEye('OG', '-8.88')]]);
        $this->createPrescription($body)->assertCreated();

        $audit = DB::table('audit_events')->where('action', 'prescription.created')->where('target_id', $body['prescription_id'])->first();
        $this->assertNotNull($audit);
        $diff = json_decode((string) $audit->sanitized_diff, true);
        $this->assertSame(['consultation_id', 'origin', 'series_id', 'version_no'], collect($diff)->keys()->sort()->values()->all());
        $dump = $this->sideEffectsDump();
        foreach (['SENTINELLE-NOTE-RX-PL3', '-9.99', '-8.88'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('outbox_events')->where('event_type', 'PrescriptionValidated')->count());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roles(): array
    {
        return ['gérante' => ['MANAGER', 201], 'professionnel' => ['CLINICIAN', 201], 'accueil' => ['RECEPTION', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('roles')]
    public function test_seuls_la_gerante_et_le_professionnel_creent_une_prescription(string $role, int $status): void
    {
        $user = $this->makeStaff($this->clinicId, $role);

        $this->createPrescription($this->rxBody($this->consultationId), $user)->assertStatus($status);
        $this->createPrescription($this->rxExternalBody(), $user)->assertStatus($status === 201 ? 201 : 403);

        $this->assertSame($status === 201 ? 2 : 0, DB::table('optical_prescriptions')->count());
    }

    public function test_sans_le_droit_d_ecrire_ou_de_lire_la_creation_est_refusee_et_le_module_ferme_repond_404(): void
    {
        $this->denyPermission($this->clinician, 'clinical.write');
        $this->createPrescription($this->rxBody($this->consultationId))->assertStatus(403);

        $other = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.sans.lecture');
        $this->denyPermission($other, 'clinical.read');
        $this->createPrescription($this->rxBody($this->consultationId), $other)->assertStatus(403);

        config(['eeo.enabled_modules' => ['today', 'patients']]);
        $this->createPrescription($this->rxBody($this->consultationId), $this->manager)->assertNotFound();
        $this->assertSame(0, DB::table('optical_prescriptions')->count());
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
    public function test_un_echec_a_n_importe_quel_point_annule_la_creation_entiere(Checkpoint $failAt): void
    {
        $body = $this->rxBody($this->consultationId, ['treatments' => [$this->term('UV')]]);
        $before = [DB::table('optical_prescriptions')->count(), DB::table('optical_prescription_eyes')->count(), DB::table('optical_prescription_treatments')->count(), $this->clinicalEffectCounts()];
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->createPrescription($body);
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (\RuntimeException) {
            // Attendu : la transaction est annulée en entier.
        }

        $this->assertSame($before, [DB::table('optical_prescriptions')->count(), DB::table('optical_prescription_eyes')->count(), DB::table('optical_prescription_treatments')->count(), $this->clinicalEffectCounts()]);

        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->createPrescription($body)->assertCreated();
    }

    public function test_les_verrous_sont_pris_dans_l_ordre_cabinet_patient_consultation(): void
    {
        $body = $this->rxBody($this->consultationId);
        unset($body['expected_version']);
        $locks = new TransactionLocks($this->clinicId);
        $locks->clinic();

        $this->app->make(CreatePrescriptionHandler::class)->handle(
            new Command('CreatePrescription', $body, $body['prescription_id']),
            CommandContext::direct($this->clinician, 'corr-test-0001'),
            $locks,
            null,
        );

        $this->assertSame(['10:'.$this->clinicId, '45:'.$this->patientId, '47:'.$this->consultationId], $locks->acquired());
    }

    public function test_un_poste_hors_ligne_ou_synchronise_est_refuse(): void
    {
        $body = $this->rxBody($this->consultationId);
        unset($body['expected_version']);

        foreach ([CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC')), $this->syncContextFor($this->clinician)] as $context) {
            try {
                $this->app->make(CreatePrescriptionHandler::class)->handle(new Command('CreatePrescription', $body, $body['prescription_id']), $context, new TransactionLocks($this->clinicId), null);
                $this->fail('Une source autre que la route directe devait être refusée.');
            } catch (BusinessError $error) {
                $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
            }
        }
        $this->assertSame(0, DB::table('optical_prescriptions')->count());
    }

    /**
     * Second examen démarré (autre patient) : [visite, consultation]. Mémorise le patient dans `$otherPatientId`.
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
