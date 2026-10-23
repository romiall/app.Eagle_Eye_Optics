<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Billing\InvoiceReader;
use App\Domain\Inventory\DeliveryReader;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Remises au patient (I01) et retours physiques (I02). Toute écriture passe par le chemin central de commandes (droits
 * relus sous verrou, idempotence, audit) : ce contrôleur ne calcule aucun stock et n'écrit rien.
 *
 *   GET  /invoices/{id}/deliveries           reste à remettre par ligne, historique des remises et retours, reste dû
 *                                            (module `sales`, `invoice.read`) ;
 *   POST /invoices/{id}/deliveries           ConfirmDelivery (module `sales`, `delivery.confirm`) ;
 *   POST /deliveries/{id}/physical-returns   RegisterPhysicalReturn (module `stock`, `stock.adjust`, réauthentification).
 *
 * Réponses d'écriture : la réponse durable de la commande (`Delivery`, `PhysicalReturn`), rejouée telle quelle par la
 * même clé d'idempotence si la première réponse s'est perdue (jamais une seconde sortie de stock).
 */
final class DeliveryController extends CommandController
{
    public function __construct(private readonly InvoiceReader $invoices, private readonly DeliveryReader $reader) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $invoice = $this->invoices->find((string) $this->actor($request)->clinic_id, $id);
        if ($invoice === null) {
            throw BusinessError::notFound();
        }

        return new JsonResponse(['data' => $this->reader->forInvoice($invoice)]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $errors = [];
        if (($payload['invoice_id'] ?? null) !== $id) {
            $errors['invoice_id'] = ['L\'identifiant de la facture du corps doit être celui de l\'adresse.'];
        }
        if (! $present || $expected !== null) {
            $errors['expected_version'] = ['La version attendue vaut null pour une remise (champ obligatoire).'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }
        $deliveryId = is_string($payload['delivery_id'] ?? null) ? $payload['delivery_id'] : null;

        $result = $this->dispatchCommand($request, new Command('ConfirmDelivery', $payload, $deliveryId, null));

        return new JsonResponse(['data' => $result->result], 201);
    }

    public function physicalReturn(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $errors = [];
        if (! $present || $expected !== null) {
            $errors['expected_version'] = ['La version attendue vaut null pour un retour (champ obligatoire).'];
        }
        $lineId = is_string($payload['delivery_line_id'] ?? null) ? $payload['delivery_line_id'] : null;
        if ($lineId !== null && ! DB::table('delivery_lines')->where('clinic_id', $this->actor($request)->clinic_id)->where('id', $lineId)->where('delivery_id', $id)->exists()) {
            $errors['delivery_line_id'] = ['Cette ligne n\'appartient pas à la remise de l\'adresse.'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }
        $returnId = is_string($payload['physical_return_id'] ?? null) ? $payload['physical_return_id'] : null;

        $result = $this->dispatchCommand($request, new Command('RegisterPhysicalReturn', $payload, $returnId, null));

        return new JsonResponse(['data' => $result->result], 201);
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
