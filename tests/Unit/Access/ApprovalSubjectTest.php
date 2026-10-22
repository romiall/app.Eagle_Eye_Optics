<?php

namespace Tests\Unit\Access;

use App\Domain\Access\Approvals\ApprovalKind;
use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Billing\BilledExamFeeControl;
use App\Domain\Billing\ExamFeeWaiver;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Shared\RuleViolation;
use PHPUnit\Framework\TestCase;

/**
 * Contenu exact d'une approbation (B06 : « Payload changé ou décision expirée refusé ») : la même opération donne la
 * même empreinte, qu'elle vienne de la demande ou de la commande approuvée ; toute différence de montant, de ligne, de
 * mode ou de cible donne une autre empreinte. Fonctions pures, données fictives.
 */
final class ApprovalSubjectTest extends TestCase
{
    private const INVOICE = '01J9ZXK4M2Q8R7S6T5V4W30026';

    private const VISIT = '01J9ZXK4M2Q8R7S6T5V4W30021';

    private const PATIENT = '01J9ZXK4M2Q8R7S6T5V4W30001';

    private const LINE_A = '01J9ZXK4M2Q8R7S6T5V4W30027';

    private const LINE_B = '01J9ZXK4M2Q8R7S6T5V4W30028';

    public function test_la_dispense_garde_l_empreinte_du_contrat_historique(): void
    {
        $subject = ApprovalSubject::examFeeWaiver(self::VISIT, self::PATIENT);

        $this->assertSame(ExamFeeWaiver::payloadHash(self::VISIT, self::PATIENT), $subject->hash());
        $this->assertSame([ExamFeeWaiver::ACTION_CODE, ExamFeeWaiver::TARGET_TYPE, self::VISIT], [$subject->actionCode(), $subject->targetType(), $subject->targetId]);
    }

    public function test_la_demande_et_la_commande_donnent_la_meme_empreinte(): void
    {
        $request = ApprovalSubject::fromRequest([
            'kind' => 'CREDIT_NOTE', 'invoice_id' => self::INVOICE, 'amount_gross' => 15000,
            'lines' => [
                ['invoice_line_id' => self::LINE_B, 'quantity' => '1', 'amount_gross' => 5000],
                ['invoice_line_id' => self::LINE_A, 'quantity' => null, 'amount_gross' => 10000],
            ],
        ]);
        // Charge de l'avoir : autres identifiants de ligne d'avoir, motif, ordre différent, quantité canonique.
        $command = ApprovalSubject::creditNote(self::INVOICE, 15000, [
            ['credit_note_line_id' => 'X', 'invoice_line_id' => self::LINE_A, 'quantity' => null, 'amount_gross' => 10000, 'reason' => 'motif'],
            ['credit_note_line_id' => 'Y', 'invoice_line_id' => self::LINE_B, 'quantity' => '1.000', 'amount_gross' => 5000, 'reason' => null],
        ]);

        $this->assertSame($request->hash(), $command->hash());
        $this->assertSame(['credit_note.issue', 'invoices'], [$request->actionCode(), $request->targetType()]);
    }

    public function test_toute_difference_de_contenu_change_l_empreinte(): void
    {
        $base = ApprovalSubject::paymentReversal(self::INVOICE, self::LINE_A, 30000)->hash();

        $this->assertNotSame($base, ApprovalSubject::paymentReversal(self::INVOICE, self::LINE_A, 29999)->hash());
        $this->assertNotSame($base, ApprovalSubject::paymentReversal(self::INVOICE, self::LINE_B, 30000)->hash());
        $this->assertNotSame(
            ApprovalSubject::refund(self::INVOICE, self::LINE_A, 1000, 'CASH')->hash(),
            ApprovalSubject::refund(self::INVOICE, self::LINE_A, 1000, 'MTN_MOMO')->hash(),
        );
        $this->assertNotSame(
            ApprovalSubject::examFeeWaiver(self::VISIT, self::PATIENT)->hash(),
            ApprovalSubject::examFeeWaiver(self::LINE_A, self::PATIENT)->hash(),
        );
    }

