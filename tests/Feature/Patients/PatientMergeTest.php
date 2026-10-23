<?php

namespace Tests\Feature\Patients;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\MigratedDatabase;
use Tests\Support\PatientActivityFixtures;
use Tests\TestCase;

/**
 * Fusion manuelle de deux fiches (L1D08 ; AT04, AT19, AT21, AT42) sur MySQL réel : aperçu (identités, éléments
 * rattachés filtrés par les droits, garde-fous), fusion par la gérante réauthentifiée avec motif et version attendue,
 * aucune réécriture (pièces et visites gardent leur fiche et leurs snapshots), fiche absorbée hors recherche,
 * activité réunie sur la fiche conservée, rejeu idempotent. Données fictives.
 */
final class PatientMergeTest extends TestCase
{
    use MigratedDatabase;
    use PatientActivityFixtures;

    private string $survivorId;

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée AVANT la création des comptes (voir PatientActivityFixtures).
        $this->bootActivity(['today', 'patients', 'consultations', 'sales', 'agenda']);
        // Homonyme : même nom, autre fiche (doublon de l'accueil).
        $this->survivorId = $this->seedPatient($this->clinicId, 'Patient Fictif', 'Test');
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function merge(array $overrides = [], ?User $as = null, bool $reauthenticated = true, ?string $key = null): TestResponse
    {
        $user = $as ?? $this->manager;
        $client = $reauthenticated ? $this->actingAsReauthenticated($user) : $this->actingAsFresh($user);

        return $client->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->postJson('/api/v1/patients/'.$this->patientId.'/merge', array_merge([
                'expected_version' => 1,
                'source_patient_id' => $this->patientId,
                'survivor_patient_id' => $this->survivorId,
                'reason' => 'Patient enregistré deux fois à l\'accueil',
            ], $overrides));
    }

