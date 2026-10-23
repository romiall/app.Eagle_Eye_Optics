<?php

namespace App\Domain\Patients\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Patients\MergePatients;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\Text;
use Illuminate\Support\Facades\DB;

/**
 * Fusion manuelle d'une fiche patient dans une autre (L1D08), par la gérante après revue (`patient.merge`, mot de
 * passe confirmé récemment : middleware de la route). Commande EN LIGNE seulement : jamais depuis le journal d'un
 * poste, jamais automatique.
 *
 * Les deux fiches sont verrouillées au rang « dossier patient », par identifiant croissant (contrat 23) : une arrivée,
 * un brouillon de facture ou un rendez-vous pris en même temps sur la fiche absorbée attend la fin de la fusion, puis
 * la voit fusionnée et refuse. Version attendue = celle de la fiche ABSORBÉE (409 `VERSION_CONFLICT` avec
 * `current_version`). Un garde-fou de `MergePatients::blockers` : 409 `STATE_TRANSITION_REFUSED` avec
 * `details.current_status` (code du premier garde-fou).
 *
 * Écritures : une ligne `patient_merges` (immuable : snapshot de l'identité absorbée, approbatrice, instant, motif) et
 * `merged_into_patient_id` sur la fiche absorbée (version + 1). Rien d'autre : l'historique n'est pas réécrit.
 */
final class MergePatientsHandler implements CommandHandler
{
    public const COMMAND = 'MergePatients';

    public function permissions(): array
    {
        return ['patient.merge'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('La fusion de deux fiches se fait en ligne, par la gérante.');
        }

        $payload = $command->payload;
        $sourceId = (string) $payload['source_patient_id'];
        $survivorId = (string) $payload['survivor_patient_id'];
        if ($sourceId === $survivorId) {
            throw BusinessError::validation(['survivor_patient_id' => ['Choisissez une autre fiche que celle à absorber.']]);
        }

        $reason = null;
        try {
            $reason = Text::optional(is_string($payload['reason'] ?? null) ? $payload['reason'] : null, MergePatients::REASON_MAX_LENGTH, 'reason');
        } catch (RuleViolation $violation) {
            throw BusinessError::validation(['reason' => [$violation->getMessage()]]);
        }
        if ($reason === null || mb_strlen($reason) < MergePatients::REASON_MIN_LENGTH) {
            throw BusinessError::validation(['reason' => ['Indiquez le motif de la fusion (au moins '.MergePatients::REASON_MIN_LENGTH.' caractères).']]);
        }

        // Ordre des verrous : à rang égal, clé croissante.
        $rows = [];
        $ordered = [$sourceId, $survivorId];
        sort($ordered, SORT_STRING);
        foreach ($ordered as $id) {
            $rows[$id] = $locks->patient($id);
        }
        $source = $rows[$sourceId];
        $survivor = $rows[$survivorId];
        if ($source === null) {
            throw BusinessError::notFound('Fiche à absorber introuvable dans ce cabinet.');
        }
        if ($survivor === null) {
            throw BusinessError::notFound('Fiche à conserver introuvable dans ce cabinet.');
        }
        if ($command->expectedVersion === null || (int) $source->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'La fiche à absorber a changé depuis l\'aperçu : relisez-le avant de fusionner.', 409, [], null, [], ['current_version' => (int) $source->version]);
        }

        $blockers = MergePatients::blockers($context->clinicId, $source, $survivor, $context->now);
        if ($blockers !== []) {
            throw BusinessError::stateRefused($blockers[0]['message'], $blockers[0]['code']);
        }

        $mergeId = Ulid::generate();
        $stamp = $context->now->utc()->format('Y-m-d H:i:s.u');
        $snapshot = MergePatients::snapshot($source, $survivor);
        DB::table('patient_merges')->insert([
            'id' => $mergeId,
            'clinic_id' => $context->clinicId,
            'source_patient_id' => $sourceId,
            'survivor_patient_id' => $survivorId,
            'approved_by' => (string) $context->actorId,
            'merged_at' => $stamp,
            'reason' => $reason,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $version = (int) $source->version + 1;
        DB::table('patient_records')
            ->where('clinic_id', $context->clinicId)
            ->where('id', $sourceId)
            ->update(['merged_into_patient_id' => $survivorId, 'version' => $version, 'updated_at' => $stamp]);

        return new CommandOutcome(
            result: ['merge_id' => $mergeId, 'source_patient_id' => $sourceId, 'survivor_patient_id' => $survivorId, 'version' => $version],
            resultType: 'patient_merges',
            resultId: $mergeId,
            audit: [new AuditEntry('patient.merged', 'patient_records', $sourceId, $reason, [
                'survivor_patient_id' => $survivorId,
                'source_record_number' => (string) $source->record_number,
                'survivor_record_number' => (string) $survivor->record_number,
                'merge_id' => $mergeId,
                'version' => $version,
            ])],
            changes: [new ChangeRecord('patient_records', $sourceId, $version, ChangeVisibility::Administrative, ChangeKind::Updated)],
        );
    }
}
