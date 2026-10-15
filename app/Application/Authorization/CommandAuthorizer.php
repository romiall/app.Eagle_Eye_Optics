<?php

namespace App\Application\Authorization;

use App\Application\CommandDefinition;
use App\Application\CommandHandler;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\GrantSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Autorisation d'une commande, à deux moments :
 *
 *  - `precheck` : avant toute transaction, sans verrou, pour refuser vite (source non admise, compte sans
 *    droit) sans faire attendre les autres sur le verrou du cabinet ;
 *  - `authorizeLocked` : sous le verrou exclusif du cabinet, droits RELUS en base. Un compte désactivé, ou
 *    dont un droit a été retiré pendant que sa requête attendait le verrou, ne peut plus agir (même
 *    garantie que StaffAdministration, désormais commune à toutes les commandes).
 *
 * Route directe : droits effectifs actuels. Synchronisation : contexte historique du grant (voir
 * HistoricalAuthorization). Tâche planifiée : identité de service, admise par l'enregistrement de la commande.
 */
final class CommandAuthorizer
{
    public function __construct(private readonly EffectivePermissions $permissions) {}

    public function precheck(CommandContext $context, CommandDefinition $definition, CommandHandler $handler): void
    {
        if (! in_array($context->source, $definition->sources, true)) {
            throw BusinessError::forbidden();
        }
        if ($context->source !== CommandSource::Direct) {
            return;
        }

        $user = $context->user ?? throw new LogicException('Une commande DIRECT exige le compte connecté dans son contexte.');
        if (! $this->holdsAny($user, $handler->permissions())) {
            throw BusinessError::forbidden();
        }
    }

    public function authorizeLocked(CommandContext $context, CommandHandler $handler): void
    {
        match ($context->source) {
            CommandSource::Direct => $this->authorizeDirectLocked($context, $handler),
            CommandSource::Sync => $this->authorizeSyncLocked($context, $handler),
            CommandSource::Job => null,
        };
    }

    private function authorizeDirectLocked(CommandContext $context, CommandHandler $handler): void
    {
        /** @var User|null $fresh */
        $fresh = User::query()->where('clinic_id', $context->clinicId)->find($context->actorId);
        if ($fresh === null || ! $fresh->active) {
            throw BusinessError::forbidden();
        }

        $this->permissions->forget($fresh);
        if (! $this->holdsAny($fresh, $handler->permissions())) {
            throw BusinessError::forbidden();
        }
    }

    private function authorizeSyncLocked(CommandContext $context, CommandHandler $handler): void
    {
        $claimed = $context->grant ?? throw new LogicException('Une commande SYNC exige le grant de son poste dans son contexte.');

        /** @var object{id: string, device_id: string, user_id: string, permissions_snapshot: mixed, rules_version: string, catalog_version: string, issued_at: string, valid_until: string, revoked_at: ?string}|null $row */
        $row = DB::table('offline_grants')->where('clinic_id', $context->clinicId)->where('id', $claimed->grantId)->first();
        if ($row === null) {
            throw new BusinessError('GRANT_EXPIRED_OR_REVOKED', 'Autorisation du poste inconnue de ce cabinet.', 403);
        }

        // Le grant relu en base fait foi (révocation enregistrée depuis le chargement du contexte).
        $verdict = HistoricalAuthorization::decide(
            GrantSnapshot::fromRow($row),
            $context,
            $handler->permissions(),
            (int) config('eeo.offline.max_clock_skew_seconds', 300),
        );

        match ($verdict->decision) {
            AuthorizationDecision::Allowed => null,
            AuthorizationDecision::Denied => throw new BusinessError('GRANT_EXPIRED_OR_REVOKED', $verdict->reason, 403),
            AuthorizationDecision::NeedsReview => throw new BusinessError('AUTHORIZATION_REVIEW_REQUIRED', $verdict->reason, 409),
        };
    }

    /**
     * @param  list<string>  $required
     */
    private function holdsAny(User $user, array $required): bool
    {
        if ($required === []) {
            throw new LogicException('Une commande doit déclarer au moins une permission.');
        }

        $effective = $this->permissions->for($user);

        return array_intersect($required, $effective) !== [];
    }
}
