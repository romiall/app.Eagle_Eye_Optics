<?php

namespace Tests\Concurrency;

use App\Domain\Access\StaffRoles;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ScenarioProcess;
use Tests\Support\AccessFixtures;
use Tests\Support\ScratchMysqlDatabases;
use Tests\TestCase;

/**
 * Entrelacements contrôlés des actions d'accès (fichier 25 : « interleavings reproductibles ») : chaque
 * action s'exécute dans un processus PHP enfant, donc une connexion MySQL distincte, sur une base jetable
 * dont les données sont validées (visibles des enfants). Le test tient une barrière (verrou MySQL dans
 * une transaction ouverte), attend que les enfants soient bloqués sur la requête attendue, puis la relâche.
 *
 * Contrat 23 : ordre des verrous cabinet → compte pour toutes les actions ; droits de l'auteur relus sous
 * le verrou du cabinet ; une seule mise en service quel que soit le niveau d'isolation.
 */
final class AccessLockOrderTest extends TestCase
{
    use AccessFixtures;
    use ScratchMysqlDatabases;

    private const CONNECTION = 'concurrency_access';

    private const DATABASE = 'eeo_conc_access_test';

    /** @var list<ScenarioProcess> */
    private array $children = [];

    private ?ConnectionInterface $gate = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createScratchDatabase(self::CONNECTION, self::DATABASE);
        $status = Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
        $this->assertSame(0, $status, Artisan::output());

