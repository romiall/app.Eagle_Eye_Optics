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
 * Lecture des prescriptions optiques (C04) : une prescription, la liste des versions d'une consultation ; données
 * cliniques (accueil et caisse : 403), isolation des cabinets (404), trace de lecture sans contenu, débit limité par
 * compte, module fermé = 404. Données fictives.
 */
final class GetPrescriptionTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $consultationId;

    /** @var array<string, mixed> */
    private array $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [, $this->consultationId] = $this->startedExam();
        $this->draft = $this->draftPrescription($this->consultationId, ['notes' => 'Note fictive de prescription']);
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    private function listFor(?string $consultationId = null, ?User $as = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->clinician)->getJson('/api/v1/consultations/'.($consultationId ?? $this->consultationId).'/prescriptions');
    }

    private function traces(string $action, string $targetId, ?string $actorId = null): int
    {
        $query = DB::table('audit_events')->where('action', $action)->where('target_id', $targetId);
        if ($actorId !== null) {
            $query->where('actor_id', $actorId);
        }

        return $query->count();
    }

    public function test_une_prescription_se_relit_conforme_au_contrat(): void
    {
        $response = $this->readPrescription($this->draft['id'])->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/PrescriptionResponse');
        $this->assertSame($this->draft, $response->json('data'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_la_liste_rend_les_versions_de_la_consultation_conforme_au_contrat(): void
    {
        $response = $this->listFor()->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/PrescriptionList');
        $this->assertSame([$this->draft['id']], array_column($response->json('data'), 'id'));

        [, $empty] = $this->secondExam();
        $this->listFor($empty)->assertOk()->assertJsonPath('data', []);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roles(): array
    {
        return ['gérante' => ['MANAGER', 200], 'professionnel' => ['CLINICIAN', 200], 'accueil' => ['RECEPTION', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('roles')]
    public function test_l_accueil_et_la_caisse_n_ont_aucun_acces_aux_prescriptions(string $role, int $status): void
    {
        $user = $this->makeStaff($this->clinicId, $role);

        $single = $this->readPrescription($this->draft['id'], $user);
        $list = $this->listFor(null, $user);

        $single->assertStatus($status);
        $list->assertStatus($status);
        if ($status === 403) {
            $this->assertStringNotContainsString('Note fictive de prescription', (string) $single->getContent().(string) $list->getContent());
            $this->assertSame(0, $this->traces('prescription.viewed', $this->draft['id'], $user->id), 'Un refus n\'est pas une lecture.');
        }
    }

    public function test_une_prescription_ou_une_consultation_inconnue_ou_d_un_autre_cabinet_repond_introuvable(): void
    {
        $foreign = $this->makeStaff($this->makeClinic(), 'CLINICIAN', 'autre.pro.lecture.rx');

        $this->readPrescription(Ulid::generate())->assertNotFound();
        $this->listFor(Ulid::generate())->assertNotFound();
        $this->readPrescription($this->draft['id'], $foreign)->assertNotFound();
        $this->listFor(null, $foreign)->assertNotFound();
        $this->assertSame(0, $this->traces('prescription.viewed', $this->draft['id'], $foreign->id));
    }

    public function test_module_ferme_404_et_sans_connexion_401(): void
    {
        $this->flushSession();
        $this->nextRequest()->getJson('/api/v1/prescriptions/'.$this->draft['id'])->assertStatus(401);

        config(['eeo.enabled_modules' => ['today', 'patients']]);
        $this->readPrescription($this->draft['id'], $this->manager)->assertNotFound();
        $this->listFor(null, $this->manager)->assertNotFound();
    }

    public function test_les_lectures_sont_tracees_sans_contenu_au_plus_une_fois_par_intervalle(): void
    {
        // La création a rendu la prescription au professionnel : c'est déjà une lecture tracée.
        $this->assertSame(1, $this->traces('prescription.viewed', $this->draft['id'], $this->clinician->id));

        $this->readPrescription($this->draft['id'], $this->manager)->assertOk();
        $this->readPrescription($this->draft['id'], $this->manager)->assertOk();
        $this->listFor(null, $this->manager)->assertOk();
        $this->listFor(null, $this->manager)->assertOk();
        $this->assertSame(1, $this->traces('prescription.viewed', $this->draft['id'], $this->manager->id));
        $this->assertSame(1, $this->traces('consultation.prescriptions_viewed', $this->consultationId, $this->manager->id));

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(11));
        $this->readPrescription($this->draft['id'], $this->manager)->assertOk();
        $this->assertSame(2, $this->traces('prescription.viewed', $this->draft['id'], $this->manager->id));

        $event = DB::table('audit_events')->where('action', 'prescription.viewed')->where('target_id', $this->draft['id'])->first();
        $this->assertNotNull($event);
        $this->assertSame('optical_prescriptions', $event->target_type);
        $this->assertNull($event->sanitized_diff);
        $this->assertStringNotContainsString('Note fictive de prescription', $this->sideEffectsDump());
    }

    public function test_la_lecture_est_limitee_en_debit_par_compte(): void
    {
        config(['eeo.rate_limits.consultation_read' => 2]);
        $fresh = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.debit.rx');

        $this->readPrescription($this->draft['id'], $fresh)->assertOk();
        $this->listFor(null, $fresh)->assertOk();
        $this->readPrescription($this->draft['id'], $fresh)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->readPrescription($this->draft['id'], $this->manager)->assertOk();
    }

    /**
     * Second examen démarré (autre patient) : [visite, consultation].
     *
     * @return array{0: string, 1: string}
     */
    private function secondExam(): array
    {
        $patientId = $this->seedPatient($this->clinicId);
        $visitId = $this->seedVisit([], $this->clinicId, $patientId);
        $body = $this->startBody($visitId, ['patient_id' => $patientId]);
        $this->startConsultation($visitId, $body)->assertCreated();

        return [$visitId, (string) $body['consultation_id']];
    }
}
