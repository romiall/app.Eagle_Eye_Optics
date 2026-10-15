<?php

namespace App\Domain\Shared;

use Carbon\CarbonImmutable;

/**
 * Autorisation d'un profil sur un poste, telle qu'elle a été signée à la préparation (table
 * `offline_grants` ; contrat F06 `OfflineGrant`). Immuable : une révocation s'ajoute (`revoked_at`), elle ne
 * réécrit jamais le grant (fichier 21, « Grants »).
 *
 * Sert de contexte d'autorisation HISTORIQUE d'une commande synchronisée : ce que le profil avait le droit de
 * faire au moment où le poste a agi, pas ce que ses droits actuels permettraient aujourd'hui.
 */
final readonly class GrantSnapshot
{
    /**
     * @param  list<string>  $permissions  `permissions_snapshot` : codes du référentiel `permissions`
     */
    public function __construct(
        public string $grantId,
        public string $deviceId,
        public string $userId,
        public array $permissions,
        public string $rulesVersion,
        public string $catalogVersion,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $validUntil,
        public ?CarbonImmutable $revokedAt = null,
    ) {}

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * @param  object{id: string, device_id: string, user_id: string, permissions_snapshot: mixed, rules_version: string, catalog_version: string, issued_at: string, valid_until: string, revoked_at: ?string}  $row  ligne `offline_grants`
     */
    public static function fromRow(object $row): self
    {
        $decoded = is_string($row->permissions_snapshot) ? json_decode($row->permissions_snapshot, true) : $row->permissions_snapshot;
        $permissions = is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];

        return new self(
            grantId: (string) $row->id,
            deviceId: (string) $row->device_id,
            userId: (string) $row->user_id,
            permissions: $permissions,
            rulesVersion: (string) $row->rules_version,
            catalogVersion: (string) $row->catalog_version,
            issuedAt: CarbonImmutable::parse((string) $row->issued_at, 'UTC'),
            validUntil: CarbonImmutable::parse((string) $row->valid_until, 'UTC'),
            revokedAt: $row->revoked_at === null ? null : CarbonImmutable::parse((string) $row->revoked_at, 'UTC'),
        );
    }
}
