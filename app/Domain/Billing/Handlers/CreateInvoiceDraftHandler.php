<?php

namespace App\Domain\Billing\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Access\Approvals\ExactApprovals;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\InvoiceDrafts;
use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\Rules\DraftLines;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Création d'un brouillon de facture (B02, EE020, EE021, EE045, AT07, AT08). Un brouillon est éditable, sans numéro
 * définitif ni créance : il n'a AUCUN effet sur le stock (la réserve n'est prise qu'à l'émission) et son total est un
 * projet, recalculé par les règles communes (`LineAmounts`, mêmes vecteurs que le futur client).
 *
 * Droits d'écriture des lignes (fichier 09) : la gérante (autorité de prix) fixe prix et remises ; les autres comptes
 * facturent au prix du catalogue, sans remise (plafond configurable, zéro par défaut) ; le profil « frais
 * d'examen » (`exam_fee.collect` seul) ne facture que la prestation fixe. Ce qui dépasse le droit est refusé
 * (`APPROVAL_REQUIRED_OR_EXPIRED`, champ par champ), jamais accepté puis corrigé. Le patient et la visite sont
 * contrôlés : même cabinet, fiche ni archivée ni fusionnée, visite du même patient.
 *
 * Verrous (contrat 23) : cabinet, idempotence, dossier patient (45), visite (46), facture (60).
 */
final class CreateInvoiceDraftHandler implements CommandHandler
{
    public const COMMAND = 'CreateInvoiceDraft';

    public function __construct(private readonly InvoiceDrafts $drafts, private readonly ExactApprovals $approvals) {}

    public function permissions(): array
    {
        return ['invoice.create', 'exam_fee.collect'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La création d\'un brouillon de facture');

        $payload = $command->payload;
        $invoiceId = (string) $payload['invoice_id'];
        $patientId = (string) $payload['patient_id'];
        $visitId = is_string($payload['visit_id'] ?? null) ? $payload['visit_id'] : null;
        $at = CarbonImmutable::now('UTC');
        $errors = new FieldErrors;

        $patient = $locks->patient($patientId);
        if ($patient === null) {
            $errors->add('patient_id', 'Patient introuvable dans ce cabinet.');
        } elseif ($patient->archived_at !== null || $patient->merged_into_patient_id !== null) {
            $errors->add('patient_id', 'Cette fiche est archivée ou fusionnée : ouvrez la fiche patient actuelle.');
        }

        if ($visitId !== null) {
            $visit = $locks->visit($visitId);
            if ($visit === null) {
                $errors->add('visit_id', 'Visite introuvable dans ce cabinet.');
            } elseif ((string) $visit->patient_id !== $patientId) {
                $errors->add('visit_id', 'Cette visite concerne un autre patient.');
            } elseif ((string) $visit->status === 'CANCELLED') {
                $errors->add('visit_id', 'Cette visite est annulée.');
            }
        }

        $locks->invoice($invoiceId);
        if (DB::table('invoices')->where('id', $invoiceId)->exists()) {
            $errors->add('invoice_id', 'Cet identifiant de facture est déjà utilisé.');
        }

        $dueDate = null;
        if (is_string($payload['due_date'] ?? null)) {
            try {
                $dueDate = CivilDate::fromString($payload['due_date'], 'due_date')->toString();
            } catch (RuleViolation $violation) {
                $errors->addViolation('', $violation);
            }
        }
        $errors->throwIfAny();

        /** @var list<array<string, mixed>> $payloadLines */
        $payloadLines = array_values($payload['lines']);
        // Dépassement de droits (prix, ligne libre, remise) : approbation exacte de la gérante pour CE brouillon et CES
        // lignes, demandée par ce compte, consommée ici (B06, AT07).
        $rightsApproval = null;
        $lines = $this->drafts->validated($context->clinicId, $invoiceId, $patientId, $payloadLines, $this->drafts->policyFor($context), true,
            function () use ($locks, $context, $invoiceId, $patientId, $payloadLines, &$rightsApproval): bool {
                $rightsApproval = $this->approvals->consumeMatching($locks, $context->clinicId, $context->now, ApprovalSubject::draftLineRights($invoiceId, $patientId, $payloadLines), $context->actorId);

                return $rightsApproval !== null;
            });
        try {
            $total = DraftLines::total($lines);
        } catch (RuleViolation $violation) {
            throw BusinessError::validation(['lines' => [$violation->getMessage()]]);
        }

        $now = $at->format('Y-m-d H:i:s.u');
        $currency = (string) $locks->clinic()->currency;
        DB::table('invoices')->insert([
            'id' => $invoiceId,
            'clinic_id' => $context->clinicId,
            'patient_id' => $patientId,
            'visit_id' => $visitId,
            'quote_id' => null,
            'number' => null,
            'issued_at' => null,
            'issued_by' => null,
            'number_usage_id' => null,
            'status' => 'DRAFT',
            'due_date' => $dueDate,
            'currency' => $currency,
            'total_gross' => $total,
            'patient_snapshot' => null,
            'clinic_snapshot' => null,
            'tax_snapshot' => null,
            'origin' => 'NATIVE',
            'legacy_reference' => null,
            'legacy_opening_balance' => null,
            'source_invoice_id' => null,
            'created_by' => $context->actorId,
            'source_device_id' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->drafts->write($context->clinicId, $invoiceId, $patientId, $lines, $at, false);

        return new CommandOutcome(
            result: ['invoice_id' => $invoiceId, 'version' => 1, 'status' => 'DRAFT', 'total_gross' => $total],
            resultType: 'invoices',
            resultId: $invoiceId,
            audit: [new AuditEntry('invoice.draft_created', 'invoices', $invoiceId, null, [
                'patient_id' => $patientId,
                'visit_id' => $visitId,
                'lines' => count($lines),
                'total_gross' => $total,
                'rights_approval_id' => $rightsApproval,
            ])],
            changes: [new ChangeRecord('invoices', $invoiceId, InvoiceLedger::publicationVersion(1, null), ChangeVisibility::Financial, ChangeKind::Created)],
        );
    }
}
