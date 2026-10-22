<?php

namespace App\Domain\Billing\Snapshots;

use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Rate;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * Contenus figés des pièces (colonnes JSON `snapshot` du catalogue, fichier 11 : « un document est généré à partir
 * de son snapshot, jamais recalculé sur l'identité ou les prix récents »). Chaque forme est celle du schéma de
 * `contracts/snapshots/` (invoice-patient, invoice-clinic, invoice-tax) : le changement de nom du patient ou de prix
 * du catalogue n'altère donc jamais une pièce émise.
 *
 * Fonctions pures : aucune lecture en base, aucun envoi.
 */
final class InvoiceSnapshots
{
    /**
     * @param  stdClass  $patient  ligne `patient_records`
     * @return array{patient_id: string, record_number: string, last_name: string, first_names: ?string}
     */
    public static function patient(stdClass $patient): array
    {
        return [
            'patient_id' => (string) $patient->id,
            'record_number' => (string) $patient->record_number,
            'last_name' => (string) $patient->last_name,
            'first_names' => $patient->first_names === null ? null : (string) $patient->first_names,
        ];
    }

    /**
     * Un document est TEST tant que les paramètres réels ne sont pas validés : il faut à la fois le réglage du cabinet
     * (`clinics.production_documents_enabled`) ET l'interrupteur d'environnement (`PRODUCTION_DOCUMENTS_ENABLED`).
     * Les deux sont faux par défaut : la combinaison est volontairement la plus prudente (L0-06 la précisera).
     */
    public static function isTestDocument(stdClass $clinic): bool
    {
        return ! ((bool) $clinic->production_documents_enabled && (bool) config('eeo.production_documents_enabled', false));
    }

    /**
     * @param  stdClass  $clinic  ligne `clinics`
     * @return array<string, mixed>
     */
    public static function clinic(stdClass $clinic): array
    {
        $settings = $clinic->document_settings;

        return [
            'clinic_id' => (string) $clinic->id,
            'name' => (string) $clinic->name,
            'address' => $clinic->address === null ? null : (string) $clinic->address,
            'phone' => $clinic->phone === null ? null : (string) $clinic->phone,
            'email' => $clinic->email === null ? null : (string) $clinic->email,
            'tax_identifier' => $clinic->tax_identifier === null ? null : (string) $clinic->tax_identifier,
            'professional_mentions' => $clinic->professional_mentions === null ? null : (string) $clinic->professional_mentions,
            // Objet JSON (stdClass, jamais `[]` pour un objet vide) ou null.
            'document_settings' => is_string($settings) ? json_decode($settings, false, 512, JSON_THROW_ON_ERROR) : null,
            'currency' => (string) $clinic->currency,
            'timezone' => (string) $clinic->timezone,
            'test_document' => self::isTestDocument($clinic),
        ];
    }

    /**
     * Régime fiscal figé : aucune taxe n'est inventée. Sans règle validée appliquée, `NO_TAX_CONFIGURED` et une
     * liste vide ; sinon les règles RÉELLEMENT appliquées aux lignes, dans l'ordre de leur code.
     *
     * @param  array<string, stdClass>  $usedRules  règles actives et validées utilisées par au moins une ligne
     * @return array{regime: string, rules: list<array<string, mixed>>}
     */
    public static function tax(array $usedRules): array
    {
        if ($usedRules === []) {
            return ['regime' => 'NO_TAX_CONFIGURED', 'rules' => []];
        }

        $rules = array_values($usedRules);
        usort($rules, static fn (stdClass $left, stdClass $right): int => strcmp((string) $left->code, (string) $right->code));

        return [
            'regime' => 'VALIDATED_RULES',
            'rules' => array_map(static fn (stdClass $rule): array => [
                'tax_rule_id' => (string) $rule->id,
                'code' => (string) $rule->code,
                'label' => (string) $rule->label,
                'rate_percent' => self::rate($rule->rate_percent)?->canonical(),
                'validated_at' => $rule->validated_at === null ? null : CarbonImmutable::parse((string) $rule->validated_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'legal_note' => $rule->legal_note === null ? null : (string) $rule->legal_note,
            ], $rules),
        ];
    }

    private static function rate(mixed $stored): ?FixedDecimal
    {
        return $stored === null ? null : Rate::of((string) $stored);
    }
}
