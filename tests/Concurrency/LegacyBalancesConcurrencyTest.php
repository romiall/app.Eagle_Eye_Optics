<?php

namespace Tests\Concurrency;

use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Concurrency\Support\ScenarioHarness;
use Tests\Concurrency\Support\ScenarioProcess;
use Tests\Support\BillingFixtures;
use Tests\Support\ScratchMysqlDatabases;
use Tests\TestCase;

/**
 * Reprise des créances papier (X01) sous concurrence réelle (deux processus PHP, deux connexions MySQL) : deux
 * reprises simultanées de la MÊME référence papier avec des clés différentes ne créent qu'une pièce, la seconde est
 * refusée lisiblement (422, aucune erreur SQL), et aucun numéro n'est brûlé. Base jetable dédiée. Données fictives.
 */
final class LegacyBalancesConcurrencyTest extends TestCase
{
    use BillingFixtures;
    use ScenarioHarness;
    use ScratchMysqlDatabases;

    private const CONNECTION = 'concurrency_legacy';

    private const DATABASE = 'eeo_conc_legacy_test';

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

        config(['eeo.enabled_modules' => ['sales', 'stock']]);
        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->patientId = $this->seedPatient($this->clinicId);
    }

    protected function tearDown(): void
    {
        $this->releaseScenarioResources();

        parent::tearDown();
    }

    /** Justificatif déjà rattaché au cabinet (le fichier lui-même n'est pas lu par la reprise). */
    private function evidence(): string
    {
        $id = Ulid::generate();
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('stored_files')->insert([
            'id' => $id, 'clinic_id' => $this->clinicId, 'private_path' => 'evidence/'.$this->clinicId.'/'.hash('sha256', $id).'.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1000, 'sha256' => hash('sha256', $id), 'classification' => 'FINANCIAL',
            'encryption_key_id' => null, 'uploaded_by' => $this->manager->id, 'archived_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    private function reprise(string $evidence, string $reference, string $key): ScenarioProcess
    {
        return $this->child('command', [
            'actor_id' => $this->manager->id,
            'name' => 'ImportLegacyBalances',
            'entity_id' => null,
            'key' => $key,
            'payload' => [
                'dry_run' => false,
                'evidence_file_id' => $evidence,
                'rows' => [[
                    'line' => 2, 'patient_id' => $this->patientId, 'patient_record_number' => null, 'paper_reference' => $reference,
                    'original_total' => 80000, 'already_paid' => 30000, 'original_invoice_on' => null,
                ]],
            ],
        ]);
    }

    public function test_deux_reprises_simultanees_de_la_meme_reference_ne_creent_qu_une_piece(): void
    {
        $evidence = $this->evidence();
        $this->holdClinicLock($this->clinicId);
        $children = [
            $this->reprise($evidence, 'CARNET-SIMULTANE', 'cle-reprise-poste01'),
            $this->reprise($evidence, 'carnet-simultané', 'cle-reprise-poste02'),
        ];
        $this->waitForBlockedQuery('`clinics`', 'for update', 2);

        $this->releaseGate();
        $outcomes = array_map(static fn (ScenarioProcess $child): array => $child->wait(self::WAIT), $children);
        $codes = array_map(static fn (array $outcome): string => $outcome['ok'] ? 'OK' : (string) $outcome['code'], $outcomes);
        sort($codes);

        $this->assertSame(['OK', 'VALIDATION_FAILED'], $codes, 'la seconde reprise voit la référence prise : refus lisible, jamais une erreur SQL');
        $this->assertSame(1, DB::table('legacy_balance_imports')->where('clinic_id', $this->clinicId)->count());
        $this->assertSame(1, DB::table('invoices')->where('clinic_id', $this->clinicId)->where('origin', 'LEGACY_BALANCE')->count());
        $this->assertSame(1, DB::table('document_number_usages')->where('clinic_id', $this->clinicId)->where('document_type', 'LEGACY_BALANCE')->count(), 'aucun numéro brûlé par le refus');
        $this->assertSame(1, (int) DB::table('document_sequences')->where('clinic_id', $this->clinicId)->where('document_type', 'LEGACY_BALANCE')->value('last_value'));
    }
}
