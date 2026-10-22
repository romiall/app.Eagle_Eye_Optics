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
 * contenu : qui, quand, quelle consultation.
 *
 * Volume : au plus UN événement par couple compte / consultation et par intervalle de 10 minutes. Un écran qui
 * recharge la consultation à chaque rubrique enregistrée ne doit pas noyer le journal d'audit, et dix minutes
 * suffisent à répondre à « qui a ouvert ce dossier et à quel moment » à un quart d'heure près. Le contrôle lit
 * le journal lui-même (index cabinet / cible / date) : aucune table ni cache supplémentaire.
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
        $since = CarbonImmutable::now('UTC')->subMinutes(self::INTERVAL_MINUTES)->format('Y-m-d H:i:s.u');

        $recent = DB::table('audit_events')
            ->where('clinic_id', $consultation->clinic_id)
            ->where('target_type', 'consultations')
            ->where('target_id', $consultation->id)
            ->where('action', 'consultation.viewed')
            ->where('actor_id', $actor->getKey())
            ->where('recorded_at', '>', $since)
            ->exists();
        if ($recent) {
            return;
        }

        $this->recorder->record((string) $consultation->clinic_id, $actor, 'consultation.viewed', 'consultations', (string) $consultation->id);
    }
}
