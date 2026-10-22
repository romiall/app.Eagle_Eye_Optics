<?php

namespace Tests\Feature\Clinical;

use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Rubrique CONCLUSION (C01, AT18) : conclusion en texte libre, orientation vers un ophtalmologiste
 * oui/non/non renseigné, niveau d'urgence OBLIGATOIRE quand le patient est orienté, motifs d'orientation (« Autre »
 * expliqué). Refus — jamais d'effacement silencieux — d'un niveau ou de motifs sans orientation. Données fictives.
 */
final class SaveConclusionStepTest extends TestCase
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

    public function test_une_conclusion_avec_orientation_est_enregistree_et_relue_a_l_identique(): void
    {
        $data = $this->conclusion([
            'conclusion' => "Myopie légère probable (fictif)\nContrôle dans 6 mois.",
            'referral' => true,
            'referral_urgency' => 'RAPID',
            'referral_reasons' => [$this->term('OTHER', 'Avis spécialisé (fictif)'), $this->term('SUSPECTED_GLAUCOMA')],
        ]);

        $saved = $this->saveCurrent($this->consultationId, 'CONCLUSION', $data)->assertOk();

        $this->assertMatchesContract($saved, 'schemas/resources/clinical.json#/$defs/ConsultationResponse');
        $step = $saved->json('data.steps.CONCLUSION');
        $this->assertSame($data['conclusion'], $step['conclusion']);
        $this->assertTrue($step['referral']);
        $this->assertSame('RAPID', $step['referral_urgency']);
        $this->assertSame(['SUSPECTED_GLAUCOMA', 'OTHER'], array_column($step['referral_reasons'], 'term_code'));
        $this->assertSame('Avis spécialisé (fictif)', $step['referral_reasons'][1]['other_text']);
        $this->assertSame($step, $this->readConsultation($this->consultationId)->json('data.steps.CONCLUSION'));

        $row = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->referral);
        $this->assertSame('RAPID', $row->referral_urgency);
        $this->assertSame(2, DB::table('consultation_referral_reasons')->where('consultation_id', $this->consultationId)->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function urgencies(): array
    {
        return ['pas urgent' => ['NOT_URGENT'], 'recommandée' => ['RECOMMENDED'], 'rapide' => ['RAPID'], 'urgence' => ['EMERGENCY']];
    }

    #[DataProvider('urgencies')]
    public function test_les_quatre_niveaux_d_urgence_sont_admis(string $urgency): void
    {
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => true, 'referral_urgency' => $urgency]))
            ->assertOk()
            ->assertJsonPath('data.steps.CONCLUSION.referral_urgency', $urgency);
    }

    public function test_l_orientation_oui_exige_le_niveau_d_urgence(): void
    {
        $response = $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'À orienter', 'referral' => true]));

        $response->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['step.data.referral_urgency']]]);
        $row = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->conclusion, 'Rien n\'est écrit : jamais d\'erreur SQL de la contrainte, jamais de saisie à moitié enregistrée.');
        $this->assertSame(1, (int) $row->version);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function referralWithoutReferral(): array
    {
        $reason = ['id' => Ulid::generate(), 'term_code' => 'CATARACT', 'other_text' => null];

        return [
            'non avec niveau' => [['referral' => false, 'referral_urgency' => 'RECOMMENDED'], ['step.data.referral_urgency']],
            'non renseigné avec niveau' => [['referral' => null, 'referral_urgency' => 'EMERGENCY'], ['step.data.referral_urgency']],
            'non avec motifs' => [['referral' => false, 'referral_reasons' => [$reason]], ['step.data.referral_reasons']],
            'non renseigné avec motifs' => [['referral' => null, 'referral_reasons' => [$reason]], ['step.data.referral_reasons']],
            'niveau et motifs sans orientation' => [['referral' => false, 'referral_urgency' => 'RAPID', 'referral_reasons' => [$reason]], ['step.data.referral_urgency', 'step.data.referral_reasons']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $fields
     */
    #[DataProvider('referralWithoutReferral')]
    public function test_un_niveau_ou_des_motifs_sans_orientation_sont_refuses_et_non_effaces(array $overrides, array $fields): void
    {
        $response = $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion($overrides));

        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing($fields, array_keys($response->json('error.fields')));
        $this->assertSame(0, DB::table('consultation_referral_reasons')->count());
        $this->assertSame(1, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
    }

    public function test_non_et_non_renseigne_sont_deux_reponses_distinctes(): void
    {
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => false]))->assertOk()->assertJsonPath('data.steps.CONCLUSION.referral', false);
        $this->assertSame(0, (int) DB::table('consultations')->where('id', $this->consultationId)->value('referral'));

        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => null]))->assertOk()->assertJsonPath('data.steps.CONCLUSION.referral', null);
        $this->assertNull(DB::table('consultations')->where('id', $this->consultationId)->value('referral'));
    }

    public function test_retirer_l_orientation_exige_de_retirer_aussi_le_niveau_et_les_motifs(): void
    {
        $reason = $this->term('CATARACT');
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => true, 'referral_urgency' => 'RECOMMENDED', 'referral_reasons' => [$reason]]))->assertOk();

        // Changer d'avis en laissant le niveau est refusé : le professionnel retire lui-même ce qu'il avait saisi.
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => false, 'referral_urgency' => 'RECOMMENDED', 'referral_reasons' => [$reason]]))->assertStatus(422);
        $this->assertSame('RECOMMENDED', DB::table('consultations')->where('id', $this->consultationId)->value('referral_urgency'));

        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => false]))->assertOk();
        $row = DB::table('consultations')->where('id', $this->consultationId)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->referral_urgency);
        $this->assertSame(0, (int) $row->referral);
        $this->assertSame(0, DB::table('consultation_referral_reasons')->count());
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string}>
     */
    public static function invalidReasons(): array
    {
        $reason = static fn (string $code, ?string $text = null): array => ['id' => Ulid::generate(), 'term_code' => $code, 'other_text' => $text];

        return [
            'motif inconnu' => [[$reason('NOT_A_REASON')], 'step.data.referral_reasons.0.term_code'],
            'motif d\'un autre groupe' => [[$reason('HEADACHE')], 'step.data.referral_reasons.0.term_code'],
            'autre sans texte' => [[$reason('OTHER')], 'step.data.referral_reasons.0.other_text'],
            'texte sans autre' => [[$reason('CATARACT', 'Précision')], 'step.data.referral_reasons.0.other_text'],
            'motif en double' => [[$reason('CATARACT'), $reason('CATARACT')], 'step.data.referral_reasons.1.term_code'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $reasons
     */
    #[DataProvider('invalidReasons')]
    public function test_des_motifs_d_orientation_invalides_sont_refuses_champ_par_champ(array $reasons, string $field): void
    {
        $response = $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => true, 'referral_urgency' => 'RECOMMENDED', 'referral_reasons' => $reasons]));

        $response->assertStatus(422);
        $this->assertArrayHasKey($field, $response->json('error.fields'));
        $this->assertSame(0, DB::table('consultation_referral_reasons')->count());
        $this->assertSame(1, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
    }

    public function test_un_niveau_inconnu_est_refuse_par_le_contrat(): void
    {
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => true, 'referral_urgency' => 'ASAP']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['step.data.referral_urgency']]]);
    }

    public function test_une_orientation_sans_motif_est_admise_a_l_enregistrement(): void
    {
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => true, 'referral_urgency' => 'NOT_URGENT']))
            ->assertOk()
            ->assertJsonPath('data.steps.CONCLUSION.referral_reasons', []);
    }

    public function test_la_comparaison_par_identifiant_conserve_les_motifs_inchanges(): void
    {
        $keep = $this->term('CATARACT');
        $drop = $this->term('CORNEAL_DISEASE');
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => true, 'referral_urgency' => 'RAPID', 'referral_reasons' => [$keep, $drop]]))->assertOk();
        $createdAt = DB::table('consultation_referral_reasons')->where('id', $keep['id'])->value('created_at');

        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'Ajout', 'referral' => true, 'referral_urgency' => 'RAPID', 'referral_reasons' => [$keep]]))->assertOk();

        $this->assertSame([$keep['id']], DB::table('consultation_referral_reasons')->pluck('id')->all());
        $this->assertSame($createdAt, DB::table('consultation_referral_reasons')->where('id', $keep['id'])->value('created_at'));
    }

    public function test_un_identifiant_de_motif_deja_utilise_ailleurs_est_un_422_par_champ(): void
    {
        $patient = $this->seedPatient($this->clinicId, 'Second Patient');
        $visitId = $this->seedVisit([], null, $patient);
        $body = $this->startBody($visitId, ['patient_id' => $patient]);
        $this->startConsultation($visitId, $body)->assertCreated();
        $taken = $this->term('CATARACT');
        $this->saveCurrent((string) $body['consultation_id'], 'CONCLUSION', $this->conclusion(['referral' => true, 'referral_urgency' => 'RAPID', 'referral_reasons' => [$taken]]))->assertOk();

        $response = $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['referral' => true, 'referral_urgency' => 'RAPID', 'referral_reasons' => [$this->term('TRAUMA', null, $taken['id'])]]));

        $response->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['step.data.referral_reasons.0.id']]]);
        $this->assertSame('CATARACT', DB::table('consultation_referral_reasons')->where('id', $taken['id'])->value('term_code'));
    }

    public function test_un_enregistrement_identique_de_la_conclusion_est_sans_effet_meme_avec_les_motifs_dans_un_autre_ordre(): void
    {
        $data = $this->conclusion([
            'conclusion' => 'Stable',
            'referral' => true,
            'referral_urgency' => 'RECOMMENDED',
            'referral_reasons' => [$this->term('CATARACT'), $this->term('CORNEAL_DISEASE')],
        ]);
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $data)->assertOk()->assertJsonPath('data.version', 2);
        $before = $this->clinicalEffectCounts();

        $reordered = [...$data, 'referral_reasons' => array_reverse($data['referral_reasons'])];
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $reordered)->assertOk()->assertJsonPath('data.version', 2);

        $after = $this->clinicalEffectCounts();
        $this->assertSame($before['audit'], $after['audit'], 'Aucun audit pour un enregistrement sans effet.');
        $this->assertSame($before['changes'], $after['changes'], 'Aucun changement publié pour un enregistrement sans effet.');
        $this->assertSame(2, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
    }

    public function test_la_conclusion_est_bornee_a_4000_caracteres_et_refuse_les_caracteres_de_controle(): void
    {
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => str_repeat('c', 4000)]))->assertOk();
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => str_repeat('c', 4001)]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['step.data.conclusion']]]);
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => "Alarme\x07"]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['step.data.conclusion']]]);
    }

    public function test_un_motif_d_orientation_desactive_depuis_la_saisie_reste_enregistrable_pour_la_meme_ligne(): void
    {
        $kept = $this->term('TRAUMA');
        $base = $this->conclusion(['referral' => true, 'referral_urgency' => 'RAPID', 'referral_reasons' => [$kept]]);
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $base)->assertOk();
        DB::table('clinical_terms')->where('group_code', 'REFERRAL_REASON')->whereIn('code', ['TRAUMA', 'CORNEAL_DISEASE'])->update(['active' => false]);

        $this->saveCurrent($this->consultationId, 'CONCLUSION', [...$base, 'conclusion' => 'Texte ajouté'])->assertOk()
            ->assertJsonPath('data.steps.CONCLUSION.referral_reasons.0.term_code', 'TRAUMA');

        $response = $this->saveCurrent($this->consultationId, 'CONCLUSION', [...$base, 'referral_reasons' => [$kept, $this->term('CORNEAL_DISEASE')]]);
        $response->assertStatus(422);
        $this->assertSame(['step.data.referral_reasons.1.term_code'], array_keys($response->json('error.fields')));
    }

    public function test_le_niveau_et_la_conclusion_ne_sortent_pas_des_tables_de_la_consultation(): void
    {
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion([
            'conclusion' => 'SENTINELLE-CONCLUSION-HD2',
            'referral' => true,
            'referral_urgency' => 'EMERGENCY',
            'referral_reasons' => [$this->term('OTHER', 'SENTINELLE-MOTIF-VB6')],
        ]))->assertOk();

        $dump = $this->sideEffectsDump();
        $this->assertStringNotContainsString('SENTINELLE-CONCLUSION-HD2', $dump);
        $this->assertStringNotContainsString('SENTINELLE-MOTIF-VB6', $dump);
        $this->assertStringNotContainsString('EMERGENCY', $dump);
        $this->assertEquals(['step' => 'CONCLUSION', 'version' => 2], json_decode((string) DB::table('audit_events')->where('action', 'consultation.step_saved')->value('sanitized_diff'), true));
    }
}
