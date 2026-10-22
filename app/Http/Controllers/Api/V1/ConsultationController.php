<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Clinical\ClinicalReadAudit;
use App\Domain\Clinical\ConsultationReader;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\ConsultationPresenter;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consultations du cabinet de la personne connectée (C01) : données CLINIQUES, réservées aux comptes qui
 * détiennent les permissions `clinical.*` (accueil et caisse n'y ont aucun accès, quelle que soit la
 * consultation). Les écritures passent par le chemin central de commandes ; la lecture est toujours limitée au
 * cabinet (un identifiant d'un autre cabinet répond « introuvable ») et tracée dans l'audit sans contenu.
 *
 * Chaque réponse relit la consultation dans les tables vivantes APRÈS le commit : c'est l'état courant, qui peut
 * déjà intégrer l'écriture d'un autre poste survenue entre-temps (la version rendue est alors plus récente).
 */
final class ConsultationController extends CommandController
{
    public function __construct(private readonly ConsultationReader $reader) {}

    public function show(Request $request, string $id, ClinicalReadAudit $audit): JsonResponse
    {
        $actor = $this->actor($request);
        $row = $this->reader->find((string) $actor->clinic_id, $id);
        if ($row === null) {
            throw BusinessError::notFound();
        }
        $audit->consultationViewed($actor, $row);

        return $this->respond($actor, $id, 200);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present) {
            throw BusinessError::validation(['expected_version' => ['Champ obligatoire absent (null au démarrage).']]);
        }
        if (($payload['visit_id'] ?? null) !== $id) {
            throw BusinessError::validation(['visit_id' => ['L\'identifiant de la visite du corps doit être celui de l\'adresse.']]);
        }

        $consultationId = is_string($payload['consultation_id'] ?? null) ? $payload['consultation_id'] : null;
        $result = $this->dispatchCommand($request, new Command('StartConsultation', $payload, $consultationId, $expected));

        return $this->respond($this->actor($request), (string) $result->resultId, 201);
    }

    public function saveStep(Request $request, string $id, string $step): JsonResponse
    {
        [$payload, $expected] = $this->directBody($request);

        $errors = [];
        if (($payload['consultation_id'] ?? null) !== $id) {
            $errors['consultation_id'] = ['L\'identifiant de la consultation du corps doit être celui de l\'adresse.'];
        }
        $submitted = $payload['step'] ?? null;
        if (is_array($submitted) && ($submitted['step'] ?? null) !== $step) {
            $errors['step.step'] = ['La rubrique du corps doit être celle de l\'adresse.'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }

        $this->dispatchCommand($request, new Command('SaveClinicalStep', $payload, $id, $expected));

        return $this->respond($this->actor($request), $id, 200);
    }

    private function respond(User $actor, string $consultationId, int $status): JsonResponse
    {
        $row = $this->reader->find((string) $actor->clinic_id, $consultationId);
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return new JsonResponse(['data' => ConsultationPresenter::consultation($row, $this->reader->steps($row), $this->reader->revisions($row))], $status);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
