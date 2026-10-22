<?php

namespace App\Domain\Clinical;

use stdClass;

/**
 * Contrôle du paiement de l'examen avant le démarrage d'une consultation (fichier 02 : « facture examen soldée
 * ou dérogation gérante enregistrée avec motif »).
 *
 * Premier jalon clinique : B04 (encaissement) et B06 (approbations) n'existent pas, ce contrôle est donc
 * INACTIF et le dit (`UncontrolledExamFee`, statut `NOT_CONTROLLED`) : aucun interrupteur de configuration ne
 * permet de « faire comme si ». B04 et B06 le remplaceront par une implémentation sur la facture de frais et
 * l'approbation de la visite ; `StartConsultationHandler` ne change pas.
 */
interface ExamFeeControl
{
    /**
     * @param  stdClass  $visit  ligne `visits` verrouillée
     * @param  array<string, mixed>  $charge  charge de `StartConsultation`
     */
    public function decide(stdClass $visit, array $charge): ExamFeeDecision;
}
