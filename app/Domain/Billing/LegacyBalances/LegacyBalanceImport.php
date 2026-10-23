<?php

namespace App\Domain\Billing\LegacyBalances;

use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Billing\Rules\LegacyBalance;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Normalizer;
use stdClass;

/**
 * Contrôle des créances papier à reprendre (X01, AT26) : le même contrôle sert l'aperçu (aucune écriture) et la
 * reprise (une seule transaction : une ligne refusée, rien n'est repris). Pour chaque ligne :
 *
 * - le patient existe dans le cabinet, désigné par son identifiant OU son numéro de dossier (jamais les deux), et
 *   n'est pas une fiche fusionnée ;
 * - la référence papier (1 à 100 caractères) apparaît une seule fois dans le fichier et n'a jamais été reprise
 *   (comparaison de la base : casse et accents ignorés, ponctuation gardée) ;
 * - le reste dû vient de la règle jumelle `LegacyBalance::openingBalance` (total d'origine strictement positif,
 *   déjà payé entre 0 et le total, reste dû strictement positif) : aucun montant n'est arrondi ni deviné ;
 * - la date de la pièce papier, facultative, n'est pas dans le futur (jour civil du cabinet).
 */
final class LegacyBalanceImport
{
    /** Lignes au plus par reprise (un carnet plus long se découpe en plusieurs fichiers). */
    public const MAX_ROWS = 500;

    private const REFERENCE_MAX = 100;

    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/u';

    /**
     * @param  list<mixed>  $rows
     * @return array{
     *     accepted: list<array{index: int, line: int, patient: stdClass, paper_reference: string, original_total: int, already_paid: int, opening_balance: int, original_invoice_on: string|null}>,
     *     rejected: list<array{index: int, line: int, field: string|null, message: string}>
     * }
     */
    public static function check(string $clinicId, string $timezone, CarbonImmutable $now, array $rows): array
    {
        $today = Instant::fromEpochMilliseconds((int) $now->format('Uv'))->civilDate($timezone);
        $accepted = [];
        $rejected = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $line = is_array($row) && is_int($row['line'] ?? null) ? $row['line'] : $index + 2;
            if (! is_array($row)) {
                $rejected[] = ['index' => $index, 'line' => $line, 'field' => null, 'message' => 'Ligne illisible.'];

                continue;
            }
            $errors = new FieldErrors;

            $patient = self::patient($clinicId, $row, $errors);
            $reference = self::reference($row['paper_reference'] ?? null, $errors);

            $original = $row['original_total'] ?? null;
            $paid = $row['already_paid'] ?? null;
            $opening = null;
            if (! is_int($original)) {
                $errors->add('original_total', 'Le total de la pièce papier est un nombre entier de FCFA.');
            }
            if (! is_int($paid)) {
                $errors->add('already_paid', 'Le montant déjà payé est un nombre entier de FCFA (0 si rien n\'a été payé).');
            }
            if (is_int($original) && is_int($paid)) {
                try {
                    $opening = LegacyBalance::openingBalance($original, $paid);
                } catch (RuleViolation $violation) {
                    $errors->addViolation('', $violation);
                }
            }

            $date = null;
            $dateText = $row['original_invoice_on'] ?? null;
            if ($dateText !== null) {
                try {
                    $civil = CivilDate::fromString(is_string($dateText) ? $dateText : '', 'original_invoice_on');
                    if ($civil->compare($today) > 0) {
                        $errors->add('original_invoice_on', 'La date de la pièce papier est dans le futur.');
                    } else {
                        $date = $civil->toString();
                    }
                } catch (RuleViolation $violation) {
                    $errors->addViolation('', $violation);
                }
            }

            if ($reference !== null) {
                $key = self::referenceKey($reference);
                if (isset($seen[$key])) {
                    $errors->add('paper_reference', 'Référence papier déjà présente à la ligne '.$seen[$key].' du fichier.');
                } else {
                    $seen[$key] = $line;
                }
            }

            foreach ($errors->all() as $field => $messages) {
                foreach ($messages as $message) {
                    $rejected[] = ['index' => $index, 'line' => $line, 'field' => $field === '' ? null : $field, 'message' => $message];
                }
            }
            if ($errors->isEmpty() && $patient !== null && $reference !== null && is_int($original) && is_int($paid) && $opening !== null) {
                $accepted[] = [
                    'index' => $index,
                    'line' => $line,
                    'patient' => $patient,
                    'paper_reference' => $reference,
                    'original_total' => $original,
                    'already_paid' => $paid,
                    'opening_balance' => $opening,
                    'original_invoice_on' => $date,
                ];
            }
        }

