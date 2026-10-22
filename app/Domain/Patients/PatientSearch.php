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
 * - Numéro de dossier : « EE-… » (préfixe), ou « AAAA-NNNN » (fin du numéro).
 * - Chiffres (téléphone, fin de numéro de dossier) : les chiffres sont comparés aux téléphones normalisés, qui
 *   n'ont aucun indicatif déduit, dans les deux sens (le numéro saisi avec indicatif retrouve un numéro enregistré
 *   sans indicatif de 7 chiffres au moins, et inversement), et à la fin du numéro de dossier. Un préfixe
 *   international « 00 » vaut « + ».
 * - Sinon, nom et prénoms : chaque mot de la requête (accents, casse et ponctuation ignorés, ordre indifférent)
 *   doit se retrouver dans la clé de recherche de la fiche.
 *
 * Toujours limitée au cabinet ; les fiches fusionnées dans une autre ne sont pas proposées. Tri stable (clé de
 * recherche, numéro, identifiant) ; 25 résultats par page ; requête de 100 caractères au plus.
 */
final class PatientSearch
{
    public const PER_PAGE = 25;

    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    /** Page maximale admise : au-delà, l'offset n'a plus de sens. */
    public const MAX_PAGE = 100000;

    /** Longueur minimale d'un numéro enregistré pour qu'il serve de suffixe à un numéro saisi avec indicatif. */
    private const MIN_STORED_DIGITS = 7;

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function __invoke(string $clinicId, mixed $query, mixed $page = null): LengthAwarePaginator
    {
        $text = $this->text($query);
        $pageNumber = $this->page($page);

        $builder = DB::table('patient_records')
            ->where('clinic_id', $clinicId)
            ->whereNull('merged_into_patient_id');

        if (preg_match('/^EE-\d{1,4}(?:-\d*)?\z/i', $text) === 1) {
            // « EE-2026 » ou « EE-2026-00 » : préfixe du numéro de dossier (au moins un chiffre après « EE- »).
            $builder->where('record_number', 'like', self::like(strtoupper($text)).'%');
        } elseif (preg_match('/^\d{4}-\d{1,}\z/', $text) === 1) {
            // « 2026-0042 » : le numéro de dossier sans son préfixe de série.
            $builder->where('record_number', 'like', '%-'.self::like($text));
        } elseif (preg_match('/^\+?[0-9 .\-\/()\x{00A0}\x{202F}]+\z/u', $text) === 1) {
            $digits = (string) preg_replace('/[^0-9]/', '', $text);
            // « 00 » initial = « + » : l'indicatif international s'écrit avec l'un ou l'autre.
            if (! str_starts_with($text, '+') && str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            }
            if (strlen($digits) < self::MIN_LENGTH) {
                throw BusinessError::validation(['query' => ['Saisissez au moins '.self::MIN_LENGTH.' chiffres.']]);
            }
            $builder->where(static function ($group) use ($digits): void {
                $group->where('phone_primary_normalized', 'like', '%'.self::like($digits).'%')
                    ->orWhere('phone_secondary_normalized', 'like', '%'.self::like($digits).'%')
                    // Réciproque : un numéro enregistré sans indicatif est la fin du numéro saisi avec indicatif.
                    ->orWhereRaw("CHAR_LENGTH(REPLACE(phone_primary_normalized, '+', '')) >= ? AND ? LIKE CONCAT('%', REPLACE(phone_primary_normalized, '+', ''))", [self::MIN_STORED_DIGITS, $digits])
                    ->orWhereRaw("CHAR_LENGTH(REPLACE(phone_secondary_normalized, '+', '')) >= ? AND ? LIKE CONCAT('%', REPLACE(phone_secondary_normalized, '+', ''))", [self::MIN_STORED_DIGITS, $digits])
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

    private function text(mixed $query): string
    {
        if (! is_string($query) || ! mb_check_encoding($query, 'UTF-8')) {
            throw BusinessError::validation(['query' => ['Saisissez au moins '.self::MIN_LENGTH.' caractères.']]);
        }
        $text = Text::trim($query);
        $length = mb_strlen($text, 'UTF-8');
        if ($length < self::MIN_LENGTH) {
            throw BusinessError::validation(['query' => ['Saisissez au moins '.self::MIN_LENGTH.' caractères.']]);
        }
        if ($length > self::MAX_LENGTH) {
            throw BusinessError::validation(['query' => ['La recherche ne dépasse pas '.self::MAX_LENGTH.' caractères.']]);
        }

        return $text;
    }

    private function page(mixed $page): int
    {
        if ($page === null) {
            return 1;
        }
        $number = (is_string($page) || is_int($page)) ? filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_PAGE]]) : false;
        if ($number === false) {
            throw BusinessError::validation(['page' => ['La page est un entier compris entre 1 et '.self::MAX_PAGE.'.']]);
        }

        return $number;
    }

    /** Échappe les jokers de LIKE : une requête « 100% » ou « a_b » cherche ces caractères, pas un motif. */
    private static function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
