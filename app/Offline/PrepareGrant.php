<?php

namespace App\Offline;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Billing\Catalog\CatalogSnapshot;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RulesVersion;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Émission d'un grant hors-ligne SIGNÉ pour un profil sur un poste enrôlé (O02 ; fichier 19, étape 3 ; fiche O02 :
 * « lier utilisateur, droits, versions, durée et snapshot catalogue privé »). Gérante réauthentifiée
 * (`offline.enroll`). Le grant fige, au moment de l'émission :
 *
 *  - les permissions EFFECTIVES du compte (rôles et dérogations à cet instant) et les compartiments qu'elles ouvrent
 *    (`contracts/compartments.json`) ; un compte sans compartiment est refusé (422 `user_id`) ;
 *  - la version des règles (`RulesVersion::CURRENT`) et l'empreinte du catalogue et des taxes appliqués
 *    (`CatalogSnapshot`) : une modification centrale ultérieure ne change pas rétroactivement le prix autorisé ;
 *  - la validité : `eeo.offline.grant_hours` (72 h par défaut, fichier 19), ou la durée demandée, bornée par
 *    `eeo.offline.grant_max_hours`.
 *
 * Rotation : un nouveau grant du même compte sur le même poste révoque (`revoked_at`) les grants encore actifs de ce
 * couple dans la même transaction ; le jeton déjà remis au poste reste immuable, la révocation n'atteint le poste
 * qu'à sa prochaine connexion (« révocation différée », fichier 19). Poste révoqué, perdu ou remplacé : 409.
 * Clé de signature du serveur absente : 409 (l'exploitant lance `eeo:offline:signing-key`), rien n'est écrit.
 *
 * Verrous : le cabinet (exclusif) et l'idempotence pris par le chemin central ; compte et poste lus sous ce verrou.
 */
final class PrepareGrant implements CommandHandler
{
    public const COMMAND = 'IssueOfflineGrant';

    public function __construct(private readonly EffectivePermissions $permissions, private readonly ServerSigningKey $key) {}

    public function permissions(): array
    {
        return ['offline.enroll'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Un grant hors-ligne s\'émet en ligne, par la gérante.');
        }
        $deviceId = (string) $command->entityId;
        $userId = (string) ($command->payload['user_id'] ?? '');
        $requestedHours = $command->payload['valid_hours'] ?? null;

        // Sous le verrou du cabinet (voir DeviceDirectory::underClinicLock : pas de verrou de rang 15/20 après l'idempotence).
        $account = DeviceDirectory::underClinicLock('users', $context->clinicId, $userId);
        $device = DeviceDirectory::underClinicLock('enrolled_devices', $context->clinicId, $deviceId);
        if ($device === null) {
            throw BusinessError::notFound('Poste introuvable.');
        }
        if ((string) $device->status !== 'ACTIVE') {
            throw BusinessError::stateRefused('Ce poste n\'est plus actif : aucun grant ne peut lui être émis.', (string) $device->status);
        }
        $user = $account === null ? null : User::query()->where('clinic_id', $context->clinicId)->find($userId);
        if (! $user instanceof User || ! (bool) $user->active) {
            throw BusinessError::validation(['user_id' => ['Le profil à préparer doit être un compte actif du cabinet.']]);
        }

        $maxHours = max(1, (int) config('eeo.offline.grant_max_hours', 168));
        $hours = is_int($requestedHours) ? $requestedHours : (int) config('eeo.offline.grant_hours', 72);
        if ($hours < 1 || $hours > $maxHours) {
            throw BusinessError::validation(['valid_hours' => ['Durée hors des bornes admises (1 à '.$maxHours.' heures).']]);
        }

        $effective = $this->permissions->for($user);
        $compartments = Compartments::of($effective);
        if ($compartments === []) {
            throw BusinessError::validation(['user_id' => ['Ce compte n\'a aucun droit qui ouvre un compartiment du poste : rien à préparer.']]);
        }
        if (! $this->key->exists()) {
            throw BusinessError::stateRefused('La clé de signature des grants n\'est pas installée sur le serveur : l\'exploitant lance « php artisan eeo:offline:signing-key ».', 'SIGNING_KEY_MISSING');
        }

        $grantId = Ulid::generate();
        $issuedAt = $context->now->utc();
        $validUntil = $issuedAt->addHours($hours);
        $catalog = CatalogSnapshot::of($context->clinicId);
        $claims = GrantClaims::build(
            $grantId,
            $context->clinicId,
            $deviceId,
            $userId,
            $effective,
            RulesVersion::CURRENT,
            $catalog['catalog_version'],
            $catalog['sha256'],
            $issuedAt,
            $validUntil,
        );
        try {
            $kid = $this->key->kid();
            $jws = CompactJws::sign($claims, $this->key->privatePem(), $kid, GrantClaims::TYPE);
        } catch (RuntimeException) {
            throw BusinessError::stateRefused('La clé de signature des grants est illisible sur le serveur.', 'SIGNING_KEY_MISSING');
        }

        $now = $issuedAt->format('Y-m-d H:i:s.u');
        $superseded = DB::table('offline_grants')
            ->where('clinic_id', $context->clinicId)
            ->where('device_id', $deviceId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('valid_until', '>', $now)
            ->orderBy('id')
            ->pluck('id')
            ->all();
        if ($superseded !== []) {
            DB::table('offline_grants')->where('clinic_id', $context->clinicId)->whereIn('id', $superseded)->update(['revoked_at' => $now, 'updated_at' => $now]);
        }

        DB::table('offline_grants')->insert([
            'id' => $grantId,
            'clinic_id' => $context->clinicId,
            'device_id' => $deviceId,
            'user_id' => $userId,
            'permissions_snapshot' => json_encode($claims['permissions'], JSON_THROW_ON_ERROR),
            'rules_version' => RulesVersion::CURRENT,
            'catalog_version' => $catalog['catalog_version'],
            'issued_at' => $now,
            'valid_until' => $validUntil->format('Y-m-d H:i:s.u'),
            'signed_payload' => $jws,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $audit = [new AuditEntry('offline_grant.issued', 'offline_grants', $grantId, null, [
            'device_id' => $deviceId,
            'user_id' => $userId,
            'compartments' => $compartments,
            'rules_version' => RulesVersion::CURRENT,
            'catalog_version' => $catalog['catalog_version'],
            'valid_until' => $claims['valid_until'],
            'kid' => $kid,
            'superseded' => $superseded,
        ])];
        $changes = [new ChangeRecord('offline_grants', $grantId, 1, ChangeVisibility::Manager, ChangeKind::Created)];
        foreach ($superseded as $previous) {
            $changes[] = new ChangeRecord('offline_grants', (string) $previous, 2, ChangeVisibility::Manager, ChangeKind::Updated);
        }

        return new CommandOutcome(
            result: ['grant_id' => $grantId, 'device_id' => $deviceId],
            resultType: 'offline_grants',
            resultId: $grantId,
            audit: $audit,
            changes: $changes,
        );
    }
}
