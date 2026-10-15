<?php

namespace App\Application\Locking;

/**
 * Rang de chaque verrou dans l'ordre unique de L0 (contrat 23, « Chemin transactionnel central ») :
 * cabinet → appareil si journal → idempotence → séquences/plages → propriété de facture → facture → session de
 * caisse → positions, lots et quotas par identifiant trié → approbation.
 *
 * Toutes les actions, ainsi que les opérations de préparation et de transfert de poste, verrouillent dans
 * cet ordre ; aucun contrôleur ne verrouille en sens inverse. Deux transactions qui prennent leurs verrous
 * dans le même ordre ne peuvent pas s'interbloquer. Les rangs sont espacés pour qu'une insertion future
 * ne renumérote pas l'existant.
 */
enum LockRank: int
{
    case Clinic = 10;
    case Device = 20;
    case Idempotency = 30;
    case Sequence = 40;
    case InvoiceOwner = 50;
    case Invoice = 60;
    case CashSession = 70;
    case StockPosition = 80;
    case StockLot = 81;
    case StockQuota = 82;
    case Approval = 90;

    public function label(): string
    {
        return match ($this) {
            self::Clinic => 'cabinet',
            self::Device => 'appareil',
            self::Idempotency => 'idempotence',
            self::Sequence => 'séquence de numéros',
            self::InvoiceOwner => 'propriété de facture',
            self::Invoice => 'facture',
            self::CashSession => 'session de caisse',
            self::StockPosition => 'position de stock',
            self::StockLot => 'lot de stock',
            self::StockQuota => 'quota de poste',
            self::Approval => 'approbation',
        };
    }
}
