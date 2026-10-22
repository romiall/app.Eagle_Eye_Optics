<?php

namespace App\Domain\Billing;

use App\Domain\Shared\BusinessError;

/**
 * Refus métier de la facturation qui portent un code du catalogue d'erreurs (fichier 08,
 * `contracts/schemas/error.json`) sans avoir de fabrique dans `BusinessError`. Chacun est un refus SANS effet : la
 * transaction est annulée en entier.
 */
final class BillingErrors
{
    /**
     * Une opération qui exige l'autorisation exacte de la gérante (remise ou prix hors droit, dérogation). Les
     * approbations (B06) ne sont pas encore ouvertes : le refus dit ce qu'il faudra obtenir, il ne contourne rien.
     *
     * @param  array<string, list<string>>  $fields
     */
    public static function approvalRequired(array $fields, string $message = 'Cette opération exige l\'autorisation exacte de la gérante.'): BusinessError
    {
        return new BusinessError('APPROVAL_REQUIRED_OR_EXPIRED', $message, 422, $fields);
    }

    /**
     * Démarrage d'examen sans règlement des frais de CETTE visite ni dérogation de la gérante.
     *
     * @param  array<string, list<string>>  $fields
     */
    public static function examPaymentRequired(array $fields, string $message): BusinessError
    {
        return new BusinessError('EXAM_PAYMENT_REQUIRED', $message, 422, $fields);
    }

    /**
     * La facture est détenue par un poste hors-ligne : aucune autre caisse ni l'API ne l'encaisse tant que son
     * droit est actif (fichier 19, « Un seul rédacteur financier par facture »).
     */
    public static function writeOwnerRequired(string $deviceName): BusinessError
    {
        return new BusinessError(
            'WRITE_OWNER_REQUIRED',
            'Encaissement disponible sur la caisse « '.$deviceName.' » : cette facture est détenue par ce poste tant que son droit est actif.',
            409,
        );
    }

    /**
     * Version attendue périmée : `current_version` est la version à reprendre, `extra` les valeurs actualisées utiles
     * à l'écran (reste dû, par exemple).
     *
     * @param  array<string, int|string|null>  $extra
     */
    public static function versionConflict(int $currentVersion, array $extra = [], string $message = 'Une version plus récente existe : actualisez avant de modifier.'): BusinessError
    {
        return new BusinessError('VERSION_CONFLICT', $message, 409, [], null, [], ['current_version' => $currentVersion] + $extra);
    }
}
