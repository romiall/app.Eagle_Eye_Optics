<?php

namespace Tests\Unit\Billing;

use App\Domain\Billing\Catalog\Rules\CatalogItemInput;
use App\Domain\Billing\Catalog\Rules\TaxRuleInput;
use App\Domain\Billing\Rules\FieldErrors;
use PHPUnit\Framework\TestCase;

/**
 * Règles d'un article du catalogue et d'une règle fiscale (B01, EE020, EE027) : fonctions pures, sans base. Aucun prix
 * ni taux réel : valeurs fictives. Les règles croisées reprennent les CHECK du schéma pour répondre 422 plutôt qu'une
 * erreur SQL.
 */
final class CatalogRulesTest extends TestCase
{
    private const UNITS = ['UNIT' => true, 'PAIR' => true, 'SERVICE' => false, 'OTHER' => false];

    private const CATEGORIES = ['SERVICE', 'FRAME', 'LENS', 'CONTACT_LENS', 'COLLYRE', 'DERMOCOSMETIC', 'OTHER'];

    private const RULE = '01J9ZXK4M2Q8R7S6T5V4W30009';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'FR-001', 'category_code' => 'FRAME', 'name' => 'Monture fictive', 'brand' => null, 'model' => null,
            'unit_code' => 'UNIT', 'quantity_integer_only' => true, 'sale_price' => 40000, 'purchase_cost' => null, 'tax_rule_id' => null,
            'stock_mode' => 'STOCKED', 'requires_expiry' => false, 'low_stock_threshold' => null, 'expiry_alert_days' => null,
            'active' => true, 'public_visibility' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?array<string, mixed>, 1: FieldErrors}
     */
    private function columns(array $payload): array
    {
        $errors = new FieldErrors;

        return [CatalogItemInput::columns($payload, self::UNITS, self::CATEGORIES, [self::RULE], $errors), $errors];
    }

    public function test_un_article_valide_donne_ses_colonnes_normalisees(): void
    {
        [$columns, $errors] = $this->columns($this->item(['reference' => '  FR-001 ', 'name' => ' Monture fictive ', 'low_stock_threshold' => '3', 'purchase_cost' => 18000]));

        $this->assertTrue($errors->isEmpty());
        $this->assertSame('FR-001', $columns['reference']);
        $this->assertSame('Monture fictive', $columns['name']);
        $this->assertSame('3.000', $columns['low_stock_threshold']);
        $this->assertSame(18000, $columns['purchase_cost']);
        $this->assertSame(40000, $columns['sale_price']);
    }

    public function test_les_trois_modes_de_stock_sont_distincts_et_le_prix_zero_est_une_valeur(): void
    {
        foreach (['NONE', 'STOCKED', 'MADE_TO_ORDER'] as $mode) {
            [$columns, $errors] = $this->columns($this->item(['stock_mode' => $mode, 'sale_price' => 0]));

            $this->assertTrue($errors->isEmpty(), $mode);
            $this->assertSame($mode, $columns['stock_mode']);
            $this->assertSame(0, $columns['sale_price']);
        }
    }

    public function test_un_collyre_exige_une_date_d_expiration_et_un_article_stocke(): void
    {
        [, $withoutExpiry] = $this->columns($this->item(['category_code' => 'COLLYRE', 'unit_code' => 'UNIT', 'requires_expiry' => false]));
        [, $notStocked] = $this->columns($this->item(['category_code' => 'COLLYRE', 'requires_expiry' => true, 'stock_mode' => 'MADE_TO_ORDER']));
        [$valid, $validErrors] = $this->columns($this->item(['category_code' => 'COLLYRE', 'requires_expiry' => true, 'stock_mode' => 'STOCKED', 'expiry_alert_days' => 90]));

        $this->assertArrayHasKey('requires_expiry', $withoutExpiry->all());
        $this->assertArrayHasKey('requires_expiry', $notStocked->all());
        $this->assertTrue($validErrors->isEmpty());
        $this->assertSame(90, $valid['expiry_alert_days']);
    }

    public function test_une_prestation_n_a_aucun_mouvement_de_stock(): void
    {
        [, $errors] = $this->columns($this->item(['category_code' => 'SERVICE', 'unit_code' => 'SERVICE', 'quantity_integer_only' => false, 'stock_mode' => 'STOCKED']));

        $this->assertArrayHasKey('stock_mode', $errors->all());
    }

    public function test_une_unite_indivisible_impose_une_quantite_entiere(): void
    {
        [, $pair] = $this->columns($this->item(['unit_code' => 'PAIR', 'quantity_integer_only' => false]));
        [$service, $serviceErrors] = $this->columns($this->item(['category_code' => 'SERVICE', 'unit_code' => 'SERVICE', 'quantity_integer_only' => false, 'stock_mode' => 'NONE']));

        $this->assertArrayHasKey('quantity_integer_only', $pair->all());
        $this->assertTrue($serviceErrors->isEmpty());
        $this->assertFalse($service['quantity_integer_only']);
    }

    public function test_les_references_inconnues_et_les_champs_vides_sont_signales_ensemble(): void
    {
        [$columns, $errors] = $this->columns($this->item([
            'reference' => '   ', 'name' => null, 'category_code' => 'INCONNUE', 'unit_code' => 'KILO', 'stock_mode' => 'AUTRE', 'tax_rule_id' => '01J9ZXK4M2Q8R7S6T5V4W3FFFF',
        ]));

        $this->assertNull($columns);
        $this->assertSame(['reference', 'name', 'category_code', 'unit_code', 'stock_mode', 'tax_rule_id'], array_keys($errors->all()));
    }

    public function test_seuil_de_stock_et_delai_de_peremption_n_ont_de_sens_que_pour_les_articles_concernes(): void
    {
        [, $threshold] = $this->columns($this->item(['stock_mode' => 'NONE', 'low_stock_threshold' => '3']));
        [, $alert] = $this->columns($this->item(['requires_expiry' => false, 'expiry_alert_days' => 30]));

        $this->assertArrayHasKey('low_stock_threshold', $threshold->all());
        $this->assertArrayHasKey('expiry_alert_days', $alert->all());
    }

    public function test_un_prix_non_entier_ou_hors_bornes_est_refuse(): void
    {
        [, $float] = $this->columns($this->item(['sale_price' => 100.5]));
        [, $tooBig] = $this->columns($this->item(['sale_price' => 1_000_000_000_001]));
        [, $negativeCost] = $this->columns($this->item(['purchase_cost' => -1]));

        $this->assertArrayHasKey('sale_price', $float->all());
        $this->assertArrayHasKey('sale_price', $tooBig->all());
        $this->assertArrayHasKey('purchase_cost', $negativeCost->all());
    }

    public function test_une_reference_avec_un_retour_a_la_ligne_est_refusee(): void
    {
        [, $errors] = $this->columns($this->item(['reference' => "FR\n001"]));

        $this->assertArrayHasKey('reference', $errors->all());
    }

    // ------------------------------------------------------------------------------------------------------------
    // Règle fiscale
    // ------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rule(array $overrides = []): array
    {
        return array_merge(['code' => 'TEST_TAXE', 'label' => 'Taxe fictive', 'rate_percent' => '10', 'active' => false, 'validate' => false, 'legal_note' => null], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?array<string, mixed>, 1: FieldErrors}
     */
    private function ruleColumns(array $payload, ?object $current = null): array
    {
        $errors = new FieldErrors;

        return [TaxRuleInput::columns($payload, $current, $errors), $errors];
    }

    public function test_aucune_taxe_n_est_active_sans_validation_de_la_gerante(): void
    {
        [, $errors] = $this->ruleColumns($this->rule(['active' => true]));
        [$columns, $valid] = $this->ruleColumns($this->rule(['active' => true, 'validate' => true]));

        $this->assertArrayHasKey('active', $errors->all());
        $this->assertTrue($valid->isEmpty());
        $this->assertTrue($columns['validated']);
        $this->assertSame('10.0000', $columns['rate_percent']);
    }

    public function test_une_regle_sans_taux_ne_se_valide_ni_ne_s_active(): void
    {
        [, $validate] = $this->ruleColumns($this->rule(['rate_percent' => null, 'validate' => true]));
        [, $active] = $this->ruleColumns($this->rule(['rate_percent' => null, 'active' => true, 'validate' => true]));

        $this->assertArrayHasKey('validate', $validate->all());
        $this->assertArrayHasKey('active', $active->all());
    }

    public function test_changer_le_taux_d_une_regle_validee_retire_sa_validation(): void
    {
        $current = (object) ['rate_percent' => '10.0000', 'validated_at' => '2026-10-20 08:00:00.000000'];

        [$kept, $keptErrors] = $this->ruleColumns($this->rule(['rate_percent' => '10.0000', 'label' => 'Nouveau libellé']), $current);
        [, $activeAfterChange] = $this->ruleColumns($this->rule(['rate_percent' => '12.5', 'active' => true]), $current);
        [$deactivated, $deactivatedErrors] = $this->ruleColumns($this->rule(['rate_percent' => '12.5', 'active' => false]), $current);
        [$revalidated, $revalidatedErrors] = $this->ruleColumns($this->rule(['rate_percent' => '12.5', 'active' => true, 'validate' => true]), $current);

        $this->assertTrue($keptErrors->isEmpty());
        $this->assertTrue($kept['keep_validation']);
        $this->assertArrayHasKey('active', $activeAfterChange->all());
        $this->assertTrue($deactivatedErrors->isEmpty());
        $this->assertFalse($deactivated['keep_validation']);
        $this->assertFalse($deactivated['validated']);
        $this->assertTrue($revalidatedErrors->isEmpty());
        $this->assertTrue($revalidated['validated']);
        $this->assertSame('12.5000', $revalidated['rate_percent']);
    }

    public function test_un_taux_hors_de_zero_a_cent_est_refuse(): void
    {
        [, $errors] = $this->ruleColumns($this->rule(['rate_percent' => '100.5']));

        $this->assertArrayHasKey('rate_percent', $errors->all());
    }
}
