<?php

/*
 * Processus enfant des tests de concurrence du chemin central (tests/Concurrency/CommandBusConcurrencyTest) :
 * exécute UNE opération réelle dans un processus PHP distinct, donc sur une connexion MySQL distincte, comme
 * une seconde requête simultanée. Refusé hors de l'environnement « testing » ou sur une base qui n'est pas une
 * base de test isolée (eeo_*_test).
 *
 *   php tests/Concurrency/bin/command-scenario.php <scénario> '<arguments JSON>'
 *
 * Scénarios :
 *   consume  une commande de test du chemin central (compteur), avec barrière facultative à un point nommé ;
 *   pull     une page du journal des changements ;
 *   deadlock deux lignes verrouillées en sens opposé dans TransactionRunner (défaut d'ordre simulé).
 *
 * Barrière : `barrier_at` (point du chemin) et `barrier` (nom d'un verrou nommé MySQL). Au point demandé,
 * l'enfant tente d'obtenir ce verrou nommé, que le test tient : il reste donc suspendu, transaction ouverte,
 * jusqu'à ce que le test le relâche. Aucun sleep : l'entrelacement est exact.
 *
 * Sortie : une ligne JSON {"ok": true, "result": …} ou {"ok": false, "code": "…", "message": "…"}.
 */

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBus;
use App\Application\CommandBusProbe;
use App\Application\CommandRegistry;
use App\Application\Publication\ChangeFeed;
use App\Application\TransactionRunner;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Models\User;
use App\Support\Testing\IsolatedDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Commands\TestCommands;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$respond = static function (array $payload): never {
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE)."\n");
    exit(0);
};

if ($app->configurationIsCached() || ! $app->environment('testing')) {
    $respond(['ok' => false, 'code' => 'ENVIRONMENT_REFUSED', 'message' => 'Environnement « testing » sans configuration en cache exigé.']);
}
$guard = IsolatedDatabaseGuard::forConnection('testing', (string) config('database.default'));
if ($guard->violations() !== []) {
    $respond(['ok' => false, 'code' => 'DATABASE_REFUSED', 'message' => implode(' ; ', $guard->violations())]);
}