    private function preview(?User $as = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->manager)
            ->getJson('/api/v1/patients/'.$this->patientId.'/merge-preview?into='.$this->survivorId);
    }

    public function test_l_apercu_compare_les_identites_et_compte_ce_qui_est_rattache(): void
    {
        $visit = $this->seedActivityVisit('2026-10-01 08:00:00', ['status' => 'COMPLETED', 'closed_at' => self::at('2026-10-01 09:00:00')]);
        $this->seedActivityConsultation($visit, '2026-10-01 08:10:00', 'COMPLETED');
        $this->seedIssuedInvoice(25000, '2026-10-01 09:00:00');

        $response = $this->preview()->assertOk();
        $this->assertMatchesContract($response, 'schemas/resources/patients.json#/$defs/PatientMergePreviewResponse');
        $response->assertJsonPath('data.source.id', $this->patientId)
            ->assertJsonPath('data.survivor.id', $this->survivorId)
            ->assertJsonPath('data.linked.visits', 1)
            ->assertJsonPath('data.linked.consultations', 1)
            ->assertJsonPath('data.linked.invoices', 1)
            ->assertJsonPath('data.can_merge', true)
            ->assertJsonPath('data.blockers', []);
        $this->assertStringNotContainsString(self::CLINICAL_SENTINEL, (string) $response->getContent(), 'aucune valeur clinique dans l\'aperçu');
    }

    public function test_la_fusion_ne_reecrit_rien_et_reunit_l_activite_sur_la_fiche_conservee(): void
    {
        $this->seedActivityVisit('2026-10-01 08:00:00', ['status' => 'COMPLETED', 'closed_at' => self::at('2026-10-01 09:00:00')]);
        [$invoiceId] = $this->seedIssuedInvoice(25000, '2026-10-01 09:00:00');
        $snapshotBefore = DB::table('invoices')->where('id', $invoiceId)->value('patient_snapshot');

        $response = $this->merge()->assertCreated();
        $this->assertMatchesContract($response, 'schemas/resources/patients.json#/$defs/PatientMergeResponse');
        $response->assertJsonPath('data.source_patient_id', $this->patientId)
            ->assertJsonPath('data.survivor_patient_id', $this->survivorId)
            ->assertJsonPath('data.approved_by', $this->manager->id)
            ->assertJsonPath('data.source_version', 2);

        $source = DB::table('patient_records')->where('id', $this->patientId)->first();
        $this->assertNotNull($source);
        $this->assertSame($this->survivorId, $source->merged_into_patient_id);
        $merge = DB::table('patient_merges')->where('source_patient_id', $this->patientId)->first();
        $this->assertNotNull($merge);
        $snapshot = json_decode((string) $merge->snapshot, true);
        $this->assertSame((string) $source->record_number, $snapshot['source']['record_number']);
        $this->assertSame('Patient Fictif', $snapshot['source']['last_name']);

        // Aucune réécriture : la facture garde sa fiche et son snapshot.
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertNotNull($invoice);
        $this->assertSame($this->patientId, $invoice->patient_id);
        $this->assertSame($snapshotBefore, $invoice->patient_snapshot);

        $audit = DB::table('audit_events')->where('action', 'patient.merged')->where('target_id', $this->patientId)->first();
        $this->assertNotNull($audit);
        $this->assertSame('Patient enregistré deux fois à l\'accueil', $audit->reason);

        // La fiche absorbée disparaît de la recherche ; la fiche conservée réunit les deux activités.
        $search = $this->actingAsFresh($this->reception)->getJson('/api/v1/patients?query=Patient%20Fictif')->assertOk();
        $this->assertNotContains($this->patientId, array_column($search->json('data'), 'id'));
        $this->assertContains($this->survivorId, array_column($search->json('data'), 'id'));

        $activity = $this->readActivity($this->manager, '', $this->survivorId)->assertOk();
        $types = array_column($activity->json('data'), 'type');
        $this->assertContains('VISIT', $types);
        $this->assertContains('INVOICE', $types);

        $this->actingAsFresh($this->reception)->getJson('/api/v1/patients/'.$this->survivorId)->assertOk()
            ->assertJsonPath('data.merged_records.0.patient_id', $this->patientId)
            ->assertJsonPath('data.merged_records.0.record_number', (string) $source->record_number);
    }

    public function test_les_gardes_fous_refusent_la_fusion_d_une_fiche_en_cours(): void
    {
        $this->seedActivityVisit('2026-10-22 08:00:00', ['status' => 'ARRIVED']);
        $this->seedDraftInvoice(10000, '2026-10-22 08:30:00');

        $this->preview()->assertOk()
            ->assertJsonPath('data.can_merge', false)
            ->assertJsonPath('data.blockers.0.code', 'VISIT_IN_PROGRESS')
            ->assertJsonPath('data.blockers.1.code', 'INVOICE_DRAFT');

        $this->merge()->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED')
            ->assertJsonPath('error.details.current_status', 'VISIT_IN_PROGRESS');
        $this->assertNull(DB::table('patient_records')->where('id', $this->patientId)->value('merged_into_patient_id'));
        $this->assertSame(0, DB::table('patient_merges')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_droits_reauthentification_version_et_motif(): void
    {
        $this->preview($this->reception)->assertForbidden();
        $this->merge([], $this->reception)->assertForbidden();
        $this->merge([], null, false)->assertStatus(423);
        $this->merge(['expected_version' => 7])->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT')
            ->assertJsonPath('error.details.current_version', 1);
        $this->merge(['reason' => 'non'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $this->merge(['survivor_patient_id' => $this->patientId])->assertStatus(422);
        $this->merge(['source_patient_id' => $this->survivorId])->assertStatus(422);

        $otherClinic = $this->makeClinic();
        $this->makeStaff($otherClinic, 'MANAGER'); // créateur de la fiche étrangère (`created_by` obligatoire)
        $stranger = $this->seedPatient($otherClinic, 'Autre Cabinet', 'Fictif');
        $this->merge(['survivor_patient_id' => $stranger])->assertNotFound();

        $this->assertSame(0, DB::table('patient_merges')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_le_rejeu_de_la_meme_cle_rend_la_meme_fusion_et_une_fiche_fusionnee_ne_se_refusionne_pas(): void
    {
        $key = Ulid::generate();
        $first = $this->merge([], null, true, $key)->assertCreated();
        $replay = $this->merge([], null, true, $key)->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, DB::table('patient_merges')->where('clinic_id', $this->clinicId)->count());

        $this->merge(['expected_version' => 2])->assertStatus(409)
            ->assertJsonPath('error.details.current_status', 'SOURCE_ALREADY_MERGED');
    }
}
