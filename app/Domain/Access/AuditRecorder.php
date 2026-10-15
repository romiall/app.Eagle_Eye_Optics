<?php

namespace App\Domain\Access;

use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\Ulid;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Écrit un événement du journal append-only `audit_events` (fichier 09), dans la transaction de l'action
 * appelante. Jamais de mot de passe, de hash ni de contenu clinique brut dans `sanitized_diff`.
 *
 * `event_hash` est le SHA-256 du contenu canonique de l'événement (détecte l'altération d'UNE ligne).
 *
 * Décision F07 sur la chaîne `previous_hash` (facultative selon le fichier 09) : elle n'est PAS tenue. Elle
 * exigerait que toute écriture d'audit soit sérialisée par un verrou exclusif et lise le dernier maillon, or
 * des événements s'écrivent hors du chemin central (échecs de connexion, sous verrou PARTAGÉ du cabinet). Une
 * chaîne incomplète donnerait de fausses alertes, et le fichier 09 rappelle qu'elle ne protège pas d'un
 * administrateur qui possède toutes les clés. La protection retenue : triggers qui interdisent UPDATE et
 * DELETE (fichier 21), compte d'application sans ces droits si l'hébergeur le permet, et export quotidien
 * hors serveur (X02) dont l'empreinte détecte une réécriture a posteriori.
 */
final class AuditRecorder
{
    /** Clés jamais admises dans un diff d'audit, à quelque niveau que ce soit. */
    private const FORBIDDEN_KEYS = ['password', 'password_hash', 'password_confirmation', 'current_password', 'mfa_secret_ciphertext', 'remember_token'];

    /**
     * Événement de la requête courante : auteur connu (ou nom de repli), corrélation de la requête.
     *
     * @param  array<string, mixed>  $sanitizedDiff
     */
    public function record(
        string $clinicId,
        ?User $actor,
        string $action,
        string $targetType,
        ?string $targetId,
        ?string $reason = null,
        array $sanitizedDiff = [],
        ?string $actorNameWhenUnknown = null,
    ): string {
        return $this->recordFor(
            $clinicId,
            $actor !== null ? (string) $actor->getKey() : null,
            $actor !== null ? (string) $actor->display_name : ($actorNameWhenUnknown ?? 'Système'),
            $action,
            $targetType,
            $targetId,
            $reason,
            $sanitizedDiff,
            AssignCorrelationId::current(request()),
        );
    }

    /**
     * Événement dont l'auteur et la corrélation viennent explicitement du CONTEXTE d'une commande (route
     * directe, poste synchronisé ou tâche planifiée) : jamais de la charge ni de la requête HTTP en cours.
     *
     * @param  array<string, mixed>  $sanitizedDiff
     */
    public function recordFor(
        string $clinicId,
        ?string $actorId,
        string $actorName,
        string $action,
        string $targetType,
        ?string $targetId,
        ?string $reason,
        array $sanitizedDiff,
        string $correlationId,
    ): string {
        $this->assertSanitized($sanitizedDiff);

        $event = [
            'id' => Ulid::generate(),
            'clinic_id' => $clinicId,
            'actor_id' => $actorId,
            'actor_name_snapshot' => mb_substr($actorName, 0, 180),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'recorded_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'correlation_id' => mb_substr($correlationId, 0, 100),
            'reason' => $reason,
            'sanitized_diff' => $sanitizedDiff === [] ? null : $sanitizedDiff,
            'previous_hash' => null,
        ];
        $event['event_hash'] = CanonicalJson::hash($event);
        $event['sanitized_diff'] = $event['sanitized_diff'] === null ? null : self::canonicalJson($event['sanitized_diff']);

        DB::table('audit_events')->insert($event);

        return $event['id'];
    }

    /**
     * JSON déterministe : clés triées récursivement, Unicode et barres obliques non échappés.
     */
    public static function canonicalJson(mixed $value): string
    {
        return CanonicalJson::encode($value);
    }

    /**
     * @param  array<array-key, mixed>  $diff
     */
    private function assertSanitized(array $diff): void
    {
        foreach ($diff as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw new \LogicException('Donnée secrète interdite dans le journal d\'audit : '.$key);
            }
            if (is_array($value)) {
                $this->assertSanitized($value);
            }
        }
    }
}
