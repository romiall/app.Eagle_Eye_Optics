<?php

namespace Tests\Feature\Patients;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Annulation d'une visite avant l'examen (P03 ; fichier 02 : la file distingue « annulé ») : motif obligatoire
 * conservé dans l'audit, version attendue, refus d'état quand l'examen a démarré ou qu'une facture non
 * abandonnée est liée, droits de l'arrivée (`visit.register`), isolation entre cabinets, module « today »,
 * rejeu idempotent. La visite reste dans la file, marquée annulée. Données fictives.
 */
final class CancelVisitTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée AVANT la création des comptes (bootClinical) : sinon les profils seraient « futurs ».
        $this->bootClinical(['today', 'patients', 'consultations']);
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function cancel(string $visitId, array $overrides = [], ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->reception)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->postJson('/api/v1/visits/'.$visitId.'/cancel', array_merge([
                'expected_version' => 1,
                'visit_id' => $visitId,
                'reason' => 'Patient reparti avant l\'examen',
            ], $overrides));
    }

    public function test_une_visite_arrivee_s_annule_avec_son_motif_et_reste_dans_la_file(): void
    {
        $visitId = $this->seedVisit();

        $response = $this->cancel($visitId)->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/patients.json#/$defs/VisitResponse');
        $response->assertJsonPath('data.id', $visitId)
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.version', 2);
        $row = DB::table('visits')->where('id', $visitId)->first();
        $this->assertNotNull($row);
        $this->assertSame('CANCELLED', $row->status);
        $this->assertNotNull($row->closed_at);

        $audit = DB::table('audit_events')->where('action', 'visit.cancelled')->where('target_id', $visitId)->first();
        $this->assertNotNull($audit, 'L\'annulation est tracée.');
        $this->assertSame('Patient reparti avant l\'examen', $audit->reason);

        $queue = $this->actingAsFresh($this->reception)->getJson('/api/v1/visits?date=2026-10-22')->assertOk();
        $this->assertContains($visitId, array_column($queue->json('data'), 'id'), 'La visite annulée reste dans la file.');
    }

    public function test_sans_motif_ou_avec_un_motif_trop_court_rien_n_est_annule(): void
    {
        $visitId = $this->seedVisit();

        $this->cancel($visitId, ['reason' => 'ok'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->cancel($visitId, ['reason' => null])->assertStatus(422);

        $this->assertSame('ARRIVED', DB::table('visits')->where('id', $visitId)->value('status'));
    }

    public function test_une_version_perimee_repond_conflit_avec_la_version_actuelle(): void
    {
        $visitId = $this->seedVisit(['version' => 3]);

        $this->cancel($visitId, ['expected_version' => 2])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT')
            ->assertJsonPath('error.details.current_version', 3);
        $this->assertSame('ARRIVED', DB::table('visits')->where('id', $visitId)->value('status'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function closedStatuses(): array
    {
        return [
            'en consultation' => ['IN_CONSULTATION'],
            'terminée' => ['COMPLETED'],
            'déjà annulée' => ['CANCELLED'],
        ];
    }

    #[DataProvider('closedStatuses')]
    public function test_une_visite_hors_de_l_avant_examen_ne_s_annule_pas(string $status): void
    {
        $visitId = $this->seedVisit(['status' => $status]);

        $this->cancel($visitId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED')
            ->assertJsonPath('error.details.current_status', $status);
    }

    public function test_une_visite_dont_l_examen_a_demarre_ne_s_annule_pas(): void
    {
        [$visitId] = $this->startedExam();
        $version = (int) DB::table('visits')->where('id', $visitId)->value('version');

        $this->cancel($visitId, ['expected_version' => $version])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
    }

    public function test_une_facture_non_abandonnee_liee_a_la_visite_empeche_l_annulation(): void
    {
        $visitId = $this->seedVisit();
        $invoiceId = Ulid::generate();
        DB::table('invoices')->insert([
            'id' => $invoiceId,
            'clinic_id' => $this->clinicId,
            'patient_id' => $this->patientId,
            'visit_id' => $visitId,
            'status' => 'DRAFT',
            'created_by' => $this->manager->id,
            'created_at' => '2026-10-22 09:20:00.000000',
            'updated_at' => '2026-10-22 09:20:00.000000',
        ]);

        $this->cancel($visitId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');

        DB::table('invoices')->where('id', $invoiceId)->update(['status' => 'ABANDONED', 'version' => 2]);
        $this->cancel($visitId)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_la_caisse_sans_droit_d_arrivee_est_refusee_et_un_autre_cabinet_repond_introuvable(): void
    {
        $visitId = $this->seedVisit();
        $this->cancel($visitId, [], $this->cashier)->assertStatus(403);

        $otherClinic = $this->makeClinic('TEST CABINET B');
        $this->makeStaff($otherClinic, 'RECEPTION', 'accueil.b.test');
        $foreign = $this->seedVisit([], $otherClinic);
        $this->cancel($foreign)->assertNotFound();
        $this->assertSame('ARRIVED', DB::table('visits')->where('id', $foreign)->value('status'));
    }

    public function test_le_corps_doit_designer_la_visite_de_l_adresse_et_porter_la_version(): void
    {
        $visitId = $this->seedVisit();

        $this->cancel($visitId, ['visit_id' => Ulid::generate()])->assertStatus(422);
        $this->actingAsFresh($this->reception)
            ->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson('/api/v1/visits/'.$visitId.'/cancel', ['visit_id' => $visitId, 'reason' => 'Motif fictif'])
            ->assertStatus(422);
        $this->assertSame('ARRIVED', DB::table('visits')->where('id', $visitId)->value('status'));
    }

    public function test_un_rejeu_de_la_meme_cle_rend_la_meme_reponse_sans_seconde_annulation(): void
    {
        $visitId = $this->seedVisit();
        $key = Ulid::generate();

        $this->cancel($visitId, [], null, $key)->assertOk();
        $this->cancel($visitId, [], null, $key)->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $this->assertSame(1, DB::table('audit_events')->where('action', 'visit.cancelled')->where('target_id', $visitId)->count());
        $this->assertSame(2, (int) DB::table('visits')->where('id', $visitId)->value('version'));
    }

    public function test_module_today_ferme_repond_introuvable(): void
    {
        $visitId = $this->seedVisit();
        config(['eeo.enabled_modules' => ['patients', 'consultations']]);

        $this->cancel($visitId)->assertNotFound();
    }
}
