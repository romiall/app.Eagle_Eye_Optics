<?php

namespace App\Offline;

use App\Application\Contracts\ContractValidator;
use App\Application\Locking\TransactionLocks;
use App\Application\TransactionRunner;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

/**
 * `POST /api/v1/sync/push` (O05 ; fichier 19 « Synchronisation », contrat 23 « Push, ACK et résolution ») : lot ordonné
 * du journal d'un poste préparé, au plus 50 commandes et 2 Mio (configurables).
 *
 *  1. Le lot est lu tel quel (objets JSON conservés) et validé contre `sync/push-request.json`.
 *  2. Le poste appartient au cabinet du compte connecté, n'est pas perdu ; le compte est préparé sur ce poste (un
 *     grant de ce poste lui a été émis) ou c'est la gérante (`offline.enroll`, reprise d'un poste). Poste REMPLACÉ
 *     (O07) : ses commandes déjà signées se transmettent par la REPRISE de la gérante (`recovered-push`, appliquées
 *     normalement) ; si l'ancien poste réapparaît et envoie lui-même, ses commandes sont vérifiées et conservées mais
 *     passent EN REVUE (conflit `DEVICE_REPLACED`), jamais appliquées d'office.
 *  3. Chaque commande est VÉRIFIÉE avant tout stockage (`SignedCommands` : empreinte JCS, JWS ES256 de la clé enrôlée,
 *     origine) ; le lot est contigu et chaîné (`previous_event_hash`), ancré sur le rang précédent déjà reçu ; même
 *     `command_id` avec une autre empreinte, ou un autre contenu au même rang : conflit d'intégrité, RIEN n'est stocké.
 *     Une attaque non authentifiée n'avance donc jamais le journal.
 *  4. Les nouvelles commandes entrent dans l'inbox IMMUABLE (`sync_commands`, charge rechiffrée par le serveur) avec
 *     leur résultat `RECEIVED` ; l'heure de PREMIÈRE réception est conservée (jugement d'horloge déterministe).
 *  5. L'inbox du poste est appliquée dans l'ordre (`SyncDrain`), chaque commande dans sa transaction par le chemin
 *     central ; la réponse donne l'ACK durable de chaque commande du lot et le préfixe finalisé du poste.
 *
 * Un délai dépassé ou une coupure rejoue les MÊMES identifiants : rien n'est appliqué deux fois.
 */
