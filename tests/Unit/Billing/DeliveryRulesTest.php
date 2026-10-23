<?php

namespace Tests\Unit\Billing;

use App\Domain\Access\Approvals\ApprovalKind;
use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Inventory\Rules\DeliveryRules;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Quantity;
use PHPUnit\Framework\TestCase;

/**
 * Règles pures de la remise et du retour (I01, I02) et contenu exact de leurs approbations : reste à remettre, choix
 * des réserves (FEFO, jamais au-delà de la part ouverte), reste retournable, destination d'un retour, empreinte de la
 * remise à crédit et du mouvement hors vente. Données fictives.
 */
final class DeliveryRulesTest extends TestCase
{
    private const INVOICE = '01J9ZXK4M2Q8R7S6T5V4W30026';

    private const LINE_A = '01J9ZXK4M2Q8R7S6T5V4W30027';

    private const LINE_B = '01J9ZXK4M2Q8R7S6T5V4W30028';

    private const POSITION = '01J9ZXK4M2Q8R7S6T5V4W3002H';

    private const ITEM = '01J9ZXK4M2Q8R7S6T5V4W3001Z';

    public function test_le_vecteur_5_3_1_laisse_un_a_remettre(): void
    {
        $delivered = Quantity::of('3')->plus(Quantity::of('1'));

        $this->assertSame('1.000', DeliveryRules::remaining(Quantity::of('5'), $delivered)->canonical());
        DeliveryRules::assertWithinRemaining(Quantity::of('1'), Quantity::of('5'), $delivered);

        try {
            DeliveryRules::assertWithinRemaining(Quantity::of('2'), Quantity::of('5'), $delivered);
            $this->fail('Une remise au-delà du reste est refusée.');
        } catch (RuleViolation $violation) {
            $this->assertSame(['delivery.exceeds_remaining', 'quantity', '1.000'], [$violation->rule, $violation->field, $violation->details['remaining']]);
        }
    }

    public function test_les_reserves_sont_prises_par_expiration_la_plus_proche_sans_depasser_leur_part_ouverte(): void
    {
        $reservations = [
            ['id' => 'R3', 'position_id' => self::POSITION, 'lot_id' => null, 'open' => Quantity::of('5'), 'expires_on' => null],
            ['id' => 'R2', 'position_id' => self::POSITION, 'lot_id' => 'L2', 'open' => Quantity::of('1'), 'expires_on' => CivilDate::fromString('2027-01-31')],
            ['id' => 'R1', 'position_id' => self::POSITION, 'lot_id' => 'L1', 'open' => Quantity::of('2'), 'expires_on' => CivilDate::fromString('2026-12-31')],
        ];

        $allocations = DeliveryRules::allocate(Quantity::of('4'), $reservations, null, null, null);

        $this->assertSame(
            [['R1', '2.000'], ['R2', '1.000'], ['R3', '1.000']],
            array_map(static fn (array $allocation): array => [$allocation['reservation_id'], $allocation['quantity']->canonical()], $allocations),
        );
        $this->assertSame([['R2', '1.000']], array_map(static fn (array $allocation): array => [$allocation['reservation_id'], $allocation['quantity']->canonical()],
            DeliveryRules::allocate(Quantity::of('1'), $reservations, null, 'L2', null)));
    }

    public function test_une_reserve_insuffisante_est_un_refus_de_stock_avec_le_disponible(): void
    {
        $reservations = [['id' => 'R1', 'position_id' => self::POSITION, 'lot_id' => null, 'open' => Quantity::of('1'), 'expires_on' => null]];

        try {
            DeliveryRules::allocate(Quantity::of('2'), $reservations, null, null, null);
            $this->fail('La remise ne prend jamais plus que la réserve ouverte.');
        } catch (RuleViolation $violation) {
            $this->assertSame([RuleViolation::STOCK_UNAVAILABLE, '1.000'], [$violation->errorCode, $violation->details['available']]);
        }

        $this->expectException(RuleViolation::class);
        DeliveryRules::allocate(Quantity::of('1'), $reservations, 'AUTRE', null, null);
    }