        // Les fixtures sont écrites et validées sur la base jetable : les processus enfants les voient.
        DB::setDefaultConnection(self::CONNECTION);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            $child->terminate();
        }
        if ($this->gate !== null && $this->gate->transactionLevel() > 0) {
            $this->gate->rollBack();
        }
        DB::setDefaultConnection('mysql');
        $this->dropScratchDatabases();
        DB::purge(self::CONNECTION);
        DB::purge('concurrency_observer');

        parent::tearDown();
    }

    public function test_la_connexion_verrouille_le_cabinet_avant_le_compte(): void
    {
        $clinicId = $this->makeClinic();
        $user = $this->makeStaff($clinicId, 'RECEPTION', 'accueil.test');
        $this->holdClinicLock($clinicId);

        $login = $this->child('login', ['login' => 'accueil.test', 'password' => self::TEST_PASSWORD]);
        $this->waitForBlockedQuery('`clinics`', 'lock in share mode', 1);
        $this->assertAccountRowIsFree($user->id, 'La connexion ne doit pas tenir la ligne du compte en attendant le cabinet (interblocage).');

        $this->releaseGate();
        $this->assertSame(['ok' => true, 'result' => $user->id], $login->wait());
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('last_login_at'));
    }

    public function test_le_changement_de_mot_de_passe_verrouille_le_cabinet_avant_le_compte(): void
    {
        $clinicId = $this->makeClinic();
        $user = $this->makeStaff($clinicId, 'CLINICIAN', 'pro.test');
        $this->holdClinicLock($clinicId);

        $change = $this->child('change-password', ['user_id' => $user->id, 'input' => [
            'current_password' => self::TEST_PASSWORD, 'password' => 'NouveauSecret2027', 'password_confirmation' => 'NouveauSecret2027',
        ]]);
        $this->waitForBlockedQuery('`clinics`', 'lock in share mode', 1);
        $this->assertAccountRowIsFree($user->id, 'Le changement de mot de passe ne doit pas tenir la ligne du compte en attendant le cabinet.');

        $this->releaseGate();
        $this->assertSame(['ok' => true, 'result' => $user->id], $change->wait());
        $this->assertSame(2, (int) DB::table('users')->where('id', $user->id)->value('version'));
    }

    public function test_deux_gerantes_qui_se_desactivent_mutuellement_laissent_une_gerante_active(): void
    {
        $clinicId = $this->makeClinic();
        $first = $this->makeStaff($clinicId, 'MANAGER', 'gerante.a');
        $second = $this->makeStaff($clinicId, 'MANAGER', 'gerante.b');
        $this->holdClinicLock($clinicId);

        $input = ['expected_version' => 1, 'active' => false, 'reason' => 'Départ (fictif)'];
        $children = [
            $this->child('update-staff', ['actor_id' => $first->id, 'target_id' => $second->id, 'input' => $input]),
            $this->child('update-staff', ['actor_id' => $second->id, 'target_id' => $first->id, 'input' => $input]),
        ];
        $this->waitForBlockedQuery('`clinics`', 'for update', 2);

        $this->releaseGate();
        $codes = array_map(static fn (ScenarioProcess $child): string => ($result = $child->wait())['ok'] ? 'OK' : (string) ($result['code'] ?? '?'), $children);
        sort($codes);

        $this->assertSame(['ACTION_FORBIDDEN', 'OK'], $codes, 'La seconde gérante, désactivée entre-temps, ne peut plus agir.');
        $this->assertSame(1, DB::table('users')->where('clinic_id', $clinicId)->where('active', true)->count());
        $this->assertCount(1, array_filter([$first, $second], fn (User $manager): bool => (bool) DB::table('users')->where('id', $manager->id)->value('active')
            && in_array(StaffRoles::MANAGER, StaffRoles::activeRoles($clinicId, $manager->id), true)));
    }

    public function test_deux_mises_en_service_simultanees_ne_creent_qu_un_cabinet_meme_en_read_committed(): void
    {
        $this->gate = DB::connection(self::CONNECTION);
        $this->gate->beginTransaction();
        $this->gate->table('roles')->where('code', StaffRoles::MANAGER)->lockForUpdate()->first();

        $children = [];
        foreach (['a', 'b'] as $suffix) {
            $children[] = $this->child('setup-clinic', ['isolation' => 'READ COMMITTED', 'input' => [
                'clinic_name' => 'CABINET FICTIF '.strtoupper($suffix), 'manager_name' => 'Gérante '.$suffix,
                'login' => 'gerante.'.$suffix, 'email' => null, 'password' => 'SecretInstall2026',
            ]]);
        }
        $this->waitForBlockedQuery('`roles`', 'for update', 2);

        $this->releaseGate();
        $codes = array_map(static fn (ScenarioProcess $child): string => ($result = $child->wait())['ok'] ? 'OK' : (string) ($result['code'] ?? '?'), $children);
        sort($codes);

        $this->assertSame(['CLINIC_ALREADY_SET_UP', 'OK'], $codes);
        $this->assertSame(1, DB::table('clinics')->count());
        $this->assertSame(1, DB::table('users')->count());
    }

    /**
     * Barrière : verrou exclusif de la ligne du cabinet dans une transaction ouverte, comme une action
     * d'administration en cours.
     */
    private function holdClinicLock(string $clinicId): void
    {
        $this->gate = DB::connection(self::CONNECTION);
        $this->gate->beginTransaction();
        $this->gate->table('clinics')->where('id', $clinicId)->lockForUpdate()->first();
    }

    private function releaseGate(): void
    {
        $this->assertNotNull($this->gate);
        $this->gate->commit();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function child(string $scenario, array $arguments): ScenarioProcess
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $connection */
        $connection = config('database.connections.'.self::CONNECTION);

        return $this->children[] = ScenarioProcess::start($scenario, $arguments, [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'],
            'EEO_CLINIC_ID' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'null',
            'BCRYPT_ROUNDS' => '4',
            'PATH' => (string) getenv('PATH'),
        ]);
    }

    /**
     * Attend que $expected connexions de la base jetable exécutent (donc attendent) la requête qui
     * contient ces fragments ; échoue avec l'état des connexions sinon.
     */
    private function waitForBlockedQuery(string $table, string $lock, int $expected): void
    {
        $observer = $this->observer();
        $deadline = microtime(true) + 20;
        $rows = [];

        do {
            $rows = $observer->select(
                // Requête préparée côté serveur : COMMAND vaut « Execute » ; requête directe : « Query ».
                "SELECT ID, INFO FROM information_schema.PROCESSLIST WHERE DB = ? AND COMMAND IN ('Query', 'Execute') AND ID <> CONNECTION_ID()",
                [self::DATABASE],
            );
            $blocked = array_filter($rows, static fn (object $row): bool => is_string($row->INFO) && str_contains($row->INFO, $table) && str_contains(strtolower($row->INFO), $lock));
            if (count($blocked) >= $expected) {
                return;
            }
            foreach ($this->children as $child) {
                if (! $child->isRunning()) {
                    $this->fail('Un processus enfant s\'est terminé avant d\'atteindre la barrière : '.$child->output());
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail(sprintf(
            '%d requête(s) attendue(s) en attente sur %s (%s) ; connexions observées : %s',
            $expected,
            $table,
            $lock,
            json_encode(array_map(static fn (object $row): mixed => $row->INFO, $rows), JSON_UNESCAPED_UNICODE),
        ));
    }

    private function assertAccountRowIsFree(string $userId, string $message): void
    {
        $observer = $this->observer();
        $observer->beginTransaction();
        try {
            $observer->select('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT', [$userId]);
            $this->addToAssertionCount(1);
        } catch (QueryException $exception) {
            $this->fail($message.' ('.$exception->getMessage().')');
        } finally {
            $observer->rollBack();
        }
    }

    private function observer(): ConnectionInterface
    {
        config(['database.connections.concurrency_observer' => (array) config('database.connections.'.self::CONNECTION)]);

        return DB::connection('concurrency_observer');
    }
}
