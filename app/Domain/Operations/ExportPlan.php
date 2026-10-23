<?php

namespace App\Domain\Operations;

/**
 * Plan d'un export contrôlé (X02, « restitution utilisable avec dictionnaire et pièces ») : tables exportées par
 * type, colonnes jamais exportées (secrets, jetons, chemins privés), filtre du dossier d'UN patient. Règles pures :
 * aucune lecture ni écriture ici (`App\Support\Exports\ExportBuilder` produit l'archive).
 *
 *   PATIENT_RECORD  le dossier d'un patient : identité, visites, consultations et toutes leurs rubriques, révisions,
 *                   prescriptions, ordonnances, contrôles recommandés (droit clinical.export) ;
 *   PATIENTS        les identités et visites (administratif, aucune donnée clinique ni financière) ;
 *   FINANCE         catalogue, factures, encaissements, reçus, corrections, caisse, créances reprises ;
 *   STOCK           articles, emplacements, positions, lots, mouvements, réserves, remises, retours ;
 *   FULL_HANDOVER   toutes les tables du cabinet et les pièces privées (remise des données à la fin du contrat).
 *
 * Les types autres que PATIENT_RECORD exigent export.global. Une sauvegarde ou un export du SERVEUR ne contient
 * jamais les saisies encore en attente sur un poste hors ligne (le manifeste le dit).
 */
final class ExportPlan
{
    public const TYPES = ['PATIENT_RECORD', 'PATIENTS', 'FINANCE', 'STOCK', 'FULL_HANDOVER'];

    /** Durée de mise à disposition d'une archive prête (heures). */
    public const RETENTION_HOURS = 48;

    private const PATIENTS = ['patient_records', 'visits', 'appointments'];

    private const FINANCE = [
        'tax_rules', 'catalog_items', 'quotes', 'quote_lines', 'quote_revisions', 'invoices', 'invoice_lines', 'payments',
        'receipts', 'payment_reversals', 'credit_notes', 'credit_note_lines', 'refunds', 'legacy_balance_imports',
        'cash_registers', 'cash_sessions', 'cash_movements', 'document_number_usages',
    ];

    private const STOCK = [
        'catalog_items', 'stock_locations', 'stock_positions', 'stock_lots', 'stock_movements', 'stock_reservations',
        'deliveries', 'delivery_lines', 'physical_returns', 'suppliers', 'goods_receipts', 'goods_receipt_lines',
        'inventory_counts', 'inventory_count_lines',
    ];

    /** Tables du dossier patient : directement par `patient_id`. */
    private const RECORD_BY_PATIENT = ['patient_records', 'visits', 'consultations', 'optical_prescriptions', 'follow_up_plans'];

    /** Tables du dossier patient : par la consultation du patient. */
    private const RECORD_BY_CONSULTATION = [
        'consultation_complaints', 'consultation_histories', 'consultation_ametropias',
        'consultation_referral_reasons', 'visual_acuities', 'refraction_measurements', 'autorefractor_centering',
        'clinical_revisions', 'medication_orders',
    ];

    /** Tables du dossier patient : par la rubrique d'antécédents (`history_id`) d'une consultation du patient. */
    private const RECORD_BY_HISTORY = ['consultation_history_terms'];

    /** Tables du dossier patient : par la prescription du patient. */
    private const RECORD_BY_PRESCRIPTION = ['optical_prescription_eyes', 'optical_prescription_treatments'];

    /** Tables jamais exportées, même pour une remise complète (sessions, jetons, files techniques). */
    private const NEVER = ['idempotency_requests', 'sync_commands', 'sync_command_results', 'webhook_inbox', 'outbox_events'];

    /** Colonnes jamais exportées : secrets, jetons, chemins privés du serveur. */
    private const EXCLUDED_COLUMNS = [
        'users' => ['password_hash', 'mfa_secret_ciphertext', 'remember_token'],
        'stored_files' => ['private_path', 'encryption_key_id'],
        'calendar_sync_states' => ['sync_token_ciphertext', 'token_encryption_key_id', 'pending_page_token'],
        'public_booking_requests' => ['management_token_hash'],
        'backup_runs' => ['private_destination'],
    ];

    public static function permissionFor(string $type): string
    {
        return $type === 'PATIENT_RECORD' ? 'clinical.export' : 'export.global';
    }

    /**
     * Tables exportées pour un type, dans l'ordre de l'archive. Pour la remise complète, toutes les tables du
     * cabinet connues du catalogue sauf les tables techniques jamais exportées.
     *
     * @param  list<string>  $clinicTables  tables qui portent `clinic_id` (catalogue du schéma)
     * @return list<string>
     */
    public static function tables(string $type, array $clinicTables): array
    {
        $list = match ($type) {
            'PATIENT_RECORD' => [...self::RECORD_BY_PATIENT, ...self::RECORD_BY_CONSULTATION, ...self::RECORD_BY_HISTORY, ...self::RECORD_BY_PRESCRIPTION],
            'PATIENTS' => self::PATIENTS,
            'FINANCE' => self::FINANCE,
            'STOCK' => self::STOCK,
            'FULL_HANDOVER' => array_values(array_diff($clinicTables, self::NEVER)),
            default => [],
        };

        return array_values(array_unique(array_values(array_intersect($list, $clinicTables))));
    }

    /**
     * Filtre d'un dossier patient pour une table : la colonne et la source des identifiants.
     *
     * @return array{column: string, source: 'patient'|'consultation'|'history'|'prescription'}|null
     */
    public static function recordFilter(string $table): ?array
    {
        if ($table === 'patient_records') {
            return ['column' => 'id', 'source' => 'patient'];
        }
        if (in_array($table, self::RECORD_BY_PATIENT, true)) {
            return ['column' => 'patient_id', 'source' => 'patient'];
        }
        if (in_array($table, self::RECORD_BY_CONSULTATION, true)) {
            return ['column' => 'consultation_id', 'source' => 'consultation'];
        }
        if (in_array($table, self::RECORD_BY_HISTORY, true)) {
            return ['column' => 'history_id', 'source' => 'history'];
        }
        if (in_array($table, self::RECORD_BY_PRESCRIPTION, true)) {
            return ['column' => 'prescription_id', 'source' => 'prescription'];
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function exportedColumns(string $table, array $columns): array
    {
        $excluded = self::EXCLUDED_COLUMNS[$table] ?? [];

        return array_values(array_filter($columns, static fn (string $column): bool => ! in_array($column, $excluded, true)));
    }

    /** Les pièces privées (originaux PDF, justificatifs) accompagnent la seule remise complète. */
    public static function includesFiles(string $type): bool
    {
        return $type === 'FULL_HANDOVER';
    }
}
