<?php

namespace Tests\Feature\Operations;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\MigratedDatabase;
use Tests\Support\PatientActivityFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Exports contrôlés (X02) sur MySQL réel : demande réauthentifiée et droits par type (dossier d'un patient avec
 * clinical.export, autres types avec export.global), production par la tâche planifiée `eeo:exports:process`
 * (archive ZIP privée : un CSV par table, dictionnaire, manifeste avec empreintes, aucun secret ni autre patient),
 * téléchargement privé tracé, expiration après 48 h. Données fictives.
 */
final class ExportsTest extends TestCase
{
    use MigratedDatabase;
    use PatientActivityFixtures;

    /** @var list<string> */
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée AVANT la création des comptes (voir PatientActivityFixtures).
        $this->bootActivity(['today', 'patients', 'consultations', 'sales', 'settings']);
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $path) {
            @unlink($path);
        }
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function requestExport(array $body, ?User $as = null, bool $reauthenticated = true): TestResponse
    {
        $user = $as ?? $this->manager;
        $client = $reauthenticated ? $this->actingAsReauthenticated($user) : $this->actingAsFresh($user);

        return $client->withHeaders(['Idempotency-Key' => Ulid::generate()])->postJson('/api/v1/exports', $body);
    }

    /** Produit les exports demandés et rend le chemin de l'archive de l'export. */
    private function produce(string $exportId): string
    {
        $this->assertSame(0, Artisan::call('eeo:exports:process'));
        $file = DB::table('stored_files')->where('id', DB::table('export_requests')->where('id', $exportId)->value('file_id'))->first();
        $this->assertNotNull($file);
        $path = storage_path('app/private/'.$file->private_path);
        $this->archives[] = $path;

        return $path;
    }

    public function test_le_dossier_d_un_patient_ne_contient_que_ce_patient_avec_dictionnaire_et_manifeste(): void
    {
        $visit = $this->seedActivityVisit('2026-10-01 08:00:00', ['status' => 'COMPLETED']);
        $this->seedActivityConsultation($visit, '2026-10-01 08:10:00', 'COMPLETED');
        $other = $this->seedPatient($this->clinicId, 'Autre Fictif', 'Test');
        $this->seedActivityVisit('2026-10-02 08:00:00', [], null, $other);

        $response = $this->requestExport(['export_type' => 'PATIENT_RECORD', 'patient_id' => $this->patientId], $this->clinician)->assertCreated();
        $this->assertMatchesContract($response, 'schemas/resources/operations.json#/$defs/ExportRequestResponse');
        $response->assertJsonPath('data.status', 'REQUESTED');
        $id = (string) $response->json('data.id');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->produce($id)) === true);
        $this->assertNotFalse($zip->locateName('dictionnaire.csv'));
        $this->assertNotFalse($zip->locateName('LISEZMOI.txt'));
        $manifest = json_decode((string) $zip->getFromName('manifeste.json'), true);
        $this->assertSame('PATIENT_RECORD', $manifest['export_type']);
        $visits = (string) $zip->getFromName('donnees/visits.csv');
        $this->assertStringContainsString($visit, $visits);
        $this->assertStringNotContainsString($other, $visits, 'aucun autre patient dans le dossier');
        $this->assertStringContainsString(self::CLINICAL_SENTINEL, (string) $zip->getFromName('donnees/consultations.csv'));
        foreach ($manifest['tables'] as $entry) {
            $this->assertSame($entry['sha256'], hash('sha256', (string) $zip->getFromName($entry['file'])), $entry['file']);
        }
        $this->assertFalse($zip->locateName('donnees/users.csv'), 'le dossier d\'un patient n\'exporte pas les comptes');
        $zip->close();

        $this->assertSame('READY', DB::table('export_requests')->where('id', $id)->value('status'));
    }

    public function test_une_remise_complete_n_exporte_jamais_les_secrets(): void
    {
        $id = (string) $this->requestExport(['export_type' => 'FULL_HANDOVER'])->assertCreated()->json('data.id');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->produce($id)) === true);
        $users = (string) $zip->getFromName('donnees/users.csv');
        $this->assertNotSame('', $users);
        $this->assertStringNotContainsString('password_hash', $users);
        $this->assertStringNotContainsString('$2y$', $users, 'aucun mot de passe haché');
        $this->assertFalse($zip->locateName('donnees/idempotency_requests.csv'));
        $zip->close();
    }

    public function test_les_droits_par_type_et_la_reauthentification_sont_exiges(): void
    {
        $this->requestExport(['export_type' => 'FINANCE'], $this->clinician)->assertForbidden();
        $this->requestExport(['export_type' => 'PATIENTS'], $this->reception)->assertForbidden();
        $this->requestExport(['export_type' => 'PATIENTS'], null, false)->assertStatus(423);
        $this->requestExport(['export_type' => 'PATIENT_RECORD'], $this->clinician)->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['patient_id']]]);
        $this->requestExport(['export_type' => 'INCONNU'])->assertStatus(422);
        $this->assertSame(0, DB::table('export_requests')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_le_telechargement_est_prive_trace_et_expire_apres_48_heures(): void
    {
        $id = (string) $this->requestExport(['export_type' => 'PATIENTS'])->assertCreated()->json('data.id');
        $this->actingAsFresh($this->manager)->get('/api/v1/exports/'.$id.'/download', ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('error.details.current_status', 'REQUESTED');

        $this->produce($id);
        $download = $this->actingAsFresh($this->manager)->get('/api/v1/exports/'.$id.'/download');
        $download->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'export.downloaded')->where('target_id', $id)->count());

        // Un autre compte sans export.global ne voit pas l'export de la gérante.
        $this->actingAsFresh($this->clinician)->get('/api/v1/exports/'.$id.'/download', ['Accept' => 'application/json'])->assertNotFound();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 10:00:00', 'UTC'));
        $this->assertSame(0, Artisan::call('eeo:exports:process'));
        $this->assertSame('EXPIRED', DB::table('export_requests')->where('id', $id)->value('status'));
        $this->actingAsFresh($this->manager)->get('/api/v1/exports/'.$id.'/download', ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('error.details.current_status', 'EXPIRED');
    }

    public function test_la_liste_montre_tout_a_la_gerante_et_ses_seuls_exports_a_un_autre_compte(): void
    {
        $this->requestExport(['export_type' => 'PATIENTS'])->assertCreated();
        $this->requestExport(['export_type' => 'PATIENT_RECORD', 'patient_id' => $this->patientId], $this->clinician)->assertCreated();

        $this->actingAsFresh($this->manager)->getJson('/api/v1/exports')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsFresh($this->clinician)->getJson('/api/v1/exports')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.export_type', 'PATIENT_RECORD');
    }
}