    public function test_le_reste_retournable_et_la_destination(): void
    {
        $this->assertSame('1.000', DeliveryRules::returnable(Quantity::of('2'), Quantity::of('1'))->canonical());
        DeliveryRules::assertReturnable(Quantity::of('1'), Quantity::of('2'), Quantity::of('1'));
        try {
            DeliveryRules::assertReturnable(Quantity::of('2'), Quantity::of('2'), Quantity::of('1'));
            $this->fail('Un retour au-delà de la quantité remise non retournée est refusé.');
        } catch (RuleViolation $violation) {
            $this->assertSame('1.000', $violation->details['returnable']);
        }

        $today = CivilDate::fromString('2026-10-22');
        DeliveryRules::assertRestockable('DAMAGED', false, null, null, $today);
        DeliveryRules::assertRestockable('SELLABLE', true, 'SELLABLE', CivilDate::fromString('2026-10-22'), $today);
        foreach ([['DAMAGED', 'SELLABLE', null], ['SELLABLE', 'SELLABLE', '2026-10-21'], ['SELLABLE', 'QUARANTINE', null]] as [$condition, $status, $expires]) {
            try {
                DeliveryRules::assertRestockable($condition, true, $status, $expires === null ? null : CivilDate::fromString($expires), $today);
                $this->fail('Remise en stock refusée : '.$condition.' / '.$status.' / '.($expires ?? '-'));
            } catch (RuleViolation $violation) {
                $this->assertSame('accepted_for_restock', $violation->field);
            }
        }
    }

    public function test_l_empreinte_de_la_remise_a_credit_ne_depend_ni_de_l_ordre_ni_de_l_ecriture_des_quantites(): void
    {
        $command = ApprovalSubject::creditDelivery(self::INVOICE, 40000, [
            ['invoice_line_id' => self::LINE_B, 'quantity' => '1'],
            ['invoice_line_id' => self::LINE_A, 'quantity' => '1'],
            ['invoice_line_id' => self::LINE_A, 'quantity' => '0.500'],
        ]);
        $request = ApprovalSubject::fromRequest([
            'kind' => 'CREDIT_DELIVERY',
            'invoice_id' => self::INVOICE,
            'amount_due' => 40000,
            'lines' => [['invoice_line_id' => self::LINE_A, 'quantity' => '1.5'], ['invoice_line_id' => self::LINE_B, 'quantity' => '1.000']],
        ]);

        $this->assertSame($request->hash(), $command->hash());
        $this->assertSame([ApprovalKind::CreditDelivery, 'delivery.confirm', 'invoices', self::INVOICE], [$command->kind, $command->actionCode(), $command->targetType(), $command->targetId]);
        $this->assertNotSame($command->hash(), ApprovalSubject::creditDelivery(self::INVOICE, 30000, [['invoice_line_id' => self::LINE_A, 'quantity' => '1.5'], ['invoice_line_id' => self::LINE_B, 'quantity' => '1']])->hash(), 'Un autre reste dû est une autre opération.');
        $this->assertNotSame($command->hash(), ApprovalSubject::creditDelivery(self::INVOICE, 40000, [['invoice_line_id' => self::LINE_A, 'quantity' => '2'], ['invoice_line_id' => self::LINE_B, 'quantity' => '1']])->hash());
        $this->assertSame($request->toArray(), ApprovalSubject::verified($request->toArray(), $request->hash()));
    }

    public function test_l_empreinte_d_un_mouvement_lie_position_lot_nature_et_quantite(): void
    {
        $subject = ApprovalSubject::stockMovement(self::POSITION, self::ITEM, null, 'BREAKAGE', '-1');

        $this->assertSame([ApprovalKind::StockMovement, 'stock.adjust', 'stock_positions', self::POSITION], [$subject->kind, $subject->actionCode(), $subject->targetType(), $subject->targetId]);
        $this->assertSame($subject->hash(), ApprovalSubject::fromRequest([
            'kind' => 'STOCK_MOVEMENT', 'position_id' => self::POSITION, 'item_id' => self::ITEM, 'lot_id' => null,
            'movement_kind' => 'BREAKAGE', 'signed_quantity' => '-1.000',
        ])->hash());
        $this->assertNotSame($subject->hash(), ApprovalSubject::stockMovement(self::POSITION, self::ITEM, null, 'ADJUSTMENT', '-1')->hash());
        $this->assertNotSame($subject->hash(), ApprovalSubject::stockMovement(self::POSITION, self::ITEM, null, 'BREAKAGE', '-2')->hash());
        $this->assertNull(ApprovalKind::fromStored('stock.adjust', 'stock_movements'), 'Le stock initial exécuté par la gérante reste hors du registre des demandes.');
    }
}
