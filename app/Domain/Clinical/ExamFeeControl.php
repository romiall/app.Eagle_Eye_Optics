<?php

namespace App\Domain\Clinical;

use App\Application\Locking\TransactionLocks;
use stdClass;

/**
 * Contrôle du paiement de l'examen avant le démarrage d'une consultation (fichier 02 : « facture examen soldée
 * ou dérogation gérante enregistrée avec motif »).
 *
 * Premier jalon clinique : B04 (encaissement) et B06 (approbations) n'existent pas, ce contrôle est donc
 * INACTIF et le dit (`UncontrolledExamFee`, statut `NOT_CONTROLLED`) : aucun interrupteur de configuration ne
 * permet de « faire comme si ». `EEO_EXAM_FEE_CONTROL=billed` le remplace par `App\Domain\Billing\BilledExamFeeControl`
 * (facture de frais de la visite réglée, ou dispense exacte de la gérante, B04 et B06).
 */
interface ExamFeeControl
{
    /**
     * @param  stdClass  $visit  ligne `visits` verrouillée
     * @param  array<string, mixed>  $charge  charge de `StartConsultation`
     * @param  TransactionLocks|null  $locks  verrous de la transaction du démarrage : la dispense consommée est prise au
     *                                        rang « approbation » (le dernier) ; null hors chemin central (tests unitaires)
     */
    public function decide(stdClass $visit, array $charge, ?TransactionLocks $locks = null): ExamFeeDecision;
}
