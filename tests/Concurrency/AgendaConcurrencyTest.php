<?php

namespace Tests\Concurrency;

use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Concurrency\Support\ScenarioHarness;
use Tests\Concurrency\Support\ScenarioProcess;
use Tests\Support\AgendaFixtures;
use Tests\Support\ScratchMysqlDatabases;
use Tests\TestCase;

/**
 * Réservations concurrentes de l'agenda (S01, AT23 ; fiche S01 « Validation : deux réservations du même créneau »)
 * entre CONNEXIONS MYSQL RÉELLES : deux processus PHP prennent le même créneau en même temps ; le test tient la ligne du
 * cabinet, attend que les deux soient suspendus sur le même verrou, puis la relâche. Un seul rendez-vous est créé, le
 * second reçoit 409 `SLOT_UNAVAILABLE`. Même clé (double clic) : un seul rendez-vous, la seconde réponse est le rejeu.
 * Base jetable migrée une fois ; l'horloge n'est PAS figée (les enfants lisent l'heure réelle) : le créneau est demain
 * à 10:00 heure du cabinet, la ressource est ouverte tous les jours. Données fictives.
 */
final class AgendaConcurrencyTest extends TestCase
{
    use AgendaFixtures;
    use ScenarioHarness;
    use ScratchMysqlDatabases;

    private const CONNECTION = 'concurrency_agenda';

    private const DATABASE = 'eeo_conc_agenda_test';

    private const SCRIPT = 'command-scenario.php';

    private const WAIT = 300.0;

    private static bool $migrated = false;

    private static bool $warmedUp = false;

    public static function tearDownAfterClass(): void
    {
        if (self::$migrated) {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s', getenv('EEO_TEST_DB_HOST') ?: '127.0.0.1', getenv('EEO_TEST_DB_PORT') ?: '3306'),
                getenv('EEO_TEST_DB_USERNAME') ?: 'eeo_test',
                getenv('EEO_TEST_DB_PASSWORD') ?: 'eeo_test_password',
            );
            $pdo->exec('DROP DATABASE IF EXISTS `'.self::DATABASE.'`');
            self::$migrated = false;
            self::$warmedUp = false;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$migrated) {
            config(['database.connections.'.self::CONNECTION => [...(array) config('database.connections.mysql'), 'database' => self::DATABASE]]);
            DB::purge(self::CONNECTION);
        } else {
            $this->createScratchDatabase(self::CONNECTION, self::DATABASE);
            $this->scratchDatabases = [];
            $status = Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
            $this->assertSame(0, $status, Artisan::output());
            self::$migrated = true;
        }
        DB::setDefaultConnection(self::CONNECTION);
        if (! self::$warmedUp) {
            $this->spawn('pull', ['clinic_id' => Ulid::generate(), 'after' => 0])->wait(self::WAIT);
            self::$warmedUp = true;
        }

        config(['eeo.enabled_modules' => ['agenda', 'patients']]);
        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->reception = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->patientId = $this->seedPatient($this->clinicId);
        $this->resourceId = $this->seedResource(null, array_map(static fn (int $day): array => [$day, '00:00', '23:00'], [1, 2, 3, 4, 5, 6, 7]));
        $this->serviceId = $this->seedService(30);
    }

    protected function tearDown(): void
    {
        $this->releaseScenarioResources();
        parent::tearDown();
    }

    /**
     * @return array{0: string, 1: string} début et fin du créneau de demain 10:00–10:30 heure du cabinet (UTC ISO)
     */
    private function tomorrowSlot(): array
    {
        $start = CarbonImmutable::now('Africa/Douala')->addDay()->setTime(10, 0)->utc();

        return [$start->format('Y-m-d\TH:i:s.v\Z'), $start->addMinutes(30)->format('Y-m-d\TH:i:s.v\Z')];
    }

    private function booking(string $patientId, string $key, ?string $appointmentId = null): ScenarioProcess
    {
        [$start, $end] = $this->tomorrowSlot();
        $id = $appointmentId ?? Ulid::generate();

        return $this->child('command', [
            'actor_id' => $this->reception->id,
            'name' => 'CreateAppointment',
            'entity_id' => $id,
            'key' => $key,
            'payload' => [
                'appointment_id' => $id, 'patient_id' => $patientId, 'prospect_contact_id' => null, 'follow_up_id' => null,
                'resource_id' => $this->resourceId, 'service_id' => $this->serviceId, 'starts_at' => $start, 'ends_at' => $end,
                'administrative_reason' => null,
            ],
        ]);
    }

    /**
     * @param  list<ScenarioProcess>  $children
     * @return list<string>
     */
    private function codes(array $children): array
    {
        $codes = array_map(static function (ScenarioProcess $child): string {
            $outcome = $child->wait(self::WAIT);

            return $outcome['ok'] ? 'OK' : (string) $outcome['code'];
        }, $children);
        sort($codes);

        return $codes;
    }

    public function test_deux_reservations_simultanees_du_meme_creneau_n_en_laissent_passer_qu_une(): void
    {
        $second = $this->seedPatient($this->clinicId, 'Second Fictif');
        $this->holdClinicLock($this->clinicId);
        $children = [
            $this->booking($this->patientId, 'cle-rdv-concurrent-01'),
            $this->booking($second, 'cle-rdv-concurrent-02'),
        ];
        $this->waitForBlockedQuery('`clinics`', 'for update', 2);
        $this->releaseGate();

        $this->assertSame(['OK', 'SLOT_UNAVAILABLE'], $this->codes($children));
        $this->assertSame(1, DB::table('appointments')->where('clinic_id', $this->clinicId)->count());
        $this->assertSame(1, DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'appointment.created')->count());
    }

    public function test_un_double_clic_avec_la_meme_cle_ne_cree_qu_un_rendez_vous(): void
    {
        $id = Ulid::generate();
        $this->holdClinicLock($this->clinicId);
        $children = [
            $this->booking($this->patientId, 'cle-rdv-double-clic', $id),
            $this->booking($this->patientId, 'cle-rdv-double-clic', $id),
        ];
        $this->waitForBlockedQuery('`clinics`', 'for update', 2);
        $this->releaseGate();

        $this->assertSame(['OK', 'OK'], $this->codes($children));
        $this->assertSame(1, DB::table('appointments')->where('clinic_id', $this->clinicId)->count());
    }
}
