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
use App\Domain\Billing\BillingErrors;
use App\Domain\Billing\Guards\ActorRights;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\InvoiceDrafts;
use App\Domain\Billing\InvoiceLedger;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Abandon d'un brouillon de facture (fichier 03 : « Une facture brouillon peut être abandonnée ») : le brouillon passe
 * `DRAFT` → `ABANDONED` avec sa VERSION DE BROUILLON attendue (409 `VERSION_CONFLICT` si elle a changé). Rien d'autre :
 * un brouillon n'a ni numéro (aucun usage de numérotation à annuler, donc aucun trou), ni réserve de stock (la réserve
 * n'est prise qu'à l'émission, I00), ni pièce PDF. Ses lignes restent en base, immobiles : l'historique n'est jamais
 * effacé. Une facture émise ne s'abandonne pas (409 `STATE_TRANSITION_REFUSED` : avoir lié, B07).
 *
 * Même droit que la modification du brouillon : son créateur, ou la gérante. Le motif est facultatif (les sources
 * n'en exigent pas) ; s'il est donné, il est conservé dans l'audit, jamais ailleurs (aucune colonne ne le porte).
 * Une facture détenue par un poste (propriété d'écriture) ne s'abandonne que sur ce poste : 409
 * `WRITE_OWNER_REQUIRED`.
 *
 * Commande EN LIGNE seulement (hors du journal d'un poste), comme le catalogue. Verrous : cabinet, idempotence,
 * propriété de facture (50), facture (60).
 */
final class AbandonInvoiceDraftHandler implements CommandHandler
{
    public const COMMAND = 'AbandonInvoiceDraft';

    public function __construct(private readonly ActorRights $rights) {}

    public function permissions(): array
    {
        return ['invoice.create', 'exam_fee.collect'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'L\'abandon d\'un brouillon de facture');

        $payload = $command->payload;
        $invoiceId = (string) $payload['invoice_id'];
        $at = CarbonImmutable::now('UTC');

        $errors = new FieldErrors;
        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, 1000, 'reason');
        } catch (RuleViolation $violation) {
            $errors->addViolation('', $violation);
        }
        $errors->throwIfAny();

        foreach ($locks->invoiceOwners($invoiceId) as $owner) {
            if (in_array((string) $owner->status, ['ACTIVE', 'CLOSING'], true)) {
                $name = DB::table('enrolled_devices')->where('clinic_id', $context->clinicId)->where('id', $owner->device_id)->value('name');
                throw BillingErrors::writeOwnerRequired(is_string($name) ? $name : 'poste');
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
            throw BusinessError::forbidden('Ce brouillon appartient à un autre compte : seule la gérante l\'abandonne.');
        }

        $version = (int) $invoice->version + 1;
        DB::table('invoices')->where('clinic_id', $context->clinicId)->where('id', $invoiceId)->update([
            'status' => 'ABANDONED',
            'version' => $version,
            'updated_at' => $at->format('Y-m-d H:i:s.u'),
        ]);

        return new CommandOutcome(
            result: ['invoice_id' => $invoiceId, 'version' => $version, 'status' => 'ABANDONED'],
            resultType: 'invoices',
            resultId: $invoiceId,
            audit: [new AuditEntry('invoice.draft_abandoned', 'invoices', $invoiceId, $reason, [
                'version' => $version,
                'total_gross' => (int) $invoice->total_gross,
            ])],
            changes: [new ChangeRecord('invoices', $invoiceId, InvoiceLedger::publicationVersion($version, null), ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
