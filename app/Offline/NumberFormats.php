<?php

namespace App\Offline;

use App\Domain\Billing\Numbering\BillingNumbers;
use App\Domain\Shared\DocumentNumbers;

/**
 * Format de rendu des numéros d'une série, remis au poste préparé avec chaque plage (O03) : le poste rend le numéro
 * qu'il consomme EXACTEMENT comme le serveur (`<préfixe>-AAAA-<valeur complétée à gauche>`), sans connaître la
 * configuration du serveur. Dossier patient : `EE-AAAA-NNNN` (DocumentNumbers) ; pièces financières : préfixes et
 * largeur de `eeo.numbering` (BillingNumbers, préfixes de TEST tant que la gérante n'a pas validé ses séries).
 */
final class NumberFormats
{
    /**
     * @return array{prefix: string, width: int}
     */
    public static function of(string $type): array
    {
        if ($type === DocumentNumbers::PATIENT) {
            return ['prefix' => 'EE', 'width' => 4];
        }

        return ['prefix' => BillingNumbers::prefix($type), 'width' => max(1, (int) config('eeo.numbering.width', 6))];
    }

    public static function render(string $type, int $year, int $value): string
    {
        return $type === DocumentNumbers::PATIENT
            ? DocumentNumbers::render($type, $year, $value)
            : BillingNumbers::render($type, $year, $value);
    }
}
