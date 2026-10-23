<?php

namespace App\Offline;

use App\Application\CommandDefinition;
use App\Domain\Shared\CommandSource;

/**
 * Commandes de préparation des postes (O02, O03) enregistrées auprès du chemin central (F07). Toutes EN LIGNE seulement
 * (source directe), hors du journal d'un poste : un poste ne s'enrôle pas, ne s'accorde pas de grant et ne se révoque
 * pas lui-même. Corps validés par les requêtes HTTP du contrat (`schemas/requests/http.json`).
 */
final class OfflineCommands
{
    /**
     * @return list<CommandDefinition>
     */
    public static function definitions(): array
    {
        $onlineOnly = [CommandSource::Direct];

        return [
            new CommandDefinition(EnrollDevice::COMMAND, EnrollDevice::class, $onlineOnly, null, true, 'schemas/requests/http.json#/$defs/EnrollDeviceRequest'),
            new CommandDefinition(PrepareGrant::COMMAND, PrepareGrant::class, $onlineOnly, null, true, 'schemas/requests/http.json#/$defs/IssueOfflineGrantRequest'),
            new CommandDefinition(RevokeDevice::COMMAND, RevokeDevice::class, $onlineOnly, null, true, 'schemas/requests/http.json#/$defs/RevokeDeviceRequest'),
            new CommandDefinition(RevokeOfflineGrant::COMMAND, RevokeOfflineGrant::class, $onlineOnly, null, true, 'schemas/requests/http.json#/$defs/RevokeOfflineGrantRequest'),
            // Ressources du poste (O03) : plages, propriétés de facture et quotas ; restitution après reprise complète.
            new CommandDefinition(PrepareResources::COMMAND, PrepareResources::class, $onlineOnly, null, true, 'schemas/requests/http.json#/$defs/PrepareDeviceRequest'),
            new CommandDefinition(ReleaseResources::COMMAND, ReleaseResources::class, $onlineOnly, null, true, 'schemas/requests/http.json#/$defs/ReleaseDeviceRequest'),
            // Synchronisation (O05) : décision motivée de la gérante sur un conflit d'une commande de poste.
            new CommandDefinition(ResolveConflict::COMMAND, ResolveConflict::class, $onlineOnly, null, true, 'schemas/requests/http.json#/$defs/ConflictResolutionRequest'),
            // Remplacement d'un poste (O07) : déclaration, puis réconciliation après reprise du journal exporté.
            new CommandDefinition(ReplaceDevice::COMMAND, ReplaceDevice::class, $onlineOnly, null, true, 'schemas/requests/http.json#/$defs/ReplaceDeviceRequest'),
            new CommandDefinition(ReconcileReplacement::COMMAND, ReconcileReplacement::class, $onlineOnly, null, true, 'schemas/requests/http.json#/$defs/ReconcileReplacementRequest'),
        ];
    }
}
