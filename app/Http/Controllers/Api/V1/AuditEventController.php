<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Consultation du journal d'audit append-only (permission `audit.read`, gérante seulement : fichier 09). Lecture
 * seule, toujours limitée au cabinet du compte connecté, du plus récent au plus ancien, 50 événements par page.
 * Le diff d'un événement est déjà assaini à l'écriture (jamais de secret ni de contenu clinique brut).
 */
final class AuditEventController extends Controller
{
    private const PER_PAGE = 50;

    /** Instant contractuel : UTC à la milliseconde. */
    private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/';

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $filters = Validator::make($request->query(), [
            'target_type' => ['sometimes', 'string', 'max:64'],
            'target_id' => ['sometimes', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}\z/'],
            'from' => ['sometimes', 'string', 'regex:'.self::INSTANT],
            'to' => ['sometimes', 'string', 'regex:'.self::INSTANT],
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        $query = DB::table('audit_events')->where('clinic_id', $actor->clinic_id);
        foreach (['target_type', 'target_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, (string) $filters[$column]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('recorded_at', '>=', self::column((string) $filters['from']));
        }
        if (isset($filters['to'])) {
            // Borne incluse à la milliseconde, précision du contrat, alors que `recorded_at` garde les
            // microsecondes : un événement de 10:00:00.123456 affiché « 10:00:00.123Z » appartient bien à
            // « to = 10:00:00.123Z ».
            $query->where('recorded_at', '<', self::column((string) $filters['to'], 1));
        }

        $page = $query->orderByDesc('recorded_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return new JsonResponse([
            'data' => array_map(self::present(...), $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    private static function column(string $instant, int $plusMilliseconds = 0): string
    {
        return CarbonImmutable::parse($instant, 'UTC')->addMilliseconds($plusMilliseconds)->format('Y-m-d H:i:s.u');
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(object $row): array
    {
        /** @var object{id: string, actor_id: ?string, actor_name_snapshot: string, action: string, target_type: string, target_id: ?string, recorded_at: string, correlation_id: string, reason: ?string, sanitized_diff: ?string, event_hash: string} $row */
        return [
            'id' => $row->id,
            'actor_id' => $row->actor_id,
            'actor_name_snapshot' => $row->actor_name_snapshot,
            'action' => $row->action,
            'target_type' => $row->target_type,
            'target_id' => $row->target_id,
            'recorded_at' => CarbonImmutable::parse($row->recorded_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'correlation_id' => $row->correlation_id,
            'reason' => $row->reason,
            'sanitized_diff' => $row->sanitized_diff === null ? null : json_decode($row->sanitized_diff, true, 512, JSON_THROW_ON_ERROR),
            'event_hash' => $row->event_hash,
        ];
    }
}
