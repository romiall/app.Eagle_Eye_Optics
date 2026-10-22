<?php

namespace App\Domain\Clinical;

use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Clinical\Rules\MedicationsStep;
use App\Domain\Shared\CanonicalJson;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Rubrique MEDICATIONS (C04 ; DP §9) : validation de l'état résultant (`Rules/MedicationsStep`), contrôle des
 * identifiants de ligne, écriture par REMPLACEMENT COMPLET et comparaison avec l'état enregistré.
 *
 * L'ORDRE des médicaments compte (la position est le rang dans la liste) : contrairement aux autres rubriques,
 * deux listes qui ne diffèrent que par l'ordre sont des états différents. Une ligne supprimée, modifiée ou
 * déplacée est supprimée puis recréée avec le même identifiant, TOUTES les suppressions avant les insertions,
 * pour ne jamais heurter l'unicité (consultation, position).
 *
 * Prescrire ne crée aucune vente et ne décrémente aucun stock : seule la table `medication_orders` est écrite.
 */
final class MedicationSteps
{
    private const TABLE = 'medication_orders';

    public function __construct(private readonly ConsultationReader $reader) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(stdClass $consultation, array $data, FieldErrors $errors): array
    {
        return MedicationsStep::normalize($data, $this->knownCatalogIds($consultation, $data['orders'] ?? null), $errors);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function rejectForeignIds(stdClass $consultation, array $data, FieldErrors $errors, string $path = 'step.data'): void
    {
        StepRows::flagTaken(self::TABLE, StepRows::idsAt($data['orders'] ?? null), StepRows::ownIds(self::TABLE, $consultation), $path.'.orders', $errors);
    }

    /**
     * Vrai si la rubrique enregistrée est déjà exactement l'état résultant, ORDRE COMPRIS.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function isUnchanged(stdClass $consultation, array $normalized): bool
    {
        return CanonicalJson::hash($this->reader->step($consultation, 'MEDICATIONS')) === CanonicalJson::hash($normalized);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function write(stdClass $consultation, array $normalized, string $now): void
    {
        $clinicId = (string) $consultation->clinic_id;
        $consultationId = (string) $consultation->id;

        $existing = [];
        foreach (DB::table(self::TABLE)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->get() as $row) {
            $existing[(string) $row->id] = ConsultationReader::orderRow($row) + ['position' => (int) $row->position];
        }
        $incoming = [];
        foreach (StepRows::rows($normalized['orders'] ?? []) as $index => $order) {
            $incoming[(string) $order['id']] = $order + ['position' => $index + 1];
        }

        $delete = [];
        $insert = [];
        foreach ($existing as $id => $old) {
            if (! isset($incoming[$id]) || $incoming[$id] !== $old) {
                $delete[] = (string) $id;
            }
        }
        foreach ($incoming as $id => $order) {
            if (! isset($existing[$id]) || $existing[$id] !== $order) {
                $insert[] = $order + [
                    'clinic_id' => $clinicId,
                    'consultation_id' => $consultationId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($delete !== []) {
            DB::table(self::TABLE)->where('clinic_id', $clinicId)->where('consultation_id', $consultationId)->whereIn('id', $delete)->delete();
        }
        if ($insert !== []) {
            DB::table(self::TABLE)->insert($insert);
        }
    }

    /**
     * Articles du catalogue du cabinet parmi les identifiants fournis.
     *
     * @return array<string, true>
     */
    private function knownCatalogIds(stdClass $consultation, mixed $orders): array
    {
        $ids = array_values(array_unique(StepRows::idsAt($orders, 'catalog_item_id')));
        if ($ids === []) {
            return [];
        }
        $known = [];
        foreach (DB::table('catalog_items')->where('clinic_id', $consultation->clinic_id)->whereIn('id', $ids)->pluck('id') as $id) {
            $known[(string) $id] = true;
        }

        return $known;
    }
}
