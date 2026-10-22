<?php

namespace Tests\Feature\Billing;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBusProbe;
use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\Handlers\CreateInvoiceDraftHandler;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BillingFixtures;
use Tests\Support\Commands\RecordingProbe;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Brouillons de facture (B02, EE020, EE021, EE045, AT07, AT08) sur MySQL réel : création et modification sans numéro
 * ni créance, calcul exact par les règles communes, droits de prix et de remise, profil « frais d'examen », version de
 * brouillon et conflit qui conserve le formulaire, aucun effet sur le stock, idempotence, retour arrière. Données
 * fictives.
 */
final class InvoiceDraftTest extends TestCase
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

    public function test_un_brouillon_est_cree_sans_numero_ni_creance_et_suit_le_contrat(): void
    {
        $body = $this->draftBody([$this->line($this->item['exam'], '1', 1), $this->line($this->item['lens'], '1', 2)]);

        $response = $this->write('POST', '/api/v1/invoices', $body, $this->cashier);

        $response->assertCreated()
            ->assertJsonPath('data.id', $body['invoice_id'])
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.issued_at', null)
            ->assertJsonPath('data.ledger_version', null)
            ->assertJsonPath('data.draft_version', 1)
            ->assertJsonPath('data.total_gross', 75000)
            ->assertJsonPath('data.balance.settlement', 'NOT_ISSUED')
            ->assertJsonPath('data.balance.balance_due', 0)
            ->assertJsonPath('data.patient_snapshot', null)
            ->assertJsonPath('data.currency', 'XAF');
        $this->assertMatchesContract($response, 'schemas/resources/billing.json#/$defs/InvoiceResponse');
        $this->assertSame([1, 2], array_column($response->json('data.lines'), 'position'));
        $this->assertSame('SERVICE', $response->json('data.lines.0.category_snapshot'));
        $this->assertSame('EX-001', $response->json('data.lines.0.reference_snapshot'));
        $this->assertSame('NO_TAX_CONFIGURED', $response->json('data.lines.0.tax_code_snapshot'));
        $this->assertNull($response->json('data.lines.0.tax_amount'), 'Aucune taxe inventée : null, pas zéro.');

        $invoice = DB::table('invoices')->where('id', $body['invoice_id'])->first();
        $this->assertNotNull($invoice);
        $this->assertSame('DRAFT', $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertNull($invoice->number_usage_id);
        $this->assertSame($this->cashier->id, $invoice->created_by);
        $this->assertSame(0, DB::table('document_sequences')->where('clinic_id', $this->clinicId)->count(), 'Un brouillon ne consomme aucun numéro.');
        $this->assertSame(0, DB::table('document_number_usages')->where('clinic_id', $this->clinicId)->count());
        $this->assertSame(0, DB::table('generated_documents')->where('clinic_id', $this->clinicId)->count());
        $this->assertSame(0, DB::table('outbox_events')->where('clinic_id', $this->clinicId)->count());

        $audit = DB::table('audit_events')->where('action', 'invoice.draft_created')->where('target_id', $body['invoice_id'])->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->cashier->id, $audit->actor_id);
        $change = DB::table('sync_change_log')->where('entity_type', 'invoices')->where('entity_id', $body['invoice_id'])->first();
        $this->assertNotNull($change);
        $this->assertSame(['FINANCIAL', 'CREATED', 1], [$change->visibility, $change->change_kind, (int) $change->entity_version]);
    }

    public function test_le_vecteur_commun_une_virgule_cent_vingt_cinq_fois_mille_un_donne_mille_cent_vingt_six(): void
    {
        // La gérante fixe le prix (1001) ; l'examen n'est pas un article à quantité entière.
        $lines = [$this->line($this->item['exam'], '1.125', 1, 1001)];

        $response = $this->createDraft($lines, $this->manager)->assertCreated();

        $response->assertJsonPath('data.lines.0.quantity', '1.125')
            ->assertJsonPath('data.lines.0.line_total_gross', 1126)
            ->assertJsonPath('data.total_gross', 1126);
        $this->assertSame(1126, DB::table('invoice_lines')->where('invoice_id', $response->json('data.id'))->value('line_total_gross'));
    }

    public function test_la_remise_de_ligne_se_deduit_du_brut_et_le_total_est_la_somme_des_nets(): void
    {
        $lines = [$this->line($this->item['lens'], '2', 1, 60000, 20000), $this->line($this->item['exam'], '1', 2)];

        $response = $this->createDraft($lines, $this->manager)->assertCreated();

        $this->assertSame([100000, 15000], array_column($response->json('data.lines'), 'line_total_gross'));
        $response->assertJsonPath('data.total_gross', 115000);
    }

    public function test_un_brouillon_n_a_aucun_effet_sur_le_stock(): void
    {
        $position = $this->seedPosition($this->item['frame'], '5');

        $this->createDraft([$this->line($this->item['frame'], '2')])->assertCreated();

        $this->assertSame('0.000', DB::table('stock_positions')->where('id', $position)->value('reserved'));
        $this->assertSame(0, DB::table('stock_reservations')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_la_caissiere_ne_remise_pas_et_la_gerante_oui(): void
    {
        $lines = [$this->line($this->item['lens'], '1', 1, null, 5000)];

        $refused = $this->createDraft($lines, $this->cashier);
        $accepted = $this->createDraft($lines, $this->manager);

        $refused->assertStatus(422)->assertJsonPath('error.code', 'APPROVAL_REQUIRED_OR_EXPIRED')->assertJsonStructure(['error' => ['fields' => ['lines.0.discount_gross']]]);
        $this->assertMatchesContract($refused, 'schemas/error.json');
        $accepted->assertCreated()->assertJsonPath('data.total_gross', 55000);
        $this->assertSame(1, DB::table('invoices')->where('clinic_id', $this->clinicId)->count(), 'La remise refusée n\'a rien écrit.');
    }

    public function test_un_plafond_de_remise_configure_s_applique_aux_comptes_sans_autorite_de_prix(): void
    {
        config(['eeo.billing.discount_cap_percent' => '5']);

        $this->createDraft([$this->line($this->item['lens'], '1', 1, null, 3000)], $this->cashier)->assertCreated();
        $this->createDraft([$this->line($this->item['lens'], '1', 1, null, 3001)], $this->cashier)->assertStatus(422)
            ->assertJsonPath('error.code', 'APPROVAL_REQUIRED_OR_EXPIRED');
    }

    public function test_la_caissiere_ne_change_pas_le_prix_du_catalogue_ni_ne_cree_de_ligne_libre(): void
    {
        $priced = $this->createDraft([$this->line($this->item['lens'], '1', 1, 59000)], $this->cashier);
        $free = $this->createDraft([[...$this->line($this->item['exam']), 'catalog_item_id' => null, 'description' => 'Réparation fictive', 'unit_price_gross' => 2500]], $this->cashier);

        $priced->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.unit_price_gross']]]);
        $free->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.catalog_item_id']]]);

        $managerFree = $this->createDraft([[...$this->line($this->item['exam']), 'catalog_item_id' => null, 'description' => 'Réparation fictive', 'unit_price_gross' => 2500]], $this->manager)->assertCreated();
        $managerFree->assertJsonPath('data.lines.0.category_snapshot', 'OTHER')->assertJsonPath('data.lines.0.unit_snapshot', 'UNIT')->assertJsonPath('data.lines.0.catalog_item_id', null);
    }

    public function test_le_profil_frais_d_examen_ne_cree_que_la_prestation_fixe(): void
    {
        $this->allowPermission($this->reception, 'exam_fee.collect');

        $this->createDraft([$this->line($this->item['exam'])], $this->reception)->assertCreated();
        $this->createDraft([$this->line($this->item['frame'])], $this->reception)->assertStatus(422)
            ->assertJsonPath('error.code', 'APPROVAL_REQUIRED_OR_EXPIRED')->assertJsonStructure(['error' => ['fields' => ['lines.0.catalog_item_id']]]);
        $this->createDraft([$this->line($this->item['exam'], '2')], $this->reception)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.quantity']]]);
        $this->createDraft([$this->line($this->item['exam'], '1', 1, 12000)], $this->reception)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.unit_price_gross']]]);

        $this->assertSame(1, DB::table('invoices')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_sans_article_de_frais_d_examen_configure_le_profil_restreint_ne_facture_rien(): void
    {
        $this->allowPermission($this->reception, 'exam_fee.collect');
        config(['eeo.billing.exam_fee_item_reference' => '']);

        $this->createDraft([$this->line($this->item['exam'])], $this->reception)->assertStatus(422)->assertJsonPath('error.code', 'APPROVAL_REQUIRED_OR_EXPIRED');
    }

    public function test_l_accueil_sans_permission_speciale_et_le_professionnel_ne_creent_pas_de_brouillon(): void
    {
        $this->createDraft(null, $this->reception)->assertForbidden();
        $this->createDraft(null, $this->clinician)->assertForbidden();

        $this->assertSame(0, DB::table('invoices')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_une_modification_remplace_les_lignes_recalcule_le_total_et_incremente_la_version_du_brouillon(): void
    {
        [$id, , $version] = $this->draft([$this->line($this->item['exam'])]);
        $update = $this->updateBody($id, $version, [$this->line($this->item['lens'], '1', 1), $this->line($this->item['exam'], '2', 2)]);

        $response = $this->write('PATCH', '/api/v1/invoices/'.$id, $update, $this->cashier);

        $response->assertOk()->assertJsonPath('data.draft_version', 2)->assertJsonPath('data.total_gross', 90000)->assertJsonPath('data.status', 'DRAFT');
        $this->assertMatchesContract($response, 'schemas/resources/billing.json#/$defs/InvoiceResponse');
        $this->assertCount(2, $response->json('data.lines'));
        $this->assertSame(2, DB::table('invoice_lines')->where('invoice_id', $id)->count());
        $this->assertSame(2, (int) DB::table('invoices')->where('id', $id)->value('version'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'invoice.draft_updated')->count());
    }

    public function test_une_version_perimee_est_un_conflit_qui_conserve_le_brouillon_et_indique_la_version_a_reprendre(): void
    {
        [$id, , $version] = $this->draft([$this->line($this->item['exam'])]);
        $this->write('PATCH', '/api/v1/invoices/'.$id, $this->updateBody($id, $version, [$this->line($this->item['lens'])]), $this->cashier)->assertOk();

        $stale = $this->write('PATCH', '/api/v1/invoices/'.$id, $this->updateBody($id, $version, [$this->line($this->item['exam'], '3')]), $this->cashier);

        $stale->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT')->assertJsonPath('error.details.current_version', 2);
        $this->assertMatchesContract($stale, 'schemas/error.json');
        $this->assertSame(60000, (int) DB::table('invoices')->where('id', $id)->value('total_gross'), 'Le brouillon courant est intact.');
        $this->assertSame(1, DB::table('invoice_lines')->where('invoice_id', $id)->count());
    }

    public function test_deux_modifications_de_la_meme_version_ne_laissent_passer_que_la_premiere(): void
    {
        [$id, , $version] = $this->draft([$this->line($this->item['exam'])]);

        $this->write('PATCH', '/api/v1/invoices/'.$id, $this->updateBody($id, $version, [$this->line($this->item['lens'])]), $this->cashier)->assertOk();
        $this->write('PATCH', '/api/v1/invoices/'.$id, $this->updateBody($id, $version, [$this->line($this->item['frame'])]), $this->cashier)->assertStatus(409);

        $this->assertSame(2, (int) DB::table('invoices')->where('id', $id)->value('version'));
    }

    public function test_le_brouillon_d_une_autre_caissiere_n_est_modifiable_que_par_la_gerante(): void
    {
        $other = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.autre');
        [$id, , $version] = $this->draft([$this->line($this->item['exam'])], $this->cashier);

        $this->write('PATCH', '/api/v1/invoices/'.$id, $this->updateBody($id, $version, [$this->line($this->item['lens'])]), $other)->assertForbidden();
        $this->write('PATCH', '/api/v1/invoices/'.$id, $this->updateBody($id, $version, [$this->line($this->item['lens'])]), $this->manager)->assertOk();

        $this->assertSame(2, (int) DB::table('invoices')->where('id', $id)->value('version'));
    }

    public function test_une_facture_emise_ne_se_modifie_plus(): void
    {
        [$id] = $this->issuedInvoice();
        $version = (int) DB::table('invoices')->where('id', $id)->value('version');

        $response = $this->write('PATCH', '/api/v1/invoices/'.$id, $this->updateBody($id, $version, [$this->line($this->item['lens'])]), $this->manager);

        $response->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED')->assertJsonPath('error.details.current_status', 'ISSUED');
        $this->assertSame(1, DB::table('invoice_lines')->where('invoice_id', $id)->count());
    }

    public function test_les_erreurs_de_toutes_les_lignes_sont_rendues_ensemble(): void
    {
        $lines = [
            $this->line($this->item['lens'], '1.5', 1),
            [...$this->line($this->item['exam'], '1', 2), 'description' => '   '],
            $this->line($this->item['frame'], '1', 3),
        ];
        DB::table('catalog_items')->where('id', $this->item['frame'])->update(['active' => 0]);

        $response = $this->createDraft($lines, $this->manager);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame(['lines.0.quantity', 'lines.1.description', 'lines.2.catalog_item_id'], array_keys($response->json('error.fields')));
    }

    public function test_le_patient_et_la_visite_sont_controles(): void
    {
        $archived = $this->seedPatient($this->clinicId, 'Archivé', null, ['archived_at' => '2026-10-01 08:00:00.000000']);
        $other = $this->seedPatient($this->clinicId, 'Autre');
        $visit = Ulid::generate();
        DB::table('visits')->insert([
            'id' => $visit, 'clinic_id' => $this->clinicId, 'patient_id' => $other, 'reason' => 'EXAM', 'occurred_at' => '2026-10-22 09:00:00.000000',
            'status' => 'ARRIVED', 'created_by' => $this->manager->id, 'version' => 1,
        ]);

        $this->createDraft(null, null, ['patient_id' => Ulid::generate()])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['patient_id']]]);
        $this->createDraft(null, null, ['patient_id' => $archived])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['patient_id']]]);
        $this->createDraft(null, null, ['visit_id' => $visit])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['visit_id']]]);
        $this->createDraft(null, null, ['patient_id' => $other, 'visit_id' => $visit])->assertCreated()->assertJsonPath('data.visit_id', $visit);

        $this->assertSame(1, DB::table('invoices')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_un_identifiant_deja_pris_est_refuse_sans_erreur_sql(): void
    {
        [$id] = $this->draft();
        $line = DB::table('invoice_lines')->where('invoice_id', $id)->value('id');

        $this->createDraft(null, null, ['invoice_id' => $id])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['invoice_id']]]);
        $this->createDraft([[...$this->line($this->item['exam']), 'line_id' => $line]])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['lines.0.line_id']]]);

        $this->assertSame(1, DB::table('invoices')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_un_rejeu_de_la_meme_cle_ne_cree_qu_un_brouillon_et_un_autre_contenu_est_un_conflit(): void
    {
        $body = $this->draftBody([$this->line($this->item['exam'])]);
        $key = Ulid::generate();

        $first = $this->write('POST', '/api/v1/invoices', $body, null, $key)->assertCreated();
        $replay = $this->write('POST', '/api/v1/invoices', $body, null, $key)->assertCreated();
        $this->write('POST', '/api/v1/invoices', [...$body, 'due_date' => '2026-12-01'], null, $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, DB::table('invoices')->where('clinic_id', $this->clinicId)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'invoice.draft_created')->count());
    }

    public function test_le_corps_direct_est_valide_contre_son_contrat(): void
    {
        $absent = $this->draftBody([$this->line($this->item['exam'])]);
        unset($absent['expected_version']);
        $badLine = $this->line($this->item['exam']);
        $badLine['quantity'] = 1;

        foreach ([
            $absent,
            $this->draftBody([$this->line($this->item['exam'])], ['expected_version' => 2]),
            $this->draftBody([$badLine]),
            $this->draftBody([[...$this->line($this->item['exam']), 'unit_price_gross' => 15000.5]]),
            $this->draftBody([$this->line($this->item['exam'])], ['invoice_id' => 'pas-un-ulid']),
            $this->draftBody([$this->line($this->item['exam'])], ['total_gross' => 15000]),
        ] as $body) {
            $this->write('POST', '/api/v1/invoices', $body)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->assertSame(0, DB::table('invoices')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_l_identifiant_du_corps_doit_etre_celui_de_l_adresse(): void
    {
        [$id, , $version] = $this->draft();

        $this->write('PATCH', '/api/v1/invoices/'.$id, $this->updateBody(Ulid::generate(), $version, [$this->line($this->item['exam'])]), $this->cashier)
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['invoice_id']]]);
    }

    public function test_un_module_ferme_repond_404(): void
    {
        config(['eeo.enabled_modules' => ['stock']]);

        $this->createDraft(null, $this->manager)->assertNotFound();
        $this->assertSame(0, DB::table('invoices')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_un_poste_hors_ligne_est_refuse_tant_que_les_plages_ne_sont_pas_preparees(): void
    {
        $payload = $this->draftBody([$this->line($this->item['exam'])]);
        unset($payload['expected_version']);
        $context = CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC'));

        try {
            $this->app->make(CreateInvoiceDraftHandler::class)->handle(new Command('CreateInvoiceDraft', $payload, $payload['invoice_id']), $context, new TransactionLocks($this->clinicId), null);
            $this->fail('Une source autre que la route directe devait être refusée.');
        } catch (BusinessError $error) {
            $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
            $this->assertStringContainsString('hors-ligne', $error->getMessage());
        }
        $this->assertSame(0, DB::table('invoices')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_les_verrous_sont_pris_dans_l_ordre_du_contrat(): void
    {
        $visit = Ulid::generate();
        DB::table('visits')->insert([
            'id' => $visit, 'clinic_id' => $this->clinicId, 'patient_id' => $this->patientId, 'reason' => 'EXAM', 'occurred_at' => '2026-10-22 09:00:00.000000',
            'status' => 'ARRIVED', 'created_by' => $this->manager->id, 'version' => 1,
        ]);
        $payload = $this->draftBody([$this->line($this->item['exam'])], ['visit_id' => $visit]);
        unset($payload['expected_version']);
        $locks = new TransactionLocks($this->clinicId);
        $locks->clinic();

        $this->app->make(CreateInvoiceDraftHandler::class)->handle(
            new Command('CreateInvoiceDraft', $payload, $payload['invoice_id']),
            CommandContext::direct($this->cashier, 'corr-test-0001'),
            $locks,
            null,
        );

        $this->assertSame(
            ['10:'.$this->clinicId, '45:'.$this->patientId, '46:'.$visit, '60:'.$payload['invoice_id']],
            $locks->acquired(),
            'Cabinet, dossier patient (45), visite (46), facture (60).',
        );
    }

    /**
     * @return array<string, array{0: Checkpoint}>
     */
    public static function checkpoints(): array
    {
        $cases = [];
        foreach (Checkpoint::cases() as $checkpoint) {
            $cases[$checkpoint->value] = [$checkpoint];
        }

        return $cases;
    }

    #[DataProvider('checkpoints')]
    public function test_un_echec_a_n_importe_quel_point_annule_toute_la_creation(Checkpoint $failAt): void
    {
        $before = $this->billingEffects();
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->createDraft([$this->line($this->item['exam']), $this->line($this->item['lens'], '1', 2)]);
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (RuntimeException) {
            // Attendu : la transaction est annulée en entier.
        }

        $this->assertSame($before, $this->billingEffects());
        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->createDraft([$this->line($this->item['exam'])])->assertCreated();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function updateBody(string $invoiceId, int $version, array $lines): array
    {
        return ['expected_version' => $version, 'invoice_id' => $invoiceId, 'visit_id' => null, 'due_date' => null, 'lines' => $lines];
    }
}
