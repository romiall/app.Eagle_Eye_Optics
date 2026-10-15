<?php

namespace App\Application;

use App\Application\Authorization\CommandAuthorizer;
use App\Application\Contracts\ContractValidator;
use App\Application\Effects\AuditWriter;
use App\Application\Effects\ChangeLogWriter;
use App\Application\Effects\IdempotencyStore;
use App\Application\Effects\OutboxWriter;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use Illuminate\Contracts\Container\Container;
use LogicException;
use stdClass;

/**
 * Chemin central des commandes (contrat 23, « Chemin transactionnel central »). Toute mutation critique passe
 * ici, quelle que soit son origine (route directe, synchronisation, tâche) : mêmes droits, mêmes verrous, même
 * idempotence, mêmes écritures d'audit, d'outbox et de journal des changements, dans la même transaction.
 *
 * Ordre, dans UNE transaction rejouée en entier sur interblocage (TransactionRunner) :
 *   droits et contrat (avant tout verrou) → verrou du cabinet → appareil si poste → clé d'idempotence
 *   (résultat déjà committé : rejoué, jamais réexécuté) → droits relus sous verrou → action (autres verrous
 *   dans l'ordre du contrat) → audit → outbox → journal des changements → résultat idempotent → commit.
 *   La réponse n'existe qu'APRÈS le commit.
 *
 * Aucun effet externe dans la transaction : l'outbox porte l'intention, un worker l'exécute après le commit.
 * Un refus métier annule tout, y compris la réservation de la clé : il n'est jamais COMMITTED, et la même
 * intention peut être renvoyée quand l'état a changé (une intention modifiée prend un nouvel identifiant).
 */
final class CommandBus
{
    /**
     * Forme admise d'une clé d'idempotence (colonne `request_key` VARCHAR(128)). La clé est insensible à la casse,
     * comme la collation de la colonne : elle est ramenée en majuscules avant tout usage, pour que la comparaison
     * soit explicite et ne dépende pas de la base (un ULID est déjà en majuscules).
     */
    private const KEY_PATTERN = '/^[A-Za-z0-9._:-]{8,128}\z/';

    public function __construct(
        private readonly Container $container,
        private readonly CommandRegistry $registry,
        private readonly TransactionRunner $transactions,
        private readonly CommandAuthorizer $authorizer,
        private readonly ContractValidator $contracts,
        private readonly IdempotencyStore $idempotency,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly ChangeLogWriter $changeLog,
        private readonly CommandBusProbe $probe,
    ) {}

    public function dispatch(Command $command, CommandContext $context, ?string $idempotencyKey = null): CommandResult
    {
        $definition = $this->registry->definition($command->name);
        $handler = $this->container->make($definition->handler);
        if (! $handler instanceof CommandHandler) {
            throw new LogicException($definition->handler.' doit implémenter CommandHandler.');
        }

        $this->assertIdempotencyKey($definition, $idempotencyKey);
        $idempotencyKey = $idempotencyKey === null ? null : strtoupper($idempotencyKey);
        if ($definition->schema !== null) {
            $this->contracts->assertCommandPayload($definition->schema, $command->payload);
        }
        $this->authorizer->precheck($context, $definition, $handler);

        $hash = $command->fingerprint($context);

        return $this->transactions->run(
            fn (int $attempt): CommandResult => $this->execute($command, $context, $handler, $idempotencyKey, $hash),
        );
    }

    private function execute(Command $command, CommandContext $context, CommandHandler $handler, ?string $idempotencyKey, string $hash): CommandResult
    {
        $locks = new TransactionLocks($context->clinicId);
        $locks->clinic();
        $this->probe->reached(Checkpoint::ClinicLocked, $context);

        if ($context->deviceId !== null && $locks->device($context->deviceId) === null) {
            throw BusinessError::forbidden('Poste inconnu de ce cabinet.');
        }

        $existing = null;
        if ($idempotencyKey !== null) {
            $existing = $locks->idempotency($context->idempotencyNamespace, $command->name, $idempotencyKey);

            if ($existing !== null && $existing->status === 'COMMITTED') {
                if (! hash_equals((string) $existing->payload_hash, $hash)) {
                    throw BusinessError::idempotencyMismatch();
                }

                // Droits ACTUELS relus avant de rendre un résultat déjà committé : un compte désactivé depuis ne
                // relit rien. Une commande de poste n'est pas réévaluée au rejeu : son autorisation historique a
                // déjà été jugée au commit, et une révocation ultérieure ne doit pas faire perdre son ACK au poste.
                if ($context->source === CommandSource::Direct) {
                    $this->authorizer->authorizeLocked($context, $handler);
                }

                return $this->replayed($existing, $context);
            }
        }

        $this->authorizer->authorizeLocked($context, $handler);

        $reservation = null;
        if ($idempotencyKey !== null) {
            if ($existing !== null) {
                // Ligne non committée (RECEIVED ou FAILED) : reprise sur la même ligne, pour la MÊME empreinte
                // seulement. Une autre intention sous la même clé reste un conflit, comme après un commit.
                if (! hash_equals((string) $existing->payload_hash, $hash)) {
                    throw BusinessError::idempotencyMismatch();
                }
                $reservation = (string) $existing->id;
                $this->idempotency->restart($context, $reservation);
            } else {
                $reservation = $this->idempotency->reserve($context, $command->name, $idempotencyKey, $hash);
            }
        }
        $this->probe->reached(Checkpoint::IdempotencyChecked, $context);

        $outcome = $handler->handle($command, $context, $locks, $reservation);
        $this->probe->reached(Checkpoint::HandlerReturned, $context);

        $this->audit->write($context, $outcome->audit);
        $this->probe->reached(Checkpoint::AuditWritten, $context);

        $this->outbox->write($context, $outcome->events);
        $this->probe->reached(Checkpoint::OutboxWritten, $context);

        $this->changeLog->write($context, $locks, $outcome->changes);
        $this->probe->reached(Checkpoint::ChangeLogWritten, $context);

        if ($reservation !== null) {
            $this->idempotency->complete($context, $reservation, $outcome->resultType, $outcome->resultId, $outcome->result);
        }
        $this->probe->reached(Checkpoint::IdempotencyCompleted, $context);

        return new CommandResult(false, $outcome->result, $outcome->resultType, $outcome->resultId, $context->correlationId);
    }

    private function replayed(stdClass $existing, CommandContext $context): CommandResult
    {
        $decoded = is_string($existing->result_payload ?? null) ? json_decode($existing->result_payload, true) : null;

        return new CommandResult(
            true,
            is_array($decoded) ? $decoded : [],
            $existing->result_type !== null ? (string) $existing->result_type : null,
            $existing->result_id !== null ? (string) $existing->result_id : null,
            $context->correlationId,
        );
    }

    private function assertIdempotencyKey(CommandDefinition $definition, ?string $key): void
    {
        if ($key === null) {
            if ($definition->idempotencyRequired) {
                throw BusinessError::validation(['Idempotency-Key' => ['L\'en-tête Idempotency-Key est obligatoire pour cette opération.']]);
            }

            return;
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw BusinessError::validation(['Idempotency-Key' => ['La clé d\'idempotence doit comporter de 8 à 128 caractères parmi lettres, chiffres, point, tiret, deux-points et soulignement.']]);
        }
    }
}
