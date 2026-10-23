<?php

namespace Tests\Feature\Reports;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MigratedDatabase;
use Tests\Support\PatientActivityFixtures;
use Tests\TestCase;

/**
 * Statistiques du dossier patient (R02 ; fichier 02 « Historique et statistiques ») sur MySQL réel :
 * GET /api/v1/reports/activity (statistics.activity) et /reports/clinical (statistics.clinical), module « reports ».
 * Accès administratif et clinique séparés, période de jours civils du cabinet (Africa/Douala, UTC+1), visites
 * annulées exclues, nouveaux et anciens patients (reprise historique comprise), sexe, tranches d'âge, motifs,
 * prescriptions validées, ordonnances, orientations, amétropies, isolation entre cabinets, aucune donnée
 * identifiante ni clinique en clair. Données fictives.
 */
final class StatisticsTest extends TestCase
{
    use MigratedDatabase;
    use PatientActivityFixtures;

    private const ACTIVITY = 'schemas/resources/scheduling.json#/$defs/ActivityReportResponse';

    private const CLINICAL = 'schemas/resources/scheduling.json#/$defs/ClinicalReportResponse';

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée AVANT la création des comptes (voir PatientActivityFixtures).
        $this->bootActivity(['today', 'patients', 'consultations', 'sales', 'reports']);
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    private function activity(string $query = '?from=2026-10-01&to=2026-10-31', ?User $as = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->manager)->getJson('/api/v1/reports/activity'.$query);
    }

    private function clinical(string $query = '?from=2026-10-01&to=2026-10-31', ?User $as = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->manager)->getJson('/api/v1/reports/clinical'.$query);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function roles(): array
    {
        return [
            'gérante : les deux tableaux' => ['manager', 200, 200],
            'accueil : activité seulement' => ['reception', 200, 403],
            'professionnel : examens seulement' => ['clinician', 403, 200],
            'caisse : aucun' => ['cashier', 403, 403],
        ];
    }

    #[DataProvider('roles')]
    public function test_l_acces_administratif_et_clinique_est_separe(string $role, int $activity, int $clinical): void
    {
        $this->activity(as: $this->{$role})->assertStatus($activity);
        $this->clinical(as: $this->{$role})->assertStatus($clinical);
    }

    public function test_un_module_ferme_repond_404_avant_les_droits_et_un_visiteur_401(): void
    {
        config(['eeo.enabled_modules' => ['today', 'patients']]);
        $this->activity()->assertNotFound();
        $this->clinical(as: $this->clinician)->assertNotFound();

        config(['eeo.enabled_modules' => ['reports']]);
        $this->flushSession();
        $this->nextRequest()->getJson('/api/v1/reports/activity?from=2026-10-01&to=2026-10-31')->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidPeriods(): array
    {
        return [
            'sans dates' => ['', 'from'],
            'fin absente' => ['?from=2026-10-01', 'to'],
            'format français' => ['?from=01/10/2026&to=2026-10-31', 'from'],
            'date impossible' => ['?from=2026-02-30&to=2026-03-31', 'from'],
            'fin avant début' => ['?from=2026-10-31&to=2026-10-01', 'to'],
            'plus de 366 jours' => ['?from=2025-01-01&to=2026-01-02', 'to'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_une_periode_invalide_est_refusee_champ_par_champ(string $query, string $field): void
    {
        $this->activity($query)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        $this->clinical($query)->assertStatus(422);
    }

    public function test_une_annee_bissextile_complete_est_acceptee(): void
    {
        $this->activity('?from=2028-01-01&to=2028-12-31')->assertOk()->assertJsonPath('data.visits', 0);
    }

    public function test_l_activite_compte_visites_patients_nouveaux_sexe_et_ages_au_fuseau_du_cabinet(): void
    {
        // Patient A (fixture, F, né le 1990-05-12 : 36 ans au 31/10/2026) : première visite AVANT la période → ancien.
        $this->seedActivityVisit('2026-09-15 08:00:00', ['status' => 'COMPLETED']);
        $this->seedActivityVisit('2026-10-05 08:00:00', ['status' => 'COMPLETED']);
        $this->seedActivityVisit('2026-10-06 08:00:00', ['reason' => 'PICKUP']);

        // Patient B (M, 12 ans) : première visite dans la période → nouveau ; la visite du 30/09 à 23:30 UTC tombe le
        // 01/10 à 00:30 au fuseau du cabinet (Africa/Douala, UTC+1) : elle compte dans octobre.
        $b = $this->seedPatient($this->clinicId, 'Enfant Fictif', 'Test', ['sex' => 'M', 'birth_date' => '2014-06-01']);
        $this->seedActivityVisit('2026-09-30 23:30:00', [], null, $b);

        // Patient C (sexe non renseigné, sans date de naissance) : dossier repris avec une première visite papier en
        // 2019 → ancien malgré sa première visite enregistrée dans la période.
        $c = $this->seedPatient($this->clinicId, 'Repris Fictif', 'Test', [
            'sex' => 'UNKNOWN', 'birth_date' => null, 'birth_date_missing_reason' => 'Inconnue (dossier papier)',
            'legacy_record' => true, 'first_known_visit_on' => '2019-03-01',
        ]);
        $this->seedActivityVisit('2026-10-20 09:00:00', ['reason' => 'FOLLOW_UP'], null, $c);

        // Patient D (F, 65 ans) : seule visite annulée → absent de la période.
        $d = $this->seedPatient($this->clinicId, 'Annule Fictif', 'Test', ['birth_date' => '1961-01-01']);
        $this->seedActivityVisit('2026-10-07 09:00:00', ['status' => 'CANCELLED'], null, $d);

        // Visite hors période (31/10 à 23:30 UTC = 01/11 00:30 au cabinet) : exclue.
        $this->seedActivityVisit('2026-10-31 23:30:00');

        // Un autre cabinet ne compte jamais.
        $other = $this->makeClinic();
        $this->makeStaff($other, 'MANAGER', 'gerante.autre');
        $foreign = $this->seedPatient($other, 'Etranger Fictif');
        $this->seedActivityVisit('2026-10-10 09:00:00', [], $other, $foreign);

        $response = $this->activity()->assertOk();
        $this->assertMatchesContract($response, self::ACTIVITY);
        $data = $response->json('data');

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31', 'timezone' => 'Africa/Douala'],
            ['from' => $data['from'], 'to' => $data['to'], 'timezone' => $data['timezone']]);
        $this->assertSame(4, $data['visits'], 'visites non annulées de la période (A ×2, B, C)');
        $this->assertSame([['reason' => 'EXAM', 'count' => 2], ['reason' => 'FOLLOW_UP', 'count' => 1], ['reason' => 'PICKUP', 'count' => 1]],
            $data['visits_by_reason']);
        $this->assertSame(3, $data['patients']);
        $this->assertSame(1, $data['new_patients'], 'seul B a sa première visite connue dans la période');
        $this->assertSame(2, $data['returning_patients']);
        $this->assertSame([['sex' => 'F', 'count' => 1], ['sex' => 'M', 'count' => 1], ['sex' => 'UNKNOWN', 'count' => 1]], $data['sex']);
        $this->assertSame([
            ['min_age' => 0, 'max_age' => 17, 'count' => 1],
            ['min_age' => 18, 'max_age' => 39, 'count' => 1],
            ['min_age' => 40, 'max_age' => 59, 'count' => 0],
            ['min_age' => 60, 'max_age' => null, 'count' => 0],
        ], $data['age_bands']);
        $this->assertSame(1, $data['age_unknown']);
        $this->assertNull($data['appointments'], 'agenda non livré : jamais un zéro inventé');
        $this->assertNull($data['follow_ups_due']);

        $body = (string) $response->getContent();
        foreach (['Enfant Fictif', 'Repris Fictif', 'EE-2026-', self::CLINICAL_SENTINEL] as $identifying) {
            $this->assertStringNotContainsString($identifying, $body);
        }
    }

    public function test_les_statistiques_cliniques_comptent_par_consultation_sans_valeur_clinique(): void
    {
        $v1 = $this->seedActivityVisit('2026-10-02 08:00:00', ['status' => 'COMPLETED']);
        $c1 = $this->seedActivityConsultation($v1, '2026-10-02 08:10:00', 'COMPLETED');
        $v2 = $this->seedActivityVisit('2026-10-03 08:00:00', ['status' => 'IN_CONSULTATION']);
        $c2 = $this->seedActivityConsultation($v2, '2026-10-03 08:10:00', 'IN_PROGRESS', ['referral' => null, 'referral_urgency' => null]);
        $v3 = $this->seedActivityVisit('2026-10-04 08:00:00', ['status' => 'IN_CONSULTATION']);
        $c3 = $this->seedActivityConsultation($v3, '2026-10-04 08:10:00', 'IN_PROGRESS', ['referral' => false, 'referral_urgency' => null]);
        // Consultation hors période : jamais comptée.
        $v4 = $this->seedActivityVisit('2026-11-02 08:00:00', ['status' => 'COMPLETED']);
        $c4 = $this->seedActivityConsultation($v4, '2026-11-02 08:10:00', 'COMPLETED');

        $complaint = fn (string $consultation, string $code): bool => DB::table('consultation_complaints')->insert([
            'id' => Ulid::generate(), 'clinic_id' => $this->clinicId, 'consultation_id' => $consultation,
            'term_group' => 'COMPLAINT', 'term_code' => $code, 'other_text' => $code === 'OTHER' ? self::CLINICAL_SENTINEL.'-AUTRE' : null,
            'created_at' => self::at('2026-10-02 08:10:00'), 'updated_at' => self::at('2026-10-02 08:10:00'),
        ]);
        $complaint($c1, 'BLURRED_VISION');
        $complaint($c1, 'HEADACHE');
        $complaint($c2, 'BLURRED_VISION');
        $complaint($c4, 'BLURRED_VISION');
        $complaint($c2, 'OTHER');

        $ametropia = DB::table('clinical_terms')->where('group_code', 'AMETROPIA')->orderBy('sort_order')->value('code');
        $this->assertIsString($ametropia, 'référentiel des amétropies présent');
        DB::table('consultation_ametropias')->insert([
            'id' => Ulid::generate(), 'clinic_id' => $this->clinicId, 'consultation_id' => $c1, 'term_group' => 'AMETROPIA',
            'term_code' => $ametropia, 'other_text' => null,
            'created_at' => self::at('2026-10-02 08:10:00'), 'updated_at' => self::at('2026-10-02 08:10:00'),
        ]);

        $this->seedOpticalPrescription($c1, '2026-10-02 08:30:00', '2026-10-02 08:40:00');
        $this->seedOpticalPrescription($c2, '2026-10-03 08:30:00');
        $this->seedMedicationOrder($c1);
        $this->seedMedicationOrder($c1, 2);
        $this->seedMedicationOrder($c3);

        $response = $this->clinical(as: $this->clinician)->assertOk();
        $this->assertMatchesContract($response, self::CLINICAL);
        $data = $response->json('data');

        $this->assertSame(3, $data['consultations']);
        $this->assertSame(1, $data['consultations_completed']);
        $this->assertSame([
            ['term_code' => 'BLURRED_VISION', 'count' => 2],
            ['term_code' => 'HEADACHE', 'count' => 1],
            ['term_code' => 'OTHER', 'count' => 1],
        ], $data['complaints']);
        $this->assertSame(1, $data['consultations_without_complaint'], 'c3');
        $this->assertSame(1, $data['prescriptions_validated'], 'un brouillon ne compte pas');
        $this->assertSame(2, $data['medication_orders'], 'une consultation compte une fois');
        $this->assertSame(1, $data['referrals']);
        $this->assertSame([['urgency' => 'RAPID', 'count' => 1]], $data['referrals_by_urgency']);
        $this->assertSame(1, $data['referral_unknown'], 'c2 : non renseigné, distinct de « non » (c3)');
        $this->assertSame([['term_code' => $ametropia, 'count' => 1]], $data['ametropias']);

        $this->assertStringNotContainsString(self::CLINICAL_SENTINEL, (string) $response->getContent());
    }

    public function test_une_derogation_qui_retire_le_droit_refuse_la_lecture(): void
    {
        $this->denyPermission($this->clinician, 'statistics.clinical');
        $this->clinical(as: $this->clinician)->assertForbidden();
        $this->denyPermission($this->reception, 'statistics.activity');
        $this->activity(as: $this->reception)->assertForbidden();
    }
}