    public function test_les_lignes_d_un_brouillon_sont_liees_sans_leurs_identifiants(): void
    {
        $line = ['line_id' => 'A', 'position' => 1, 'catalog_item_id' => self::LINE_A, 'description' => 'Examen fictif', 'quantity' => '1', 'unit_price_gross' => 15000, 'discount_gross' => 3000, 'prescription_id' => null];
        $first = ApprovalSubject::draftLineRights(self::INVOICE, self::PATIENT, [$line]);
        $same = ApprovalSubject::draftLineRights(self::INVOICE, self::PATIENT, [['line_id' => 'B', 'quantity' => '1.000'] + $line]);
        $other = ApprovalSubject::draftLineRights(self::INVOICE, self::PATIENT, [['discount_gross' => 3001] + $line]);

        $this->assertSame($first->hash(), $same->hash());
        $this->assertNotSame($first->hash(), $other->hash());
        $this->assertArrayNotHasKey('line_id', $first->toArray()['lines'][0]);
    }

    public function test_une_nature_inconnue_ou_un_champ_manquant_est_refuse(): void
    {
        foreach ([['kind' => 'ANY'], ['kind' => 'PAYMENT_REVERSAL', 'invoice_id' => self::INVOICE, 'amount' => 10], ['kind' => 'REFUND', 'invoice_id' => self::INVOICE, 'credit_note_id' => self::LINE_A, 'amount' => 0, 'method' => 'CASH']] as $subject) {
            try {
                ApprovalSubject::fromRequest($subject);
                $this->fail('Contenu accepté à tort : '.json_encode($subject));
            } catch (RuleViolation $violation) {
                $this->assertStringStartsWith('subject.', $violation->field);
            }
        }
    }

    public function test_le_contenu_relu_n_est_rendu_que_s_il_reproduit_l_empreinte(): void
    {
        $subject = ApprovalSubject::refund(self::INVOICE, self::LINE_A, 1000, 'CASH');

        $this->assertSame($subject->toArray(), ApprovalSubject::verified($subject->toArray(), $subject->hash()));
        $this->assertNull(ApprovalSubject::verified(['amount' => 2000] + $subject->toArray(), $subject->hash()));
        $this->assertNull(ApprovalSubject::verified(['kind' => 'NOPE'], $subject->hash()));
    }

    public function test_chaque_nature_a_une_permission_distincte_et_une_cible(): void
    {
        $codes = array_map(static fn (ApprovalKind $kind): string => $kind->actionCode(), ApprovalKind::cases());

        $this->assertSame(count($codes), count(array_unique($codes)), 'Prix, examen, correction, avoir et remboursement restent des droits distincts.');
        $this->assertSame(ApprovalKind::Refund, ApprovalKind::fromStored('refund.execute', 'invoices'));
        $this->assertNull(ApprovalKind::fromStored('stock.adjust', 'stock_movements'), 'Le stock initial reste une exécution directe.');
    }

    public function test_une_facture_de_frais_creditee_puis_remboursee_ne_prouve_pas_le_reglement(): void
    {
        $this->assertTrue(BilledExamFeeControl::settledByMoney(LedgerBalance::of(15000, [15000], [], [], [])));
        $this->assertFalse(BilledExamFeeControl::settledByMoney(LedgerBalance::of(15000, [10000], [], [], [])), 'Un acompte ne règle pas.');
        $this->assertFalse(BilledExamFeeControl::settledByMoney(LedgerBalance::of(15000, [15000], [15000], [], [])), 'Un paiement contre-passé ne règle pas.');
        $this->assertFalse(BilledExamFeeControl::settledByMoney(LedgerBalance::of(15000, [15000], [], [15000], [15000])), 'Crédité puis remboursé : rien n\'est conservé.');
        $this->assertTrue(BilledExamFeeControl::settledByMoney(LedgerBalance::of(15000, [10000], [], [5000], [])), 'Réduction par avoir puis solde nul : la part non créditée est couverte.');
    }
}
