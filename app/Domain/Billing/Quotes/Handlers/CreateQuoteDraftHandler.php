<?php

namespace App\Domain\Billing\Quotes\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\Guards\CentralOnly;
use App\Domain\Billing\Quotes;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Brouillon de devis (L1D07) : destinataire (un patient du cabinet, ou un prospect saisi ici, jamais les deux), date
 * de validité facultative, lignes du catalogue soumises aux droits de l'auteur (mêmes règles qu'un brouillon de
 * facture). Aucun numéro, aucun effet sur le stock, aucune créance.
 *
 * Le prospect (`prospect_contacts`, origine MANUAL) est une personne qui n'a pas encore de dossier : nom et téléphone
 * seulement, aucune donnée clinique. Il se rattachera explicitement à un dossier patient à la conversion.
 *
 * Commande EN LIGNE. Verrous : cabinet, idempotence, dossier patient (45), devis (55).
 */
final class CreateQuoteDraftHandler implements CommandHandler
{
    public const COMMAND = 'CreateQuoteDraft';

    public function __construct(private readonly Quotes $quotes) {}

    public function permissions(): array
    {
        return ['invoice.create'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        CentralOnly::assert($context, 'La préparation d\'un devis');

        $payload = $command->payload;
        $quoteId = (string) $payload['quote_id'];
        $patientId = is_string($payload['patient_id'] ?? null) ? $payload['patient_id'] : null;
        $prospect = is_array($payload['prospect'] ?? null) ? $payload['prospect'] : null;
        $at = CarbonImmutable::now('UTC');
        $clinic = $locks->clinic();
        $errors = new FieldErrors;

        if (($patientId === null) === ($prospect === null)) {
            $errors->add('patient_id', 'Un devis vise exactement un patient OU un prospect : choisissez l\'un des deux.');
        }

        if ($patientId !== null) {
            $patient = $locks->patient($patientId);
            if ($patient === null) {
                $errors->add('patient_id', 'Patient introuvable dans ce cabinet.');
            } elseif ($patient->archived_at !== null || $patient->merged_into_patient_id !== null) {
                $errors->add('patient_id', 'Cette fiche est archivée ou fusionnée : ouvrez la fiche patient actuelle.');
            }
        }

        $prospectName = null;
        $prospectPhone = null;
        $prospectPhoneNormalized = null;
        if ($prospect !== null) {
            try {
                $prospectName = Text::optional(is_string($prospect['name'] ?? null) ? $prospect['name'] : null, 180, 'prospect.name');
                if ($prospectName === null) {
                    $errors->add('prospect.name', 'Le nom du prospect est obligatoire.');
                }
            } catch (RuleViolation $violation) {
                $errors->addViolation('', $violation);
            }
            try {
                $phone = is_string($prospect['phone'] ?? null) ? $prospect['phone'] : null;
                $prospectPhone = Text::optional($phone, 32, 'prospect.phone');
                $prospectPhoneNormalized = Text::phone($prospectPhone, 'prospect.phone');
            } catch (RuleViolation $violation) {
                $errors->addViolation('', $violation);
            }
        }

        $existing = $locks->quote($quoteId);
        if ($existing !== null || DB::table('quotes')->where('id', $quoteId)->exists()) {
            $errors->add('quote_id', 'Cet identifiant de devis est déjà utilisé.');
        }

        $validUntil = Quotes::validUntil($payload['valid_until'] ?? null, Quotes::today($clinic, $at), false, $errors);
        $errors->throwIfAny();

        /** @var list<array<string, mixed>> $payloadLines */
        $payloadLines = array_values($payload['lines']);
        $lines = $this->quotes->validatedLines($context->clinicId, $quoteId, $payloadLines, $this->quotes->policyFor($context));
        $total = Quotes::total($lines);

        $now = $at->format('Y-m-d H:i:s.u');
        $prospectId = null;
        if ($prospect !== null) {
            $prospectId = Ulid::generate();
            DB::table('prospect_contacts')->insert([
                'id' => $prospectId,
                'clinic_id' => $context->clinicId,
                'name' => $prospectName,
                'phone' => $prospectPhone,
                'phone_normalized' => $prospectPhoneNormalized,
                'email' => null,
                'patient_id' => null,
                'origin' => 'MANUAL',
                'administrative_notes' => null,
                'created_by' => $context->actorId,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('quotes')->insert([
            'id' => $quoteId,
            'clinic_id' => $context->clinicId,
            'patient_id' => $patientId,
            'prospect_contact_id' => $prospectId,
            'number' => null,
            'status' => 'DRAFT',
            'valid_until' => $validUntil,
            'total_gross' => $total,
            'recipient_snapshot' => null,
            'created_by' => $context->actorId,
            'issued_at' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->quotes->writeLines($context->clinicId, $quoteId, $lines, $at);

        return new CommandOutcome(
            result: ['quote_id' => $quoteId, 'version' => 1, 'status' => 'DRAFT', 'total_gross' => $total],
            resultType: 'quotes',
            resultId: $quoteId,
            audit: [new AuditEntry('quote.draft_created', 'quotes', $quoteId, null, [
                'patient_id' => $patientId,
                'prospect_contact_id' => $prospectId,
                'lines' => count($lines),
                'total_gross' => $total,
            ])],
            changes: [new ChangeRecord('quotes', $quoteId, 1, ChangeVisibility::Financial, ChangeKind::Created)],
        );
    }
}
