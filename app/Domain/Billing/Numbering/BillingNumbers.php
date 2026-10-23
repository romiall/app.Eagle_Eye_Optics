<?php

namespace App\Domain\Billing\Numbering;

use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\DocumentNumbers;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Numéros des pièces financières (fichier 03, « Numérotation et émission ») : facture, reçu, avoir, remboursement.
 *
 * Format PROPOSÉ par le fichier 03 : `<préfixe>-AAAA-NNNNNN` (série par cabinet et par année civile du fuseau du
 * cabinet, six chiffres au moins, jamais tronqué au-delà). Les préfixes (`eeo.numbering.prefixes`) sont ceux des
 * séries papier à choisir avec la gérante AVANT la bascule (Q03, Q05) : tant qu'ils ne le sont pas, ils valent
 * `TEST-FAC`, `TEST-REC`, `TEST-AV`, `TEST-RMB`, `TEST-REP` (reprise de créance papier) et `TEST-DEV` (devis), donc
 * tout numéro émis
 * est visiblement un numéro de TEST. Un
 * préfixe vide ou une série inconnue est refusé : aucun format n'est inventé en silence.
 *
 * Le numéro est pris dans `document_sequences` sous le verrou exclusif du cabinet puis celui de la série (rang 40),
 * enregistré dans `document_number_usages` dans la MÊME transaction que la pièce : aucun trou en cas d'échec (tout
 * est annulé), aucun recyclage, jamais `MAX + 1` sans verrou. Le numéro d'une pièce ne change jamais, y compris à
 * la synchronisation d'un poste (plages disjointes : O03).
 */
final class BillingNumbers
{
    public const INVOICE = 'INVOICE';

    public const RECEIPT = 'RECEIPT';

    public const CREDIT_NOTE = 'CREDIT_NOTE';

    public const REFUND = 'REFUND';

    /** Reprise d'une créance papier (X01) : pièce `LEGACY_BALANCE`, série distincte des factures du jour. */
    public const LEGACY_BALANCE = 'LEGACY_BALANCE';

    /**
     * Devis (L1D07) : série distincte, jamais un numéro de facture. Un devis n'est ni une créance ni un encaissement ;
     * il est numéroté par la même mécanique (sans trou, sous verrou) parce qu'il se remet au client.
     */
    public const QUOTE = 'QUOTE';

    /** Séries numérotées par ce service : pièces financières et devis. */
    public const SERIES = [self::INVOICE, self::RECEIPT, self::CREDIT_NOTE, self::REFUND, self::LEGACY_BALANCE, self::QUOTE];

    /** Longueur maximale d'un numéro rendu (`document_number_usages.rendered_number` VARCHAR(64)). */
    private const MAX_LENGTH = 64;

    public static function prefix(string $type): string
    {
        $prefix = config('eeo.numbering.prefixes.'.$type);
        if (! in_array($type, self::SERIES, true) || ! is_string($prefix) || trim($prefix) === '') {
            throw new LogicException('Préfixe de numérotation non défini pour la série '.$type.' : à valider avec le cabinet (Q03).');
        }

        return trim($prefix);
    }

    public static function render(string $type, int $year, int $value): string
    {
        $width = max(1, (int) config('eeo.numbering.width', 6));
        $rendered = sprintf('%s-%04d-%s', self::prefix($type), $year, str_pad((string) $value, $width, '0', STR_PAD_LEFT));
        if (mb_strlen($rendered) > self::MAX_LENGTH) {
            throw new LogicException('Numéro de pièce trop long pour la série '.$type.' : préfixe à raccourcir.');
        }

        return $rendered;
    }

    /**
     * Attribue le numéro suivant de la série (route directe : numéro central hors de toute plage réservée à un poste).
     * `$at` est l'instant de l'émission, relu APRÈS le verrou du cabinet : il fixe l'année de la série.
     *
     * @return array{value: int, rendered: string, year: int}
     */
    public static function allocate(TransactionLocks $locks, CommandContext $context, string $type, string $timezone, CarbonImmutable $at): array
    {
        return DocumentNumbers::allocateCentral(
            $locks,
            $context,
            $type,
            $timezone,
            $at,
            static fn (string $series, int $year, int $value): string => self::render($series, $year, $value),
        );
    }
}
