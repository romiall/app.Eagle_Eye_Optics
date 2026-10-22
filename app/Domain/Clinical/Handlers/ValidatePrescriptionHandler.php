<?php

namespace App\Domain\Clinical\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Effects\OutboxEvent;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\OpticalPrescriptions;
use App\Domain\Clinical\RevisionSnapshot;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Clinical\Rules\OpticalPrescriptionRules;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use Illuminate\Support\Facades\DB;

/**
 * Validation explicite d'une prescription optique par le professionnel (EE009, EE014 ; AT20, AT21) : la version devient
 * IMMUABLE (triggers de la base : ni la ligne ni ses enfants ne changent plus) et un document PDF est PRÉPARÉ
 * (`generated_documents`, statut PENDING) dans la même transaction. La génération du PDF suit le commit et appartient
 * au moteur de documents (D01, D02) : cette action n'écrit aucun fichier et ne dit jamais « imprimée ».
 *
 * Règles (fichier 21, « Valider prescription ») :
 *  - la prescription est un brouillon (sinon 409 `STATE_TRANSITION_REFUSED`) ;
 *  - pour chaque œil : valeurs explicitement saisies OU « aucune correction » ; un cylindre non nul exige un axe ;
 *  - la DP est cohérente (ou justifiée), vérifiée dès l'enregistrement du brouillon ;
 *  - une ordonnance externe porte son prescripteur et sa date (vérifié à la création) ;
 *  - l'identifiant du document fourni par le poste est neuf.
 * Aucune vente, aucune ligne de facture et aucun mouvement de stock ne sont créés.
 *
 * Effets : audit `prescription.validated` (version, origine, document), événement `PrescriptionValidated` (charge
 * minimale), journal des changements. Le permis est celui de `clinical.complete`.
 */
final class ValidatePrescriptionHandler implements CommandHandler
{
    public const COMMAND = 'ValidatePrescription';

    /**
     * Version du modèle du document préparé, convention `<type>.v<n>` des documents (comme `invoice.v1`,
     * `receipt.v1`) : le registre du moteur de documents (`CLINICAL_TEMPLATE_VERSIONS`) la connaît et
     * `contracts/compatibility.json` la déclare. La mention « document de test » ne fait pas partie du nom :
     * elle vient du cabinet (`production_documents_enabled` faux), comme pour les pièces financières.
     */
    public const TEMPLATE_VERSION = 'optical_prescription.v1';

    public function __construct(private readonly OpticalPrescriptions $prescriptions) {}

    public function permissions(): array
    {
        return ['clinical.complete'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La validation d\'une prescription depuis un poste hors-ligne n\'est pas encore ouverte.');
        }

        $prescriptionId = (string) $command->payload['prescription_id'];
        $documentId = (string) $command->payload['document_id'];
        $clinicId = $context->clinicId;

        $row = $this->prescriptions->find($clinicId, $prescriptionId);
        if ($row === null) {
            throw BusinessError::notFound();
        }
        if ($row->status !== 'DRAFT') {
            throw BusinessError::stateRefused('Cette prescription est déjà validée : elle est immuable. Créez-en une nouvelle version pour la corriger.', (string) $row->status);
        }

        $errors = new FieldErrors;
        /** @var array<string, mixed> $view */
        $view = $this->prescriptions->view($clinicId, $prescriptionId) ?? [];
        OpticalPrescriptionRules::readyToValidate($view, $errors);
        if (DB::table('generated_documents')->where('id', $documentId)->exists()) {
            $errors->add('document_id', 'Cet identifiant de document est déjà utilisé.');
        }
        if (! $errors->isEmpty()) {
            throw BusinessError::validation($errors->all());
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('optical_prescriptions')->where('clinic_id', $clinicId)->where('id', $prescriptionId)->update([
            'status' => 'VALIDATED',
            'validated_at' => $now,
            'validated_by' => (string) $context->actorId,
            'updated_at' => $now,
        ]);
        DB::table('generated_documents')->insert([
            'id' => $documentId,
            'clinic_id' => $clinicId,
            'document_type' => 'OPTICAL_PRESCRIPTION',
            'prescription_id' => $prescriptionId,
            'source_key' => 'optical_prescription:'.$prescriptionId,
            'revision_no' => (int) $row->version_no,
            'template_version' => self::TEMPLATE_VERSION,
            'status' => 'PENDING',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: ['prescription_id' => $prescriptionId, 'status' => 'VALIDATED', 'version_no' => (int) $row->version_no, 'document_id' => $documentId],
            resultType: 'optical_prescriptions',
            resultId: $prescriptionId,
            audit: [new AuditEntry('prescription.validated', 'optical_prescriptions', $prescriptionId, null, [
                'origin' => (string) $row->origin,
                'series_id' => (string) $row->series_id,
                'version_no' => (int) $row->version_no,
                'document_id' => $documentId,
            ])],
            events: [new OutboxEvent('PrescriptionValidated', 1, 'optical_prescriptions', $prescriptionId, (int) $row->version_no, [
                'prescription_id' => $prescriptionId,
                'patient_id' => (string) $row->patient_id,
                'consultation_id' => $row->consultation_id === null ? null : (string) $row->consultation_id,
                'origin' => (string) $row->origin,
                'version_no' => (int) $row->version_no,
                'validated_at' => RevisionSnapshot::instant($context->now),
                'document_id' => $documentId,
            ])],
            changes: [new ChangeRecord('optical_prescriptions', $prescriptionId, (int) $row->version_no, ChangeVisibility::Clinical, ChangeKind::Updated)],
        );
    }
}
