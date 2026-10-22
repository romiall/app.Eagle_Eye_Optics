<?php

namespace App\Domain\Billing;

use App\Domain\Shared\CanonicalJson;

/**
 * Dérogation de la gérante au règlement des frais d'examen (fichier 03 : « Le démarrage exige solde nul et
 * encaissement confirmé, ou une dérogation spécifique ») : elle est une APPROBATION liée au contenu exact de ce qu'elle
 * autorise, ici le démarrage de l'examen de CETTE visite pour CE patient, consommable une seule fois.
 *
 * Ce fichier fixe le contrat que la demande d'approbation (B06, `RequestApproval`) doit respecter pour que
 * `BilledExamFeeControl` l'accepte : `action_code`, `target_type = visits`, `target_id` = la visite, `payload_hash`
 * = `payloadHash(visite, patient)`. Aucune dérogation n'est jamais « générale » ni transférable à une autre visite.
 */
final class ExamFeeWaiver
{
    /** Code d'action de l'approbation : la permission que la dérogation contourne (encaisser les frais d'examen). */
    public const ACTION_CODE = 'exam_fee.collect';

    public const TARGET_TYPE = 'visits';

    public static function payloadHash(string $visitId, string $patientId): string
    {
        return CanonicalJson::hash(['action' => 'EXAM_FEE_WAIVER', 'visit_id' => $visitId, 'patient_id' => $patientId]);
    }
}