final class PushCommands
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly ContractValidator $contracts,
        private readonly EffectivePermissions $permissions,
        private readonly SyncDrain $drain,
    ) {}

    /**
     * @return array{results: list<array<string, mixed>>, finalized_prefix: array{last_sequence: string, last_hash: ?string}, server_time: string}
     */
    public function push(User $user, string $rawBody, string $correlationId, ?string $recoveredDeviceId = null): array
    {
        $clinicId = (string) $user->clinic_id;
        $maxBytes = max(1024, (int) config('eeo.offline.push_max_bytes', 2 * 1024 * 1024));
        if (strlen($rawBody) > $maxBytes) {
            throw new BusinessError('PAYLOAD_TOO_LARGE', 'Lot de synchronisation trop volumineux : envoyez moins de commandes à la fois.', 413);
        }
        try {
            $body = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw BusinessError::validation(['commands' => ['Lot de synchronisation illisible : JSON attendu.']]);
        }
        if (! $body instanceof stdClass) {
            throw BusinessError::validation(['commands' => ['Lot de synchronisation illisible : objet JSON attendu.']]);
        }
        $fields = $this->contracts->violations('sync/push-request.json', $body);
        if ($fields !== []) {
            throw BusinessError::validation($fields, 'Lot de synchronisation non conforme au contrat.');
        }
        $maxCommands = max(1, (int) config('eeo.offline.push_max_commands', 50));
        /** @var list<mixed> $commands */
        $commands = array_values((array) $body->commands);
        if (count($commands) > $maxCommands) {
            throw new BusinessError('PAYLOAD_TOO_LARGE', 'Au plus '.$maxCommands.' commandes par envoi.', 413);
        }

        $deviceId = (string) $body->device_id;
        $device = DeviceDirectory::device($clinicId, $deviceId);
        if ($device === null) {
            throw BusinessError::notFound('Poste inconnu de ce cabinet.');
        }
        // Reprise d'un poste remplacé (O07) : la gérante transmet le journal exporté de CE poste, rien d'autre.
        if ($recoveredDeviceId !== null) {
            if ($recoveredDeviceId !== $deviceId) {
                throw BusinessError::validation(['device_id' => ['Le lot doit être celui du poste repris (adresse).']]);
            }
            if ((string) $device->status !== 'REPLACED') {
                throw BusinessError::stateRefused("La reprise d'un journal exporté suit le remplacement déclaré du poste.", (string) $device->status);
            }
            if (! $this->permissions->has($user, 'offline.enroll')) {
                throw BusinessError::forbidden("Seule la gérante reprend le journal d'un poste remplacé.");
            }
        } elseif ((string) $device->status === 'LOST') {
            throw BusinessError::forbidden('Ce poste est déclaré perdu ou remplacé : son journal se reprend par la procédure de la gérante.');
        }
        $review = $recoveredDeviceId === null && (string) $device->status === 'REPLACED';
        $prepared = DB::table('offline_grants')->where('clinic_id', $clinicId)->where('device_id', $deviceId)->where('user_id', $user->id)->exists();
        if (! $prepared && ! $this->permissions->has($user, 'offline.enroll')) {
            throw BusinessError::forbidden('Ce compte n\'est pas préparé sur ce poste.');
        }

        $verified = $this->verifyBatch($commands, $clinicId, $deviceId, $device);
        $this->store($clinicId, $deviceId, $verified);

        $ids = array_map(static fn (VerifiedCommand $command): string => $command->commandId, $verified);
        $alreadyAccepted = array_fill_keys(
            array_map('strval', DB::table('sync_command_results')->where('clinic_id', $clinicId)->whereIn('command_id', $ids)->where('status', 'ACCEPTED')->pluck('command_id')->all()),
            true,
        );

        if ($review) {
            // Ancien poste réapparu : ses commandes nouvelles attendent la décision de la gérante (fichier 19).
            $this->drain->holdForReview($clinicId, $deviceId, $ids, $correlationId);
        } else {
            $this->drain->drain($clinicId, $deviceId, $correlationId);
        }

        return [
            'results' => SyncAcks::forCommands($clinicId, $deviceId, $ids, $alreadyAccepted),
            'finalized_prefix' => self::prefix($clinicId, $deviceId),
            'server_time' => SignedCommands::format(CarbonImmutable::now('UTC')),
        ];
    }

    /**
     * Préfixe finalisé et vérifié du journal du poste (ACCEPTED et REJECTED décidés), jamais au-delà d'un non finalisé.
     *
     * @return array{last_sequence: string, last_hash: ?string}
     */
    public static function prefix(string $clinicId, string $deviceId): array
    {
        $device = DB::table('enrolled_devices')->where('clinic_id', $clinicId)->where('id', $deviceId)->first(['last_accepted_sequence', 'last_accepted_hash']);

        return [
            'last_sequence' => (string) (int) ($device->last_accepted_sequence ?? 0),
            'last_hash' => $device === null || $device->last_accepted_hash === null ? null : (string) $device->last_accepted_hash,
        ];
    }

    /**
     * @param  list<mixed>  $commands
     * @return list<VerifiedCommand>
     */
    private function verifyBatch(array $commands, string $clinicId, string $deviceId, stdClass $device): array
    {
        $jwk = ServerSigningKey::enrolledJwk((string) $device->public_key_jwk);
        if ($jwk === null) {
            throw new BusinessError('SYNC_INTEGRITY_FAILED', 'Clé publique enrôlée du poste illisible : enrôlez-le de nouveau.', 422);
        }

        $verified = [];
        foreach ($commands as $index => $signed) {
            try {
                $command = SignedCommands::verify($signed, $clinicId, $deviceId, $jwk);
            } catch (UnexpectedValueException|InvalidArgumentException $refusal) {
                // InvalidArgumentException : valeur sans forme JCS (nombre à virgule, texte UTF-8 invalide).
                throw self::integrity($index, $refusal->getMessage());
            }
            $previous = $verified[$index - 1] ?? null;
            if ($previous !== null) {
                if ($command->deviceSequence !== $previous->deviceSequence + 1) {
                    throw self::integrity($index, 'Le lot doit suivre l\'ordre du journal du poste, sans trou ni doublon.');
                }
                if ($command->previousEventHash !== $previous->eventHash) {
                    throw self::integrity($index, 'Chaînage rompu : l\'empreinte précédente ne correspond pas à la commande précédente du lot.');
                }
            }
            $verified[] = $command;
        }

        return $verified;
    }

    /**
     * Inbox immuable : ancrage du lot, conflits d'intégrité, puis insertion des commandes nouvelles et de leur résultat
     * RECEIVED, en UNE transaction (tout le lot ou rien) sous les verrous du cabinet et du poste.
     *
     * @param  list<VerifiedCommand>  $verified
     */
    private function store(string $clinicId, string $deviceId, array $verified): void
    {
        $this->transactions->run(function () use ($clinicId, $deviceId, $verified): void {
            $locks = new TransactionLocks($clinicId);
            $locks->clinic();
            if ($locks->device($deviceId) === null) {
                throw BusinessError::notFound('Poste inconnu de ce cabinet.');
            }

            $first = $verified[0];
            if ($first->deviceSequence === 1) {
                if ($first->previousEventHash !== null) {
                    throw self::integrity(0, 'La première commande du journal d\'un poste n\'a pas d\'empreinte précédente.');
                }
            } else {
                $anchor = DB::table('sync_commands')
                    ->where('clinic_id', $clinicId)->where('device_id', $deviceId)->where('device_sequence', $first->deviceSequence - 1)
                    ->value('event_hash');
                if (! is_string($anchor)) {
                    $known = (int) DB::table('sync_commands')->where('clinic_id', $clinicId)->where('device_id', $deviceId)->max('device_sequence');
                    throw new BusinessError(
                        'SYNC_SEQUENCE_GAP',
                        'Le serveur n\'a pas reçu la commande précédente de ce poste : renvoyez le journal depuis la plus ancienne commande non confirmée.',
                        409,
                        [],
                        null,
                        [],
                        ['expected_sequence' => (string) ($known + 1)],
                    );
                }
                if ($anchor !== $first->previousEventHash) {
                    throw self::integrity(0, 'L\'empreinte précédente ne correspond pas à la commande reçue au rang précédent : journal du poste divergent.');
                }
            }

            $received = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            foreach ($verified as $index => $command) {
                $existing = DB::table('sync_commands')->where('id', $command->commandId)->first();
                if ($existing !== null) {
                    if ((string) $existing->clinic_id !== $clinicId || (string) $existing->device_id !== $deviceId
                        || (int) $existing->device_sequence !== $command->deviceSequence || ! hash_equals((string) $existing->event_hash, $command->eventHash)) {
                        throw new BusinessError(
                            'IDEMPOTENCY_PAYLOAD_MISMATCH',
                            'Cette commande a déjà été reçue avec un autre contenu : conflit d\'intégrité, rien n\'est enregistré.',
                            409,
                            ['commands.'.$index => ['Même identifiant de commande, autre empreinte.']],
                        );
                    }

                    continue;
                }
                $slot = DB::table('sync_commands')->where('clinic_id', $clinicId)->where('device_id', $deviceId)->where('device_sequence', $command->deviceSequence)->value('id');
                if ($slot !== null) {
                    throw self::integrity($index, 'Ce rang du journal porte déjà une autre commande : journal du poste divergent.');
                }
                $grant = DB::table('offline_grants')->where('clinic_id', $clinicId)->where('id', $command->grantId)->first(['device_id', 'user_id']);
                if ($grant === null || (string) $grant->device_id !== $deviceId || (string) $grant->user_id !== $command->actorId) {
                    throw self::integrity($index, 'Autorisation de la commande inconnue pour ce poste et cet auteur.');
                }
                if ($command->numberAllocationId !== null
                    && ! DB::table('document_number_allocations')->where('clinic_id', $clinicId)->where('id', $command->numberAllocationId)->exists()) {
                    throw self::integrity($index, 'Plage de numéros inconnue du serveur.');
                }

                DB::table('sync_commands')->insert([
                    'id' => $command->commandId,
                    'clinic_id' => $clinicId,
                    'device_id' => $deviceId,
                    'actor_id' => $command->actorId,
                    'grant_id' => $command->grantId,
                    'device_sequence' => $command->deviceSequence,
                    'previous_event_hash' => $command->previousEventHash,
                    'event_hash' => $command->eventHash,
                    'signature' => $command->signature,
                    'schema_version' => $command->schemaVersion,
                    'rules_version' => $command->rulesVersion,
                    'command_name' => $command->commandName,
                    'occurred_at_local' => $command->occurredAtLocal->format('Y-m-d H:i:s.u'),
                    'last_server_time' => $command->lastServerTime->format('Y-m-d H:i:s.u'),
                    'entity_type' => $command->entityType,
                    'entity_id' => $command->entityId,
                    'expected_version' => $command->expectedVersion,
                    'number_allocation_id' => $command->numberAllocationId,
                    'payload_ciphertext' => SyncPayloads::sealCommand($command->payload, $command->stockAllocationIds),
                    'encryption_key_id' => SyncPayloads::keyId(),
                    'received_at' => $received,
                    'created_at' => $received,
                    'updated_at' => $received,
                ]);
                DB::table('sync_command_results')->insert([
                    'id' => Ulid::generate(),
                    'clinic_id' => $clinicId,
                    'command_id' => $command->commandId,
                    'status' => 'RECEIVED',
                    'result_references' => null,
                    'error_code' => null,
                    'processed_at' => null,
                    'attempt_count' => 0,
                    'created_at' => $received,
                    'updated_at' => $received,
                ]);
            }
        });
    }

    private static function integrity(int $index, string $message): BusinessError
    {
        return new BusinessError('SYNC_INTEGRITY_FAILED', 'Commande '.($index + 1).' du lot refusée : '.$message.' Rien n\'a été enregistré.', 422, ['commands.'.$index => [$message]]);
    }
}
