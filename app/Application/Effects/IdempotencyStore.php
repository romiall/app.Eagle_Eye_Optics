<?php

namespace App\Application\Effects;

use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * Écritures de la table `idempotency_requests` (la lecture verrouillée est faite par TransactionLocks, au rang
 * « idempotence » de l'ordre des verrous). Réservation et résultat sont écrits dans la transaction de la
 * commande : une commande annulée ne laisse aucune clé réservée, une commande committée garde son résultat
 * durable. Un refus métier sans effet n'est donc jamais COMMITTED.
 */
final class IdempotencyStore
{
    public function reserve(CommandContext $context, string $commandName, string $requestKey, string $payloadHash): string
    {
        $id = Ulid::generate();
        $now = self::timestamp($context);

        DB::table('idempotency_requests')->insert([
            'id' => $id,
            'clinic_id' => $context->clinicId,
            'namespace' => $context->idempotencyNamespace,
            'command_name' => $commandName,
            'request_key' => $requestKey,
            'payload_hash' => $payloadHash,
            'status' => 'RECEIVED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * Ligne laissée RECEIVED ou FAILED sans résultat durable (jamais produite par ce chemin, qui annule la
     * réservation avec la transaction) : la commande est rejouée sur la même ligne.
     */
    public function restart(CommandContext $context, string $id, string $payloadHash): void
    {
        DB::table('idempotency_requests')->where('clinic_id', $context->clinicId)->where('id', $id)->update([
            'payload_hash' => $payloadHash,
            'status' => 'RECEIVED',
            'result_type' => null,
            'result_id' => null,
            'result_payload' => null,
            'completed_at' => null,
            'updated_at' => self::timestamp($context),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result  objet JSON, rejoué tel quel si la première réponse s'est perdue
     */
    public function complete(CommandContext $context, string $id, ?string $resultType, ?string $resultId, array $result): void
    {
        $now = self::timestamp($context);

        DB::table('idempotency_requests')->where('clinic_id', $context->clinicId)->where('id', $id)->update([
            'status' => 'COMMITTED',
            'result_type' => $resultType,
            'result_id' => $resultId,
            'result_payload' => json_encode((object) $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function timestamp(CommandContext $context): string
    {
        return $context->now->utc()->format('Y-m-d H:i:s.u');
    }
}
