<?php

namespace App\Domain\Patients;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Recherche de patients (P02, fichier 02) : à partir de deux caractères, insensible à la casse et aux accents
 * pour les noms, normalisée pour les téléphones, jamais limitée à un seul critère.
 *
 * - Numéro de dossier : une requête qui commence par « EE- » cherche par préfixe de numéro.
 * - Chiffres (téléphone, fin de numéro de dossier) : les chiffres sont comparés aux téléphones normalisés, qui
 *   n'ont aucun indicatif déduit, et à la fin du numéro de dossier.
 * - Sinon, nom et prénoms : chaque mot de la requête (accents, casse et ponctuation ignorés, ordre indifférent)
 *   doit se retrouver dans la clé de recherche de la fiche.
 *
 * Toujours limitée au cabinet ; les fiches fusionnées dans une autre ne sont pas proposées. Tri stable (clé de
 * recherche, numéro, identifiant) ; 25 résultats par page.
 */
final class PatientSearch
{
    public const PER_PAGE = 25;

    public const MIN_LENGTH = 2;

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function __invoke(string $clinicId, mixed $query, mixed $page = null): LengthAwarePaginator
    {
        $text = is_string($query) ? Text::trim($query) : '';
        if (mb_strlen($text, 'UTF-8') < self::MIN_LENGTH) {
            throw BusinessError::validation(['query' => ['Saisissez au moins '.self::MIN_LENGTH.' caractères.']]);
        }
        $pageNumber = 1;
        if ($page !== null) {
            if (! is_string($page) && ! is_int($page) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw BusinessError::validation(['page' => ['La page est un entier supérieur ou égal à 1.']]);
            }
            $pageNumber = (int) $page;
        }

        $builder = DB::table('patient_records')
            ->where('clinic_id', $clinicId)
            ->whereNull('merged_into_patient_id');

        if (preg_match('/^EE-/i', $text) === 1) {
            $builder->where('record_number', 'like', self::like(strtoupper($text)).'%');
        } elseif (preg_match('/^\+?[0-9 .\-\/()\x{00A0}\x{202F}]+\z/u', $text) === 1) {
            $digits = (string) preg_replace('/[^0-9]/', '', $text);
            if (strlen($digits) < self::MIN_LENGTH) {
                throw BusinessError::validation(['query' => ['Saisissez au moins '.self::MIN_LENGTH.' chiffres.']]);
            }
            $builder->where(static function ($group) use ($digits): void {
                $group->where('phone_primary_normalized', 'like', '%'.self::like($digits).'%')
                    ->orWhere('phone_secondary_normalized', 'like', '%'.self::like($digits).'%')
                    ->orWhere('record_number', 'like', '%'.self::like($digits));
            });
        } else {
            try {
                $key = Text::searchKey($text);
            } catch (RuleViolation $violation) {
                throw BusinessError::validation(['query' => [$violation->getMessage()]]);
            }
            $tokens = array_values(array_filter(explode(' ', $key), static fn (string $token): bool => $token !== ''));
            if ($tokens === []) {
                throw BusinessError::validation(['query' => ['Saisissez au moins '.self::MIN_LENGTH.' caractères.']]);
            }
            foreach ($tokens as $token) {
                $builder->where('name_search', 'like', '%'.self::like($token).'%');
            }
        }

        return $builder->orderBy('name_search')->orderBy('record_number')->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $pageNumber);
    }

    /** Échappe les jokers de LIKE : une requête « 100% » ou « a_b » cherche ces caractères, pas un motif. */
    private static function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
