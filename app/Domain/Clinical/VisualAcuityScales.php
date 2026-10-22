<?php

namespace App\Domain\Clinical;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Échelles d'acuité visuelle du cabinet (`visual_acuity_scales`, C02) : le professionnel choisit l'échelle utilisée
 * pour chaque valeur ; AUCUNE conversion entre échelles n'est fournie ni présumée (« 10/10 », « 6/6 », « P2 » ou
 * une valeur qualitative ne deviennent pas des nombres comparables sans conversion validée par la cliente).
 *
 * Tant que la cliente n'a pas validé ses échelles (Q06), seules des échelles de DÉMONSTRATION fictives existent
 * (commande `eeo:clinical:scales --demo`, environnements de développement, test et démonstration). Toute lecture est
 * limitée au cabinet.
 */
final class VisualAcuityScales
{
    /**
     * Échelles du cabinet, actives d'abord, puis par code.
     *
     * @return list<array{id: string, code: string, label: string, description: string|null, active: bool}>
     */
    public function all(string $clinicId): array
    {
        $rows = DB::table('visual_acuity_scales')
            ->where('clinic_id', $clinicId)
            ->orderByDesc('active')
            ->orderBy('code')
            ->get()
            ->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'label' => (string) $row->label,
                'description' => $row->description === null ? null : (string) $row->description,
                'active' => (bool) $row->active,
            ])
            ->all();

        return array_values($rows);
    }

    /**
     * @return list<string>
     */
    public function activeIds(string $clinicId): array
    {
        return $this->ids($clinicId, true);
    }

    /**
     * Toutes les échelles du cabinet, actives ou non.
     *
     * @return list<string>
     */
    public function knownIds(string $clinicId): array
    {
        return $this->ids($clinicId, false);
    }

    /**
     * @return list<string>
     */
    private function ids(string $clinicId, bool $onlyActive): array
    {
        $query = DB::table('visual_acuity_scales')->where('clinic_id', $clinicId);
        if ($onlyActive) {
            $query->where('active', true);
        }

        return array_values($query->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
    }
}
