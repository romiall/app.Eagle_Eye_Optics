<?php

namespace Tests\Feature\Billing;

use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Abandon d'un brouillon de facture (fichier 03 : « Une facture brouillon peut être abandonnée ») : version de
 * brouillon attendue, créateur ou gérante, motif facultatif conservé dans l'audit ; aucun numéro, aucune réserve,
 * aucun document ; une facture émise ne s'abandonne jamais (avoir lié). Données fictives.
 */
final class AbandonInvoiceDraftTest extends TestCase
{
    use BillingFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBilling();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function abandonBody(string $invoiceId, ?int $version, ?string $reason = null): array
    {
        return ['expected_version' => $version, 'invoice_id' => $invoiceId, 'reason' => $reason];
    }

    public function test_le_createur_abandonne_son_brouillon_sans_aucun_effet_financier(): void
    {
        [$id, , $version] = $this->draft([$this->line($this->item['frame'])], $this->cashier);
        $before = $this->billingEffects();

        $response = $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version, '  Le patient renonce  '), $this->cashier)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'ABANDONED')
            ->assertJsonPath('data.draft_version', $version + 1)
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.ledger_version', null);
        $this->assertMatchesContract($response, 'schemas/resources/billing.json#/$defs/InvoiceResponse');

        $after = $this->billingEffects();
        foreach (['usages', 'sequences', 'reservations', 'reserved', 'documents', 'payments', 'receipts', 'lines', 'invoices', 'issued'] as $key) {
            $this->assertSame($before[$key], $after[$key], $key.' ne change pas');
        }
        $audit = DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'invoice.draft_abandoned')->where('target_id', $id)->first();
        $this->assertNotNull($audit);
        $this->assertSame('Le patient renonce', $audit->reason, 'Motif conservé dans l\'audit, blancs de bord retirés');
    }

    public function test_le_motif_est_facultatif(): void
    {
        [$id, , $version] = $this->draft(null, $this->cashier);

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $this->cashier)
            ->assertOk()
            ->assertJsonPath('data.status', 'ABANDONED');
    }

    public function test_une_version_perimee_est_un_conflit_et_rien_n_est_ecrit(): void
    {
        [$id, , $version] = $this->draft(null, $this->cashier);

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version + 1), $this->cashier)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT')
            ->assertJsonPath('error.details.current_version', $version);
        $this->assertSame('DRAFT', DB::table('invoices')->where('id', $id)->value('status'));
    }

    public function test_sans_version_attendue_l_abandon_est_refuse(): void
    {
        [$id] = $this->draft(null, $this->cashier);

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, null), $this->cashier)->assertStatus(422);
        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', ['invoice_id' => $id, 'reason' => null], $this->cashier)->assertStatus(422);
        $this->assertSame('DRAFT', DB::table('invoices')->where('id', $id)->value('status'));
    }

    public function test_l_identifiant_du_corps_doit_etre_celui_de_l_adresse(): void
    {
        [$id, , $version] = $this->draft(null, $this->cashier);

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody(Ulid::generate(), $version), $this->cashier)
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['invoice_id']]]);
    }

    public function test_une_facture_emise_ne_s_abandonne_pas(): void
    {
        [$id] = $this->issuedInvoice(null, $this->cashier);
        $version = (int) DB::table('invoices')->where('id', $id)->value('version');

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $this->cashier)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
        $this->assertSame('ISSUED', DB::table('invoices')->where('id', $id)->value('status'));
    }

    public function test_un_brouillon_abandonne_ne_se_modifie_ni_ne_s_abandonne_plus(): void
    {
        [$id, , $version] = $this->draft(null, $this->cashier);
        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $this->cashier)->assertOk();

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version + 1), $this->cashier)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
        $this->write('PATCH', '/api/v1/invoices/'.$id, ['expected_version' => $version + 1, 'invoice_id' => $id, 'visit_id' => null, 'due_date' => null, 'lines' => [$this->line($this->item['exam'])]], $this->cashier)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
        $this->write('POST', '/api/v1/invoices/'.$id.'/issue', $this->issueBody($id, $version + 1, 15000), $this->cashier)
            ->assertStatus(409);
    }

    public function test_seuls_le_createur_et_la_gerante_abandonnent(): void
    {
        $otherCashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.deux');
        [$id, , $version] = $this->draft(null, $this->cashier);

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $otherCashier)->assertForbidden();
        $this->assertSame('DRAFT', DB::table('invoices')->where('id', $id)->value('status'));

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $this->manager)
            ->assertOk()
            ->assertJsonPath('data.status', 'ABANDONED');
    }

    public function test_l_accueil_et_le_professionnel_n_ont_pas_ce_droit(): void
    {
        [$id, , $version] = $this->draft(null, $this->cashier);

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $this->reception)->assertForbidden();
        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $this->clinician)->assertForbidden();
    }

    public function test_le_rejeu_de_la_meme_cle_rend_la_meme_reponse(): void
    {
        [$id, , $version] = $this->draft(null, $this->cashier);
        $key = Ulid::generate();

        $first = $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $this->cashier, $key)->assertOk();
        $second = $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $this->cashier, $key)->assertOk();

        $this->assertSame($first->json('data.draft_version'), $second->json('data.draft_version'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'invoice.draft_abandoned')->where('target_id', $id)->count());
    }

    public function test_une_facture_d_un_autre_cabinet_est_introuvable(): void
    {
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $otherCashier = $this->makeStaff($otherClinic, 'CASHIER', 'caisse.b');
        [$id, , $version] = $this->draft(null, $this->cashier);

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $otherCashier)->assertNotFound();
        $this->assertSame('DRAFT', DB::table('invoices')->where('id', $id)->value('status'));
    }

    public function test_module_ferme_404(): void
    {
        [$id, , $version] = $this->draft(null, $this->cashier);
        config(['eeo.enabled_modules' => ['stock']]);

        $this->write('POST', '/api/v1/invoices/'.$id.'/abandon', $this->abandonBody($id, $version), $this->cashier)->assertNotFound();
    }
}
