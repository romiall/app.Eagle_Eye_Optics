<?php

namespace App\Domain\Clinical\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Clinical\NarrativeSteps;
use App\Domain\Clinical\Rules\ClinicalSteps;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement d'une rubrique du dossier de consultation (EE003 à EE005, AT18, AT19).
 *
 * Premier jalon : les rubriques NARRATIVES (motifs et lunettes, antécédents, conclusion et orientation). Les
 * trois autres (acuités, réfraction, centrage) sont refusées en 422 (`step.step`) sans rien écrire jusqu'à C02.
 *
 * - Sémantique : REMPLACEMENT COMPLET de la rubrique, avec comparaison par identifiant de ligne
 *   (`NarrativeSteps`) ; les lignes absentes de la charge sont supprimées.
 * - Concurrence : la version attendue est comparée à `consultations.version` lue sous le verrou de la
 *   consultation (rang 47) ; un écart est un 409 `VERSION_CONFLICT` avec la version actuelle, jamais un
 *   « dernier écrit gagne ». La version couvre TOUTE la consultation : enregistrer une rubrique invalide la
 *   version tenue par un autre poste, même s'il édite une autre rubrique.
 * - État : une consultation terminée ne se modifie plus ici (409 `STATE_TRANSITION_REFUSED`) ; sa correction
 *   passera par une révision motivée (C03). La première sauvegarde passe le brouillon à « en cours ».
 * - Intégrité : toute l'erreur est rendue ensemble, par champ, AVANT la première écriture (jamais d'état
 *   invalide stocké, jamais d'erreur SQL). Une saisie qui ne change rien n'incrémente pas la version et ne
 *   laisse aucun audit.
 * - Confidentialité : l'audit ne porte que {rubrique, version, nombre de lignes} ; le résultat idempotent ne
 *   porte que des références. Aucune valeur clinique n'est copiée hors des tables de la consultation.
 */
final class SaveClinicalStepHandler implements CommandHandler
{
    public const COMMAND = 'SaveClinicalStep';

    public function __construct(private readonly NarrativeSteps $steps) {}

    public function permissions(): array
    {
        return ['clinical.write'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('L\'enregistrement d\'une rubrique clinique depuis un poste hors-ligne n\'est pas encore ouvert.');
        }

        $consultationId = (string) $command->payload['consultation_id'];
        /** @var array{step: string, data: array<array-key, mixed>} $submitted */
        $submitted = $command->payload['step'];
        $step = $submitted['step'];

        if (! in_array($step, ClinicalSteps::OPEN, true)) {
            throw BusinessError::validation(['step.step' => ['Cette rubrique n\'est pas encore ouverte.']]);
        }

        $consultation = $locks->consultation($consultationId);
        if ($consultation === null) {
            throw BusinessError::notFound();
        }
        if ($consultation->status === ClinicalSteps::COMPLETED) {
            throw BusinessError::stateRefused('Cette consultation est terminée : elle ne se modifie plus par une saisie simple.', ClinicalSteps::COMPLETED);
        }
        if ((int) $consultation->version !== $command->expectedVersion) {
            throw new BusinessError('VERSION_CONFLICT', 'Une version plus récente existe : actualisez avant de modifier.', 409, [], null, [], ['current_version' => (int) $consultation->version]);
        }

        $errors = new FieldErrors;
        $normalized = $this->steps->normalize($step, $submitted['data'], $errors);
        $this->steps->rejectForeignIds($consultation, $step, $submitted['data'], $errors);
        if (! $errors->isEmpty()) {
            throw BusinessError::validation($errors->all());
        }

        $firstSave = $consultation->status === ClinicalSteps::DRAFT;
        if (! $firstSave && $this->steps->isUnchanged($consultation, $step, $normalized)) {
            return new CommandOutcome(
                result: ['consultation_id' => $consultationId, 'version' => (int) $consultation->version, 'status' => (string) $consultation->status, 'step' => $step],
                resultType: 'consultations',
                resultId: $consultationId,
            );
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        $version = (int) $consultation->version + 1;
        $columns = $this->steps->write($consultation, $step, $normalized, $now);
        DB::table('consultations')
            ->where('clinic_id', $context->clinicId)
            ->where('id', $consultationId)
            ->update($columns + ['status' => ClinicalSteps::IN_PROGRESS, 'version' => $version, 'updated_at' => $now]);

        return new CommandOutcome(
            result: ['consultation_id' => $consultationId, 'version' => $version, 'status' => ClinicalSteps::IN_PROGRESS, 'step' => $step],
            resultType: 'consultations',
            resultId: $consultationId,
            audit: [new AuditEntry('consultation.step_saved', 'consultations', $consultationId, null, [
                'step' => $step,
                'version' => $version,
                'rows' => $this->steps->rowCount($step, $normalized),
            ])],
            changes: [new ChangeRecord('consultations', $consultationId, $version, ChangeVisibility::Clinical, ChangeKind::Updated)],
        );
    }
}
