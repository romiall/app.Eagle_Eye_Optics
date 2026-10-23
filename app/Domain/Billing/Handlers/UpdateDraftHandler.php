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
use App\Domain\Billing\BillingErrors;
use App\Domain\Billing\Guards\ActorRights;
use App\Domain\Billing\Guards\AuthoredSource;
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
 * Modification d'un brouillon avec sa VERSION DE BROUILLON attendue (`invoices.version`, figée à l'émission) : la
 * liste de lignes REMPLACE la précédente et le total est recalculé. Une version périmée donne 409 `VERSION_CONFLICT`
 * avec la version à reprendre : l'écran conserve la saisie (B02 : « conflit conserve formulaire »).
 *
 * Le brouillon est éditable par son créateur ou par la gérante (fichier 03 : « éditable par le créateur habilité ») ;
 * les droits d'écriture des lignes sont ceux de l'auteur de CETTE modification (voir `CreateInvoiceDraftHandler`).
 * Une facture émise ou abandonnée ne se modifie plus : 409 `STATE_TRANSITION_REFUSED`. Aucun effet sur le stock.
 *
 * Verrous : cabinet, idempotence, visite (46) si elle change, facture (60).
 */
final class UpdateDraftHandler implements CommandHandler
{
    public const COMMAND = 'UpdateDraft';

    public function __construct(private readonly InvoiceDrafts $drafts, private readonly ActorRights $rights, private readonly ExactApprovals $approvals) {}

    public function permissions(): array
    {
        return ['invoice.create', 'exam_fee.collect'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        AuthoredSource::assert($context, 'La modification d\'un brouillon de facture');

        $payload = $command->payload;
        $invoiceId = (string) $payload['invoice_id'];
        $visitId = is_string($payload['visit_id'] ?? null) ? $payload['visit_id'] : null;
        $at = CarbonImmutable::now('UTC');

        // Lecture sans verrou pour connaître le patient (rang 45 < visite 46 < facture 60), puis lecture sous verrou.
        $peek = DB::table('invoices')->where('clinic_id', $context->clinicId)->where('id', $invoiceId)->first();
        if ($peek === null) {
            throw BusinessError::notFound('Facture introuvable dans ce cabinet.');
        }
        $patientId = (string) $peek->patient_id;

        $errors = new FieldErrors;
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
        $invoice = $locks->invoice($invoiceId);
        if ($invoice === null) {
            throw BusinessError::notFound('Facture introuvable dans ce cabinet.');
        }
        InvoiceDrafts::assertDraft($invoice);
        if ($command->expectedVersion === null || (int) $invoice->version !== $command->expectedVersion) {
            throw BillingErrors::versionConflict((int) $invoice->version);
        }
        if ((string) $invoice->created_by !== (string) $context->actorId && ! $this->rights->manager($context)) {
            throw BusinessError::forbidden('Ce brouillon appartient à un autre compte : seule la gérante le modifie.');
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
        // Dépassement de droits : approbation exacte de la gérante pour CE brouillon et CES lignes (B06, AT07).
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

        $version = (int) $invoice->version + 1;
        DB::table('invoices')->where('clinic_id', $context->clinicId)->where('id', $invoiceId)->update([
            'visit_id' => $visitId,
            'due_date' => $dueDate,
            'total_gross' => $total,
            'version' => $version,
            'updated_at' => $at->format('Y-m-d H:i:s.u'),
        ]);
        $this->drafts->write($context->clinicId, $invoiceId, $patientId, $lines, $at, true);

        return new CommandOutcome(
            result: ['invoice_id' => $invoiceId, 'version' => $version, 'status' => 'DRAFT', 'total_gross' => $total],
            resultType: 'invoices',
            resultId: $invoiceId,
            audit: [new AuditEntry('invoice.draft_updated', 'invoices', $invoiceId, null, [
                'version' => $version,
                'lines' => count($lines),
                'total_gross_before' => (int) $invoice->total_gross,
                'total_gross_after' => $total,
                'rights_approval_id' => $rightsApproval,
            ])],
            changes: [new ChangeRecord('invoices', $invoiceId, InvoiceLedger::publicationVersion($version, null), ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
