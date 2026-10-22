<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Http\Resources\CatalogPresenter;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Règles fiscales du cabinet (B01), gérante seulement (`catalog.manage`) : la lecture est libre de réauthentification,
 * l'écriture exige une réauthentification récente (middleware de la route). Aucune taxe n'est préconfigurée : la règle,
 * son taux et sa validation viennent de la gérante. Module `sales`.
 */
final class TaxRuleController extends CommandController
{
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('tax_rules')->where('clinic_id', $this->actor($request)->clinic_id)->orderBy('code')->orderBy('id')->get();

        return new JsonResponse(['data' => array_map(static fn (\stdClass $row): array => CatalogPresenter::taxRule($row), array_values($rows->all()))]);
    }

    public function store(Request $request): JsonResponse
    {
        [$payload, $expected, $present] = $this->directBody($request);
        if (! $present || $expected !== null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue vaut null à la création (champ obligatoire).']]);
        }

        $result = $this->dispatchCommand($request, new Command('CreateTaxRule', $payload, null, null));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$payload, $expected] = $this->directBody($request);

        $result = $this->dispatchCommand($request, new Command('UpdateTaxRule', $payload, $id, $expected));

        return new JsonResponse(['data' => $this->present($request, (string) $result->resultId)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, string $ruleId): array
    {
        $row = DB::table('tax_rules')->where('clinic_id', $this->actor($request)->clinic_id)->where('id', $ruleId)->first();
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return CatalogPresenter::taxRule($row);
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
