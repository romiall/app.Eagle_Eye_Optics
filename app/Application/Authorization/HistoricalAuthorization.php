<?php

namespace App\Application\Authorization;

use App\Domain\Shared\CommandContext;
use App\Domain\Shared\GrantSnapshot;

/**
 * Contexte d'autorisation HISTORIQUE d'une commande de poste (contrat 23) : ce que le profil pouvait faire au
 * moment où le poste a agi, pas ce que ses droits actuels permettraient. Une révocation d'aujourd'hui ne
 * détruit pas une pièce valable hier ; une action qui contredit l'horloge du serveur, ou qui tombe autour d'une
 * révocation sans que le poste l'ait pu connaître, passe en revue au lieu d'être acceptée ou rejetée à tort.
 *
 * Fonction pure (aucune base, aucune horloge système) : l'heure du serveur est `context->now`. Les règles
 * d'horloge ci-dessous posent la structure ; la tâche O02 les confirmera avec la préparation du poste
 * (« horloge suspecte », révocation différée).
 *
 *  1. Identité : le grant est celui de cet appareil et de cet auteur, sinon refus (substitution).
 *  2. Droit : une des permissions de la commande figure dans `permissions_snapshot`, sinon refus.
 *  3. Horloge : l'instant déclaré par le poste n'est ni antérieur à la dernière heure serveur qu'il connaissait
 *     (antidatage), ni postérieur à l'heure du serveur (horloge avancée), à la tolérance près : revue.
 *  4. Fenêtre : l'instant est dans [émission, validité], à la tolérance près ; avant l'émission : revue ;
 *     après la validité : refus (le moteur local refuse d'agir avec un grant expiré).
 *  5. Révocation : si le poste avait déjà synchronisé après la révocation, il la connaissait : refus. Sinon,
 *     une action antérieure à la révocation (tolérance comprise) reste valable ; toute autre est en revue.
 */
final class HistoricalAuthorization
{
    /**
     * @param  list<string>  $requiredPermissions  l'une d'elles suffit
     */
    public static function decide(GrantSnapshot $grant, CommandContext $context, array $requiredPermissions, int $maxClockSkewSeconds): AuthorizationVerdict
    {
        $occurred = $context->occurredAtLocal;
        $lastServerTime = $context->lastServerTime;

        if ($context->deviceId === null || $occurred === null || $lastServerTime === null) {
            return AuthorizationVerdict::denied('Contexte de poste incomplet : appareil ou horloge manquants.');
        }
        if ($grant->deviceId !== $context->deviceId || $grant->userId !== $context->actorId) {
            return AuthorizationVerdict::denied('Le grant n\'appartient pas à cet appareil et à cet auteur.');
        }
        if ($requiredPermissions === [] || array_filter($requiredPermissions, $grant->allows(...)) === []) {
            return AuthorizationVerdict::denied('Le grant ne comporte pas la permission requise par la commande.');
        }

        $skew = max(0, $maxClockSkewSeconds);

        if ($occurred < $lastServerTime->subSeconds($skew)) {
            return AuthorizationVerdict::needsReview('L\'horloge du poste est antérieure à la dernière heure serveur qu\'il connaissait (antidatage possible).');
        }
        if ($occurred > $context->now->addSeconds($skew)) {
            return AuthorizationVerdict::needsReview('L\'horloge du poste est en avance sur l\'heure du serveur.');
        }
        if ($occurred < $grant->issuedAt->subSeconds($skew)) {
            return AuthorizationVerdict::needsReview('L\'action déclarée précède l\'émission du grant.');
        }
        if ($occurred > $grant->validUntil->addSeconds($skew)) {
            return AuthorizationVerdict::denied('Le grant était expiré à l\'instant déclaré de l\'action.');
        }

        if ($grant->revokedAt !== null) {
            if ($lastServerTime >= $grant->revokedAt) {
                return AuthorizationVerdict::denied('Le poste avait synchronisé après la révocation du grant : il en avait connaissance.');
            }
            if ($occurred <= $grant->revokedAt->subSeconds($skew)) {
                return AuthorizationVerdict::allowed();
            }

            return AuthorizationVerdict::needsReview('L\'action tombe autour de la révocation du grant sans que le poste ait pu la connaître.');
        }

        return AuthorizationVerdict::allowed();
    }
}