        // Références déjà reprises (comparaison de la base : casse et accents ignorés).
        if ($accepted !== []) {
            $taken = array_fill_keys(DB::table('legacy_balance_imports')
                ->where('clinic_id', $clinicId)
                ->whereIn('paper_reference', array_map(static fn (array $item): string => $item['paper_reference'], $accepted))
                ->pluck('paper_reference')
                ->map(static fn (mixed $reference): string => self::referenceKey((string) $reference))
                ->all(), true);
            $kept = [];
            foreach ($accepted as $item) {
                if (isset($taken[self::referenceKey($item['paper_reference'])])) {
                    $rejected[] = ['index' => $item['index'], 'line' => $item['line'], 'field' => 'paper_reference',
                        'message' => 'Cette référence papier a déjà été reprise.'];

                    continue;
                }
                $kept[] = $item;
            }
            $accepted = $kept;
        }

        usort($rejected, static fn (array $a, array $b): int => [$a['line'], (string) $a['field']] <=> [$b['line'], (string) $b['field']]);

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * Clé de comparaison d'une référence, comme la collation de la base (`utf8mb4_0900_ai_ci`).
     */
    public static function referenceKey(string $reference): string
    {
        $decomposed = Normalizer::normalize(mb_strtolower(trim($reference), 'UTF-8'), Normalizer::FORM_D);

        return (string) preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $reference : $decomposed);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function patient(string $clinicId, array $row, FieldErrors $errors): ?stdClass
    {
        $id = $row['patient_id'] ?? null;
        $number = $row['patient_record_number'] ?? null;
        $number = is_string($number) && trim($number) !== '' ? trim($number) : null;
        if (($id === null) === ($number === null)) {
            $errors->add('patient', 'Indiquez le patient par sa fiche ou par son numéro de dossier (un seul des deux).');

            return null;
        }
        $query = DB::table('patient_records')->where('clinic_id', $clinicId);
        $patient = $id !== null
            ? (is_string($id) ? $query->where('id', $id)->first() : null)
            : $query->where('record_number', $number)->first();
        if ($patient === null) {
            $errors->add($id !== null ? 'patient_id' : 'patient_record_number', 'Patient introuvable dans ce cabinet.');

            return null;
        }
        if ($patient->merged_into_patient_id !== null) {
            $errors->add($id !== null ? 'patient_id' : 'patient_record_number', 'Cette fiche a été fusionnée : reprenez la créance sur la fiche conservée.');

            return null;
        }

        return $patient;
    }

    private static function reference(mixed $value, FieldErrors $errors): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            $errors->add('paper_reference', 'La référence de la pièce papier est obligatoire.');

            return null;
        }
        $reference = trim($value);
        if (mb_strlen($reference) > self::REFERENCE_MAX) {
            $errors->add('paper_reference', 'La référence papier compte au plus '.self::REFERENCE_MAX.' caractères.');

            return null;
        }
        if (preg_match(self::CONTROL_CHARACTERS, $reference) === 1) {
            $errors->add('paper_reference', 'La référence papier ne peut pas contenir de caractère de contrôle.');

            return null;
        }

        return $reference;
    }
}
