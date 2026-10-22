<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Billing\InvoiceReader;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\InvoicePresenter;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Factures du cabinet (B02, B03, B04) : brouillon, émission immuable, encaissement et lectures. Toute écriture passe
 * par le chemin central de commandes (droits relus sous verrou, idempotence, audit) : ce contrôleur ne calcule aucun
 * solde et n'écrit rien. Module `sales`.
 *
 * Réponses d'écriture : la facture relue APRÈS le commit (brouillon, émission), ou la réponse durable de
 * l'encaissement (`PaymentResult`, rejouée telle quelle si la première réponse s'est perdue : jamais un second
 * paiement). `draft_version` et `ledger_version` sont nommées distinctement ; aucun statut « payé » n'existe.
 */
final class InvoiceController extends CommandController
{
    public function __construct(private readonly InvoiceReader $reader) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $timezone = (string) Clinic::query()->whereKey($actor->clinic_id)->value('timezone');
        ['page' => $page, 'ledgers' => $ledgers] = $this->reader->page(
            (string) $actor->clinic_id,
            $request->query('status'),
            $request->query('patient_id'),
            $request->query('page'),
            $request->query('issued_on'),
            $timezone !== '' ? $timezone : 'Africa/Douala',
        );

        return new JsonResponse([
            'data' => array_map(static fn (\stdClass $row): array => InvoicePresenter::summary($row, $ledgers[(string) $row->id] ?? null), array_values($page->items())),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($request, $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la création (champ obligatoire).']]);
        }

        $invoiceId = is_string($payload['invoice_id'] ?? null) ? $payload['invoice_id'] : null;
        $result = $this->dispatchCommand($request, new Command('CreateInvoiceDraft', $payload, $invoiceId, null));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $this->assertSameInvoice($payload, $id, $present);

        $result = $this->dispatchCommand($request, new Command('UpdateDraft', $payload, $id, $expected));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)]);
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $this->assertSameInvoice($payload, $id, $present);

        $result = $this->dispatchCommand($request, new Command('IssueInvoice', $payload, $id, $expected));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)]);
    }

    /**
     * Abandon d'un brouillon (en ligne seulement) : version de brouillon attendue, motif facultatif ; rend la facture
     * relue (statut `ABANDONED`).
     */
    public function abandon(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $this->assertSameInvoice($payload, $id, $present);

        $result = $this->dispatchCommand($request, new Command('AbandonInvoiceDraft', $payload, $id, $expected));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)]);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        $this->assertSameInvoice($payload, $id, $present);

        $result = $this->dispatchCommand($request, new Command('RegisterPayment', $payload, $id, $expected));

        return new JsonResponse(['data' => $result->result], 201);
    }

    /**
     * L'identifiant de la facture du corps est celui de l'adresse, et la version attendue est présente (le corps
     * direct l'exige : un entier, jamais omis).
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertSameInvoice(array $payload, string $id, bool $versionPresent): void
    {
        $errors = [];
        if (($payload['invoice_id'] ?? null) !== $id) {
            $errors['invoice_id'] = ['L\'identifiant de la facture du corps doit être celui de l\'adresse.'];
        }
        if (! $versionPresent) {
            $errors['expected_version'] = ['Champ obligatoire absent.'];
        }
        if ($errors !== []) {
            throw BusinessError::validation($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, string $invoiceId): array
    {
        $row = $this->reader->find((string) $this->actor($request)->clinic_id, $invoiceId);
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return InvoicePresenter::invoice($this->reader->detail($row));
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
