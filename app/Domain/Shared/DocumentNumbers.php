<?php

namespace App\Domain\Shared;

use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\ValueObjects\Instant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numéros de pièces attribués par le serveur (fichier 03, « Numérotation » ; contrat 23).
 *
 * Le numéro est pris dans `document_sequences` sous le verrou exclusif du cabinet puis celui de la séquence
 * (type, année), enregistré dans `document_number_usages` (append-only) dans la MÊME transaction que la pièce :
 * jamais `MAX + 1` ni `COUNT + 1`, aucun trou en cas d'échec (tout est annulé), aucun recyclage. Une plage
 * réservée à un poste hors-ligne (O03) fait monter `last_value` à la fin de la plage : un numéro central est
 * donc toujours hors de toute plage réservée.
 *
 * Seul le format du dossier patient est défini (`EE-AAAA-NNNN`, proposition du fichier 02, au-delà de quatre
 * chiffres sans troncature). Les formats des factures, reçus, avoirs et remboursements dépendent des
 * séries validées par le cabinet (fichier 15, Q03) : ils ne sont pas inventés ici.
 */
final class DocumentNumbers
{
    public const PATIENT = 'PATIENT';

    /** Préfixe de série du dossier patient (proposition du fichier 02 : « EE-AAAA-NNNN »). */
    private const PATIENT_PREFIX = 'EE';

    /**
     * @throws LogicException si le format de la série n'est pas encore défini
     */
    public static function render(string $type, int $year, int $value): string
    {
        if ($type !== self::PATIENT) {
            throw new LogicException('Format de numéro non défini pour la série '.$type.' : à valider avec le cabinet.');
        }

        return sprintf('%s-%04d-%s', self::PATIENT_PREFIX, $year, str_pad((string) $value, 4, '0', STR_PAD_LEFT));
    }

    /**
     * Année civile de la série au fuseau du cabinet (jamais en UTC : le 31 décembre à 23 h 30 UTC est déjà le
     * 1er janvier à Douala).
     */
    public static function yearOf(CommandContext $context, string $timezone): int
    {
        $milliseconds = (int) $context->now->format('Uv');

        return Instant::fromEpochMilliseconds($milliseconds)->civilDate($timezone)->year;
    }

    /**
     * Attribue le numéro suivant d'une série, en route directe (numéro central). Le verrou du cabinet est déjà
     * tenu par le chemin central ; celui de la séquence est pris ici dans l'ordre du contrat.
     *
     * @return array{value: int, rendered: string, year: int}
     */
    public static function allocateCentral(TransactionLocks $locks, CommandContext $context, string $type, string $timezone): array
    {
        $year = self::yearOf($context, $timezone);
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        $key = sprintf('%s:%04d', $type, $year);

        $row = $locks->sequences([[$type, $year]])[$key] ?? null;
        $next = $row === null ? 1 : ((int) $row->last_value) + 1;
        $rendered = self::render($type, $year, $next);

        if ($row === null) {
            DB::table('document_sequences')->insert([
                'id' => Ulid::generate(),
                'clinic_id' => $context->clinicId,
                'document_type' => $type,
                'document_year' => $year,
                'last_value' => $next,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('document_sequences')
                ->where('clinic_id', $context->clinicId)
                ->where('id', $row->id)
                ->update(['last_value' => $next, 'version' => ((int) $row->version) + 1, 'updated_at' => $now]);
        }

        return ['value' => $next, 'rendered' => $rendered, 'year' => $year];
    }
}
