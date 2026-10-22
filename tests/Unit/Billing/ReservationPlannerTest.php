<?php

namespace Tests\Unit\Billing;

use App\Domain\Inventory\Rules\ReservationPlanner;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Quantity;
use PHPUnit\Framework\TestCase;

/**
 * Plan d'une réserve de stock centrale (I00, AT15, AT17) : positions par identifiant, lots en FEFO, péremption à la date
 * LOCALE de remise, quarantaine, passage d'un lot au suivant, jamais de stock négatif. Fonction pure, sans base.
 * Données fictives.
 */
final class ReservationPlannerTest extends TestCase
{
    private const TODAY = '2026-10-22';

    private function qty(string $value): FixedDecimal
    {
        return Quantity::of($value);
    }

    private function today(): CivilDate
    {
        return CivilDate::fromString(self::TODAY);
    }

    /**
     * @return array{id: string, position_id: string, available: FixedDecimal, status: string, expires_on: ?CivilDate}
     */
    private function lot(string $id, string $available, ?string $expires, string $status = 'SELLABLE', string $position = 'P1'): array
    {
        return ['id' => $id, 'position_id' => $position, 'available' => $this->qty($available), 'status' => $status, 'expires_on' => $expires === null ? null : CivilDate::fromString($expires)];
    }

    /**
     * @param  list<array{position_id: string, lot_id: ?string, quantity: FixedDecimal}>  $plan
     * @return list<array{string, ?string, string}>
     */
    private function flat(array $plan): array
    {
        return array_map(static fn (array $line): array => [$line['position_id'], $line['lot_id'], $line['quantity']->canonical()], $plan);
    }

    public function test_une_position_sans_lot_couvre_la_quantite_demandee(): void
    {
        $plan = ReservationPlanner::plan($this->qty('2'), false, [['id' => 'P1', 'available' => $this->qty('5')]], [], $this->today());

        $this->assertSame([['P1', null, '2.000']], $this->flat($plan));
    }

    public function test_la_derniere_unite_est_reservable_une_fois_et_le_refus_indique_le_disponible(): void
    {
        $positions = [['id' => 'P1', 'available' => $this->qty('1')]];
        $this->assertSame([['P1', null, '1.000']], $this->flat(ReservationPlanner::plan($this->qty('1'), false, $positions, [], $this->today())));

        $positions = [['id' => 'P1', 'available' => $this->qty('0')]];
        try {
            ReservationPlanner::plan($this->qty('1'), false, $positions, [], $this->today());
            $this->fail('Plus aucune unité disponible : la réserve devait être refusée.');
        } catch (RuleViolation $violation) {
            $this->assertSame('STOCK_UNAVAILABLE', $violation->errorCode);
            $this->assertSame(['available' => '0.000'], $violation->details);
        }
    }

    public function test_un_disponible_negatif_est_rendu_zero_dans_le_refus(): void
    {
        try {
            ReservationPlanner::plan($this->qty('1'), false, [['id' => 'P1', 'available' => $this->qty('-2')]], [], $this->today());
            $this->fail('Un disponible négatif ne permet aucune réserve.');
        } catch (RuleViolation $violation) {
            $this->assertSame(['available' => '0.000'], $violation->details);
        }
    }

    public function test_plusieurs_positions_se_remplissent_par_identifiant_croissant(): void
    {
        $plan = ReservationPlanner::plan($this->qty('5'), false, [
            ['id' => 'P2', 'available' => $this->qty('4')],
            ['id' => 'P1', 'available' => $this->qty('3')],
        ], [], $this->today());

        $this->assertSame([['P1', null, '3.000'], ['P2', null, '2.000']], $this->flat($plan));
    }

    public function test_les_lots_sortent_par_expiration_la_plus_proche_les_lots_sans_date_ensuite(): void
    {
        $lots = [
            $this->lot('L3', '10', null),
            $this->lot('L2', '10', '2027-06-30'),
            $this->lot('L1', '10', '2026-12-31'),
        ];

        $plan = ReservationPlanner::plan($this->qty('25'), true, [['id' => 'P1', 'available' => $this->qty('30')]], $lots, $this->today());

        $this->assertSame([['P1', 'L1', '10.000'], ['P1', 'L2', '10.000'], ['P1', 'L3', '5.000']], $this->flat($plan));
    }

