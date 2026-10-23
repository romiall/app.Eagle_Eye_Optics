<?php

namespace App\Domain\Clinical;

use App\Domain\Access\AuditRecorder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Trace des LECTURES de dossiers cliniques (fichier 09 : « les lectures de dossiers sensibles sont tracées sans
 * recopier leurs contenus médicaux »). L'événement `consultation.viewed` vise la consultation et ne porte aucun
 * contenu : qui, quand, quelle consultation. La lecture d'une révision figée est tracée de même
 * (`consultation.revision_viewed`, cible la révision).
 *
 * Toute réponse qui REND le dossier est une lecture : `GET /consultations/{id}` bien sûr, mais aussi les réponses
 * des écritures (démarrage, enregistrement d'une rubrique, finalisation, correction), y compris un enregistrement
 * sans effet ou le rejeu d'une clé, qui ne laisseraient sinon aucune trace. Le contrôleur appelle donc ce service
 * pour chacune d'elles.
 *
 * Volume : au plus UN événement par couple compte / cible et par intervalle de 10 minutes. Un écran qui recharge
 * la consultation à chaque rubrique enregistrée ne doit pas noyer le journal d'audit, et dix minutes suffisent à
 * répondre à « qui a ouvert ce dossier et à quel moment » à un quart d'heure près. Le contrôle lit le journal
 * lui-même (index cabinet / cible / date) : aucune table ni cache supplémentaire.
 *
 * Écriture hors du chemin des commandes (une lecture ne modifie aucun fait clinique) : si l'écriture de l'audit
 * échoue, la lecture échoue aussi, car un dossier ne se lit pas sans trace.
 */
final class ClinicalReadAudit
{
    public const INTERVAL_MINUTES = 10;

    public function __construct(private readonly AuditRecorder $recorder) {}

    public function consultationViewed(User $actor, stdClass $consultation): void
    {
        $this->once($actor, (string) $consultation->clinic_id, 'consultation.viewed', 'consultations', (string) $consultation->id);
    }

    /**
     * @param  string  $revisionId  identifiant de la révision lue
     */
    public function revisionViewed(User $actor, string $clinicId, string $revisionId): void
    {
        $this->once($actor, $clinicId, 'consultation.revision_viewed', 'clinical_revisions', $revisionId);
    }

    /**
     * Lecture d'une prescription optique (la ressource contient des valeurs cliniques) : cible la prescription.
     */
    public function prescriptionViewed(User $actor, string $clinicId, string $prescriptionId): void
    {
        $this->once($actor, $clinicId, 'prescription.viewed', 'optical_prescriptions', $prescriptionId);
    }

    /**
     * Lecture de la liste des prescriptions d'une consultation : cible la consultation (un événement, pas un par version).
     */
    public function prescriptionsListed(User $actor, string $clinicId, string $consultationId): void
    {
        $this->once($actor, $clinicId, 'consultation.prescriptions_viewed', 'consultations', $consultationId);
    }

    /**
     * Lecture de la liste des prescriptions d'un patient (ordonnances externes comprises) : cible le dossier patient.
     */
    public function patientPrescriptionsListed(User $actor, string $clinicId, string $patientId): void
    {
        $this->once($actor, $clinicId, 'patient.prescriptions_viewed', 'patient_records', $patientId);
    }

    private function once(User $actor, string $clinicId, string $action, string $targetType, string $targetId): void
    {
        $since = CarbonImmutable::now('UTC')->subMinutes(self::INTERVAL_MINUTES)->format('Y-m-d H:i:s.u');

        $recent = DB::table('audit_events')
            ->where('clinic_id', $clinicId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('action', $action)
            ->where('actor_id', $actor->getKey())
            ->where('recorded_at', '>', $since)
            ->exists();
        if ($recent) {
            return;
        }

        $this->recorder->record($clinicId, $actor, $action, $targetType, $targetId);
    }
}
