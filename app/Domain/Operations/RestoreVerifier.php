<?php

namespace App\Domain\Operations;

use App\Domain\Shared\CanonicalJson;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Vérification d'une base RESTAURÉE sur un environnement isolé (X03 ; AT27 ; EE040–EE042 ; fichier 09 « Sauvegardes et
 * incident » : « restaurer en environnement contrôlé, réconcilier pièces/paiements depuis la dernière sauvegarde »).
 * Lecture seule sur la connexion fournie ; jamais la base de l'application (le contrôle du nom est dans la commande
 * `eeo:restore:verify`). Aucune donnée de patient n'est rendue : identifiants techniques, codes et comptes seulement.
 *
 * Contrôles :
 *   SCHEMA_TABLES        les 117 tables du catalogue sont présentes (le schéma complet : `eagle:verify-schema`) ;
 *   INTEGRITY            les diagnostics de `database/05_integrity_checks.sql` rendent zéro ligne ;
 *   SEQUENCES            aucune remise à zéro : chaque séquence est au moins au plus haut numéro consommé ET au bout
 *                        de chaque plage réservée à un poste (une plage hors-ligne n'est jamais réattribuée) ;
 *   NUMBERED_DOCUMENTS   factures, reçus, avoirs et remboursements émis gardent le numéro de leur consommation ;
 *   AUDIT_HASHES         l'empreinte de chaque événement d'audit se recalcule à l'identique ;
 *   STORED_FILES         chaque fichier privé (PDF originaux, justificatifs, exports) est présent avec son empreinte ;
 *   OFFLINE_RECONCILIATION  (alerte, pas un échec) plages de numéros et quotas de stock encore ouverts sur des postes,
 *                        commandes reçues non traitées : ces postes peuvent détenir des pièces locales plus récentes que
 *                        la sauvegarde ; ils se synchronisent (ACK) AVANT toute réouverture des ventes.
 * Faits relevés (`facts`) : dernier reçu, dernière facture émise, dernier paiement, dernier événement d'audit, dernière
 * commande reçue d'un poste : ils servent à mesurer la perte (RPO) contre la base vivante.
 */
final class RestoreVerifier
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /** Détails rendus au plus par contrôle (le compte reste exact). */
    private const DETAIL_LIMIT = 20;

    /** Lignes d'audit relues par lot. */
    private const AUDIT_CHUNK = 500;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  (Closure(string): (string|null))|null  $fileDigest  empreinte SHA-256 du fichier privé restauré (chemin
     *                                                             relatif de `stored_files.private_path`), null s'il
     *                                                             manque ; sans fonction, les fichiers ne sont pas
     *                                                             vérifiés (alerte, jamais un succès présumé)
     * @return array{status: string, checks: list<array{code: string, label: string, status: string, count: int, details: list<string>}>, facts: array<string, mixed>}
     */
    public function verify(?Closure $fileDigest): array
    {
        $checks = [
            $this->schemaTables(),
            $this->integrity(),
            $this->sequences(),
            $this->numberedDocuments(),
            $this->auditHashes(),
            $this->storedFiles($fileDigest),
            $this->offlineReconciliation(),
        ];
        $statuses = array_column($checks, 'status');
        $status = in_array(self::FAIL, $statuses, true) ? self::FAIL : (in_array(self::WARN, $statuses, true) ? self::WARN : self::PASS);

        return ['status' => $status, 'checks' => $checks, 'facts' => $this->facts()];
    }

    /**
     * Derniers faits datés de la base (UTC, format de la base), pour la mesure de la perte.
     *
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        $receipt = $this->db->table('receipts')->orderByDesc('issued_at')->orderByDesc('id')->first(['number', 'issued_at']);
        $invoice = $this->db->table('invoices')->where('status', 'ISSUED')->orderByDesc('issued_at')->orderByDesc('id')->first(['number', 'issued_at']);

        return [
            'last_receipt_number' => $receipt?->number,
            'last_receipt_at' => $receipt?->issued_at,
            'last_invoice_number' => $invoice?->number,
            'last_invoice_at' => $invoice?->issued_at,
            'last_payment_at' => $this->db->table('payments')->max('created_at'),
            'last_audit_at' => $this->db->table('audit_events')->max('recorded_at'),
            'last_sync_received_at' => $this->db->table('sync_commands')->max('received_at'),
            'receipts' => $this->db->table('receipts')->count(),
            'invoices_issued' => $this->db->table('invoices')->where('status', 'ISSUED')->count(),
        ];
    }

    /**
     * @return array{code: string, label: string, status: string, count: int, details: list<string>}
     */
    private function schemaTables(): array
    {
        $catalog = json_decode((string) file_get_contents(base_path('database/schema_catalog.json')), true, 512, JSON_THROW_ON_ERROR);
        $expected = array_map(static fn (array $table): string => (string) $table['name'], $catalog['tables']);
        $present = array_map(
            static fn (stdClass $row): string => (string) $row->name,
            $this->db->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ?', ['BASE TABLE']),
        );
        $missing = array_values(array_diff($expected, $present));

        return self::check('SCHEMA_TABLES', 'Tables du catalogue présentes ('.count($expected).')', $missing === [] ? self::PASS : self::FAIL, count($missing), $missing);
    }

    /**
     * @return array{code: string, label: string, status: string, count: int, details: list<string>}
     */
    private function integrity(): array
    {
        $issues = [];
        $total = 0;
        foreach (self::diagnostics() as $statement) {
            $rows = $this->db->select($statement);
            if ($rows === []) {
                continue;
            }
            $code = (string) ($rows[0]->issue ?? 'DIAGNOSTIC');
            $issues[$code] = ($issues[$code] ?? 0) + count($rows);
            $total += count($rows);
        }
        $details = [];
        foreach ($issues as $code => $count) {
            $details[] = $code.' : '.$count.' ligne(s)';
        }

        return self::check('INTEGRITY', 'Diagnostics d\'intégrité (database/05_integrity_checks.sql) sans ligne', $total === 0 ? self::PASS : self::FAIL, $total, $details);
    }

    /**
     * @return array{code: string, label: string, status: string, count: int, details: list<string>}
     */
    private function sequences(): array
    {
        $details = [];
        $usages = $this->db->select(
            'SELECT u.clinic_id, u.document_type, u.document_year, MAX(u.number_value) AS highest, MAX(s.last_value) AS sequence_value
               FROM document_number_usages u
               LEFT JOIN document_sequences s
                 ON s.clinic_id = u.clinic_id AND s.document_type = u.document_type AND s.document_year = u.document_year
              GROUP BY u.clinic_id, u.document_type, u.document_year
             HAVING MAX(s.last_value) IS NULL OR MAX(u.number_value) > MAX(s.last_value)'
        );
        foreach ($usages as $row) {
            $details[] = sprintf('%s %s : séquence à %s, numéro %d déjà consommé', $row->document_type, $row->document_year, $row->sequence_value ?? 'absente', (int) $row->highest);
        }
        $ranges = $this->db->select(
            'SELECT a.document_type, a.document_year, MAX(a.range_end) AS highest, MAX(s.last_value) AS sequence_value
               FROM document_number_allocations a
               LEFT JOIN document_sequences s
                 ON s.clinic_id = a.clinic_id AND s.document_type = a.document_type AND s.document_year = a.document_year
              GROUP BY a.clinic_id, a.document_type, a.document_year
             HAVING MAX(s.last_value) IS NULL OR MAX(a.range_end) > MAX(s.last_value)'
        );
        foreach ($ranges as $row) {
            $details[] = sprintf('%s %s : séquence à %s, plage d\'un poste jusqu\'à %d', $row->document_type, $row->document_year, $row->sequence_value ?? 'absente', (int) $row->highest);
        }

        return self::check('SEQUENCES', 'Séquences de numéros jamais remises à zéro', $details === [] ? self::PASS : self::FAIL, count($details), $details);
    }

    /**
     * @return array{code: string, label: string, status: string, count: int, details: list<string>}
     */
    private function numberedDocuments(): array
    {
        $details = [];
        $sources = [
            'invoices' => "t.status = 'ISSUED'",
            'receipts' => '1 = 1',
            'credit_notes' => "t.status = 'ISSUED'",
            'refunds' => 't.number IS NOT NULL',
        ];
        foreach ($sources as $table => $condition) {
            $rows = $this->db->select(
                "SELECT t.id FROM {$table} t
                   LEFT JOIN document_number_usages u ON u.clinic_id = t.clinic_id AND u.id = t.number_usage_id
                  WHERE {$condition}
                    AND (u.id IS NULL OR u.rendered_number <> t.number OR u.entity_id <> t.id OR u.status <> 'USED')"
            );
            foreach ($rows as $row) {
                $details[] = $table.' '.$row->id;
            }
        }

        return self::check('NUMBERED_DOCUMENTS', 'Pièces émises liées à la consommation de leur numéro', $details === [] ? self::PASS : self::FAIL, count($details), $details);
    }

    /**
     * @return array{code: string, label: string, status: string, count: int, details: list<string>}
     */
    private function auditHashes(): array
    {
        $altered = [];
        $checked = 0;
        $this->db->table('audit_events')->orderBy('id')->chunk(self::AUDIT_CHUNK, function (Collection $rows) use (&$altered, &$checked): void {
            foreach ($rows as $row) {
                $checked++;
                if (! hash_equals((string) $row->event_hash, self::auditHash($row))) {
                    $altered[] = (string) $row->id;
                }
            }
        });

        return self::check('AUDIT_HASHES', 'Empreintes des '.$checked.' événements d\'audit recalculées', $altered === [] ? self::PASS : self::FAIL, count($altered), $altered);
    }

    /**
     * @param  (Closure(string): (string|null))|null  $fileDigest
     * @return array{code: string, label: string, status: string, count: int, details: list<string>}
     */
    private function storedFiles(?Closure $fileDigest): array
    {
        if ($fileDigest === null) {
            $total = $this->db->table('stored_files')->whereNull('archived_at')->count();

            return self::check('STORED_FILES', 'Fichiers privés non vérifiés : dossier des fichiers restaurés non fourni', self::WARN, $total, []);
        }
        $details = [];
        $checked = 0;
        foreach ($this->db->table('stored_files')->whereNull('archived_at')->orderBy('id')->cursor() as $file) {
            $checked++;
            $digest = $fileDigest((string) $file->private_path);
            if ($digest === null) {
                $details[] = 'manquant : fichier '.$file->id.' ('.$file->classification.')';
            } elseif (! hash_equals((string) $file->sha256, $digest)) {
                $details[] = 'empreinte différente : fichier '.$file->id.' ('.$file->classification.')';
            }
        }

        return self::check('STORED_FILES', 'Fichiers privés restaurés avec leur empreinte ('.$checked.')', $details === [] ? self::PASS : self::FAIL, count($details), $details);
    }

    /**
     * @return array{code: string, label: string, status: string, count: int, details: list<string>}
     */
    private function offlineReconciliation(): array
    {
        $details = [];
        $ranges = $this->db->table('document_number_allocations as a')
            ->leftJoin('enrolled_devices as d', static function (JoinClause $join): void {
                $join->on('d.clinic_id', '=', 'a.clinic_id')->on('d.id', '=', 'a.device_id');
            })
            ->whereIn('a.status', ['ACTIVE', 'CLOSING'])
            ->orderBy('d.device_code')->orderBy('a.document_type')
            ->get(['d.device_code', 'd.name', 'a.document_type', 'a.document_year', 'a.range_start', 'a.range_end', 'a.last_consumed_value']);
        foreach ($ranges as $range) {
            $details[] = sprintf(
                'poste %s : %s %s, plage %d–%d, dernier numéro connu du serveur %s',
                $range->device_code ?? '?',
                $range->document_type,
                $range->document_year,
                (int) $range->range_start,
                (int) $range->range_end,
                $range->last_consumed_value === null ? 'aucun' : (string) $range->last_consumed_value,
            );
        }
        $quotas = $this->db->table('device_stock_allocations')->whereIn('status', ['ACTIVE', 'CLOSING'])->count();
        if ($quotas > 0) {
            $details[] = $quotas.' quota(s) de stock encore ouvert(s) sur des postes';
        }
        $pending = $this->db->table('sync_commands as c')
            ->leftJoin('sync_command_results as r', static function (JoinClause $join): void {
                $join->on('r.clinic_id', '=', 'c.clinic_id')->on('r.command_id', '=', 'c.id');
            })
            ->where(static function (Builder $query): void {
                $query->whereNull('r.id')->orWhereIn('r.status', ['RECEIVED', 'WAITING_DEPENDENCY', 'CONFLICT_REQUIRES_REVIEW']);
            })
            ->count();
        if ($pending > 0) {
            $details[] = $pending.' commande(s) de poste reçue(s) et non conclue(s)';
        }

        return self::check('OFFLINE_RECONCILIATION', 'Postes à synchroniser avant la réouverture', $details === [] ? self::PASS : self::WARN, count($details), $details);
    }

    /**
     * Empreinte recalculée d'un événement d'audit, comme `AuditRecorder::recordFor` : contenu canonique de l'événement
     * sans `event_hash`, différence nettoyée relue depuis son JSON canonique.
     */
    public static function auditHash(stdClass $row): string
    {
        $diff = $row->sanitized_diff;

        return CanonicalJson::hash([
            'id' => (string) $row->id,
            'clinic_id' => (string) $row->clinic_id,
            'actor_id' => $row->actor_id,
            'actor_name_snapshot' => (string) $row->actor_name_snapshot,
            'action' => (string) $row->action,
            'target_type' => (string) $row->target_type,
            'target_id' => $row->target_id,
            'recorded_at' => (string) $row->recorded_at,
            'correlation_id' => (string) $row->correlation_id,
            'reason' => $row->reason,
            'sanitized_diff' => is_string($diff) ? json_decode($diff, true, 512, JSON_THROW_ON_ERROR) : null,
            'previous_hash' => $row->previous_hash,
        ]);
    }

    /**
     * Requêtes de diagnostic du fichier versionné, une par instruction (commentaires retirés).
     *
     * @return list<string>
     */
    public static function diagnostics(): array
    {
        $sql = (string) file_get_contents(base_path('database/05_integrity_checks.sql'));
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '--'),
        );
        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * @param  list<string>  $details
     * @return array{code: string, label: string, status: string, count: int, details: list<string>}
     */
    private static function check(string $code, string $label, string $status, int $count, array $details): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'status' => $status,
            'count' => $count,
            'details' => array_slice($details, 0, self::DETAIL_LIMIT),
        ];
    }
}
