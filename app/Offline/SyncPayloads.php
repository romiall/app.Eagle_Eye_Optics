<?php

namespace App\Offline;

use Illuminate\Support\Facades\Crypt;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Chiffrement SERVEUR des contenus sensibles de la synchronisation (contrat 23, « Signature et chiffrement » : « chiffrer
 * à nouveau à réception/stockage serveur selon classification ») : charge métier d'une commande reçue
 * (`sync_commands.payload_ciphertext`) et instantanés d'un conflit (`sync_conflicts.*_snapshot_ciphertext`).
 *
 * Chiffrement authentifié de Laravel (`Crypt`, clé d'application `APP_KEY`, hors de la base) ; `encryption_key_id`
 * désigne cette clé (`eeo.offline.sync_key_id`). Une rotation de `APP_KEY` garde les anciennes clés dans
 * `APP_PREVIOUS_KEYS` pour relire les commandes déjà reçues. Le clair est le JCS du contenu : la charge relue est
 * exactement celle que le poste a signée (un objet vide reste `{}`).
 */
final class SyncPayloads
{
    public static function keyId(): string
    {
        $id = config('eeo.offline.sync_key_id', 'app-key-1');

        return is_string($id) && $id !== '' ? $id : 'app-key-1';
    }

    /**
     * Charge métier d'une commande et ses quotas déclarés (absents des colonnes de `sync_commands`).
     *
     * @param  list<string>  $stockAllocationIds
     */
    public static function sealCommand(mixed $payload, array $stockAllocationIds): string
    {
        return Crypt::encryptString(Jcs::encode(['payload' => $payload, 'stock_allocation_ids' => $stockAllocationIds]));
    }

    /**
     * @return array{payload: array<string, mixed>, payload_jcs: string, stock_allocation_ids: list<string>}
     */
    public static function openCommand(string $ciphertext): array
    {
        $decoded = self::decode(Crypt::decryptString($ciphertext), false);
        if (! $decoded instanceof stdClass) {
            throw new RuntimeException('Contenu chiffré de synchronisation illisible.');
        }
        $payloadObject = $decoded->payload ?? new stdClass;
        $payload = self::decode(Jcs::encode($payloadObject), true);
        $rawIds = $decoded->stock_allocation_ids ?? [];
        $ids = is_array($rawIds) ? array_values(array_filter($rawIds, is_string(...))) : [];

        return [
            'payload' => is_array($payload) ? $payload : [],
            'payload_jcs' => Jcs::encode($payloadObject),
            'stock_allocation_ids' => $ids,
        ];
    }

    /**
     * Instantané d'un conflit (commande locale, ou état central et refus), chiffré.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function sealSnapshot(array $snapshot): string
    {
        return Crypt::encryptString(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public static function openSnapshot(string $ciphertext): array
    {
        $decoded = self::decode(Crypt::decryptString($ciphertext), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function decode(string $json, bool $associative): mixed
    {
        try {
            return json_decode($json, $associative, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $error) {
            throw new RuntimeException('Contenu chiffré de synchronisation illisible.', 0, $error);
        }
    }
}
