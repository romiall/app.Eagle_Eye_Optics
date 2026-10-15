<?php

namespace Tests\Feature\Http;

use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Inventory\Rules\LotRules;
use App\Domain\Inventory\Rules\StockAvailability;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Quantity;
use App\Http\Errors\ApiErrorRenderer;
use Illuminate\Http\Request;
use Tests\Support\ValidatesContracts;
use Tests\TestCase;

/**
 * Un refus de règle pure devient la réponse du fichier 08 (constat de revue F08) : saisie invalide et lot interdit
 * en 422, solde ou stock dépassés en 409 AVEC les valeurs actualisées à proposer (`error.details`), le tout
 * conforme au contrat `schemas/error.json`.
 */
final class RuleViolationResponseTest extends TestCase
{
    use ValidatesContracts;

    /**
     * @return array<string, mixed>
     */
    private function respond(RuleViolation $violation, int $expectedStatus): array
    {
        $response = ApiErrorRenderer::render($violation->toBusinessError(), Request::create('/api/v1/test', 'POST'));
        $this->assertSame($expectedStatus, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertDataMatchesContract($data, 'schemas/error.json');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function violation(callable $rule): RuleViolation
    {
        try {
            $rule();
        } catch (RuleViolation $violation) {
            return $violation;
        }

        throw new \LogicException('La règle devait refuser.');
    }

    public function test_un_encaissement_refuse_propose_le_reste_du_actualise(): void
    {
        // AT12 : 30 000 déjà payés sur 80 000, un règlement de 40 000 passe, le second de 40 000 est refusé.
        $ledger = LedgerBalance::of(80000, [30000, 40000], [], [], []);
        $body = $this->respond(self::violation(static fn () => $ledger->assertPayment(40000)), 409);

        $this->assertSame('PAYMENT_EXCEEDS_BALANCE', $body['error']['code']);
        $this->assertSame(['amount_due' => 10000], $body['error']['details']);
        $this->assertArrayHasKey('amount', $body['error']['fields']);
    }

    public function test_un_stock_insuffisant_propose_la_quantite_disponible(): void
    {
        $body = $this->respond(self::violation(static fn () => StockAvailability::assertCanTake(Quantity::of('3.000'), Quantity::of('2.000'))), 409);

        $this->assertSame('STOCK_UNAVAILABLE', $body['error']['code']);
        $this->assertSame(['available' => '2.000'], $body['error']['details']);
    }

    public function test_un_lot_expire_est_un_refus_422_comme_le_fichier_08(): void
    {
        $refusal = self::violation(static fn () => LotRules::assertDeliverable('SELLABLE', CivilDate::fromString('2026-10-14'), CivilDate::fromString('2026-10-15')));
        $body = $this->respond($refusal, 422);

        $this->assertSame('LOT_EXPIRED', $body['error']['code']);
        $this->assertSame(['expires_on' => '2026-10-14'], $body['error']['details']);
    }

    public function test_une_saisie_invalide_est_un_refus_422_sans_details_vides(): void
    {
        $body = $this->respond(self::violation(static fn () => Quantity::parse('1,2345')), 422);

        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);
        $this->assertSame(['quantity'], array_keys($body['error']['fields']));
        $this->assertSame(['scale' => 3], $body['error']['details']);
    }
}
