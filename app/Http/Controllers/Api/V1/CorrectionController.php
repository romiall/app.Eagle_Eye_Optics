<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Corrections des pièces financières émises (B07, B08) : contre-écriture d'un paiement, avoir lié aux lignes d'une
 * facture, remboursement d'un crédit. Module `sales`, permission propre à chaque opération et réauthentification récente
 * (routes `reauthenticated`). Toute écriture passe par le chemin central de commandes ; ce contrôleur ne calcule rien et
 * n'écrit rien. Jamais de suppression ni de modification d'un paiement, d'un reçu, d'une facture ou d'un avoir : chaque
 * correction est une pièce liée.
 *
 * Réponses : la réponse durable de la commande (`ReversalResult`, `CreditNoteResult`, `RefundResult`), rejouée telle
 * quelle par la même clé d'idempotence si la première réponse s'est perdue (jamais une seconde correction).
 */
final class CorrectionController extends CommandController
{
    /** `POST /payments/{id}/reversals` : l'identifiant de l'adresse est le paiement ; l'agrégat est sa facture. */
    public function reverse(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $this->assertBody($payload, 'payment_id', $id, $present, 'L\'identifiant du paiement du corps doit être celui de l\'adresse.');
        $invoiceId = is_string($payload['invoice_id'] ?? null) ? $payload['invoice_id'] : null;

        $result = $this->dispatchCommand($request, new Command('ReversePayment', $payload, $invoiceId, $expected));

        return new JsonResponse(['data' => $result->result], 201);
    }

    /** `POST /invoices/{id}/credit-notes`. */
    public function creditNote(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $this->assertBody($payload, 'invoice_id', $id, $present, 'L\'identifiant de la facture du corps doit être celui de l\'adresse.');

        $result = $this->dispatchCommand($request, new Command('IssueCreditNote', $payload, $id, $expected));

        return new JsonResponse(['data' => $result->result], 201);
    }

    /** `POST /invoices/{id}/refunds`. */
    public function refund(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $this->assertBody($payload, 'invoice_id', $id, $present, 'L\'identifiant de la facture du corps doit être celui de l\'adresse.');

        $result = $this->dispatchCommand($request, new Command('RefundCredit', $payload, $id, $expected));

        return new JsonResponse(['data' => $result->result], 201);
    }

    /**
     * L'identifiant du corps est celui de l'adresse et la version attendue (ledger_version) est présente.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertBody(array $payload, string $field, string $id, bool $versionPresent, string $message): void
    {
        $errors = [];
        if (($payload[$field] ?? null) !== $id) {
            $errors[$field] = [$message];
        }
        if (! $versionPresent) {
            $errors['expected_version'] = ['Champ obligatoire absent (ledger_version de la facture).'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }
    }
}
