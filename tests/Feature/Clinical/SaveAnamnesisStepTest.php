<?php

namespace Tests\Feature\Clinical;

use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Rubrique ANAMNESIS (C01, AT18) : motifs en sélection multiple (« Autre » expliqué), symptômes, port de
 * lunettes oui/non/non renseigné et ses dépendances. Remplacement complet par identifiant de ligne, intégrité
 * des termes contre le référentiel, refus (jamais d'effacement silencieux) des valeurs sans objet, erreurs
 * rendues ensemble par champ. Données fictives.
 */
final class SaveAnamnesisStepTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $consultationId;

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

    public function test_l_anamnese_complete_est_enregistree_et_relue_a_l_identique(): void
    {
        $other = $this->term('OTHER', 'Éblouissement la nuit (fictif)');
        $data = $this->anamnesis([
            'symptoms' => "Vision floue de près\ndepuis deux mois (fictif)",
            'wears_glasses' => true,
            'glasses_duration' => 'environ 3 ans',
            'previous_correction_available' => false,
            'complaints' => [$this->term('NEAR_DIFFICULTY'), $this->term('HEADACHE'), $other],
        ]);

        $saved = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $data)->assertOk();

        $this->assertMatchesContract($saved, 'schemas/resources/clinical.json#/$defs/ConsultationResponse');
        $steps = $saved->json('data.steps.ANAMNESIS');
        $this->assertSame($data['symptoms'], $steps['symptoms']);
        $this->assertTrue($steps['wears_glasses']);
        $this->assertSame('environ 3 ans', $steps['glasses_duration']);
        $this->assertFalse($steps['previous_correction_available'], 'Non (false) est une réponse, distincte de non renseigné (null).');
        $this->assertEqualsCanonicalizing(array_column($data['complaints'], 'id'), array_column($steps['complaints'], 'id'));
        // Ordre d'affichage du référentiel : céphalées (3), difficulté de près (6), autre (14).
        $this->assertSame(['HEADACHE', 'NEAR_DIFFICULTY', 'OTHER'], array_column($steps['complaints'], 'term_code'));
        $this->assertSame('Éblouissement la nuit (fictif)', $steps['complaints'][2]['other_text']);

        $reread = $this->readConsultation($this->consultationId)->assertOk();
        $this->assertSame($steps, $reread->json('data.steps.ANAMNESIS'));

        $rows = DB::table('consultation_complaints')->where('consultation_id', $this->consultationId)->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['COMPLAINT'], $rows->pluck('term_group')->unique()->values()->all());
        $this->assertSame($this->clinicId, $rows->first()->clinic_id);
    }

    public function test_les_reponses_non_posees_restent_null_et_non_est_distinct_de_non_renseigne(): void
    {
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['wears_glasses' => false]))->assertOk();
        $row = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->wears_glasses, 'Non est stocké comme 0.');
        $this->assertNull($row->previous_correction_available, 'Une réponse non posée reste NULL, jamais 0.');

        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['wears_glasses' => null]))->assertOk()->assertJsonPath('data.steps.ANAMNESIS.wears_glasses', null);
        $this->assertNull(DB::table('consultations')->where('id', $this->consultationId)->value('wears_glasses'));
    }

    public function test_l_enregistrement_remplace_la_rubrique_entiere_et_efface_ce_qui_est_omis(): void
    {
        $headache = $this->term('HEADACHE');
        $pain = $this->term('PAIN');
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Douleur', 'wears_glasses' => true, 'glasses_duration' => '1 an', 'complaints' => [$headache, $pain]]))->assertOk();

        // La ligne omise disparaît, la ligne conservée garde son identifiant.
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Douleur', 'wears_glasses' => true, 'glasses_duration' => '1 an', 'complaints' => [$pain]]))->assertOk();
        $this->assertSame([$pain['id']], DB::table('consultation_complaints')->pluck('id')->all());

        // Tout omettre efface tout : texte, réponses et motifs.
        $cleared = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis())->assertOk();
        $this->assertSame($this->anamnesis(), $cleared->json('data.steps.ANAMNESIS'));
        $this->assertSame(0, DB::table('consultation_complaints')->count());
    }

    public function test_la_comparaison_se_fait_par_identifiant_de_ligne(): void
    {
        $first = $this->term('HEADACHE', null, Ulid::generate());
        $second = $this->term('OTHER', 'Avant', Ulid::generate());
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$first, $second]]))->assertOk();
        $createdAt = DB::table('consultation_complaints')->where('id', $first['id'])->value('created_at');

        // Le texte d'une ligne change : la ligne est mise à jour, pas recréée.
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$first, [...$second, 'other_text' => 'Après']]]))->assertOk();
        $this->assertSame('Après', DB::table('consultation_complaints')->where('id', $second['id'])->value('other_text'));
        $this->assertSame($createdAt, DB::table('consultation_complaints')->where('id', $first['id'])->value('created_at'));
        $this->assertSame(2, DB::table('consultation_complaints')->count());

        // Le code d'une ligne change : la ligne est recréée avec le même identifiant.
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [[...$first, 'term_code' => 'PAIN'], [...$second, 'other_text' => 'Après']]]))->assertOk();
        $this->assertSame('PAIN', DB::table('consultation_complaints')->where('id', $first['id'])->value('term_code'));
    }

    public function test_deux_lignes_peuvent_echanger_leurs_codes_sans_heurter_l_unicite(): void
    {
        $a = $this->term('HEADACHE');
        $b = $this->term('PAIN');
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$a, $b]]))->assertOk();

        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [[...$a, 'term_code' => 'PAIN'], [...$b, 'term_code' => 'HEADACHE']]]))->assertOk();

        $this->assertSame('PAIN', DB::table('consultation_complaints')->where('id', $a['id'])->value('term_code'));
        $this->assertSame('HEADACHE', DB::table('consultation_complaints')->where('id', $b['id'])->value('term_code'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidAnamneses(): array
    {
        $id = Ulid::generate();

        return [
            'code inconnu du référentiel' => [['complaints' => [['id' => $id, 'term_code' => 'NOT_A_CODE', 'other_text' => null]]], 'step.data.complaints.0.term_code'],
            'code d\'un autre groupe' => [['complaints' => [['id' => $id, 'term_code' => 'DIABETES', 'other_text' => null]]], 'step.data.complaints.0.term_code'],
            'aucun connu n\'est pas un motif' => [['complaints' => [['id' => $id, 'term_code' => 'NONE', 'other_text' => null]]], 'step.data.complaints.0.term_code'],
            'autre sans texte' => [['complaints' => [['id' => $id, 'term_code' => 'OTHER', 'other_text' => null]]], 'step.data.complaints.0.other_text'],
            'autre avec texte blanc' => [['complaints' => [['id' => $id, 'term_code' => 'OTHER', 'other_text' => "  \n "]]], 'step.data.complaints.0.other_text'],
            'texte sur un code qui n\'est pas autre' => [['complaints' => [['id' => $id, 'term_code' => 'PAIN', 'other_text' => 'Précision']]], 'step.data.complaints.0.other_text'],
            'durée sans lunettes' => [['wears_glasses' => false, 'glasses_duration' => '3 ans'], 'step.data.glasses_duration'],
            'durée avec lunettes non renseigné' => [['wears_glasses' => null, 'glasses_duration' => '3 ans'], 'step.data.glasses_duration'],
            'ancienne correction sans lunettes' => [['wears_glasses' => false, 'previous_correction_available' => true], 'step.data.previous_correction_available'],
            'ancienne correction (non) sans lunettes' => [['wears_glasses' => null, 'previous_correction_available' => false], 'step.data.previous_correction_available'],
            'caractère de contrôle dans les symptômes' => [['symptoms' => "Gêne\u{0007}"], 'step.data.symptoms'],
            'caractère de contrôle dans un autre' => [['complaints' => [['id' => $id, 'term_code' => 'OTHER', 'other_text' => "Ligne 1\nLigne 2"]]], 'step.data.complaints.0.other_text'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidAnamneses')]
    public function test_une_anamnese_invalide_est_refusee_champ_par_champ_sans_rien_ecrire(array $overrides, string $field): void
    {
        $response = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis($overrides));

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey($field, $response->json('error.fields'));
        $row = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->version);
        $this->assertSame('DRAFT', $row->status);
        $this->assertNull($row->glasses_duration, 'La valeur sans objet est refusée, jamais effacée en silence ni stockée.');
        $this->assertSame(0, DB::table('consultation_complaints')->count());
    }

    public function test_un_code_desactive_du_referentiel_n_est_plus_admis_mais_reste_lisible(): void
    {
        $kept = $this->term('REDNESS');
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$kept]]))->assertOk();
        DB::table('clinical_terms')->where('group_code', 'COMPLAINT')->whereIn('code', ['REDNESS', 'ITCHING'])->update(['active' => false]);

        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$this->term('ITCHING')]]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['step.data.complaints.0.term_code']]]);
        $this->assertSame('REDNESS', $this->readConsultation($this->consultationId)->json('data.steps.ANAMNESIS.complaints.0.term_code'), 'Un code désactivé reste lisible dans les consultations déjà saisies.');
    }

    public function test_les_doublons_de_code_et_d_identifiant_sont_refuses(): void
    {
        $id = Ulid::generate();

        $codes = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$this->term('PAIN'), $this->term('PAIN')]]));
        $codes->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['step.data.complaints.1.term_code']]]);

        $ids = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$this->term('PAIN', null, $id), $this->term('HEADACHE', null, $id)]]));
        $ids->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['step.data.complaints.1.id']]]);

        $this->assertSame(0, DB::table('consultation_complaints')->count());
    }

    public function test_un_identifiant_de_ligne_deja_utilise_ailleurs_est_un_422_par_champ_et_non_une_erreur_sql(): void
    {
        // Ligne d'une AUTRE consultation du même cabinet.
        [, $otherConsultation] = $this->startedExam2();
        $taken = $this->term('PAIN');
        $this->saveCurrent($otherConsultation, 'ANAMNESIS', $this->anamnesis(['complaints' => [$taken]]))->assertOk();

        // Ligne d'un autre cabinet.
        $foreign = $this->foreignComplaintId();

        $response = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [
            $this->term('HEADACHE', null, $taken['id']),
            $this->term('PAIN', null, $foreign),
        ]]));

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('step.data.complaints.0.id', $response->json('error.fields'));
        $this->assertArrayHasKey('step.data.complaints.1.id', $response->json('error.fields'));
        $this->assertSame(0, DB::table('consultation_complaints')->where('consultation_id', $this->consultationId)->count());
        $this->assertSame('PAIN', DB::table('consultation_complaints')->where('id', $taken['id'])->value('term_code'), 'La ligne de l\'autre consultation est intacte.');
    }

    public function test_toutes_les_erreurs_sont_rendues_ensemble(): void
    {
        $response = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis([
            'wears_glasses' => false,
            'glasses_duration' => '2 ans',
            'previous_correction_available' => true,
            'complaints' => [$this->term('OTHER'), $this->term('NOT_A_CODE')],
        ]));

        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing(
            ['step.data.glasses_duration', 'step.data.previous_correction_available', 'step.data.complaints.0.other_text', 'step.data.complaints.1.term_code'],
            array_keys($response->json('error.fields')),
        );
    }

    public function test_les_blancs_de_bord_sont_retires_et_les_retours_a_la_ligne_conserves(): void
    {
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => "  Première ligne\nSeconde ligne  \n", 'wears_glasses' => true, 'glasses_duration' => '   ']))
            ->assertOk()
            ->assertJsonPath('data.steps.ANAMNESIS.symptoms', "Première ligne\nSeconde ligne")
            ->assertJsonPath('data.steps.ANAMNESIS.glasses_duration', null);
    }

    public function test_le_texte_est_borne_a_4000_caracteres_par_le_contrat(): void
    {
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => str_repeat('é', 4000)]))->assertOk();
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => str_repeat('é', 4001)]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['step.data.symptoms']]]);
    }

    public function test_une_ligne_existante_au_code_desactive_reste_enregistrable_mais_pas_une_ligne_neuve(): void
    {
        $kept = $this->term('REDNESS');
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Avant', 'complaints' => [$kept]]))->assertOk();
        DB::table('clinical_terms')->where('group_code', 'COMPLAINT')->whereIn('code', ['REDNESS', 'ITCHING'])->update(['active' => false]);

        // Même ligne (même identifiant, même code), seul le texte change : la sauvegarde passe.
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Après', 'complaints' => [$kept]]))
            ->assertOk()
            ->assertJsonPath('data.steps.ANAMNESIS.symptoms', 'Après')
            ->assertJsonPath('data.steps.ANAMNESIS.complaints.0.term_code', 'REDNESS');
        // Renvoi strictement identique : sans effet, pas d'erreur.
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'Après', 'complaints' => [$kept]]))->assertOk()->assertJsonPath('data.version', 3);

        // Une ligne neuve avec un code désactivé est refusée ; la ligne conservée ne l'est pas.
        $response = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$kept, $this->term('ITCHING')]]));
        $response->assertStatus(422);
        $this->assertSame(['step.data.complaints.1.term_code'], array_keys($response->json('error.fields')));

        // Le même identifiant avec un AUTRE code désactivé n'est pas « la même ligne » : refusé.
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$this->term('ITCHING', null, $kept['id'])]]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['step.data.complaints.0.term_code']]]);
    }

    public function test_un_texte_autre_invalide_ne_recoit_pas_la_consigne_de_le_preciser(): void
    {
        // Retour à la ligne dans une précision sur une ligne : l'erreur est celle du caractère, pas « Précisez… ».
        $control = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$this->term('OTHER', "a\nb")]]));
        $control->assertStatus(422);
        $this->assertSame(['Ce champ ne peut pas contenir de caractère de contrôle.'], $control->json('error.fields')['step.data.complaints.0.other_text']);

        // Texte absent ou blanc : la consigne de préciser est donnée.
        foreach ([null, '   '] as $missing) {
            $response = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$this->term('OTHER', $missing)]]));
            $response->assertStatus(422);
            $this->assertSame(['Précisez le texte de « Autre ».'], $response->json('error.fields')['step.data.complaints.0.other_text']);
        }
    }

    public function test_un_texte_trop_long_n_a_qu_un_message_de_limite_sans_type_invalide(): void
    {
        $response = $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['complaints' => [$this->term('OTHER', str_repeat('x', 4001))]]));

        $response->assertStatus(422);
        $this->assertSame(['Valeur hors des limites admises.'], $response->json('error.fields')['step.data.complaints.0.other_text']);
    }

    public function test_la_duree_de_port_est_bornee_a_4000_caracteres_et_refuse_les_caracteres_de_controle(): void
    {
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['wears_glasses' => true, 'glasses_duration' => str_repeat('d', 4000)]))->assertOk();
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['wears_glasses' => true, 'glasses_duration' => str_repeat('d', 4001)]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['step.data.glasses_duration']]]);
        $this->saveCurrent($this->consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => "Alarme\x07"]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['step.data.symptoms']]]);
    }

    /**
     * Seconde consultation du même cabinet, sur un autre patient et une autre visite.
     *
     * @return array{0: string, 1: string}
     */
    private function startedExam2(): array
    {
        $patient = $this->seedPatient($this->clinicId, 'Second Patient');
        $visitId = $this->seedVisit([], null, $patient);
        $body = $this->startBody($visitId, ['patient_id' => $patient]);
        $this->startConsultation($visitId, $body)->assertCreated();

        return [$visitId, (string) $body['consultation_id']];
    }

    /**
     * Motif d'une consultation d'un AUTRE cabinet (inséré directement) : retourne son identifiant.
     */
    private function foreignComplaintId(): string
    {
        $clinic = $this->makeClinic();
        $user = $this->makeStaff($clinic, 'MANAGER', 'autre.gerante.motif');
        $patient = $this->seedPatient($clinic);
        $visit = $this->seedVisit([], $clinic, $patient);
        $consultation = Ulid::generate();
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('consultations')->insert([
            'id' => $consultation, 'clinic_id' => $clinic, 'visit_id' => $visit, 'patient_id' => $patient, 'clinician_id' => $user->id,
            'effective_at' => $now, 'status' => 'DRAFT', 'current_revision' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = Ulid::generate();
        DB::table('consultation_complaints')->insert([
            'id' => $id, 'clinic_id' => $clinic, 'consultation_id' => $consultation, 'term_group' => 'COMPLAINT', 'term_code' => 'PAIN',
            'other_text' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }
}
