<?php

namespace App\Domain\Access\Actions;

use App\Application\Command;
use App\Application\CommandBus;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Access\Handlers\SetStaffPermissionOverrideHandler;
use App\Domain\Access\StaffAdministration;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Dérogation nominative datée et motivée (fichier 09 : « toute permission supplémentaire est attribuée
 * explicitement, datée et journalisée »). ALLOW ajoute, DENY retire malgré le profil, CLEAR supprime la
 * dérogation (l'historique reste dans l'audit). Pas de dérogation sur son propre compte.
 *
 * users.manage n'est jamais délégable (fichier 09 : gestion des utilisateurs réservée à la gérante) : une
 * dérogation temporaire suffirait sinon à réinitialiser le mot de passe d'une gérante, à s'attribuer le
 * profil Gérante et à garder le contrôle après expiration ; un DENY sur une gérante pourrait laisser le
 * cabinet sans aucun compte capable de gérer les droits. Seuls l'ajout ou le retrait du profil Gérante,
 * sous l'invariant « au moins une gérante active », modifient ce droit ; CLEAR d'une ligne existante reste
 * possible (retour aux droits du profil).
 *
 * Cette classe valide la saisie ; l'écriture est SetStaffPermissionOverrideHandler, exécuté par le chemin
 * central de commandes (F07).
 */
final class SetStaffPermissionOverride
{
    public const INSTANT_PATTERN = '/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\.[0-9]{3}Z\z/';

    public function __construct(
        private readonly CommandBus $bus,
        private readonly EffectivePermissions $permissions,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, string $userId, array $input): User
    {
        $clinicId = $actor->clinic_id;

        if ($userId === $actor->id) {
            throw new BusinessError('SELF_OVERRIDE_FORBIDDEN', 'Une dérogation ne s\'applique pas à son propre compte.', 409);
        }

        $data = Validator::make($input, [
            'permission_code' => ['required', 'string', Rule::in($this->permissions->known())],
            'effect' => ['required', 'string', Rule::in(['ALLOW', 'DENY', 'CLEAR'])],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            // Instant UTC du contrat (2026-10-16T17:00:00.000Z) : jamais une date civile convertie
            // implicitement à minuit UTC (contrat 23).
            'expires_at' => ['nullable', 'string', 'regex:'.self::INSTANT_PATTERN, 'date', 'after:now'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ], [
            'expires_at.regex' => 'La date d\'expiration est un instant UTC complet (AAAA-MM-JJTHH:MM:SS.mmmZ).',
        ])->validate();

        // CLEAR reste admis : supprimer une dérogation ne peut que revenir aux droits du profil.
        if ($data['permission_code'] === StaffAdministration::MANAGE_PERMISSION && $data['effect'] !== 'CLEAR') {
            throw BusinessError::validation(['permission_code' => ['La gestion des comptes est réservée au profil Gérante : elle ne s\'attribue ni ne se retire par dérogation.']]);
        }

        $this->bus->dispatch(
            new Command(SetStaffPermissionOverrideHandler::COMMAND, [
                'permission_code' => (string) $data['permission_code'],
                'effect' => (string) $data['effect'],
                'reason' => (string) $data['reason'],
                'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            ], $userId, (int) $data['expected_version']),
            CommandContext::direct($actor, AssignCorrelationId::current(request())),
        );

        return User::query()->where('clinic_id', $clinicId)->findOrFail($userId);
    }
}
