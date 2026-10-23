<?php

namespace App\Application\Publication;

use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Application\TransactionRunner;
use Closure;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lecture ordonnée du journal des changements (`sync_change_log`) pour le pull (fichier 19).
 *
 * La page est lue sous le verrou PARTAGÉ de la ligne du cabinet. Toute mutation centrale tient le verrou
 * EXCLUSIF de cette ligne de son premier verrou à son commit, et n'insère ses changements qu'à l'intérieur de
 * cette fenêtre : la lecture attend donc la fin des mutations en cours, puis voit TOUS les changements
 * committés du cabinet. Aucun curseur ne peut ainsi dépasser un numéro encore non committé, et un numéro
 * manquant ne peut qu'être un trou d'AUTO_INCREMENT (transaction annulée), jamais un changement à venir.
 * On n'utilise pas un MAX global observé : seules comptent les lignes de CE cabinet.
 *
 * La page porte une borne supérieure : le numéro du dernier changement balayé, retenu ou non par le filtre de
 * compartiments. Le curseur avance donc aussi sur des entrées filtrées et ne boucle jamais. Le client ne
 * valide cette borne qu'après commit local de toute la page (curseur opaque signé : tâche O05).
 */
final class ChangeFeed
{
    public const MAX_PAGE_SIZE = 100;

    public function __construct(private readonly TransactionRunner $transactions) {}

    /**
     * `$project` (pull, O05) : projection autorisée de chaque changement RETENU, calculée DANS la même transaction, sous
     * le même verrou partagé du cabinet, et rangée dans `$row->projection` : la page et ses projections sont un état
     * committé cohérent (aucune mutation du cabinet ne s'intercale entre les deux lectures).
     *
     * @param  list<ChangeVisibility>|null  $visibilities  compartiments lisibles par le profil ; null : tous
     * @param  (Closure(stdClass): array<string, mixed>)|null  $project
     * @return array{changes: list<stdClass>, upper_bound: int, has_more: bool}
     */
    public function page(string $clinicId, int $afterSequence, int $limit = self::MAX_PAGE_SIZE, ?array $visibilities = null, ?Closure $project = null): array
    {
        $limit = max(1, min($limit, self::MAX_PAGE_SIZE));

        return $this->transactions->run(function () use ($clinicId, $afterSequence, $limit, $visibilities, $project): array {
            (new TransactionLocks($clinicId))->clinicShared();

            /** @var list<stdClass> $scanned */
            $scanned = DB::table('sync_change_log')
                ->where('clinic_id', $clinicId)
                ->where('change_sequence', '>', $afterSequence)
                ->orderBy('change_sequence')
                ->limit($limit + 1)
                ->get()
                ->all();

            $hasMore = count($scanned) > $limit;
            $scanned = array_slice($scanned, 0, $limit);

            $allowed = $visibilities === null ? null : array_map(static fn (ChangeVisibility $visibility): string => $visibility->value, $visibilities);
            $changes = array_values(array_filter(
                $scanned,
                static fn (stdClass $row): bool => $allowed === null || in_array($row->visibility, $allowed, true),
            ));
            if ($project !== null) {
                foreach ($changes as $row) {
                    $row->projection = $project($row);
                }
            }

            return [
                'changes' => $changes,
                'upper_bound' => $scanned === [] ? $afterSequence : (int) $scanned[array_key_last($scanned)]->change_sequence,
                'has_more' => $hasMore,
            ];
        });
    }
}
