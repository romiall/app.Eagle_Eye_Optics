<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Access\EffectivePermissions;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AgendaFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Contrôles recommandés (S02, AT22) sur MySQL réel : date calculée depuis l'origine choisie (recalculée par le
 * serveur), date manuelle motivée, aucune décision médicale automatique, tâche de rappel interne unique, programmation
 * par un rendez-vous (SCHEDULED, tâches BOOKED), réouverture après annulation, cloisonnement clinique de la liste et
 * droits. Données fictives.
 */
final class FollowUpsTest extends TestCase
{
    use AgendaFixtures;
    use MigratedDatabase;

    private string $consultationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgenda();
        $this->consultationId = $this->seedConsultation();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    private function create(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->send($as ?? $this->clinician, 'POST', '/api/v1/follow-ups', $this->followUpBody($this->consultationId, $overrides));
    }

    public function test_un_controle_calcule_cree_sa_tache_de_rappel_interne(): void
    {
        $response = $this->create()->assertCreated();

        $this->assertMatchesContract($response, 'schemas/resources/scheduling.json#/$defs/FollowUpPlanResponse');
        $response->assertJsonPath('data.recommended_date', '2027-01-22')
            ->assertJsonPath('data.reminder_date', '2027-01-15')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.reason', 'ROUTINE');
        $tasks = DB::table('contact_tasks')->where('follow_up_id', $response->json('data.id'))->get();
        $this->assertCount(1, $tasks);
        $this->assertSame('2027-01-14 23:00:00.000000', (string) $tasks[0]->due_at, 'minuit du 15 janvier à Douala');
        $this->assertSame('CALL', $tasks[0]->channel);
        $audit = (string) DB::table('audit_events')->where('action', 'follow_up.created')->value('sanitized_diff');
        $this->assertStringNotContainsString('SENTINELLE', $audit, 'aucun texte clinique dans l\'audit');
    }

    public function test_la_date_est_recalculee_par_le_serveur_au_calendrier_civil(): void
    {
        $this->create(['recommended_date' => '2027-01-23'])->assertStatus(422)
            ->assertJsonPath('error.fields.recommended_date.0', 'La date recommandée ne correspond pas au calcul (départ + délai = 2027-01-22).');
        $this->create(['anchor_date' => '2026-10-21'])->assertStatus(422);

        // Fin de mois : 31 janvier + 1 mois = dernier jour de février.
        $this->create(['anchor_kind' => 'TREATMENT_END', 'anchor_date' => '2027-01-31', 'interval_value' => 1, 'recommended_date' => '2027-02-28'])
            ->assertCreated();
        // Collyre : 12 jours après la fin du traitement donnée par le professionnel.
        $this->create(['reason' => 'DROPS', 'anchor_kind' => 'TREATMENT_END', 'anchor_date' => '2026-10-30', 'interval_value' => 12,
            'interval_unit' => 'DAY', 'recommended_date' => '2026-11-11'])->assertCreated();
    }

    public function test_une_date_manuelle_exige_son_motif(): void
    {
        $manual = ['date_origin' => 'MANUAL', 'anchor_kind' => 'MANUAL', 'anchor_date' => '2026-11-05', 'interval_value' => null,
            'interval_unit' => null, 'recommended_date' => '2026-11-05'];
        // Sans motif : refusé dès le contrat de la commande (date_origin MANUAL impose une chaîne non vide).
        $this->create($manual)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['manual_reason']]]);
        // Motif trop court : la règle métier donne le message français.
        $this->create(['manual_reason' => 'ab'] + $manual)->assertStatus(422)
            ->assertJsonPath('error.fields.manual_reason.0', 'Une date saisie à la main porte son motif (au moins 3 caractères).');
        $this->create(['manual_reason' => 'Disponibilité du patient'] + $manual)->assertCreated()->assertJsonPath('data.date_origin', 'MANUAL');
    }

    public function test_droits_et_cloisonnement_clinique(): void
    {
        $this->create([], $this->reception)->assertForbidden();
        $plan = $this->create()->assertCreated()->json('data.id');

        $reception = $this->read($this->reception, '/api/v1/follow-ups?from=2027-01-01&to=2027-01-31')->assertOk();
        $this->assertMatchesContract($reception, 'schemas/resources/scheduling.json#/$defs/FollowUpPlanList');
        $reception->assertJsonPath('data.0.id', $plan)->assertJsonPath('data.0.reason', null)->assertJsonPath('data.0.notes', null);
        $this->assertStringNotContainsString('SENTINELLE', (string) $reception->getContent());

        $this->read($this->clinician, '/api/v1/follow-ups?from=2027-01-01&to=2027-01-31')
            ->assertJsonPath('data.0.reason', 'ROUTINE')->assertJsonPath('data.0.notes', 'SENTINELLE-CLINIQUE-NOTE');

        // Un professionnel privé de la lecture clinique ne programme pas de contrôle.
        DB::table('user_permissions')->insert([
            'id' => Ulid::generate(), 'clinic_id' => $this->clinicId, 'user_id' => $this->clinician->id, 'permission_code' => 'clinical.read',
            'allowed' => false, 'assigned_by' => $this->manager->id, 'reason' => 'Test', 'expires_at' => null,
            'created_at' => '2026-01-01 00:00:00.000000', 'updated_at' => '2026-01-01 00:00:00.000000',
        ]);
        $this->app->make(EffectivePermissions::class)->forget($this->clinician);
        $this->create()->assertForbidden();
    }

    public function test_un_rendez_vous_programme_le_controle_et_son_annulation_le_rouvre(): void
    {
        $plan = $this->create()->assertCreated()->json('data.id');

        $appointment = $this->book(['follow_up_id' => $plan, 'starts_at' => '2026-10-26T07:00:00.000Z', 'ends_at' => '2026-10-26T07:30:00.000Z'])
            ->assertCreated()->assertJsonPath('data.follow_up_id', $plan)->json('data.id');
        $this->assertSame('SCHEDULED', DB::table('follow_up_plans')->where('id', $plan)->value('status'));
        $this->assertSame('BOOKED', DB::table('contact_tasks')->where('follow_up_id', $plan)->value('status'));
        $this->read($this->clinician, '/api/v1/follow-ups?consultation_id='.$this->consultationId)->assertJsonPath('data.0.appointment_id', $appointment);

        $this->send($this->reception, 'POST', "/api/v1/appointments/$appointment/cancel", ['expected_version' => 1, 'reason' => 'Report fictif'])->assertOk();
        $this->assertSame('ACTIVE', DB::table('follow_up_plans')->where('id', $plan)->value('status'), 'le patient reste à revoir');

        // Un rendez-vous ne programme pas le contrôle d'un autre patient.
        $other = $this->seedPatient($this->clinicId, 'Autre Fictif');
        $this->book(['patient_id' => $other, 'follow_up_id' => $plan])->assertStatus(422);
    }
}