$scenario = (string) ($argv[1] ?? '');
/** @var array<string, mixed> $arguments */
$arguments = json_decode((string) ($argv[2] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

/**
 * Suspend la transaction au point demandé (verrou nommé tenu par le test) ou y lève une erreur : dans les deux
 * cas la transaction est RÉELLEMENT ouverte sur une connexion distincte, avec ses effets déjà écrits.
 */
$barrierProbe = new class($arguments['barrier'] ?? null, $arguments['barrier_at'] ?? null, $arguments['fail_at'] ?? null) implements CommandBusProbe
{
    public function __construct(private readonly ?string $name, private readonly ?string $at, private readonly ?string $failAt) {}

    public function reached(Checkpoint $checkpoint, CommandContext $context): void
    {
        if ($this->failAt === $checkpoint->value) {
            throw new RuntimeException('Erreur injectée au point '.$checkpoint->value);
        }
        if ($this->name === null || $this->at !== $checkpoint->value) {
            return;
        }
        // Verrou nommé MySQL : non transactionnel, il n'interfère pas avec les verrous de lignes.
        DB::selectOne('SELECT GET_LOCK(?, 600) AS acquired', [$this->name]);
        DB::selectOne('SELECT RELEASE_LOCK(?)', [$this->name]);
    }
};

try {
    $result = match ($scenario) {
        'consume' => (static function () use ($app, $arguments, $barrierProbe): array {
            $app->instance(CommandBusProbe::class, $barrierProbe);
            TestCommands::register($app->make(CommandRegistry::class));

            /** @var User $actor */
            $actor = User::query()->findOrFail((string) $arguments['actor_id']);
            $command = new Command(
                (string) ($arguments['command'] ?? TestCommands::CONSUME),
                ['amount' => (int) $arguments['amount']],
                (string) $arguments['counter_id'],
                isset($arguments['expected_version']) ? (int) $arguments['expected_version'] : null,
            );
            $result = $app->make(CommandBus::class)->dispatch(
                $command,
                CommandContext::direct($actor, (string) ($arguments['correlation_id'] ?? 'corr-child-0001')),
                isset($arguments['key']) ? (string) $arguments['key'] : null,
            );

            return ['replayed' => $result->replayed, 'result' => $result->result];
        })(),
        'create-patient' => (static function () use ($app, $arguments, $barrierProbe): array {
            // Création d'un patient par la route directe du chemin central : numéro central sous verrou.
            $app->instance(CommandBusProbe::class, $barrierProbe);

            /** @var User $actor */
            $actor = User::query()->findOrFail((string) $arguments['actor_id']);
            /** @var array<string, mixed> $payload */
            $payload = $arguments['payload'];
            unset($payload['expected_version']);
            $result = $app->make(CommandBus::class)->dispatch(
                new Command('CreatePatient', $payload, (string) $payload['patient_id'], null),
                CommandContext::direct($actor, (string) ($arguments['correlation_id'] ?? 'corr-child-0001')),
                isset($arguments['key']) ? (string) $arguments['key'] : null,
            );

            return ['replayed' => $result->replayed, 'result' => $result->result];
        })(),
        'pull' => (static function () use ($app, $arguments): array {
            $page = $app->make(ChangeFeed::class)->page((string) $arguments['clinic_id'], (int) $arguments['after']);

            return [
                'sequences' => array_map(static fn (object $row): int => (int) $row->change_sequence, $page['changes']),
                'upper_bound' => $page['upper_bound'],
                'has_more' => $page['has_more'],
            ];
        })(),
        'deadlock' => (static function () use ($app, $arguments): array {
            // Défaut d'ordre SIMULÉ (deux lignes dans des sens opposés) : prouve que TransactionRunner rejoue.
            [$first, $second] = [(string) $arguments['first'], (string) $arguments['second']];
            $barrier = (string) $arguments['barrier'];
            $attempts = [];

            $runner = new TransactionRunner($app->make('db'), $app, 3, 600);
            $outcome = $runner->run(static function (int $attempt) use ($first, $second, $barrier, &$attempts): string {
                $attempts[] = $attempt;
                DB::table('document_sequences')->where('id', $first)->lockForUpdate()->first();
                if ($attempt === 1) {
                    // Les deux enfants tiennent leur première ligne avant que l'un ne demande la seconde.
                    DB::selectOne('SELECT GET_LOCK(?, 600) AS acquired', [$barrier]);
                    DB::selectOne('SELECT RELEASE_LOCK(?)', [$barrier]);
                }
                DB::table('document_sequences')->where('id', $second)->lockForUpdate()->first();
                DB::table('document_sequences')->where('id', $first)->increment('last_value');

                return 'termine';
            });

            return ['outcome' => $outcome, 'attempts' => $attempts];
        })(),
        default => throw new InvalidArgumentException('Scénario inconnu : '.$scenario),
    };
    $respond(['ok' => true, 'result' => $result]);
} catch (BusinessError $error) {
    // Diagnostic : cause réelle derrière un refus réessayable (interblocage ou attente de verrou) et délai de verrou effectif.
    $diagnostic = [];
    if ($error->getPrevious() !== null) {
        $diagnostic['previous'] = $error->getPrevious()::class.' : '.mb_substr($error->getPrevious()->getMessage(), 0, 300);
    }
    try {
        $diagnostic['innodb_lock_wait_timeout'] = (int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
    } catch (Throwable) {
    }
    $respond(['ok' => false, 'code' => $error->errorCode, 'message' => $error->getMessage(), 'diagnostic' => $diagnostic]);
} catch (Throwable $error) {
    $respond(['ok' => false, 'code' => 'EXCEPTION', 'message' => $error::class.' : '.$error->getMessage()]);
}
