<?php

namespace App\Domain\Cash\Rules;

use App\Domain\Shared\ValueObjects\Money;

/**
 * Espèces théoriques d'une session de caisse et écart de clôture (fichier 03, « Caisse et indicateurs ») :
 *
 *   espèces_théoriques = ouverture + encaissements espèces − contre-écritures espèces − remboursements espèces
 *                        + mouvements autorisés (entrées positives, sorties négatives)
 *   écart              = montant compté − espèces_théoriques
 *
 * C'est la formule de la vue `vw_cash_session_totals` (`recomputed_cash_amount`). Les paiements par mobile money, virement
 * ou chèque ne sont PAS dans le tiroir : ils ont leurs propres rapprochements. Fonctions pures, entiers exacts.
 * La clôture fige l'attendu, le compté et l'écart ; une correction ultérieure d'une ancienne erreur ne réécrit jamais
 * cette photographie (la vue montre à part les contre-écritures postérieures à la clôture).
 */
final class CashClosing
{
    public static function theoretical(int $opening, int $cashReceived, int $cashReversed, int $cashRefunded, int $authorizedMovements): int
    {
        return Money::signed($opening + $cashReceived - $cashReversed - $cashRefunded + $authorizedMovements, 'expected_amount');
    }

    public static function variance(int $counted, int $expected): int
    {
        Money::amount($counted, 'counted_amount');

        return Money::signed($counted - $expected, 'variance');
    }
}
