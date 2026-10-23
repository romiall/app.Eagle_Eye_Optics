<?php

namespace Tests\Concurrency;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Concurrency\Support\ScenarioHarness;
use Tests\Concurrency\Support\ScenarioProcess;
use Tests\Support\BillingFixtures;
use Tests\Support\OfflineResourceFixtures;
use Tests\Support\ScratchMysqlDatabases;
use Tests\Support\SyncFixtures;
use Tests\TestCase;

/**
 * Synchronisation concurrente (O05 ; fiche « Validation : crash après commit avant ACK ; pull avec commits
 * entrelacés » ; AT38, AT42) entre CONNEXIONS MYSQL RÉELLES, enfants suspendus sur le verrou du cabinet puis relâchés
 * ensemble :
 *
 *  - le même lot envoyé deux fois en même temps (délai dépassé côté poste, renvoi) n'applique chaque commande qu'UNE
 *    fois : un seul patient par commande, préfixe à la fin du lot, l'autre envoi rend l'ACK existant ;
 *  - deux postes qui synchronisent en même temps gardent chacun les numéros de SA plage, sans jamais en partager.
 *
 * Base jetable migrée une fois pour la classe ; horloge réelle (les enfants ne partagent pas l'horloge figée) : les
 * instants des commandes sont dans le passé. Données fictives.
 */
final class SyncConcurrencyTest extends TestCase
{
    use BillingFixtures;
    use OfflineResourceFixtures;
    use ScenarioHarness;
    use ScratchMysqlDatabases;
    use SyncFixtures;

    private const CONNECTION = 'concurrency_sync';

    private const DATABASE = 'eeo_conc_sync_test';

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

    /**
     * @param  list<array{core: array<string, mixed>, event_hash: string, signature: string}>  $commands
     */
    private function pushing(string $deviceId, array $commands): ScenarioProcess
    {
        return $this->child('sync-push', [
            'actor_id' => $this->reception->id,
            'body' => json_encode(['device_id' => $deviceId, 'commands' => $commands], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return list<array{core: array<string, mixed>, event_hash: string, signature: string}>
     */
    private function patients(string $grantId, string $rangeId, int $first, int $count): array
    {
        $commands = [];
        for ($index = 0; $index < $count; $index++) {
            $payload = $this->patientPayload(sprintf('EE-2026-%04d', $first + $index), ['last_name' => 'PATIENT CONCURRENT '.($index + 1)]);
            $commands[] = $this->signed('CreatePatient', $payload, 'patient_records', (string) $payload['patient_id'], $this->reception, $grantId, null, $rangeId);
        }

        return $commands;
    }

    public function test_le_meme_lot_envoye_deux_fois_en_meme_temps_n_applique_chaque_commande_qu_une_fois(): void
    {
        $device = $this->signingDevice('Poste accueil (fictif)');
        $grant = $this->seedGrant($device, $this->reception, '2030-01-01 00:00:00.000000');
        $range = $this->seedRange($grant, 'PATIENT', 101, 150);
        $commands = $this->patients($grant, $range, 101, 3);

        $this->holdClinicLock($this->clinicId);
        $children = [$this->pushing($device, $commands), $this->pushing($device, $commands)];
        $this->waitForBlockedQuery('`clinics`', 'for update', 2);
        $this->releaseGate();
        $outcomes = array_map(static fn (ScenarioProcess $child): array => $child->wait(self::WAIT), $children);

        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome['ok'], json_encode($outcome, JSON_UNESCAPED_UNICODE) ?: 'échec');
            $this->assertSame('3', $outcome['result']['prefix']['last_sequence']);
        }
        $this->assertSame(3, DB::table('patient_records')->where('clinic_id', $this->clinicId)->count(), 'un seul fait par commande');
        $this->assertSame(3, DB::table('sync_commands')->where('device_id', $device)->count());
        // La base jetable est partagée par les tests de la classe : seuls les ACK de ce cabinet comptent.
        $this->assertSame(3, DB::table('sync_command_results')->where('clinic_id', $this->clinicId)->where('status', 'ACCEPTED')->count());
        $this->assertSame(['EE-2026-0101', 'EE-2026-0102', 'EE-2026-0103'], DB::table('patient_records')->where('clinic_id', $this->clinicId)->orderBy('record_number')->pluck('record_number')->all());
    }

    public function test_deux_postes_synchronisent_en_meme_temps_sans_partager_un_numero(): void
    {
        $first = $this->signingDevice('Poste accueil A (fictif)');
        $grantA = $this->seedGrant($first, $this->reception, '2030-01-01 00:00:00.000000');
        $commandsA = $this->patients($grantA, $this->seedRange($grantA, 'PATIENT', 101, 150), 101, 2);

        $second = $this->signingDevice('Poste accueil B (fictif)');
        $grantB = $this->seedGrant($second, $this->reception, '2030-01-01 00:00:00.000000');
        $commandsB = $this->patients($grantB, $this->seedRange($grantB, 'PATIENT', 151, 200), 151, 2);

        $this->holdClinicLock($this->clinicId);
        $children = [$this->pushing($first, $commandsA), $this->pushing($second, $commandsB)];
        $this->waitForBlockedQuery('`clinics`', 'for update', 2);
        $this->releaseGate();
        $outcomes = array_map(static fn (ScenarioProcess $child): array => $child->wait(self::WAIT), $children);

        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome['ok'], json_encode($outcome, JSON_UNESCAPED_UNICODE) ?: 'échec');
            $this->assertSame(['ACCEPTED', 'ACCEPTED'], $outcome['result']['statuses']);
        }
        $numbers = DB::table('patient_records')->where('clinic_id', $this->clinicId)->orderBy('record_number')->pluck('record_number')->all();
        $this->assertSame(['EE-2026-0101', 'EE-2026-0102', 'EE-2026-0151', 'EE-2026-0152'], $numbers);
        $this->assertSame(2, (int) DB::table('enrolled_devices')->where('id', $first)->value('last_accepted_sequence'));
        $this->assertSame(2, (int) DB::table('enrolled_devices')->where('id', $second)->value('last_accepted_sequence'));
        $this->assertSame(0, DB::table('document_sequences')->where('clinic_id', $this->clinicId)->count(), 'aucun numéro central');
    }
}
