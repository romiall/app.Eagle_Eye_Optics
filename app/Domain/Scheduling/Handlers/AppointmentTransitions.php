<?php

namespace App\Domain\Scheduling\Handlers;

use App\Application\Command;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use stdClass;

/**
 * Préalables communs aux changements d'un rendez-vous existant (déplacer, annuler, constater l'absence) : en ligne
 * seulement, rendez-vous du cabinet lu sous verrou (rang « rendez-vous »), version attendue (409 `VERSION_CONFLICT`
 * avec `current_version`), statut encore modifiable (409 `STATE_TRANSITION_REFUSED` avec `current_status`).
 */
final class AppointmentTransitions
{
    /** Un rendez-vous se déplace, s'annule ou se constate absent tant qu'il n'a pas eu lieu. */
    public const CHANGEABLE = ['PENDING_CONFIRMATION', 'CONFIRMED'];

    public static function locked(Command $command, CommandContext $context, TransactionLocks $locks): stdClass
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Un rendez-vous se modifie en ligne : les autres calendriers ne sont pas connus hors ligne.');
        }
        $appointment = $locks->appointment((string) $command->entityId);
        if ($appointment === null) {
            throw BusinessError::notFound('Rendez-vous introuvable dans ce cabinet.');
        }
        if ($command->expectedVersion === null || (int) $appointment->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'Le rendez-vous a changé depuis l\'affichage : l\'agenda est relu.', 409, [], null, [], ['current_version' => (int) $appointment->version]);
        }
        $status = (string) $appointment->status;
        if (! in_array($status, self::CHANGEABLE, true)) {
            throw BusinessError::stateRefused(
                match ($status) {
                    'CANCELLED' => 'Ce rendez-vous est déjà annulé.',
                    'NO_SHOW' => 'L\'absence à ce rendez-vous est déjà constatée.',
                    default => 'Le patient est arrivé ou le rendez-vous est terminé : il ne se modifie plus.',
                },
                $status,
            );
        }

        return $appointment;
    }
}
