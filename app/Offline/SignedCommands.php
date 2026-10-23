<?php

namespace App\Offline;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use stdClass;
use UnexpectedValueException;

/**
 * Vérification de l'ORIGINE d'une commande signée reçue d'un poste (O05 ; contrat 23, « Signature et chiffrement »), sans
 * aucune lecture ni écriture en base :
 *
 *  1. le cœur appartient à ce cabinet et au poste qui envoie le lot ;
 *  2. `event_hash` est EXACTEMENT le SHA-256 du JCS du cœur reçu (objets JSON conservés tels quels, un objet vide reste
 *     `{}`), recalculé ici : une commande altérée en transit ou sur le poste est refusée ;
 *  3. la signature est un JWS compact ES256 de la clé ENRÔLÉE du poste : en-tête exact `{alg, kid, typ}`, `alg = ES256`
 *     (jamais `none`), `typ = eeo-command+jws`, `kid` = empreinte RFC 7638 de cette clé (décision O02, alignée par O05 :
 *     le contrat déclarait un ULID), charge JCS exactement `{command_id, device_id, event_hash}` de cette commande ;
 *     aucune clé n'est lue dans le jeton ;
 *  4. la commande appartient au journal d'un poste (`contracts/compartments.json`).
 *
 * Le chaînage (`previous_event_hash`, rangs sans trou) et le grant sont contrôlés ensuite par `PushCommands`, qui
 * connaît l'inbox et les grants du poste.
 */
final class SignedCommands
{
    public const JWS_TYPE = 'eeo-command+jws';

    private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/';

    /**
     * @param  array{kty: string, crv: string, x: string, y: string}  $publicJwk  clé publique enrôlée du poste (`enrolled_devices.public_key_jwk`)
     *
     * @throws UnexpectedValueException avec un message lisible (aucune donnée de la charge)
     * @throws InvalidArgumentException valeur du cœur sans forme JCS (nombre à virgule, texte UTF-8 invalide)
     */
    public static function verify(mixed $signed, string $clinicId, string $deviceId, array $publicJwk): VerifiedCommand
    {
        if (! $signed instanceof stdClass || ! ($signed->core ?? null) instanceof stdClass) {
            throw new UnexpectedValueException('Commande signée illisible : cœur absent.');
        }
        $core = $signed->core;
        if (($core->clinic_id ?? null) !== $clinicId) {
            throw new UnexpectedValueException('Commande d\'un autre cabinet.');
        }
        if (($core->device_id ?? null) !== $deviceId) {
            throw new UnexpectedValueException('Commande d\'un autre poste que celui du lot.');
        }

        $hash = Jcs::sha256($core);
        if (! is_string($signed->event_hash ?? null) || ! hash_equals($hash, $signed->event_hash)) {
            throw new UnexpectedValueException('L\'empreinte transmise ne correspond pas au cœur de la commande : commande altérée.');
        }

        $kid = ServerSigningKey::thumbprint($publicJwk);
        $claims = CompactJws::verify(is_string($signed->signature ?? null) ? $signed->signature : '', $publicJwk, $kid, self::JWS_TYPE);
        $keys = array_keys($claims);
        sort($keys);
        if ($keys !== ['command_id', 'device_id', 'event_hash']) {
            throw new UnexpectedValueException('Charge signée inattendue : {command_id, device_id, event_hash} exigé.');
        }
        if ($claims['command_id'] !== ($core->command_id ?? null) || $claims['device_id'] !== $deviceId || $claims['event_hash'] !== $hash) {
            throw new UnexpectedValueException('La signature porte sur une autre commande.');
        }

        $name = is_string($core->command_name ?? null) ? $core->command_name : '';
        if (Compartments::ofCommand($name) === null) {
            throw new UnexpectedValueException('Commande hors du journal d\'un poste.');
        }
        $sequence = is_string($core->device_sequence ?? null) && preg_match('/^[1-9][0-9]{0,17}\z/', $core->device_sequence) === 1 ? (int) $core->device_sequence : 0;
        if ($sequence < 1) {
            throw new UnexpectedValueException('Rang de la commande dans le journal du poste illisible.');
        }
        $previous = $core->previous_event_hash ?? null;
        if ($previous !== null && (! is_string($previous) || preg_match('/^[0-9a-f]{64}\z/', $previous) !== 1)) {
            throw new UnexpectedValueException('Empreinte précédente illisible.');
        }
        $expected = $core->expected_version ?? null;
        $allocation = $core->number_allocation_id ?? null;
        $quotas = is_array($core->stock_allocation_ids ?? null) ? array_values(array_filter($core->stock_allocation_ids, is_string(...))) : [];

        return new VerifiedCommand(
            commandId: (string) $core->command_id,
            commandName: $name,
            deviceId: $deviceId,
            actorId: (string) ($core->actor_id ?? ''),
            grantId: (string) ($core->grant_id ?? ''),
            deviceSequence: $sequence,
            previousEventHash: $previous,
            eventHash: $hash,
            signature: (string) $signed->signature,
            schemaVersion: (string) ($core->schema_version ?? ''),
            rulesVersion: (string) ($core->rules_version ?? ''),
            occurredAtLocal: self::instant($core->occurred_at_local ?? null, 'occurred_at_local'),
            lastServerTime: self::instant($core->last_server_time ?? null, 'last_server_time'),
            entityType: (string) ($core->entity_type ?? ''),
            entityId: (string) ($core->entity_id ?? ''),
            expectedVersion: is_int($expected) ? $expected : null,
            numberAllocationId: is_string($allocation) ? $allocation : null,
            stockAllocationIds: $quotas,
            payload: $core->payload ?? new stdClass,
        );
    }

    /**
     * Instant du cœur, restitué EXACTEMENT (milliseconde) pour reconstituer le même cœur et la même empreinte.
     */
    public static function format(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function instant(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::INSTANT, $value) !== 1) {
            throw new UnexpectedValueException('Instant '.$field.' illisible.');
        }

        return CarbonImmutable::parse($value, 'UTC');
    }
}
