<?php

namespace Tests\Feature\Clinical;

use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Rubrique MEDICATIONS (C04, AT20) : médicaments en saisie libre contrôlée, dans l'ordre saisi. Aucune posologie,
 * fréquence ni durée n'est déduite ; prescrire ne crée aucune vente ni mouvement de stock. Données fictives.
 */
final class SaveMedicationsStepTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $consultationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [, $this->consultationId] = $this->startedExam();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array<string, mixed>
     */
    private function data(array $orders): array
    {
        return ['orders' => $orders];
    }

    public function test_les_medicaments_sont_enregistres_dans_l_ordre_saisi_et_relus_tels_quels(): void
    {
        $orders = [
            $this->order('Collyre fictif A', ['form' => 'DROPS', 'strength' => '0,5 %', 'dosage' => '1 goutte', 'frequency' => '3 fois par jour', 'duration_text' => 'Une semaine', 'duration_value' => 7, 'duration_unit' => 'DAY', 'instructions' => "À agiter avant emploi\nÉviter le port de lentilles"]),
            $this->order('Pommade fictive B', ['form' => 'OINTMENT']),
            $this->order('Complément fictif C', ['form' => 'OTHER', 'form_other' => 'Sachet']),
        ];

        $saved = $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data($orders))->assertOk();

        $this->assertMatchesContract($saved, 'schemas/resources/clinical.json#/$defs/ConsultationResponse');
        $this->assertSame($orders, $saved->json('data.steps.MEDICATIONS.orders'));
        $this->assertSame([1, 2, 3], DB::table('medication_orders')->where('consultation_id', $this->consultationId)->orderBy('position')->pluck('position')->map(static fn (mixed $p): int => (int) $p)->all());
        $this->assertSame(['Collyre fictif A', 'Pommade fictive B', 'Complément fictif C'], DB::table('medication_orders')->where('consultation_id', $this->consultationId)->orderBy('position')->pluck('name')->all());
    }

    public function test_aucune_posologie_frequence_ni_duree_n_est_deduite(): void
    {
        $saved = $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$this->order('Collyre fictif')]))->assertOk();

        $order = $saved->json('data.steps.MEDICATIONS.orders.0');
        foreach (['strength', 'dosage', 'frequency', 'duration_text', 'duration_value', 'duration_unit', 'instructions', 'catalog_item_id', 'form_other'] as $field) {
            $this->assertNull($order[$field], 'Le champ « '.$field.' » n\'est jamais complété par le système.');
        }
    }

    public function test_changer_l_ordre_est_un_changement_reel_et_ne_heurte_pas_l_unicite_de_la_position(): void
    {
        $a = $this->order('Collyre fictif A');
        $b = $this->order('Collyre fictif B');
        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$a, $b]))->assertOk();

        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$b, $a]))->assertOk()->assertJsonPath('data.version', 3);

        $this->assertSame([$b['id'], $a['id']], DB::table('medication_orders')->where('consultation_id', $this->consultationId)->orderBy('position')->pluck('id')->all());
    }

    public function test_un_enregistrement_identique_ne_change_pas_la_version_et_retirer_une_ligne_la_supprime(): void
    {
        $a = $this->order('Collyre fictif A');
        $b = $this->order('Collyre fictif B');
        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$a, $b]))->assertOk();

        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$a, $b]))->assertOk()->assertJsonPath('data.version', 2);

        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$b]))->assertOk();
        $this->assertSame([$b['id']], DB::table('medication_orders')->where('consultation_id', $this->consultationId)->pluck('id')->all());
        $this->assertSame(1, (int) DB::table('medication_orders')->where('id', $b['id'])->value('position'), 'La position est le rang dans la liste.');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidOrders(): array
    {
        return [
            'nom vide' => [['name' => ''], 'orders.0.name'],
            'nom blanc' => [['name' => '   '], 'orders.0.name'],
            'nom trop long' => [['name' => str_repeat('a', 181)], 'orders.0.name'],
            'forme inconnue' => [['form' => 'SPRAY'], 'orders.0.form'],
            'autre sans précision' => [['form' => 'OTHER'], 'orders.0.form_other'],
            'précision sans autre' => [['form' => 'DROPS', 'form_other' => 'Sachet'], 'orders.0.form_other'],
            'durée sans unité' => [['duration_value' => 7], 'orders.0.duration_unit'],
            'unité sans durée' => [['duration_unit' => 'DAY'], 'orders.0.duration_value'],
            'durée nulle' => [['duration_value' => 0, 'duration_unit' => 'DAY'], 'orders.0.duration_value'],
            'durée négative' => [['duration_value' => -3, 'duration_unit' => 'DAY'], 'orders.0.duration_value'],
            'durée décimale' => [['duration_value' => 1.5, 'duration_unit' => 'DAY'], 'orders.0.duration_value'],
            'unité inconnue' => [['duration_value' => 3, 'duration_unit' => 'YEAR'], 'orders.0.duration_unit'],
            'article inconnu' => [['catalog_item_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'], 'orders.0.catalog_item_id'],
            'fréquence trop longue' => [['frequency' => str_repeat('f', 181)], 'orders.0.frequency'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidOrders')]
    public function test_une_ligne_invalide_est_refusee_avant_toute_ecriture(array $overrides, string $fragment): void
    {
        $response = $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$this->order('Collyre fictif', $overrides)]));

        $this->assertFieldError($response, $fragment);
        $this->assertSame(0, DB::table('medication_orders')->count());
        $this->assertSame(1, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
    }

    public function test_au_plus_vingt_medicaments(): void
    {
        $orders = [];
        for ($i = 1; $i <= 21; $i++) {
            $orders[] = $this->order('Médicament fictif '.$i);
        }

        $this->assertFieldError($this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data($orders)), 'orders');
        $this->assertSame(0, DB::table('medication_orders')->count());
        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data(array_slice($orders, 0, 20)))->assertOk();
        $this->assertSame(20, DB::table('medication_orders')->count());
    }

    public function test_un_article_du_catalogue_du_cabinet_est_reference_sans_creer_de_vente_ni_de_stock(): void
    {
        $itemId = $this->seedCatalogItem($this->clinicId);
        $sales = [DB::table('invoices')->count(), DB::table('stock_movements')->count()];

        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$this->order('Collyre fictif', ['catalog_item_id' => $itemId])]))
            ->assertOk()
            ->assertJsonPath('data.steps.MEDICATIONS.orders.0.catalog_item_id', $itemId);

        $this->assertSame($sales, [DB::table('invoices')->count(), DB::table('stock_movements')->count()], 'Prescrire ne vend rien et ne décrémente aucun stock.');
    }

    public function test_un_article_d_un_autre_cabinet_est_refuse(): void
    {
        $otherClinic = $this->makeClinic();
        $foreignItem = $this->seedCatalogItem($otherClinic);

        $response = $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$this->order('Collyre fictif', ['catalog_item_id' => $foreignItem])]));

        $this->assertFieldError($response, 'orders.0.catalog_item_id');
    }

    public function test_un_identifiant_de_ligne_pris_ailleurs_est_refuse_par_champ(): void
    {
        $taken = $this->order('Collyre fictif');
        $patientId = $this->seedPatient($this->clinicId);
        $visitId = $this->seedVisit([], $this->clinicId, $patientId);
        $body = $this->startBody($visitId, ['patient_id' => $patientId]);
        $this->startConsultation($visitId, $body)->assertCreated();
        $this->saveCurrent((string) $body['consultation_id'], 'MEDICATIONS', $this->data([$taken]))->assertOk();

        $this->assertFieldError($this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$taken])), 'orders.0.id');
    }

    public function test_deux_lignes_ne_partagent_pas_un_meme_identifiant(): void
    {
        $order = $this->order('Collyre fictif');

        $this->assertFieldError($this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$order, $order])), 'orders.1.id');
    }

    public function test_l_audit_ne_contient_ni_nom_ni_posologie(): void
    {
        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$this->order('SENTINELLE-MEDICAMENT-HV8', ['dosage' => 'SENTINELLE-POSOLOGIE-HV8'])]))->assertOk();

        $dump = $this->sideEffectsDump();
        $this->assertStringNotContainsString('SENTINELLE-MEDICAMENT-HV8', $dump);
        $this->assertStringNotContainsString('SENTINELLE-POSOLOGIE-HV8', $dump);
    }

    public function test_l_accueil_et_la_caisse_ne_saisissent_pas_de_medicaments(): void
    {
        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$this->order('Collyre fictif')]), $this->reception)->assertStatus(403);
        $this->saveCurrent($this->consultationId, 'MEDICATIONS', $this->data([$this->order('Collyre fictif')]), $this->cashier)->assertStatus(403);
        $this->assertSame(0, DB::table('medication_orders')->count());
    }

    private function seedCatalogItem(string $clinicId): string
    {
        $id = Ulid::generate();
        DB::table('catalog_items')->insert([
            'id' => $id, 'clinic_id' => $clinicId, 'reference' => 'MED-'.substr($id, -8), 'category_code' => 'OTHER', 'name' => 'Article fictif',
            'brand' => null, 'model' => null, 'unit_code' => 'UNIT', 'quantity_integer_only' => 1, 'sale_price' => 10000, 'purchase_cost' => null,
            'tax_rule_id' => null, 'stock_mode' => 'NONE', 'requires_expiry' => 0, 'low_stock_threshold' => null, 'expiry_alert_days' => null,
            'active' => 1, 'public_visibility' => 0, 'version' => 1,
        ]);

        return $id;
    }
}
