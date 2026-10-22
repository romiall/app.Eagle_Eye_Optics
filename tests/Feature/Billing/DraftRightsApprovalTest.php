<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\Approvals\ApprovalSubject;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApprovalFixtures;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Remise ou prix d'un brouillon au-delà des droits de la caisse (B02 avec B06, AT07 ; fichier 09 : « Modifier
 * prix/remise : caissière sur approbation exacte uniquement ») : la remise non autorisée est bloquée, la demande à la
 * gérante est possible, et l'approbation accordée ne vaut que pour CE brouillon, CES lignes, CETTE caissière, une
 * fois. Données fictives.
 */
final class DraftRightsApprovalTest extends TestCase
{
    use ApprovalFixtures;
    use BillingFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBilling(['sales', 'stock', 'settings']);
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    public function test_une_remise_de_la_caisse_exige_puis_consomme_l_approbation_exacte(): void
    {
        $lines = [$this->line($this->item['exam'], '1', 1, null, 3000)];
        $body = $this->draftBody($lines);

        $this->write('POST', '/api/v1/invoices', $body, $this->cashier)
            ->assertStatus(422)->assertJsonPath('error.code', 'APPROVAL_REQUIRED_OR_EXPIRED')
            ->assertJsonStructure(['error' => ['fields' => ['lines.0.discount_gross']]]);

        $subject = ApprovalSubject::draftLineRights((string) $body['invoice_id'], $this->patientId, $lines);
        $approval = $this->approved($subject->toArray(), $this->cashier);

        $created = $this->write('POST', '/api/v1/invoices', $body, $this->cashier)->assertCreated();
        $this->assertSame(12000, (int) $created->json('data.total_gross'));
        $this->assertSame('CONSUMED', DB::table('approvals')->where('id', $approval)->value('status'));
        $audit = DB::table('audit_events')->where('action', 'invoice.draft_created')->where('target_id', $body['invoice_id'])->value('sanitized_diff');
        $this->assertSame($approval, json_decode((string) $audit, true)['rights_approval_id']);

        // Approbation brûlée : la même remise sur une modification du brouillon redemande l'approbation.
        $update = ['expected_version' => 1, 'invoice_id' => $body['invoice_id'], 'visit_id' => null, 'due_date' => null, 'lines' => [$this->line($this->item['exam'], '1', 1, null, 3000)]];
        $this->write('PATCH', '/api/v1/invoices/'.$body['invoice_id'], $update, $this->cashier)->assertStatus(422)->assertJsonPath('error.code', 'APPROVAL_REQUIRED_OR_EXPIRED');
    }

    public function test_une_autre_remise_un_autre_brouillon_ou_une_autre_caissiere_n_en_profitent_pas(): void
    {
        $second = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse2.test');
        $lines = [$this->line($this->item['exam'], '1', 1, null, 3000)];
        $body = $this->draftBody($lines);
        $this->approved(ApprovalSubject::draftLineRights((string) $body['invoice_id'], $this->patientId, $lines)->toArray(), $this->cashier);

        // Remise plus forte : contenu différent.
        $more = $body;
        $more['lines'][0]['discount_gross'] = 4000;
        $this->write('POST', '/api/v1/invoices', $more, $this->cashier)->assertStatus(422);
        // Même contenu sur un autre brouillon.
        $this->write('POST', '/api/v1/invoices', array_merge($body, ['invoice_id' => Ulid::generate()]), $this->cashier)->assertStatus(422);
        // Même brouillon, autre caissière.
        $this->write('POST', '/api/v1/invoices', $body, $second)->assertStatus(422);
        $this->assertSame(0, DB::table('invoices')->count());

        $this->write('POST', '/api/v1/invoices', $body, $this->cashier)->assertCreated();
    }

    public function test_sans_remise_aucune_approbation_n_est_consommee(): void
    {
        $lines = [$this->line($this->item['exam'], '1', 1, null, 3000)];
        $body = $this->draftBody($lines);
        $approval = $this->approved(ApprovalSubject::draftLineRights((string) $body['invoice_id'], $this->patientId, $lines)->toArray(), $this->cashier);

        $this->createDraft([$this->line($this->item['exam'])], $this->cashier)->assertCreated();

        $this->assertSame('APPROVED', DB::table('approvals')->where('id', $approval)->value('status'));
    }
}
