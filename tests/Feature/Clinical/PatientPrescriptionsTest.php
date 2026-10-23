<?php

namespace Tests\Feature\Clinical;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\MigratedDatabase;
use Tests\Support\PatientActivityFixtures;
use Tests\TestCase;

/**
 * Prescriptions d'un patient (onglet « Prescriptions » du dossier, ordonnances externes A13) :
 * GET /api/v1/patients/{id}/prescriptions, module « consultations », droit clinical.read, sur MySQL réel.
 * Internes ET externes (sans consultation), de la plus récente à la plus ancienne, limitées au cabinet,
 * lecture tracée une fois par 10 minutes ; l'accueil et la caisse n'y ont pas accès. Données fictives.
 */
final class PatientPrescriptionsTest extends TestCase
{
    use MigratedDatabase;
    use PatientActivityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée AVANT la création des comptes (voir PatientActivityFixtures).
        $this->bootActivity(['today', 'patients', 'consultations']);
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    private function read(?User $as = null, ?string $patientId = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->clinician)
            ->getJson('/api/v1/patients/'.($patientId ?? $this->patientId).'/prescriptions');
    }

    public function test_les_prescriptions_internes_et_externes_du_patient_sont_listees_de_la_plus_recente_a_la_plus_ancienne(): void
    {
        $visit = $this->seedActivityVisit('2026-10-01 08:00:00', ['status' => 'COMPLETED']);
        $consultation = $this->seedActivityConsultation($visit, '2026-10-01 08:10:00', 'COMPLETED');
        $internal = $this->seedOpticalPrescription($consultation, '2026-10-01 08:30:00', '2026-10-01 08:40:00');
        $external = $this->seedOpticalPrescription(null, '2026-10-10 12:00:00');

        // Les lignes sont posées directement en base sans leurs deux yeux : la forme complète de la ressource est
        // prouvée par les tests de prescription (ValidatePrescriptionTest), ici l'ordre, l'origine et le cabinet.
        $response = $this->read()->assertOk();

        $this->assertSame([$external, $internal], array_column($response->json('data'), 'id'));
        $this->assertSame(['EXTERNAL', 'INTERNAL'], array_column($response->json('data'), 'origin'));
        $this->assertNull($response->json('data.0.consultation_id'), 'une ordonnance externe n’a pas de consultation');
    }

    public function test_un_patient_sans_prescription_rend_une_liste_vide(): void
    {
        $this->read()->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_l_accueil_et_la_caisse_n_y_ont_pas_acces_la_gerante_oui(): void
    {
        $this->read($this->reception)->assertForbidden();
        $this->read($this->cashier)->assertForbidden();
        $this->read($this->manager)->assertOk();
    }

    public function test_un_patient_inconnu_ou_d_un_autre_cabinet_repond_introuvable_et_un_module_ferme_404(): void
    {
        $other = $this->makeClinic();
        $this->makeStaff($other, 'MANAGER', 'gerante.autre');
        $foreign = $this->seedPatient($other, 'Etranger Fictif');

        $this->read(patientId: $foreign)->assertNotFound();
        $this->read(patientId: '01J9ZXK4M2Q8R7S6T5V4W3ZZZZ')->assertNotFound();

        config(['eeo.enabled_modules' => ['today', 'patients']]);
        $this->read()->assertNotFound();
    }

    public function test_la_lecture_est_tracee_une_fois_par_dix_minutes_sans_valeur_clinique(): void
    {
        $this->seedOpticalPrescription(null, '2026-10-10 12:00:00');

        $this->read()->assertOk();
        $this->read()->assertOk();

        $events = DB::table('audit_events')
            ->where('clinic_id', $this->clinicId)
            ->where('action', 'patient.prescriptions_viewed')
            ->get();
        $this->assertCount(1, $events);
        $this->assertSame('patient_records', $events[0]->target_type);
        $this->assertSame($this->patientId, $events[0]->target_id);
        $this->assertStringNotContainsString(self::CLINICAL_SENTINEL, (string) json_encode($events));
    }
}
