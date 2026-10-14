<?php

/*
 * Processus enfant des tests de concurrence (tests/Concurrency) : exécute UNE action réelle de
 * l'application dans un processus PHP distinct, donc sur une connexion MySQL distincte, comme une
 * seconde requête simultanée. Lancé par Tests\Concurrency\Support\ScenarioProcess avec la base jetable
 * dans l'environnement. Refusé hors de l'environnement « testing » ou sur une base qui n'est pas une
 * base de test isolée (eeo_*_test).
 *
 *   php tests/Concurrency/bin/access-scenario.php <scénario> '<arguments JSON>'
 *
 * Sortie : une ligne JSON {"ok": true, "result": …} ou {"ok": false, "code": "…", "message": "…"}.
 */

use App\Domain\Access\Actions\AuthenticateStaff;
use App\Domain\Access\Actions\ChangeOwnPassword;
use App\Domain\Access\Actions\SetupClinic;
use App\Domain\Access\Actions\UpdateStaffMember;
use App\Domain\Shared\BusinessError;
use App\Models\User;
use App\Support\Testing\IsolatedDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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

$isolation = $arguments['isolation'] ?? null;
if ($isolation !== null) {
    if (! in_array($isolation, ['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'], true)) {
        $respond(['ok' => false, 'code' => 'ISOLATION_REFUSED', 'message' => 'Niveau d\'isolation inconnu.']);
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
}

try {
    $result = match ($scenario) {
        'login' => app(AuthenticateStaff::class)((string) $arguments['login'], (string) $arguments['password'], '127.0.0.1')->id,
        'change-password' => (static function () use ($arguments): string {
            /** @var User $user */
            $user = User::query()->findOrFail((string) $arguments['user_id']);
            app(ChangeOwnPassword::class)($user, (array) $arguments['input'], null);

            return $user->id;
        })(),
        'update-staff' => app(UpdateStaffMember::class)(
            User::query()->findOrFail((string) $arguments['actor_id']),
            (string) $arguments['target_id'],
            (array) $arguments['input'],
        )->id,
        'setup-clinic' => app(SetupClinic::class)((array) $arguments['input'])['clinic_id'],
        default => throw new InvalidArgumentException('Scénario inconnu : '.$scenario),
    };
    $respond(['ok' => true, 'result' => $result]);
} catch (BusinessError $error) {
    $respond(['ok' => false, 'code' => $error->errorCode, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    $respond(['ok' => false, 'code' => 'EXCEPTION', 'message' => $error::class.' : '.$error->getMessage()]);
}
