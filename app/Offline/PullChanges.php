<?php

namespace App\Offline;

use App\Application\Effects\ChangeVisibility;
use App\Application\Publication\ChangeFeed;
use App\Domain\Access\EffectivePermissions;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\GrantSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * `GET /api/v1/sync/pull?device_id=…&cursor=…` (O05 ; fichier 19, contrat 23 « publication pull transactionnelle »).
 *
 * Changements DÉJÀ committés du cabinet après le curseur, lus par `ChangeFeed` sous le verrou partagé du cabinet (aucun
 * curseur ne dépasse un changement non publié), au plus 100 par page, avec leur projection calculée dans la même
 * transaction. Le filtre de compartiments est l'INTERSECTION de ceux du grant ACTIF du compte sur ce poste et de ceux
 * de ses droits ACTUELS : un profil sans droit clinique ne reçoit JAMAIS un changement CLINICAL, même si son grant était
 * plus large ; un changement PUBLIC est lisible par tous. Le curseur avance aussi sur les entrées filtrées (aucune
 * boucle) ; il est opaque, signé et propre au poste, au compte et aux compartiments (`PullCursor`).
 *
 * Le poste ne retient `next_cursor` qu'après le commit local de TOUTE la page.
 */
final class PullChanges
{
    /** Compartiment du coffre → visibilité du journal des changements. */
    private const VISIBILITIES = [
        'ADMINISTRATIVE' => ChangeVisibility::Administrative,
        'CLINICAL' => ChangeVisibility::Clinical,
        'FINANCIAL' => ChangeVisibility::Financial,
        'MANAGER' => ChangeVisibility::Manager,
    ];

    public function __construct(
        private readonly ChangeFeed $feed,
        private readonly EffectivePermissions $permissions,
    ) {}

    /**
     * @return array{changes: list<array<string, mixed>>, next_cursor: string, has_more: bool, server_time: string}
     */
    public function page(User $user, string $deviceId, ?string $cursor): array
    {
        $clinicId = (string) $user->clinic_id;
        $now = CarbonImmutable::now('UTC');
        $device = DB::table('enrolled_devices')->where('clinic_id', $clinicId)->where('id', $deviceId)->first(['id', 'status']);
        if ($device === null) {
            throw BusinessError::notFound('Poste inconnu de ce cabinet.');
        }
        if (in_array((string) $device->status, ['LOST', 'REPLACED'], true)) {
            throw BusinessError::forbidden('Ce poste est déclaré perdu ou remplacé : il ne reçoit plus de données.');
        }

        $grant = $this->activeGrant($clinicId, $deviceId, (string) $user->id, $now);
        $compartments = array_values(array_intersect(
            Compartments::of($grant->permissions),
            Compartments::of($this->permissions->for($user)),
        ));
        $visibilities = [ChangeVisibility::Public];
        foreach ($compartments as $compartment) {
            $visibilities[] = self::VISIBILITIES[$compartment];
        }

        $after = $cursor === null || $cursor === '' ? 0 : PullCursor::after($cursor, $clinicId, $deviceId, (string) $user->id, $compartments);
        $limit = max(1, min(ChangeFeed::MAX_PAGE_SIZE, (int) config('eeo.offline.pull_page_size', ChangeFeed::MAX_PAGE_SIZE)));
        $page = $this->feed->page(
            $clinicId,
            $after,
            $limit,
            $visibilities,
            static fn (stdClass $row): array => PullProjections::project($clinicId, (string) $row->entity_type, (string) $row->entity_id),
        );

        return [
            'changes' => array_map(static fn (stdClass $row): array => [
                'change_sequence' => (string) $row->change_sequence,
                'entity_type' => (string) $row->entity_type,
                'entity_id' => (string) $row->entity_id,
                'entity_version' => (int) $row->entity_version,
                'change_kind' => (string) $row->change_kind,
                'visibility' => (string) $row->visibility,
                'recorded_at' => SignedCommands::format(CarbonImmutable::parse((string) $row->recorded_at, 'UTC')),
                'data' => (object) (is_array($row->projection ?? null) ? $row->projection : []),
            ], $page['changes']),
            'next_cursor' => PullCursor::sign($clinicId, $deviceId, (string) $user->id, $compartments, $page['upper_bound']),
            'has_more' => $page['has_more'],
            'server_time' => SignedCommands::format($now),
        ];
    }

    /**
     * Grant ACTIF (ni révoqué, ni expiré) le plus récent du compte sur ce poste ; sinon 403 GRANT_EXPIRED_OR_REVOKED.
     */
    private function activeGrant(string $clinicId, string $deviceId, string $userId, CarbonImmutable $now): GrantSnapshot
    {
        $row = DB::table('offline_grants')
            ->where('clinic_id', $clinicId)
            ->where('device_id', $deviceId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('valid_until', '>', $now->format('Y-m-d H:i:s.u'))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->first();
        if ($row === null) {
            throw new BusinessError('GRANT_EXPIRED_OR_REVOKED', 'Aucune autorisation active de ce compte sur ce poste : préparez de nouveau le profil en ligne.', 403);
        }

        return DeviceDirectory::grantSnapshot($row);
    }
}
