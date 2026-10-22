<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\ConsultationReader;
use App\Domain\Shared\BusinessError;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Instantané cohérent de la lecture d'une consultation (correction C01) : ligne, rubriques, révisions et patient
 * décrivent la MÊME version. Une écriture concurrente survenue entre les requêtes de lecture (simulée ici en
 * incrémentant la version au milieu de la lecture) fait relire l'ensemble ; si elle se répète à chaque tentative, le
 * serveur répond « occupé » (503) plutôt que de rendre un état qui n'a jamais existé. Données fictives.
 */
final class ConsultationReaderSnapshotTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $consultationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [, $this->consultationId] = $this->startedExam();
        $this->saveCurrent($this->consultationId, 'CONCLUSION', $this->conclusion(['conclusion' => 'Avant écriture concurrente']))->assertOk();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * Pendant la lecture (au moment où les révisions sont lues), une « autre transaction » modifie la conclusion et
     * incrémente la version, `$times` fois au plus.
     */
    private function concurrentWriteDuringRead(int $times): void
    {
        $remaining = $times;
        $busy = false;
        DB::listen(function (object $query) use (&$remaining, &$busy): void {
            if ($busy || $remaining <= 0 || ! str_contains((string) $query->sql, 'from `clinical_revisions`')) {
                return;
            }
            $busy = true;
            $remaining--;
            DB::table('consultations')->where('id', $this->consultationId)->update([
                'conclusion' => 'Après écriture concurrente '.$remaining,
                'version' => DB::raw('version + 1'),
            ]);
            $busy = false;
        });
    }

    public function test_une_ecriture_entre_deux_requetes_de_lecture_fait_relire_l_ensemble(): void
    {
        $this->concurrentWriteDuringRead(1);

        $view = $this->app->make(ConsultationReader::class)->view($this->clinicId, $this->consultationId);

        $this->assertNotNull($view);
        $this->assertSame(3, (int) $view->row->version, 'La version rendue est celle de l\'état relu.');
        $this->assertSame('Après écriture concurrente 0', $view->steps['CONCLUSION']['conclusion'], 'Les rubriques décrivent la même version que la ligne.');
    }

    public function test_une_ecriture_a_chaque_tentative_rend_le_serveur_occupe_plutot_qu_un_etat_melange(): void
    {
        $this->concurrentWriteDuringRead(10);

        try {
            $this->app->make(ConsultationReader::class)->view($this->clinicId, $this->consultationId);
            $this->fail('Une lecture jamais cohérente doit être refusée.');
        } catch (BusinessError $error) {
            $this->assertSame('SERVER_BUSY', $error->errorCode);
            $this->assertSame(503, $error->httpStatus);
        }
    }

    public function test_par_la_route_l_occupation_est_un_503_a_renvoyer_a_l_identique(): void
    {
        $this->concurrentWriteDuringRead(10);

        $response = $this->readConsultation($this->consultationId);

        $response->assertStatus(503)->assertJsonPath('error.code', 'SERVER_BUSY');
        $this->assertStringNotContainsString('Après écriture concurrente', (string) $response->getContent());
    }
}
