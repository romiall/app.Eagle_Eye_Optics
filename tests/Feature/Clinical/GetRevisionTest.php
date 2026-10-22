<?php

namespace Tests\Feature\Clinical;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Lecture d'une révision figée avec son snapshot (C03, AT19, AT42) : lecture seule, droits cliniques, isolation des
 * cabinets, trace de lecture sans contenu (au plus une par compte et par révision et par intervalle), débit limité.
 * Données fictives.
 */
final class GetRevisionTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $consultationId;

    private string $firstRevisionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [, $this->consultationId] = $this->completableExam();
        $body = $this->completeBody($this->consultationId);
        $this->completeConsultation($this->consultationId, $body)->assertOk();
        $this->firstRevisionId = (string) $body['revision_id'];
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    private function readRevision(int|string $revisionNo, ?string $consultationId = null, ?User $as = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->clinician)->getJson('/api/v1/consultations/'.($consultationId ?? $this->consultationId).'/revisions/'.$revisionNo);
    }

    private function viewedCount(?string $actorId = null): int
    {
        $query = DB::table('audit_events')->where('action', 'consultation.revision_viewed')->where('target_id', $this->firstRevisionId);
        if ($actorId !== null) {
            $query->where('actor_id', $actorId);
        }

        return $query->count();
    }

    public function test_la_revision_est_rendue_avec_son_snapshot_conforme_au_contrat(): void
    {
        $response = $this->readRevision(1)->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/ClinicalRevisionResponse');
        $response->assertJsonPath('data.id', $this->firstRevisionId)
            ->assertJsonPath('data.revision_no', 1)
            ->assertJsonPath('data.previous_revision_id', null)
            ->assertJsonPath('data.author_id', $this->clinician->id)
            ->assertJsonPath('data.snapshot.consultation_id', $this->consultationId)
            ->assertJsonPath('data.snapshot.steps.CONCLUSION.conclusion', 'Conclusion fictive');
        $this->assertSame(DB::table('clinical_revisions')->where('id', $this->firstRevisionId)->value('sha256'), $response->json('data.sha256'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_une_revision_corrigee_est_lisible_a_cote_de_la_precedente(): void
    {
        $this->amendConsultation($this->consultationId, $this->amendBody($this->consultationId, ['CONCLUSION' => $this->conclusion(['conclusion' => 'Corrigée', 'referral' => false])]))->assertCreated();

        $second = $this->readRevision(2)->assertOk();
        $first = $this->readRevision(1)->assertOk();

        $this->assertMatchesContract($second, 'schemas/resources/clinical.json#/$defs/ClinicalRevisionResponse');
        $second->assertJsonPath('data.snapshot.steps.CONCLUSION.conclusion', 'Corrigée')->assertJsonPath('data.previous_revision_id', $this->firstRevisionId);
        $first->assertJsonPath('data.snapshot.steps.CONCLUSION.conclusion', 'Conclusion fictive');
    }

    public function test_une_revision_inconnue_ou_un_numero_invalide_repondent_introuvable(): void
    {
        $this->readRevision(2)->assertNotFound();
        $this->readRevision(0)->assertNotFound();
        $this->readRevision('abc')->assertNotFound();
        $this->readRevision(1, Ulid::generate())->assertNotFound();
        $this->assertSame(0, $this->viewedCount());
    }

    public function test_une_revision_d_un_autre_cabinet_repond_introuvable(): void
    {
        $foreign = $this->makeStaff($this->makeClinic(), 'CLINICIAN', 'autre.pro.revision');

        $this->readRevision(1, null, $foreign)->assertNotFound();

        $this->assertSame(0, $this->viewedCount($foreign->id));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roles(): array
    {
        return ['gérante' => ['MANAGER', 200], 'professionnel' => ['CLINICIAN', 200], 'accueil' => ['RECEPTION', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('roles')]
    public function test_l_accueil_et_la_caisse_n_ont_aucun_acces_aux_revisions(string $role, int $status): void
    {
        $user = $this->makeStaff($this->clinicId, $role);

        $response = $this->readRevision(1, null, $user);

        $response->assertStatus($status);
        if ($status === 403) {
            $this->assertStringNotContainsString('Conclusion fictive', (string) $response->getContent());
            $this->assertSame(0, $this->viewedCount($user->id), 'Un refus n\'est pas une lecture.');
        }
    }

    public function test_module_ferme_404_et_sans_connexion_401(): void
    {
        $this->flushSession();
        $this->nextRequest()->getJson('/api/v1/consultations/'.$this->consultationId.'/revisions/1')->assertStatus(401);

        config(['eeo.enabled_modules' => ['today', 'patients']]);
        $this->readRevision(1, null, $this->manager)->assertNotFound();
    }

    public function test_la_lecture_est_tracee_sans_contenu_et_au_plus_une_fois_par_compte_et_par_intervalle(): void
    {
        $this->readRevision(1)->assertOk();
        $this->readRevision(1)->assertOk();
        $this->assertSame(1, $this->viewedCount($this->clinician->id));

        $this->readRevision(1, null, $this->manager)->assertOk();
        $this->assertSame(1, $this->viewedCount($this->manager->id));

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(11));
        $this->readRevision(1)->assertOk();
        $this->assertSame(2, $this->viewedCount($this->clinician->id));

        $event = DB::table('audit_events')->where('action', 'consultation.revision_viewed')->where('target_id', $this->firstRevisionId)->first();
        $this->assertNotNull($event);
        $this->assertSame('clinical_revisions', $event->target_type);
        $this->assertNull($event->sanitized_diff);
        $this->assertStringNotContainsString('Conclusion fictive', $this->sideEffectsDump());
    }

    public function test_la_lecture_ne_modifie_rien_et_est_limitee_en_debit_par_compte(): void
    {
        $before = [DB::table('clinical_revisions')->get()->all(), DB::table('sync_change_log')->count(), DB::table('outbox_events')->count()];
        config(['eeo.rate_limits.consultation_read' => 2]);
        // Un compte neuf : les lectures de la mise en place n'entrent pas dans son compteur.
        $fresh = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.debit');

        $this->readRevision(1, null, $fresh)->assertOk();
        $this->readRevision(1, null, $fresh)->assertOk();
        $this->readRevision(1, null, $fresh)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->readRevision(1, null, $this->manager)->assertOk();

        $this->assertEquals($before[0], DB::table('clinical_revisions')->get()->all());
        $this->assertSame($before[1], DB::table('sync_change_log')->count());
        $this->assertSame($before[2], DB::table('outbox_events')->count());
    }
}
