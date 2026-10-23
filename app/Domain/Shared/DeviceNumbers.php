<?php

namespace App\Domain\Shared;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numéros PRÉ-ALLOUÉS d'un poste préparé (fichier 19 « Numéros », O03), consommés à la synchronisation (O05). Le
 * poste a déjà attribué, imprimé et remis la pièce avec un numéro de SA plage : le serveur vérifie ce numéro et
 * l'enregistre TEL QUEL. Il ne le renumérote jamais et ne prend jamais de numéro central pour une pièce de poste.
 *
 * Contrôles : plage désignée par le cœur signé (`number_allocation_id`), de ce cabinet, de CE poste et de cette série ;
 * plage ACTIVE ou CLOSING (une plage close a inscrit ses numéros inutilisés `UNUSED`, aucun ne s'y consomme plus) ;
 * numéro rendu exactement au format de la série remis au poste (`<préfixe>-AAAA-<valeur>`), valeur dans les bornes de
 * la plage, jamais déjà consommée. Le curseur `last_consumed_value` de la plage monte et ne recule jamais.
 *
 * Refus : 409 `NUMBER_RANGE_INVALID` (la commande passe en revue, rien n'est écrit). La plage est lue SOUS le verrou
 * exclusif du cabinet tenu par le chemin central : toutes ses écritures (préparation, consommation, clôture) passent
 * par ce chemin et ce verrou, qui les sérialise (même règle que `DeviceDirectory::underClinicLock`).
 */
final class DeviceNumbers
{
    /**
     * @param  Closure(string, int, int): string  $renderer  format de la série (celui remis au poste avec sa plage)
     * @return array{value: int, rendered: string, year: int, allocation_id: string}
     */
    public static function consume(CommandContext $context, string $type, mixed $rendered, Closure $renderer): array
    {
        $references = $context->syncReferences;
        if ($context->deviceId === null || $references === null) {
            throw new LogicException('Un numéro pré-alloué ne se consomme que pour une commande de poste synchronisée.');
        }
        $allocationId = $references->numberAllocationId;
        if ($allocationId === null) {
            throw self::invalid('La commande ne désigne aucune plage de numéros de ce poste.');
        }
        if (! is_string($rendered) || $rendered === '') {
            throw self::invalid('Le numéro attribué par le poste est absent de la commande.');
        }

        $range = DB::table('document_number_allocations')->where('clinic_id', $context->clinicId)->where('id', $allocationId)->first();
        if ($range === null || (string) $range->device_id !== $context->deviceId || (string) $range->document_type !== $type) {
            throw self::invalid('La plage désignée n\'appartient pas à ce poste pour cette série de numéros.');
        }
        if (! in_array((string) $range->status, ['ACTIVE', 'CLOSING'], true)) {
            throw self::invalid('La plage de numéros de ce poste est close : ses numéros inutilisés ont été inscrits, celui-ci ne peut plus être consommé.');
        }

        $year = (int) $range->document_year;
        $value = self::valueOf($type, $year, $rendered, $renderer);
        if ($value === null || $value < (int) $range->range_start || $value > (int) $range->range_end) {
            throw self::invalid('Le numéro '.$rendered.' n\'est pas un numéro de la plage de ce poste.');
        }

        $sameValue = DB::table('document_number_usages')
            ->where('clinic_id', $context->clinicId)->where('document_type', $type)->where('document_year', $year)->where('number_value', $value)
            ->exists();
        $sameText = DB::table('document_number_usages')
            ->where('clinic_id', $context->clinicId)->where('document_type', $type)->where('rendered_number', $rendered)
            ->exists();
        if ($sameValue || $sameText) {
            throw self::invalid('Le numéro '.$rendered.' est déjà enregistré : un numéro n\'est jamais attribué deux fois.');
        }

        $last = $range->last_consumed_value === null ? null : (int) $range->last_consumed_value;
        DB::table('document_number_allocations')->where('clinic_id', $context->clinicId)->where('id', $allocationId)->update([
            'last_consumed_value' => $last === null ? $value : max($last, $value),
            'version' => ((int) $range->version) + 1,
            'updated_at' => $context->now->utc()->format('Y-m-d H:i:s.u'),
        ]);

        return ['value' => $value, 'rendered' => $rendered, 'year' => $year, 'allocation_id' => $allocationId];
    }

    /**
     * Valeur d'un numéro rendu, si et seulement si le format de la série la rend exactement ainsi pour cette année.
     *
     * @param  Closure(string, int, int): string  $renderer
     */
    private static function valueOf(string $type, int $year, string $rendered, Closure $renderer): ?int
    {
        if (preg_match('/-(\d{4})-(\d{1,15})\z/', $rendered, $match) !== 1 || (int) $match[1] !== $year) {
            return null;
        }
        $value = (int) $match[2];

        return $value > 0 && $renderer($type, $year, $value) === $rendered ? $value : null;
    }

    private static function invalid(string $message): BusinessError
    {
        return new BusinessError('NUMBER_RANGE_INVALID', $message, 409);
    }
}