    public function test_un_lot_qui_expire_le_jour_meme_reste_reservable_un_lot_expire_hier_non(): void
    {
        $plan = ReservationPlanner::plan(
            $this->qty('2'),
            true,
            [['id' => 'P1', 'available' => $this->qty('10')]],
            [$this->lot('LHIER', '10', '2026-10-21'), $this->lot('LJOUR', '10', self::TODAY)],
            $this->today(),
        );

        $this->assertSame([['P1', 'LJOUR', '2.000']], $this->flat($plan));
    }

    public function test_un_lot_en_quarantaine_n_est_jamais_reserve(): void
    {
        try {
            ReservationPlanner::plan($this->qty('1'), true, [['id' => 'P1', 'available' => $this->qty('10')]], [$this->lot('LQ', '10', '2027-01-01', 'QUARANTINE')], $this->today());
            $this->fail('Un lot en quarantaine ne se vend pas.');
        } catch (RuleViolation $violation) {
            $this->assertSame('STOCK_UNAVAILABLE', $violation->errorCode);
            $this->assertSame(['available' => '0.000'], $violation->details);
        }
    }

    public function test_quand_la_peremption_prive_de_stock_le_refus_est_lot_expire_avec_le_disponible_non_expire(): void
    {
        try {
            ReservationPlanner::plan(
                $this->qty('5'),
                true,
                [['id' => 'P1', 'available' => $this->qty('10')]],
                [$this->lot('LOK', '2', '2027-01-01'), $this->lot('LEXP', '10', '2026-10-01')],
                $this->today(),
            );
            $this->fail('Seules deux unités non expirées sont disponibles.');
        } catch (RuleViolation $violation) {
            $this->assertSame('LOT_EXPIRED', $violation->errorCode);
            $this->assertSame(['available' => '2.000'], $violation->details);
        }
    }

    public function test_un_article_perissable_sans_lot_n_est_pas_reservable(): void
    {
        try {
            ReservationPlanner::plan($this->qty('1'), true, [['id' => 'P1', 'available' => $this->qty('10')]], [], $this->today());
            $this->fail('Un article périssable exige un lot.');
        } catch (RuleViolation $violation) {
            $this->assertSame('STOCK_UNAVAILABLE', $violation->errorCode);
        }
    }

    public function test_la_position_qui_porte_un_lot_limite_ce_qu_il_offre(): void
    {
        // Le lot annonce 10 mais la position n'a plus que 3 de disponible (le reste est réservé ailleurs).
        $plan = ReservationPlanner::plan($this->qty('3'), true, [['id' => 'P1', 'available' => $this->qty('3')]], [$this->lot('L1', '10', '2027-01-01')], $this->today());
        $this->assertSame([['P1', 'L1', '3.000']], $this->flat($plan));

        $this->expectException(RuleViolation::class);
        ReservationPlanner::plan($this->qty('4'), true, [['id' => 'P1', 'available' => $this->qty('3')]], [$this->lot('L1', '10', '2027-01-01')], $this->today());
    }

    public function test_des_lots_de_positions_differentes_partagent_chacun_leur_position(): void
    {
        $plan = ReservationPlanner::plan(
            $this->qty('6'),
            true,
            [['id' => 'P1', 'available' => $this->qty('4')], ['id' => 'P2', 'available' => $this->qty('10')]],
            [$this->lot('L1', '4', '2026-11-30', 'SELLABLE', 'P1'), $this->lot('L2', '10', '2026-12-31', 'SELLABLE', 'P2')],
            $this->today(),
        );

        $this->assertSame([['P1', 'L1', '4.000'], ['P2', 'L2', '2.000']], $this->flat($plan));
    }

    public function test_une_quantite_nulle_ou_negative_est_refusee(): void
    {
        $this->expectException(RuleViolation::class);

        ReservationPlanner::plan($this->qty('0'), false, [['id' => 'P1', 'available' => $this->qty('5')]], [], $this->today());
    }
}
