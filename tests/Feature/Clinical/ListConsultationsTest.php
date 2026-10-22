<?php

namespace Tests\Feature\Clinical;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Liste des examens du cabinet (écran « Consultations ») : patient, état, dates et professionnel, AUCUNE valeur
 * clinique ; réservée à clinical.read (accueil et caisse : 403), module « consultations » (fermé : 404), limitée
 * au cabinet ; filtres jour civil du cabinet, état et « mes examens » ; ordre du plus récent au plus ancien ; la
 * liste n'est pas une lecture de dossier (aucun consultation.viewed). Données fictives.
 */
final class ListConsultationsTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * Consultation insérée directement (brouillon ou en cours), sur une visite neuve du cabinet donné.
     */
    private function seedConsultation(string $status = 'IN_PROGRESS', string $effectiveAt = '2026-10-22 09:20:00.000000', ?string $clinicId = null, ?User $clinician = null): string
    {
        $clinicId ??= $this->clinicId;
        $visitId = $this->seedVisit([], $clinicId);
        $id = Ulid::generate();
        DB::table('consultations')->insert([
            'id' => $id,
            'clinic_id' => $clinicId,
            'visit_id' => $visitId,
            'patient_id' => (string) DB::table('visits')->where('id', $visitId)->value('patient_id'),
            'clinician_id' => ($clinician ?? $this->clinician)->id,
            'effective_at' => $effectiveAt,
            'status' => $status,
            'current_revision' => 0,
            'completed_at' => null,
            'version' => 1,
            'created_at' => $effectiveAt,
            'updated_at' => $effectiveAt,
        ]);

        return $id;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function list(array $query = [], ?User $as = null): \Illuminate\Testing\TestResponse
    {
        $suffix = $query === [] ? '' : '?'.http_build_query($query);

        return $this->actingAsFresh($as ?? $this->clinician)->getJson('/api/v1/consultations'.$suffix);
    }

    public function test_la_liste_rend_les_examens_du_cabinet_sans_aucune_valeur_clinique(): void
    {
        [, $consultationId] = $this->startedExam();
        $this->saveCurrent($consultationId, 'ANAMNESIS', $this->anamnesis(['symptoms' => 'SENTINELLE-LISTE-SYMPTOME', 'wears_glasses' => true]))->assertOk();
        $this->saveCurrent($consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'SENTINELLE-LISTE-CONCLUSION', 'referral' => false]))->assertOk();
        $viewedBefore = DB::table('audit_events')->where('action', 'consultation.viewed')->count();

        $response = $this->list()->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/ConsultationSummaryPage');
        $response->assertJsonPath('data.0.id', $consultationId)
            ->assertJsonPath('data.0.status', 'IN_PROGRESS')
            ->assertJsonPath('data.0.patient.id', $this->patientId)
            ->assertJsonPath('data.0.clinician.id', $this->clinician->id)
            ->assertJsonPath('data.0.clinician.display_name', $this->clinician->display_name)
            ->assertJsonPath('meta.total', 1);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('SENTINELLE', $body);
        $this->assertStringNotContainsString('symptoms', $body);
        $this->assertStringNotContainsString('conclusion', $body);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        // Une liste n'est pas l'ouverture d'un dossier : aucune trace de lecture n'est écrite.
        $this->assertSame($viewedBefore, DB::table('audit_events')->where('action', 'consultation.viewed')->count());
    }

    public function test_accueil_et_caisse_n_ont_aucun_acces_la_gerante_et_le_professionnel_si(): void
    {
        $this->seedConsultation();

        $this->list([], $this->reception)->assertForbidden();
        $this->list([], $this->cashier)->assertForbidden();
        $this->list([], $this->manager)->assertOk()->assertJsonPath('meta.total', 1);
        $this->list([], $this->clinician)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_une_derogation_qui_retire_la_lecture_clinique_ferme_la_liste(): void
    {
        $this->seedConsultation();
        $this->denyPermission($this->clinician, 'clinical.read');

        $this->list()->assertForbidden();
    }

    public function test_module_consultations_ferme_la_liste_repond_introuvable(): void
    {
        $this->seedConsultation();
        config(['eeo.enabled_modules' => ['today', 'patients']]);

        $this->list()->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->list([], $this->reception)->assertNotFound();
    }

    public function test_les_examens_d_un_autre_cabinet_n_apparaissent_jamais(): void
    {
        $mine = $this->seedConsultation();
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $otherClinician = $this->makeStaff($otherClinic, 'CLINICIAN', 'pro.autre');
        $foreign = $this->seedConsultation('IN_PROGRESS', '2026-10-22 09:25:00.000000', $otherClinic, $otherClinician);

        $ids = array_column($this->list()->assertOk()->json('data'), 'id');

        $this->assertSame([$mine], $ids);
        $this->assertNotContains($foreign, $ids);
    }

    public function test_du_plus_recent_au_plus_ancien_avec_pagination(): void
    {
        $older = $this->seedConsultation('IN_PROGRESS', '2026-10-21 08:00:00.000000');
        $newer = $this->seedConsultation('DRAFT', '2026-10-22 09:00:00.000000');

        $response = $this->list()->assertOk();

        $this->assertSame([$newer, $older], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.last_page', 1);
        $this->list(['page' => '2'])->assertOk()->assertJsonPath('data', []);
    }

    public function test_filtre_par_jour_civil_du_cabinet_jamais_minuit_utc(): void
    {
        // 2026-10-21 23:30 UTC = 2026-10-22 00:30 à Douala (UTC+1) : examen du 22 pour le cabinet.
        $lateNightUtc = $this->seedConsultation('IN_PROGRESS', '2026-10-21 23:30:00.000000');
        // 2026-10-21 22:30 UTC = 2026-10-21 23:30 à Douala : examen du 21.
        $previousDay = $this->seedConsultation('IN_PROGRESS', '2026-10-21 22:30:00.000000');

        $this->assertSame([$lateNightUtc], array_column($this->list(['date' => '2026-10-22'])->assertOk()->json('data'), 'id'));
        $this->assertSame([$previousDay], array_column($this->list(['date' => '2026-10-21'])->assertOk()->json('data'), 'id'));
    }

    public function test_filtres_etat_et_mes_examens(): void
    {
        $draft = $this->seedConsultation('DRAFT', '2026-10-22 08:00:00.000000');
        $inProgress = $this->seedConsultation('IN_PROGRESS', '2026-10-22 08:30:00.000000');
        $otherClinician = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.second');
        $byOther = $this->seedConsultation('IN_PROGRESS', '2026-10-22 08:45:00.000000', null, $otherClinician);

        $this->assertSame([$draft], array_column($this->list(['status' => 'DRAFT'])->assertOk()->json('data'), 'id'));
        $this->assertSame([$byOther, $inProgress], array_column($this->list(['status' => 'IN_PROGRESS'])->assertOk()->json('data'), 'id'));
        $this->assertSame([$inProgress, $draft], array_column($this->list(['mine' => 'true'])->assertOk()->json('data'), 'id'));
        $this->assertCount(3, $this->list(['mine' => 'false'])->assertOk()->json('data'));
    }

    public function test_une_consultation_terminee_porte_sa_date_de_fin_et_sa_revision(): void
    {
        [, $consultationId] = $this->completableExam();
        $this->completeConsultation($consultationId)->assertOk();

        $response = $this->list(['status' => 'COMPLETED'])->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/ConsultationSummaryPage');
        $response->assertJsonPath('data.0.id', $consultationId)
            ->assertJsonPath('data.0.current_revision', 1);
        $this->assertNotNull($response->json('data.0.completed_at'));
    }

    public function test_les_filtres_invalides_sont_refuses_champ_par_champ(): void
    {
        $response = $this->list(['date' => '22/10/2026', 'status' => 'FINISHED', 'mine' => 'oui', 'page' => '0'])->assertUnprocessable();

        $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
        foreach (['date', 'status', 'mine', 'page'] as $field) {
            $this->assertArrayHasKey($field, $response->json('error.fields'), $field);
        }
    }
}
