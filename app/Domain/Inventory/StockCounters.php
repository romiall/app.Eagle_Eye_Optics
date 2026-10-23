<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Rules\StockAvailability;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Quantity;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Physique d'une position ou d'un lot DÉJÀ verrouillé (rangs 80 et 81), hors vente : retour remis en stock
 * (`PATIENT_RETURN`), casse et ajustement (I02). La projection est écrite dans la même transaction que son mouvement.
 *
 * Un retrait ne prend jamais au-delà du disponible central (physique − réserves centrales − quotas des postes) : une
 * casse ou un ajustement négatif ne touche ni la marchandise réservée pour une vente émise, ni celle confiée à un
 * poste hors-ligne (CHECK `on_hand >= reserved + offline_allocated`, dernier garde-fou en base).
 */
final class StockCounters
{
    /**
     * Physique après le mouvement signé `$delta`.
     *
     * @throws RuleViolation STOCK_UNAVAILABLE (retrait au-delà du disponible, `available` à proposer) ou hors bornes
     */
    public static function onHandAfter(stdClass $row, FixedDecimal $delta, string $field = 'signed_quantity'): FixedDecimal
    {
        $onHand = Quantity::of((string) $row->on_hand);
        if ($delta->isNegative()) {
            $available = StockAvailability::central($onHand, Quantity::of((string) $row->reserved), Quantity::of((string) $row->offline_allocated));
            $taken = Quantity::of('0')->minus($delta);
            if ($taken->compare($available) > 0) {
                throw new RuleViolation(
                    RuleViolation::STOCK_UNAVAILABLE,
                    'stock.movement_exceeds_available',
                    $field,
                    'Quantité supérieure au stock disponible : la marchandise réservée pour une vente ou confiée à un poste ne se retire pas.',
                    ['available' => $available->isNegative() ? Quantity::of('0')->canonical() : $available->canonical()],
                );
            }
        }

        return Quantity::bounded($onHand->units + $delta->units, 'on_hand');
    }

    /**
     * Écrit le physique d'une ligne verrouillée et rend sa nouvelle version.
     */
    public static function write(string $clinicId, string $table, stdClass $row, FixedDecimal $onHand, string $now): int
    {
        $version = (int) $row->version + 1;
        DB::table($table)->where('clinic_id', $clinicId)->where('id', (string) $row->id)->update([
            'on_hand' => $onHand->canonical(),
            'version' => $version,
            'updated_at' => $now,
        ]);

        return $version;
    }
}
