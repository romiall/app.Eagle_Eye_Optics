<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\InvoiceReader;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoicePresenter;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pièces de correction (B07, B08), en lecture : un avoir et un remboursement, avec leur snapshot figé (source de leur
 * document PDF, produit sur le poste) et l'état de leur document. Ces pièces ne se modifient ni ne se suppriment ; une
 * nouvelle erreur se corrige par une nouvelle pièce liée. Module `sales`, droit `invoice.read`. Toujours limitée au
 * cabinet : une pièce d'un autre cabinet est « introuvable ». Les écritures restent dans `CorrectionController`.
 */
final class CorrectionDocumentController extends Controller
{
    public function __construct(private readonly InvoiceReader $reader) {}

    /** `GET /credit-notes/{id}`. */
    public function creditNote(Request $request, string $id): JsonResponse
    {
        $detail = $this->reader->creditNote($this->clinicId($request), $id);
        if ($detail === null) {
            throw BusinessError::notFound('Avoir introuvable dans ce cabinet.');
        }

        return new JsonResponse(['data' => InvoicePresenter::creditNote($detail)]);
    }

    /** `GET /refunds/{id}`. */
    public function refund(Request $request, string $id): JsonResponse
    {
        $detail = $this->reader->refund($this->clinicId($request), $id);
        if ($detail === null) {
            throw BusinessError::notFound('Remboursement introuvable dans ce cabinet.');
        }

        return new JsonResponse(['data' => InvoicePresenter::refund($detail)]);
    }

    private function clinicId(Request $request): string
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return (string) $user->clinic_id;
    }
}
