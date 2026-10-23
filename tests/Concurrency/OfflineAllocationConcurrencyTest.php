<?php

namespace Tests\Concurrency;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Concurrency\Support\ScenarioHarness;
use Tests\Concurrency\Support\ScenarioProcess;
use Tests\Support\BillingFixtures;
use Tests\Support\OfflineResourceFixtures;
use Tests\Support\ScratchMysqlDatabases;
use Tests\TestCase;

/**
 * Allocation concurrente des plages de numéros (O03 ; fiche O03 « Validation : concurrence allocation » ; AT09, AT43)
 * entre CONNEXIONS MYSQL RÉELLES : deux postes préparent chacun une plage de dossiers patients pendant qu'une création
 * de patient centrale prend un numéro, les trois suspendus sur le verrou du cabinet puis relâchés ensemble. Les plages
 * sont disjointes, le numéro central est hors des deux plages, la séquence centrale est à la fin de tout ce qui est
 * réservé ou consommé. Base jetable migrée une fois pour la classe ; données fictives.
 */
final class OfflineAllocationConcurrencyTest extends TestCase
{
    use BillingFixtures;
    use OfflineResourceFixtures;
    use ScenarioHarness;
    use ScratchMysqlDatabases;

    private const CONNECTION = 'concurrency_offline';

    private const DATABASE = 'eeo_conc_offline_test';

    private const SCRIPT = 'command-scenario.php';

    private const WAIT = 300.0;

    private static bool $migrated = false;

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

        config(['eeo.enabled_modules' => ['patients', 'offline']]);
        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->reception = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
    }

    protected function tearDown(): void
    {
        $this->releaseScenarioResources();

        parent::tearDown();
    }

    private function preparing(string $device, string $key): ScenarioProcess
    {
        return $this->child('command', [
            'actor_id' => $this->manager->id,
            'name' => 'PrepareDevice',
            'entity_id' => $device,
            'key' => $key,
            'payload' => [
                'profiles' => [$this->reception->id],
                'number_ranges' => [['document_type' => 'PATIENT', 'quantity' => 100]],
            ],
        ]);
    }

    private function centralPatient(User $actor, string $key): ScenarioProcess
    {
        return $this->child('create-patient', [
            'actor_id' => $actor->id,
            'key' => $key,
            'payload' => [
                'expected_version' => null,
                'patient_id' => Ulid::generate(),
                'record_number_usage_id' => Ulid::generate(),
                'record_number' => null,
                'last_name' => 'Patient Concurrent',
                'first_names' => null,
                'sex' => 'UNKNOWN',
                'birth_date' => '1990-05-12',
                'birth_date_missing_reason' => null,
                'phone_primary' => '600000001',
                'phone_secondary' => null,
                'phone_missing_reason' => null,
                'address' => null,
                'profession' => null,
                'guardian_contact' => null,
                'legacy_record' => false,
                'first_known_visit_on' => null,
                'first_visit_certified' => false,
                'legacy_record_number' => null,
            ],
        ]);
    }

    public function test_deux_postes_et_une_creation_centrale_ne_partagent_jamais_un_numero(): void
    {
        $first = $this->seedDevice('Poste accueil A (fictif)');
        $second = $this->seedDevice('Poste accueil B (fictif)');
        $this->seedGrant($first, $this->reception, '2030-01-01 00:00:00.000000');
        $this->seedGrant($second, $this->reception, '2030-01-01 00:00:00.000000');

        $this->holdClinicLock($this->clinicId);
        $children = [
            $this->preparing($first, 'cle-preparation-a-0001'),
            $this->centralPatient($this->reception, 'cle-patient-central-01'),
            $this->preparing($second, 'cle-preparation-b-0001'),
        ];
        $this->waitForBlockedQuery('`clinics`', 'for update', 3);
        $this->releaseGate();
        $outcomes = array_map(static fn (ScenarioProcess $child): array => $child->wait(self::WAIT), $children);

        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome['ok'], json_encode($outcome, JSON_UNESCAPED_UNICODE) ?: 'échec');
        }
        $year = (int) DB::table('document_number_allocations')->where('clinic_id', $this->clinicId)->value('document_year');
        $ranges = $this->rangesOf('PATIENT', $year);
        $this->assertCount(2, $ranges);
        $this->assertLessThan($ranges[1]['start'], $ranges[0]['end'], 'plages disjointes');

        $central = (int) DB::table('document_number_usages')->where('clinic_id', $this->clinicId)->where('document_type', 'PATIENT')->value('number_value');
        foreach ($ranges as $range) {
            $this->assertFalse($central >= $range['start'] && $central <= $range['end'], 'le numéro central est hors de toute plage');
        }
        $highest = max($central, $ranges[0]['end'], $ranges[1]['end']);
        $this->assertSame($highest, (int) DB::table('document_sequences')->where('clinic_id', $this->clinicId)->where('document_type', 'PATIENT')->value('last_value'));
        $this->assertSame(201, $highest, 'deux plages de 100 et un numéro central, sans trou');
    }
}
