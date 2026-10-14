<?php

namespace App\Domain\Access;

use App\Domain\Shared\Ulid;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Écrit un événement du journal append-only `audit_events` (fichier 09), dans la transaction de l'action
 * appelante. Jamais de mot de passe, de hash ni de contenu clinique brut dans `sanitized_diff`.
 *
 * `event_hash` est le SHA-256 du contenu canonique de l'événement. La chaîne `previous_hash` (facultative
 * selon le fichier 09) n'est pas tenue ici : elle exigerait un verrou et une lecture triée à chaque
 * écriture ; le chemin central de commandes (F07) décidera de l'ajouter sous le verrou du cabinet.
 */
final class AuditRecorder
{
    /** Clés jamais admises dans un diff d'audit, à quelque niveau que ce soit. */
    private const FORBIDDEN_KEYS = ['password', 'password_hash', 'password_confirmation', 'current_password', 'mfa_secret_ciphertext', 'remember_token'];

    /**
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
        $this->assertSanitized($sanitizedDiff);

        $event = [
            'id' => Ulid::generate(),
            'clinic_id' => $clinicId,
            'actor_id' => $actor?->getKey(),
            'actor_name_snapshot' => mb_substr($actor !== null ? (string) $actor->display_name : ($actorNameWhenUnknown ?? 'Système'), 0, 180),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'recorded_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'correlation_id' => mb_substr(AssignCorrelationId::current(request()), 0, 100),
            'reason' => $reason,
            'sanitized_diff' => $sanitizedDiff === [] ? null : $sanitizedDiff,
            'previous_hash' => null,
        ];
        $event['event_hash'] = hash('sha256', self::canonicalJson($event));
        $event['sanitized_diff'] = $event['sanitized_diff'] === null ? null : self::canonicalJson($event['sanitized_diff']);

        DB::table('audit_events')->insert($event);

        return $event['id'];
    }

    /**
     * JSON déterministe : clés triées récursivement, Unicode et barres obliques non échappés.
     */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
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
