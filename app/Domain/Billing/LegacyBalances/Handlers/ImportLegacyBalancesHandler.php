<?php

namespace App\Domain\Billing\LegacyBalances\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\LegacyBalances\LegacyBalanceImport;
use App\Domain\Billing\Numbering\BillingNumbers;
use App\Domain\Billing\Numbering\NumberUsages;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Billing\Snapshots\InvoiceSnapshots;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reprise des créances papier (X01, AT26, AT39), EN LIGNE, par la gérante (`finance.global.read`, réauthentification
 * récente sur la route) : une ou plusieurs créances (saisie unitaire ou fichier), TOUTES reprises dans la même
 * transaction ou AUCUNE (422 ligne par ligne). Chaque créance devient une pièce ÉMISE d'origine `LEGACY_BALANCE` :
 *
 * - son total est le SEUL reste dû (`legacy_opening_balance = total_gross`, ck_invoices_03) : ni chiffre d'affaires
 *   du jour (la vente appartient au carnet papier, `vw_revenue_events` ne retient que les factures NATIVE), ni
 *   encaissement ni reçu pour l'ancien acompte, ni ligne d'article, ni mouvement de stock ;
 * - numéro de la série `LEGACY_BALANCE` (préfixe distinct, sans trou : un échec annule aussi les numéros) ;
 * - snapshots de l'identité du patient, du cabinet et du régime fiscal (aucune taxe recalculée sur une pièce papier) ;
 * - la ligne `legacy_balance_imports` conserve la référence papier (unique), le total d'origine, l'acompte déjà
 *   payé, le reste dû, la date de la pièce et le justificatif scanné (empreinte obligatoire), en ajout seul.
 *
 * Le reste dû se règle ensuite par l'encaissement normal (`RegisterPayment` : reçu unique par encaissement).
 * Verrous : cabinet, idempotence, séquence LEGACY_BALANCE (40), dossiers patients (45, par identifiant croissant).
 */
final class ImportLegacyBalancesHandler implements CommandHandler
{
    public const COMMAND = 'ImportLegacyBalances';

