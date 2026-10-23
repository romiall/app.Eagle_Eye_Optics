<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApprovalFixtures;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Dépenses et mouvements de caisse hors vente (L1D05 ; AT25, AT30 ; EE030) sur MySQL réel : la gérante exécute
 * directement, la caissière avec l'approbation exacte (consommée une fois) ; jamais un paiement ni un reçu ; espèces
 * attendues recalculées ; sortie au-delà du tiroir refusée ; dépense après clôture refusée (clôture figée) ;
 * catégories (code figé, désactivation) ; revue de l'écart d'une session clôturée, une fois. Données fictives.
 */
final class CashMovementsTest extends TestCase
{
    use ApprovalFixtures;
    use BillingFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBilling(['sales', 'settings']);
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    private function category(string $code = 'TRANSPORT', string $name = 'Transport (fictif)'): string
    {
        $id = Ulid::generate();
        $this->write('POST', '/api/v1/expense-categories', [
            'expected_version' => null, 'category_id' => $id, 'code' => $code, 'name' => $name, 'active' => true,
        ], $this->manager)->assertOk()->assertJsonPath('data.code', $code);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function movement(string $sessionId, array $overrides = [], ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->write('POST', '/api/v1/cash-sessions/'.$sessionId.'/movements', array_merge([
            'expected_version' => null,
            'movement_id' => Ulid::generate(),
            'cash_session_id' => $sessionId,
            'movement_kind' => 'EXPENSE',
            'signed_amount' => -5000,
            'expense_category_id' => null,
            'reason' => 'Course de taxi pour un fournisseur (fictif)',
            'reference' => 'BON-0001',
            'approval_id' => Ulid::generate(),
        ], $overrides), $as ?? $this->manager, $key);
    }

    private function theoretical(User $as): int
    {
        return (int) $this->read('/api/v1/cash-sessions/current', $as)->assertOk()->json('data.theoretical_cash');
    }

    public function test_la_gerante_enregistre_une_depense_directement_sans_paiement_ni_recu(): void
    {
        $category = $this->category();
        $session = $this->openedSession($this->manager, 25000);

        $response = $this->movement($session, ['expense_category_id' => $category])->assertCreated();
        $this->assertMatchesContract($response, 'schemas/resources/billing.json#/$defs/CashMovementResponse');
        $response->assertJsonPath('data.kind', 'EXPENSE')
            ->assertJsonPath('data.signed_amount', -5000)
            ->assertJsonPath('data.approval_mode', 'DIRECT')
            ->assertJsonPath('data.expense_category.code', 'TRANSPORT');

        $this->assertSame(20000, $this->theoretical($this->manager), 'les espèces attendues baissent de la dépense');
        $this->assertSame(0, DB::table('payments')->where('clinic_id', $this->clinicId)->count(), 'aucun paiement simulé');
        $this->assertSame(0, DB::table('receipts')->where('clinic_id', $this->clinicId)->count(), 'aucun reçu');
        $approval = DB::table('approvals')->where('id', $response->json('data.approval_id'))->first();
        $this->assertNotNull($approval);
        $this->assertSame('CONSUMED', $approval->status);
        $this->assertSame('cash.manage', $approval->action_code);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash.movement_recorded')->count());

        $list = $this->read('/api/v1/cash-sessions/'.$session.'/movements', $this->manager)->assertOk();
        $this->assertMatchesContract($list, 'schemas/resources/billing.json#/$defs/CashSessionMovementsResponse');
        $list->assertJsonCount(1, 'data.movements')->assertJsonPath('data.closed', false);
    }

    public function test_la_caissiere_a_besoin_de_l_approbation_exacte_consommee_une_seule_fois(): void
    {
        $category = $this->category();
        $session = $this->openedSession($this->cashier, 30000);

        $this->movement($session, ['expense_category_id' => $category], $this->cashier)
            ->assertStatus(422)->assertJsonPath('error.code', 'APPROVAL_REQUIRED_OR_EXPIRED');
        $this->assertSame(0, DB::table('cash_movements')->where('clinic_id', $this->clinicId)->count());

        $approval = $this->approved(ApprovalSubject::cashMovement($session, 'EXPENSE', -5000, $category)->toArray(), $this->cashier);
        $this->movement($session, ['expense_category_id' => $category, 'approval_id' => $approval], $this->cashier)
            ->assertCreated()->assertJsonPath('data.approval_mode', 'APPROVED');
        $this->assertSame(25000, $this->theoretical($this->cashier));

        // Même approbation pour un autre mouvement : refus (consommée), et un montant différent n'est jamais couvert.
        $this->movement($session, ['expense_category_id' => $category, 'approval_id' => $approval], $this->cashier)
            ->assertStatus(422);
        $other = $this->approved(ApprovalSubject::cashMovement($session, 'EXPENSE', -5000, $category)->toArray(), $this->cashier);
        $this->movement($session, ['expense_category_id' => $category, 'approval_id' => $other, 'signed_amount' => -6000], $this->cashier)
            ->assertStatus(422);
        $this->assertSame(1, DB::table('cash_movements')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_formes_et_tiroir_une_sortie_ne_depasse_jamais_les_especes_attendues(): void
    {
        $category = $this->category();
        $session = $this->openedSession($this->manager, 10000);

        $this->movement($session, ['expense_category_id' => $category, 'signed_amount' => -15000])
            ->assertStatus(422)->assertJsonPath('error.details.available', 10000);
        $this->movement($session, ['signed_amount' => -100])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['expense_category_id']]]);
        $this->movement($session, ['movement_kind' => 'DEPOSIT', 'signed_amount' => -100, 'expense_category_id' => null])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['signed_amount']]]);
        $this->movement($session, ['movement_kind' => 'WITHDRAWAL', 'signed_amount' => -100, 'expense_category_id' => $category])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['expense_category_id']]]);
        $this->movement($session, ['expense_category_id' => $category, 'reason' => 'x'])->assertStatus(422);

        // Correction de l'ouverture : signe libre, ce n'est pas une sortie réelle.
        $this->movement($session, ['movement_kind' => 'OPENING_CORRECTION', 'signed_amount' => 2000, 'expense_category_id' => null])
            ->assertCreated();
        $this->movement($session, ['movement_kind' => 'DEPOSIT', 'signed_amount' => 3000, 'expense_category_id' => null])->assertCreated();
        $this->assertSame(15000, $this->theoretical($this->manager));
    }

    public function test_une_depense_apres_cloture_est_refusee_la_cloture_est_figee(): void
    {
        $category = $this->category();
        $session = $this->openedSession($this->manager, 25000);
        $this->write('POST', '/api/v1/cash-sessions/'.$session.'/close', $this->closeSessionBody($session, 25000, 25000), $this->manager)->assertOk();

        $this->movement($session, ['expense_category_id' => $category])
            ->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED')->assertJsonPath('error.details.current_status', 'CLOSED');
        $this->assertSame(0, DB::table('cash_movements')->where('clinic_id', $this->clinicId)->count());
        $this->read('/api/v1/cash-sessions/'.$session.'/movements', $this->manager)->assertOk()->assertJsonPath('data.closed', true);
    }

    public function test_une_caissiere_ne_touche_pas_la_session_d_une_autre(): void
    {
        $category = $this->category();
        $session = $this->openedSession($this->manager, 25000);
        $approval = $this->approved(ApprovalSubject::cashMovement($session, 'EXPENSE', -5000, $category)->toArray(), $this->cashier);

        $this->movement($session, ['expense_category_id' => $category, 'approval_id' => $approval], $this->cashier)->assertForbidden();
        $this->read('/api/v1/cash-sessions/'.$session.'/movements', $this->cashier)->assertForbidden();
        $this->movement($session, [], $this->clinician)->assertForbidden();
    }

    public function test_categories_code_unique_et_fige_desactivation_sans_suppression(): void
    {
        $id = $this->category('FOURNITURES', 'Fournitures (fictif)');
        $this->write('POST', '/api/v1/expense-categories', [
            'expected_version' => null, 'category_id' => Ulid::generate(), 'code' => 'FOURNITURES', 'name' => 'Doublon', 'active' => true,
        ], $this->manager)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->write('POST', '/api/v1/expense-categories', [
            'expected_version' => null, 'category_id' => $id, 'code' => 'AUTRE', 'name' => 'Fournitures (fictif)', 'active' => true,
        ], $this->manager)->assertStatus(422);
        $this->write('POST', '/api/v1/expense-categories', [
            'expected_version' => null, 'category_id' => $id, 'code' => null, 'name' => 'Fournitures de bureau (fictif)', 'active' => false,
        ], $this->manager)->assertOk()->assertJsonPath('data.active', false);

        $this->read('/api/v1/expense-categories', $this->cashier)->assertOk()->assertJsonCount(0, 'data');
        $this->read('/api/v1/expense-categories', $this->manager)->assertOk()->assertJsonCount(1, 'data');
        $this->write('POST', '/api/v1/expense-categories', [
            'expected_version' => null, 'category_id' => Ulid::generate(), 'code' => 'X1', 'name' => 'Interdit', 'active' => true,
        ], $this->cashier)->assertForbidden();

        $session = $this->openedSession($this->manager, 25000);
        $this->movement($session, ['expense_category_id' => $id])->assertStatus(422);
    }

    public function test_la_gerante_revoit_une_fois_l_ecart_d_une_session_cloturee(): void
    {
        $session = $this->openedSession($this->cashier, 25000);
        $this->write('POST', '/api/v1/cash-sessions/'.$session.'/close', $this->closeSessionBody($session, 24000, 25000, 'Erreur de rendu de monnaie (fictif)'), $this->cashier)->assertOk();
        $body = ['expected_version' => null, 'cash_session_id' => $session, 'decision' => 'DISPUTED', 'note' => null];

        $this->write('POST', '/api/v1/cash-sessions/'.$session.'/variance-review', $body, $this->manager)->assertStatus(423);
        $this->writeReauthenticated('POST', '/api/v1/cash-sessions/'.$session.'/variance-review', $body, $this->manager)
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['note']]]);
        $review = $this->writeReauthenticated('POST', '/api/v1/cash-sessions/'.$session.'/variance-review', ['decision' => 'ACCEPTED', 'note' => 'Motif vérifié'] + $body, $this->manager)
            ->assertCreated();
        $this->assertMatchesContract($review, 'schemas/resources/billing.json#/$defs/CashVarianceReviewResponse');
        $review->assertJsonPath('data.decision', 'ACCEPTED')->assertJsonPath('data.reviewed_by', $this->manager->id);

        $this->writeReauthenticated('POST', '/api/v1/cash-sessions/'.$session.'/variance-review', ['decision' => 'ACCEPTED', 'note' => null] + $body, $this->manager)
            ->assertStatus(409)->assertJsonPath('error.details.current_status', 'REVIEWED');
        $closed = DB::table('cash_sessions')->where('id', $session)->first();
        $this->assertNotNull($closed);
        $this->assertSame(-1000, (int) $closed->variance, 'la clôture figée n\'est pas réécrite');
        $this->read('/api/v1/cash-sessions/'.$session.'/movements', $this->manager)->assertOk()->assertJsonPath('data.variance_review.decision', 'ACCEPTED');
    }
}
