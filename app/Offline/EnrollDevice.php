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
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Enrôlement d'un poste (O02 ; fichier 19, « Préparation du poste », étapes 1 et 3) par la gérante réauthentifiée
 * (`offline.enroll`, la route exige la réauthentification). Le poste a généré sa paire ES256 dans son coffre local ;
 * il envoie sa clé PUBLIQUE et la preuve qu'il détient la clé privée (`DeviceProof`). Le serveur attribue le
 * `device_id`, conserve la clé publique (jamais une clé privée : « clés privées non présentes dans MySQL »), le
 * responsable du poste et la version de l'application.
 *
 * Refus (422 par champ, jamais une erreur SQL) : code de poste déjà enrôlé dans le cabinet, clé publique déjà
 * enrôlée, responsable inconnu ou inactif, preuve de possession absente, invalide ou périmée. En ligne seulement : un
 * enrôlement n'entre jamais dans le journal d'un poste.
 *
 * Aucun droit hors-ligne n'est accordé ici : les grants signés sont émis ensuite, par profil (`PrepareGrant`).
 */
final class EnrollDevice implements CommandHandler
{
    public const COMMAND = 'EnrollDevice';

    public function permissions(): array
    {
        return ['offline.enroll'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('L\'enrôlement d\'un poste se fait en ligne, par la gérante.');
        }
        $payload = $command->payload;
        $deviceCode = trim((string) ($payload['device_code'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $responsibleId = (string) ($payload['responsible_user_id'] ?? '');
        $appVersion = trim((string) ($payload['app_version'] ?? ''));
        $proof = (string) ($payload['proof'] ?? '');
        $jwkInput = is_array($payload['public_key_jwk'] ?? null) ? $payload['public_key_jwk'] : [];
        $jwk = [
            'kty' => (string) ($jwkInput['kty'] ?? ''),
            'crv' => (string) ($jwkInput['crv'] ?? ''),
            'x' => (string) ($jwkInput['x'] ?? ''),
            'y' => (string) ($jwkInput['y'] ?? ''),
        ];

        $errors = [];
        if ($deviceCode === '') {
            $errors['device_code'][] = 'Indiquez le code du poste.';
        }
        if ($name === '') {
            $errors['name'][] = 'Indiquez le nom du poste.';
        }
        if ($appVersion === '') {
            $errors['app_version'][] = 'Version de l\'application manquante.';
        }
        if ($jwk['kty'] !== 'EC' || $jwk['crv'] !== 'P-256') {
            $errors['public_key_jwk'][] = 'Clé publique ES256 (EC P-256) exigée.';
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        if (DB::table('enrolled_devices')->where('clinic_id', $context->clinicId)->where('device_code', $deviceCode)->exists()) {
            $errors['device_code'][] = 'Ce code de poste est déjà enrôlé dans le cabinet.';
        }
        $canonicalJwk = Jcs::encode($jwk);
        if (DB::table('enrolled_devices')->where('public_key_jwk', $canonicalJwk)->exists()) {
            $errors['public_key_jwk'][] = 'Cette clé de poste est déjà enrôlée : un poste remplacé reçoit une nouvelle clé.';
        }
        $responsible = DeviceDirectory::underClinicLock('users', $context->clinicId, $responsibleId);
        if ($responsible === null || ! (bool) $responsible->active) {
            $errors['responsible_user_id'][] = 'Le responsable du poste doit être un compte actif du cabinet.';
        }
        if ($errors === []) {
            try {
                DeviceProof::verify($proof, $jwk, $context->clinicId, $deviceCode, $context->now);
            } catch (UnexpectedValueException $refused) {
                $errors['proof'][] = $refused->getMessage();
            }
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        $id = Ulid::generate();
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('enrolled_devices')->insert([
            'id' => $id,
            'clinic_id' => $context->clinicId,
            'device_code' => $deviceCode,
            'name' => $name,
            'public_key_jwk' => $canonicalJwk,
            'key_algorithm' => 'ES256',
            'status' => 'ACTIVE',
            'enrolled_by' => (string) $context->actorId,
            'responsible_user_id' => $responsibleId,
            'enrolled_at' => $now,
            'last_sync_at' => null,
            'last_accepted_sequence' => 0,
            'last_accepted_hash' => null,
            'app_version' => $appVersion,
            'replaced_by_device_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: ['device_id' => $id],
            resultType: 'enrolled_devices',
            resultId: $id,
            audit: [new AuditEntry('device.enrolled', 'enrolled_devices', $id, null, [
                'device_code' => $deviceCode,
                'responsible_user_id' => $responsibleId,
                'app_version' => $appVersion,
                'jwk_thumbprint' => ServerSigningKey::thumbprint($jwk),
            ])],
            changes: [new ChangeRecord('enrolled_devices', $id, 1, ChangeVisibility::Manager, ChangeKind::Created)],
        );
    }
}