    public function permissions(): array
    {
        return ['finance.global.read'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La reprise d\'une créance papier');

        $payload = $command->payload;
        if (($payload['dry_run'] ?? null) !== false) {
            throw BusinessError::validation(['dry_run' => ['La reprise enregistre les créances : dry_run vaut false (l\'aperçu ne passe pas par cette commande).']]);
        }
        $rows = $payload['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || $rows === [] || count($rows) > LegacyBalanceImport::MAX_ROWS) {
            throw BusinessError::validation(['rows' => ['Entre 1 et '.LegacyBalanceImport::MAX_ROWS.' créances sont attendues.']]);
        }

        $clinic = $locks->clinic();
        $timezone = (string) $clinic->timezone;
        $at = CarbonImmutable::now('UTC');

        $evidence = is_string($payload['evidence_file_id'] ?? null)
            ? DB::table('stored_files')->where('clinic_id', $context->clinicId)->where('id', $payload['evidence_file_id'])->first()
            : null;
        if ($evidence === null || $evidence->classification !== 'FINANCIAL' || $evidence->archived_at !== null) {
            throw BusinessError::validation(['evidence_file_id' => ['Déposez d\'abord le justificatif scanné de la pièce papier.']]);
        }

        $check = LegacyBalanceImport::check($context->clinicId, $timezone, $at, $rows);
        if ($check['rejected'] !== []) {
            $fields = [];
            foreach ($check['rejected'] as $rejection) {
                $path = 'rows.'.$rejection['index'].($rejection['field'] === null ? '' : '.'.$rejection['field']);
                $fields[$path][] = 'Ligne '.$rejection['line'].' : '.$rejection['message'];
            }
            throw BusinessError::validation($fields, 'Aucune créance n\'a été reprise : certaines lignes sont à corriger.');
        }

        // 1. Numéros (rang 40), dans l'ordre des lignes, avant les dossiers patients (rang 45).
        $numbers = [];
        foreach ($check['accepted'] as $item) {
            $numbers[] = BillingNumbers::allocate($locks, $context, BillingNumbers::LEGACY_BALANCE, $timezone, $at);
        }

        // 2. Dossiers patients, par identifiant croissant (le verrouillage les trie) ; relus sous verrou.
        $patientIds = array_values(array_unique(array_map(static fn (array $item): string => (string) $item['patient']->id, $check['accepted'])));
        sort($patientIds, SORT_STRING);
        $patients = [];
        foreach ($patientIds as $patientId) {
            $patient = $locks->patient($patientId);
            if ($patient === null || $patient->merged_into_patient_id !== null) {
                throw BusinessError::validation(['rows' => ['Un dossier patient a changé pendant la reprise : vérifiez de nouveau le fichier.']]);
            }
            $patients[$patientId] = $patient;
        }

        $nowDb = $at->format('Y-m-d H:i:s.u');
        $clinicSnapshot = self::json(InvoiceSnapshots::clinic($clinic));
        $taxSnapshot = self::json(InvoiceSnapshots::tax([]));
        $created = [];
        $imported = [];
        $audit = [];
        $changes = [];
        foreach ($check['accepted'] as $position => $item) {
            $number = $numbers[$position];
            $patient = $patients[(string) $item['patient']->id];
            $invoiceId = Ulid::generate();
            $usageId = Ulid::generate();
            $opening = $item['opening_balance'];

            // Ordre des écritures : usage du numéro (clé étrangère de la facture), facture ÉMISE, ligne de reprise.
            NumberUsages::record($context, $usageId, BillingNumbers::LEGACY_BALANCE, $number, 'invoices', $invoiceId, $at);
            DB::table('invoices')->insert([
                'id' => $invoiceId,
                'clinic_id' => $context->clinicId,
                'patient_id' => (string) $patient->id,
                'visit_id' => null,
                'quote_id' => null,
                'number' => $number['rendered'],
                'issued_at' => $nowDb,
                'issued_by' => (string) $context->actorId,
                'number_usage_id' => $usageId,
                'status' => 'ISSUED',
                'due_date' => null,
                'currency' => 'XAF',
                'total_gross' => $opening,
                'patient_snapshot' => self::json(InvoiceSnapshots::patient($patient)),
                'clinic_snapshot' => $clinicSnapshot,
                'tax_snapshot' => $taxSnapshot,
                'origin' => 'LEGACY_BALANCE',
                'legacy_reference' => $item['paper_reference'],
                'legacy_opening_balance' => $opening,
                'source_invoice_id' => null,
                'created_by' => (string) $context->actorId,
                'source_device_id' => null,
                'version' => 1,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
            ]);
            DB::table('legacy_balance_imports')->insert([
                'id' => Ulid::generate(),
                'clinic_id' => $context->clinicId,
                'patient_id' => (string) $patient->id,
                'invoice_id' => $invoiceId,
                'paper_reference' => $item['paper_reference'],
                'original_total' => $item['original_total'],
                'previously_paid' => $item['already_paid'],
                'opening_balance' => $opening,
                'original_invoice_on' => $item['original_invoice_on'],
                'evidence_file_id' => (string) $evidence->id,
                'evidence_sha256' => (string) $evidence->sha256,
                'validated_by' => (string) $context->actorId,
                'validated_at' => $nowDb,
                'created_at' => $nowDb,
                'updated_at' => $nowDb,
            ]);

            $ledger = LedgerBalance::of($opening, [], [], [], []);
            $created[] = $invoiceId;
            $imported[] = ['line' => $item['line'], 'invoice_id' => $invoiceId, 'number' => $number['rendered'], 'opening_balance' => $opening];
            $audit[] = new AuditEntry('legacy_balance.imported', 'invoices', $invoiceId, null, [
                'number' => $number['rendered'],
                'patient_id' => (string) $patient->id,
                'paper_reference' => $item['paper_reference'],
                'original_total' => $item['original_total'],
                'already_paid' => $item['already_paid'],
                'opening_balance' => $opening,
                'original_invoice_on' => $item['original_invoice_on'],
                'evidence_sha256' => (string) $evidence->sha256,
                'line' => $item['line'],
            ]);
            $changes[] = new ChangeRecord('invoices', $invoiceId, InvoiceLedger::publicationVersion(1, $ledger->ledgerVersion), ChangeVisibility::Financial, ChangeKind::Created);
        }

        return new CommandOutcome(
            result: ['created_ids' => $created, 'imported' => $imported, 'rows' => count($rows)],
            resultType: 'invoices',
            resultId: $created[0],
            audit: $audit,
            changes: $changes,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function json(array $snapshot): string
    {
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
