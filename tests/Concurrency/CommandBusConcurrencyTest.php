<?php

namespace Tests\Concurrency;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Concurrency\Support\ScenarioProcess;
use Tests\Support\CommandFixtures;
use Tests\Support\Commands\TestCommands;
use Tests\Support\ScratchMysqlDatabases;
use Tests\TestCase;

/**
 * Entrelacements contrôlés du chemin central entre CONNEXIONS MYSQL RÉELLES (fichier 25 : « deux connexions
 * MySQL avec interleavings contrôlés »). Chaque opération s'exécute dans un processus PHP enfant ; le test tient
 * une barrière (verrou de ligne ou verrou nommé MySQL), attend que les enfants soient suspendus sur la requête
 * attendue, puis la relâche : aucun sleep, aucun hasard d'ordonnancement.
 *
 * AT12 : deux règlements concurrents de 40 000 sur un solde de 50 000 — un seul accepté.
 * AT11/AT38 : double clic et rejeu avec la même clé — un seul effet.
 * Contrat 23 : interblocage rejoué (trois tentatives), droits relus sous le verrou du cabinet, publication du
 * pull ordonnée par le verrou du cabinet, aucun effet partiel après un arrêt avant le commit.
 *
 * La base jetable est migrée UNE fois pour la classe ; chaque test travaille dans son propre cabinet.
 */
final class CommandBusConcurrencyTest extends TestCase
{
    use CommandFixtures;
    use ScratchMysqlDatabases;

    private const CONNECTION = 'concurrency_commands';

    private const DATABASE = 'eeo_conc_commands_test';

    private const SCRIPT = 'command-scenario.php';

    /**
     * Délai maximal d'attente d'un enfant ou d'une barrière, en secondes. Un processus enfant démarre Laravel : de
     * ~2 s à chaud, jusqu'à ~30 s à froid sur un disque lent. Les attentes de l'enfant lui-même (verrou de ligne,
     * barrière) sont réglées plus haut que ce délai, pour que le test échoue sur une vraie violation et non sur un
     * enfant lent à démarrer.
     */
    private const WAIT = 300.0;

    private static bool $migrated = false;

    private static bool $warmedUp = false;

    /** @var list<ScenarioProcess> */
    private array $children = [];

    private ?ConnectionInterface $gate = null;

    private ?ConnectionInterface $barrierHolder = null;

    private ?string $barrierName = null;

    private string $clinicId;

    private User $manager;

    private User $cashier;

    private User $receptionist;

    private string $counterId;

    /**
     * Boucle de développement locale (opt-in, EEO_TEST_REUSE_SCHEMA=1) : la base jetable migrée est conservée entre
     * deux exécutions de la classe et réutilisée tant que ses migrations sont exactement celles du dépôt. Une
     * migration complète de la baseline coûte plusieurs minutes sur un disque lent.
     */
    private static function reusingScratchSchema(): bool
    {
        return getenv('EEO_TEST_REUSE_SCHEMA') === '1';
    }

    private static function scratchSchemaMatchesRepository(): bool
    {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('EEO_TEST_DB_HOST') ?: '127.0.0.1', getenv('EEO_TEST_DB_PORT') ?: '3306', self::DATABASE),
                getenv('EEO_TEST_DB_USERNAME') ?: 'eeo_test',
                getenv('EEO_TEST_DB_PASSWORD') ?: 'eeo_test_password',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            /** @var list<string> $recorded */
            $recorded = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            $files = array_map(static fn (string $path): string => basename($path, '.php'), glob(database_path('migrations/*.php')) ?: []);
            sort($recorded);
            sort($files);

