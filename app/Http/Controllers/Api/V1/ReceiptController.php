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
 * Reçu d'un encaissement (B04), en lecture : pièce immuable avec son snapshot figé et l'état de son document PDF. Un
 * reçu ne se modifie ni ne se supprime ; une erreur se corrige par des pièces liées. Le PDF d'origine est rouvert,
 * jamais recalculé sur l'identité ou les prix actuels (moteur de documents, D01). Module `sales`.
 */
final class ReceiptController extends Controller
{
    public function __construct(private readonly InvoiceReader $reader) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $receipt = $this->reader->receipt((string) $user->clinic_id, $id);
        if ($receipt === null) {
            throw BusinessError::notFound();
        }

        return new JsonResponse(['data' => InvoicePresenter::receipt($receipt, $this->reader->receiptDocument((string) $user->clinic_id, $id))]);
    }
}