            return $recorded !== [] && $recorded === $files;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$migrated && ! self::reusingScratchSchema()) {
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

        if (! self::$migrated && self::reusingScratchSchema() && self::scratchSchemaMatchesRepository()) {
            self::$migrated = true;
        }

        if (self::$migrated) {
            config(['database.connections.'.self::CONNECTION => [...(array) config('database.connections.mysql'), 'database' => self::DATABASE]]);
            DB::purge(self::CONNECTION);
        } else {
            $this->createScratchDatabase(self::CONNECTION, self::DATABASE);
            $this->scratchDatabases = []; // supprimée par tearDownAfterClass, pas à chaque test
            $status = Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
            $this->assertSame(0, $status, Artisan::output());
            self::$migrated = true;
        }

        // Fixtures validées sur la base jetable : les processus enfants les voient.
        DB::setDefaultConnection(self::CONNECTION);

        if (! self::$warmedUp) {
            // Premier enfant jetable : amorce le cache disque (le démarrage à froid d'un enfant dépasse 20 s sur un
            // disque lent), pour que les enfants des tests suivants démarrent à chaud et de façon comparable.
            $this->spawn('pull', ['clinic_id' => Ulid::generate(), 'after' => 0])->wait(self::WAIT);
            self::$warmedUp = true;
        }

        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->cashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');
        $this->receptionist = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->counterId = $this->makeCounter($this->clinicId, 50000);
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
        foreach ([self::CONNECTION, 'concurrency_observer', 'concurrency_gate', 'concurrency_barrier'] as $connection) {
            DB::purge($connection);
        }

        parent::tearDown();
    }

    public function test_deux_reglements_concurrents_de_40000_sur_50000_ne_laissent_passer_que_le_premier(): void
    {
        // AT12 : un seul règlement de 40 000 accepté, le second est refusé sans effet.
        $this->holdClinicLock();
        $children = [
            $this->consume($this->cashier, 40000, 'cle-at12-aaaaa1'),
            $this->consume($this->manager, 40000, 'cle-at12-bbbbb1'),
        ];
        $this->waitForBlockedQuery('`clinics`', 'for update', 2);

        $this->releaseGate();
        $outcomes = array_map(static fn (ScenarioProcess $child): array => $child->wait(self::WAIT), $children);
        $codes = array_map(static fn (array $outcome): string => $outcome['ok'] ? 'OK' : (string) $outcome['code'], $outcomes);
        sort($codes);

        $this->assertSame(['OK', 'PAYMENT_EXCEEDS_BALANCE'], $codes);
        $this->assertSame(10000, $this->counterBalance($this->counterId));
        $this->assertSame(2, $this->counterVersion($this->counterId));
        $this->assertSame(['audit' => 1, 'outbox' => 1, 'changes' => 1, 'idempotency' => 1], $this->effectCounts($this->clinicId));
    }

    public function test_un_double_clic_concurrent_avec_la_meme_cle_n_applique_qu_un_seul_effet(): void
    {
        // AT11/AT38 : même clé, même contenu, simultanément — un seul effet, la seconde réponse est le rejeu.
        $this->holdClinicLock();
        $children = [
            $this->consume($this->cashier, 10000, 'cle-double-clic1'),
            $this->consume($this->cashier, 10000, 'cle-double-clic1'),
        ];
        $this->waitForBlockedQuery('`clinics`', 'for update', 2);

        $this->releaseGate();
        $outcomes = array_map(static fn (ScenarioProcess $child): array => $child->wait(self::WAIT), $children);

        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome['ok'], json_encode($outcome, JSON_UNESCAPED_UNICODE));
        }
        $replays = array_map(static fn (array $outcome): bool => (bool) $outcome['result']['replayed'], $outcomes);
        sort($replays);
        $this->assertSame([false, true], $replays, 'Une exécution, un rejeu.');
        $this->assertEquals($outcomes[0]['result']['result'], $outcomes[1]['result']['result'], 'Même réponse (MySQL normalise l\'ordre des clés du JSON stocké).');
        $this->assertSame(40000, $this->counterBalance($this->counterId));
        $this->assertSame(['audit' => 1, 'outbox' => 1, 'changes' => 1, 'idempotency' => 1], $this->effectCounts($this->clinicId));
    }

    public function test_la_meme_cle_avec_deux_contenus_differents_en_concurrence_refuse_le_second(): void
    {
        $this->holdClinicLock();
        $children = [
            $this->consume($this->cashier, 10000, 'cle-contenus-001'),
            $this->consume($this->cashier, 20000, 'cle-contenus-001'),
        ];
        $this->waitForBlockedQuery('`clinics`', 'for update', 2);

        $this->releaseGate();
        $outcomes = array_map(static fn (ScenarioProcess $child): array => $child->wait(self::WAIT), $children);
        $codes = array_map(static fn (array $outcome): string => $outcome['ok'] ? 'OK' : (string) $outcome['code'], $outcomes);
        sort($codes);

        $this->assertSame(['IDEMPOTENCY_PAYLOAD_MISMATCH', 'OK'], $codes);
        $winner = array_values(array_filter($outcomes, static fn (array $outcome): bool => $outcome['ok']))[0];
        $this->assertSame(50000 - $winner['result']['result']['amount'], $this->counterBalance($this->counterId), 'Seul le contenu gagnant est appliqué.');
        $this->assertSame(1, DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_la_publication_du_pull_attend_la_fin_de_la_mutation_en_cours(): void
    {
        $barrier = $this->holdBarrier();

        // Le rédacteur écrit ses faits, son audit, son outbox ET son changement, puis se suspend AVANT le commit.
        $writer = $this->consume($this->cashier, 10000, 'cle-publication-1', ['barrier' => $barrier, 'barrier_at' => 'change-log-written']);
        $this->waitForBarrierWaiters(1);

        // Rien de la transaction ouverte n'est visible, et le verrou exclusif du cabinet est tenu.
        $this->assertSame(0, DB::table('sync_change_log')->where('clinic_id', $this->clinicId)->count(), 'Changement non committé : invisible.');

        $reader = $this->child('pull', ['clinic_id' => $this->clinicId, 'after' => 0]);
        $this->waitForBlockedQuery('`clinics`', 'lock in share mode', 1);
        $this->assertTrue($reader->isRunning(), 'Le lecteur attend la fin de la mutation : il ne peut pas dépasser un changement non committé.');

        $this->releaseBarrier();
        $this->assertTrue($writer->wait(self::WAIT)['ok']);
        $page = $reader->wait(self::WAIT);

        $committed = (int) DB::table('sync_change_log')->where('clinic_id', $this->clinicId)->value('change_sequence');
        $this->assertTrue($page['ok'], json_encode($page, JSON_UNESCAPED_UNICODE));
        $this->assertSame([$committed], $page['result']['sequences'], 'Après le commit, la page contient le changement.');
        $this->assertSame($committed, $page['result']['upper_bound']);
        $this->assertFalse($page['result']['has_more']);
    }

    public function test_les_numeros_de_changement_suivent_l_ordre_des_commits(): void
    {
        // Trois mutations concurrentes : leurs changements sont numérotés dans l'ordre où elles committent.
        $this->holdClinicLock();
        $children = [
            $this->consume($this->cashier, 1000, 'cle-ordre-000001'),
            $this->consume($this->cashier, 2000, 'cle-ordre-000002'),
            $this->consume($this->cashier, 3000, 'cle-ordre-000003'),
        ];
        $this->waitForBlockedQuery('`clinics`', 'for update', 3);

        $this->releaseGate();
        foreach ($children as $child) {
            $this->assertTrue($child->wait(self::WAIT)['ok']);
        }

        // La version de l'entité croît avec le numéro de changement : l'ordre de publication est l'ordre d'application.
        $rows = DB::table('sync_change_log')->where('clinic_id', $this->clinicId)->orderBy('change_sequence')->get();
        $this->assertCount(3, $rows);
        $this->assertSame([2, 3, 4], $rows->map(static fn (object $row): int => (int) $row->entity_version)->all());
        $this->assertSame(44000, $this->counterBalance($this->counterId));
    }

    public function test_un_interblocage_reel_est_rejoue_et_les_deux_travaux_aboutissent(): void
    {
        $first = $this->counterId;
        $second = Ulid::generate();
        DB::table('document_sequences')->insert([
            'id' => $second, 'clinic_id' => $this->clinicId, 'document_type' => 'INVOICE', 'document_year' => 2026, 'last_value' => 0,
        ]);

        $barrier = $this->holdBarrier();
        $children = [
            $this->child('deadlock', ['first' => $first, 'second' => $second, 'barrier' => $barrier]),
            $this->child('deadlock', ['first' => $second, 'second' => $first, 'barrier' => $barrier]),
        ];
        // Les deux enfants tiennent chacun leur première ligne et attendent la barrière.
        $this->waitForBarrierWaiters(2);

        $this->releaseBarrier();
        $outcomes = array_map(static fn (ScenarioProcess $child): array => $child->wait(self::WAIT), $children);

        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome['ok'], 'Le travail doit aboutir malgré l\'interblocage : '.json_encode($outcome, JSON_UNESCAPED_UNICODE));
            $this->assertSame('termine', $outcome['result']['outcome']);
        }
        $attempts = array_map(static fn (array $outcome): int => count($outcome['result']['attempts']), $outcomes);
        sort($attempts);
        $this->assertSame([1, 2], $attempts, 'InnoDB choisit une victime : elle est rejouée une fois, l\'autre passe du premier coup.');

        // Chaque travail a incrémenté sa première ligne, une seule fois.
        $this->assertSame(50001, $this->counterBalance($first));
        $this->assertSame(1, (int) DB::table('document_sequences')->where('id', $second)->value('last_value'));
    }

    public function test_un_utilisateur_sans_droit_est_refuse_sans_attendre_le_verrou_du_cabinet(): void
    {
        $this->holdClinicLock();

        // L'accueil n'a pas payment.collect : refus immédiat (avant toute transaction), alors même que le
        // verrou du cabinet reste tenu par ailleurs.
        $outcome = $this->consume($this->receptionist, 100, 'cle-sans-droit-01')->wait(self::WAIT);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('ACTION_FORBIDDEN', $outcome['code']);
        $this->assertSame('Action non autorisée pour ce compte.', $outcome['message']);
        $this->assertNotNull($this->gate);
        $this->assertSame(1, $this->gate->transactionLevel(), 'Le verrou du cabinet est resté tenu pendant le refus.');
        $this->releaseGate();
        $this->assertSame(50000, $this->counterBalance($this->counterId));
    }

    public function test_un_droit_retire_pendant_l_attente_du_verrou_est_pris_en_compte(): void
    {
        $this->holdClinicLock();
        $child = $this->consume($this->cashier, 100, 'cle-droit-retire1');
        $this->waitForBlockedQuery('`clinics`', 'for update', 1);

        // Le compte est désactivé pendant que sa requête, déjà autorisée en amont, attend le verrou.
        $this->assertNotNull($this->gate);
        $this->gate->table('users')->where('id', $this->cashier->id)->update(['active' => false]);

        $this->releaseGate();
        $outcome = $child->wait(self::WAIT);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('ACTION_FORBIDDEN', $outcome['code'], 'Droits relus sous le verrou du cabinet.');
        $this->assertSame(50000, $this->counterBalance($this->counterId));
        $this->assertSame(['audit' => 0, 'outbox' => 0, 'changes' => 0, 'idempotency' => 0], $this->effectCounts($this->clinicId));
    }

    public function test_une_erreur_apres_les_ecritures_annule_reellement_tout_ce_qui_etait_ecrit(): void
    {
        // Sur une connexion réelle : l'enfant a écrit fait, audit, outbox et changement, puis échoue au dernier point.
        $outcome = $this->consume($this->cashier, 10000, 'cle-erreur-00001', ['fail_at' => 'idempotency-completed'])->wait(self::WAIT);

        $this->assertFalse($outcome['ok']);
        $this->assertSame('EXCEPTION', $outcome['code']);
        $this->assertSame(50000, $this->counterBalance($this->counterId));
        $this->assertSame(['audit' => 0, 'outbox' => 0, 'changes' => 0, 'idempotency' => 0], $this->effectCounts($this->clinicId));

        // La clé n'est pas brûlée : la même demande s'applique ensuite.
        $this->assertTrue($this->consume($this->cashier, 10000, 'cle-erreur-00001')->wait(self::WAIT)['ok']);
        $this->assertSame(40000, $this->counterBalance($this->counterId));
    }

    public function test_un_processus_tue_avant_le_commit_ne_laisse_aucun_effet_partiel(): void
    {
        $barrier = $this->holdBarrier();
        $victim = $this->consume($this->cashier, 10000, 'cle-processus-01', ['barrier' => $barrier, 'barrier_at' => 'change-log-written']);
        $this->waitForBarrierWaiters(1);

        // Panne brutale : le processus disparaît avant d'avoir pu committer. MySQL ne constate la connexion fermée
        // qu'au retour de la requête en cours (GET_LOCK) : relâcher la barrière laisse le serveur la constater et
        // annuler la transaction ouverte. L'enfant, lui, n'est plus là pour émettre un COMMIT.
        $victim->terminate();
        $this->releaseBarrier();
        $this->waitForClinicLockRelease();

        $this->assertSame(50000, $this->counterBalance($this->counterId));
        $this->assertSame(['audit' => 0, 'outbox' => 0, 'changes' => 0, 'idempotency' => 0], $this->effectCounts($this->clinicId));

        // Le client renvoie la même demande (même clé) : elle s'applique une fois.
        $retry = $this->consume($this->cashier, 10000, 'cle-processus-01')->wait(self::WAIT);
        $this->assertTrue($retry['ok']);
        $this->assertFalse($retry['result']['replayed']);
        $this->assertSame(40000, $this->counterBalance($this->counterId));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function consume(User $actor, int $amount, string $key, array $extra = []): ScenarioProcess
    {
        return $this->child('consume', [
            'actor_id' => $actor->id,
            'counter_id' => $this->counterId,
            'amount' => $amount,
            'key' => $key,
            'command' => TestCommands::CONSUME,
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function child(string $scenario, array $arguments): ScenarioProcess
    {
        // Les enfants suivis par le test sont ceux qui doivent rester suspendus jusqu'à la libération de la barrière.
        return $this->children[] = $this->spawn($scenario, $arguments);
    }

    /**
     * Lance un enfant SANS le suivre : un enfant déjà terminé (échauffement) n'est pas « un enfant qui aurait dû
     * rester bloqué » pour waitForBlockedQuery et waitForBarrierWaiters.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function spawn(string $scenario, array $arguments): ScenarioProcess
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $connection */
        $connection = config('database.connections.'.self::CONNECTION);

        return ScenarioProcess::start($scenario, $arguments, [
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
            'EEO_LOCK_WAIT_TIMEOUT' => '600',
            'LOG_CHANNEL' => 'null',
            'BCRYPT_ROUNDS' => '4',
            'PATH' => (string) getenv('PATH'),
            // Windows : sans ces variables, un PHP lancé avec un environnement vide ne peut pas ouvrir de socket.
            'SystemRoot' => (string) getenv('SystemRoot'),
            'windir' => (string) getenv('windir'),
            'TEMP' => (string) getenv('TEMP'),
            'TMP' => (string) getenv('TMP'),
        ], self::SCRIPT);
    }

    /**
     * Barrière : verrou exclusif de la ligne du cabinet dans une transaction ouverte, comme une mutation en cours.
     */
    private function holdClinicLock(): void
    {
        config(['database.connections.concurrency_gate' => (array) config('database.connections.'.self::CONNECTION)]);
        $this->gate = DB::connection('concurrency_gate');
        $this->gate->beginTransaction();
        $this->gate->table('clinics')->where('id', $this->clinicId)->lockForUpdate()->first();
    }

    private function releaseGate(): void
    {
        $this->assertNotNull($this->gate);
        $this->gate->commit();
    }

    /**
     * Barrière à verrou nommé : le test le tient, les enfants s'y suspendent aux points demandés.
     */
    private function holdBarrier(): string
    {
        config(['database.connections.concurrency_barrier' => (array) config('database.connections.'.self::CONNECTION)]);
        $this->barrierName = 'eeo_b_'.substr(md5(uniqid('', true)), 0, 12);
        $this->barrierHolder = DB::connection('concurrency_barrier');
        $this->barrierHolder->selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$this->barrierName]);

        return $this->barrierName;
    }

    private function releaseBarrier(): void
    {
        $this->assertNotNull($this->barrierHolder);
        $this->barrierHolder->selectOne('SELECT RELEASE_LOCK(?)', [$this->barrierName]);
    }

    /**
     * Attend que $expected enfants soient suspendus sur la barrière (requête GET_LOCK en cours).
     */
    private function waitForBarrierWaiters(int $expected): void
    {
        $observer = $this->observer();
        $deadline = microtime(true) + self::WAIT;
        $count = 0;

        do {
            $rows = $observer->select(
                "SELECT ID FROM information_schema.PROCESSLIST WHERE DB = ? AND COMMAND IN ('Query', 'Execute') AND INFO LIKE '%GET_LOCK%' AND ID <> CONNECTION_ID()",
                [self::DATABASE],
            );
            $count = count($rows);
            if ($count >= $expected) {
                return;
            }
            foreach ($this->children as $child) {
                if (! $child->isRunning() && $count < $expected) {
                    $this->fail('Un processus enfant s\'est terminé avant d\'atteindre la barrière : '.$child->output());
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail(sprintf('%d enfant(s) attendu(s) sur la barrière, %d observé(s).', $expected, $count));
    }

    /**
     * Attend que MySQL ait annulé la transaction d'un processus mort : son verrou exclusif sur le cabinet doit
     * se libérer. On le prouve en le prenant sans attendre (NOWAIT), sans dépendre du droit PROCESS.
     */
    private function waitForClinicLockRelease(): void
    {
        $observer = $this->observer();
        $deadline = microtime(true) + 60;

        do {
            $observer->beginTransaction();
            try {
                $observer->select('SELECT id FROM clinics WHERE id = ? FOR UPDATE NOWAIT', [$this->clinicId]);

                return;
            } catch (QueryException) {
                usleep(100_000);
            } finally {
                $observer->rollBack();
            }
        } while (microtime(true) < $deadline);

        $this->fail('Le verrou du cabinet est resté tenu après la mort du processus : la transaction n\'a pas été annulée.');
    }

    /**
     * Attend que $expected connexions de la base jetable exécutent (donc attendent) la requête qui contient ces
     * fragments ; échoue avec l'état des connexions sinon.
     */
    private function waitForBlockedQuery(string $table, string $lock, int $expected): void
    {
        $observer = $this->observer();
        $deadline = microtime(true) + self::WAIT;
        $rows = [];

        do {
            $rows = $observer->select(
                "SELECT ID, INFO FROM information_schema.PROCESSLIST WHERE DB = ? AND COMMAND IN ('Query', 'Execute') AND ID <> CONNECTION_ID()",
                [self::DATABASE],
            );
            $blocked = array_filter($rows, static fn (object $row): bool => is_string($row->INFO) && str_contains($row->INFO, $table) && str_contains(strtolower($row->INFO), $lock));
            if (count($blocked) >= $expected) {
                return;
            }
            foreach ($this->children as $child) {
                if (! $child->isRunning() && count($blocked) < $expected) {
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

    private function observer(): ConnectionInterface
    {
        config(['database.connections.concurrency_observer' => (array) config('database.connections.'.self::CONNECTION)]);

        return DB::connection('concurrency_observer');
    }
}
