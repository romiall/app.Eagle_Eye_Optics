<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Clinical\Rules\MedicationsStep;
use App\Domain\Clinical\Rules\OpticalPrescriptionRules;
use App\Domain\Shared\Ulid;
use Tests\TestCase;

/**
 * Règles pures de la prescription optique et des médicaments (C04) : brouillon cohérent (incomplet admis), exigences
 * de la validation par œil, aucune valeur déduite ; médicaments en saisie libre contrôlée, aucune posologie déduite.
 * Sans base : les codes du référentiel sont fournis par le test. Données fictives.
 */
final class PrescriptionRulesTest extends TestCase
{
    private const CODES = [
        'usage' => ['DISTANCE', 'NEAR', 'PERMANENT', 'OTHER'],
        'lens_type' => ['SINGLE_VISION', 'PROGRESSIVE', 'OTHER'],
        'treatment' => ['ANTI_REFLECTION', 'UV', 'OTHER'],
    ];

    private const NOTHING_STORED = ['usage_code' => null, 'lens_type_code' => null, 'treatments' => []];

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function eye(string $eye, array $values = []): array
    {
        return ['id' => Ulid::generate(), 'eye' => $eye, 'no_correction' => false, 'sphere' => null, 'cylinder' => null, 'axis' => null, 'addition' => null, 'fitting_height' => null, ...$values];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function content(array $overrides = []): array
    {
        return [
            'external_prescriber' => null, 'external_prescribed_on' => null, 'external_file_id' => null,
            'usage_code' => 'PERMANENT', 'usage_other' => null, 'lens_type_code' => 'SINGLE_VISION', 'lens_type_other' => null,
            'lens_index' => '1.60', 'lens_index_other' => null, 'pd_total' => '62', 'pd_od' => null, 'pd_og' => null,
            'pd_inconsistency_reason' => null, 'notes' => null,
            'eyes' => [$this->eye('OD', ['sphere' => '-1.25', 'cylinder' => '-0.50', 'axis' => 90]), $this->eye('OG', ['sphere' => '-1'])],
            'treatments' => [],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: FieldErrors}
     */
    private function normalize(array $data, string $origin = 'INTERNAL', array $stored = self::NOTHING_STORED): array
    {
        $errors = new FieldErrors;

        return [OpticalPrescriptionRules::normalize($data, $origin, self::CODES, $stored, '2026-10-22', $errors), $errors];
    }

    public function test_un_brouillon_coherent_est_normalise_sans_valeur_deduite(): void
    {
        [$state, $errors] = $this->normalize($this->content(['eyes' => [$this->eye('OG', ['sphere' => '+0,75']), $this->eye('OD', ['no_correction' => true])]]));

        $this->assertTrue($errors->isEmpty());
        $this->assertSame(['OD', 'OG'], array_column($state['eyes'], 'eye'));
        $this->assertTrue($state['eyes'][0]['no_correction']);
        $this->assertSame('0.75', $state['eyes'][1]['sphere']);
        $this->assertSame(['62.00', null, null], [$state['pd_total'], $state['pd_od'], $state['pd_og']], 'La DP monoculaire n\'est pas fabriquée à partir du total.');
        $this->assertSame('1.60', $state['lens_index']);
    }

    public function test_un_brouillon_incomplet_est_admis_mais_ne_se_valide_pas(): void
    {
        [$state, $errors] = $this->normalize($this->content(['usage_code' => null, 'lens_type_code' => null, 'lens_index' => null, 'pd_total' => null, 'eyes' => [$this->eye('OD'), $this->eye('OG')]]));
        $this->assertTrue($errors->isEmpty());

        $ready = new FieldErrors;
        OpticalPrescriptionRules::readyToValidate($state, $ready);
        $this->assertSame(['eyes.0.no_correction', 'eyes.1.no_correction'], array_keys($ready->all()));
    }

    public function test_la_validation_exige_un_axe_pour_un_cylindre_non_nul_seulement(): void
    {
        [$state] = $this->normalize($this->content(['eyes' => [$this->eye('OD', ['sphere' => '-1', 'cylinder' => '-0.75']), $this->eye('OG', ['cylinder' => '0'])]]));

        $ready = new FieldErrors;
        OpticalPrescriptionRules::readyToValidate($state, $ready);

        $this->assertSame(['eyes.0.axis'], array_keys($ready->all()), 'Un cylindre nul n\'exige pas d\'axe ; zéro est une valeur explicite.');
    }

    public function test_aucune_correction_avec_une_valeur_est_contradictoire(): void
    {
        [, $errors] = $this->normalize($this->content(['eyes' => [$this->eye('OD', ['no_correction' => true, 'addition' => '2']), $this->eye('OG', ['sphere' => '-1'])]]));

        $this->assertSame(['eyes.0.no_correction'], array_keys($errors->all()));
    }

    public function test_les_yeux_sont_exactement_od_et_og(): void
    {
        [, $one] = $this->normalize($this->content(['eyes' => [$this->eye('OD', ['sphere' => '-1'])]]));
        $this->assertSame(['eyes'], array_keys($one->all()));

        [$state, $twice] = $this->normalize($this->content(['eyes' => [$this->eye('OD', ['sphere' => '-1']), $this->eye('OD', ['sphere' => '-2'])]]));
        $this->assertSame(['eyes.1.eye'], array_keys($twice->all()));
        $this->assertSame([], $state['eyes']);
    }

    public function test_les_choix_codes_viennent_du_bon_referentiel_et_autre_exige_sa_precision(): void
    {
        [, $errors] = $this->normalize($this->content([
            'usage_code' => 'SINGLE_VISION',
            'lens_type_code' => 'OTHER',
            'lens_index' => '1.55',
            'treatments' => [['id' => Ulid::generate(), 'term_code' => 'PROGRESSIVE', 'other_text' => null]],
        ]));

        $this->assertSame(['usage_code', 'lens_type_other', 'lens_index', 'treatments.0.term_code'], array_keys($errors->all()));
    }

    public function test_un_code_desactive_reste_valable_s_il_est_deja_enregistre_sur_le_brouillon(): void
    {
        $treatment = ['id' => Ulid::generate(), 'term_code' => 'HMC', 'other_text' => null];

        [, $kept] = $this->normalize($this->content(['usage_code' => 'COMPUTER_WORK', 'treatments' => [$treatment]]), 'INTERNAL', ['usage_code' => 'COMPUTER_WORK', 'lens_type_code' => null, 'treatments' => [$treatment['id'] => 'HMC']]);
        $this->assertTrue($kept->isEmpty());

        [, $refused] = $this->normalize($this->content(['usage_code' => 'COMPUTER_WORK', 'treatments' => [$treatment]]));
        $this->assertSame(['usage_code', 'treatments.0.term_code'], array_keys($refused->all()));
    }

    public function test_la_dp_incoherente_exige_une_justification(): void
    {
        [, $errors] = $this->normalize($this->content(['pd_total' => '62', 'pd_od' => '31', 'pd_og' => '32']));
        $this->assertSame(['pd_inconsistency_reason'], array_keys($errors->all()));

        [, $justified] = $this->normalize($this->content(['pd_total' => '62', 'pd_od' => '31', 'pd_og' => '32', 'pd_inconsistency_reason' => 'Écart constaté (fictif)']));
        $this->assertTrue($justified->isEmpty());
    }

    public function test_l_ordonnance_externe_exige_prescripteur_et_date_passee_et_l_interne_n_en_porte_pas(): void
    {
        [, $missing] = $this->normalize($this->content(), 'EXTERNAL');
        $this->assertSame(['external_prescriber', 'external_prescribed_on'], array_keys($missing->all()));

        [, $future] = $this->normalize($this->content(['external_prescriber' => 'Dr Fictif', 'external_prescribed_on' => '2026-10-23']), 'EXTERNAL');
        $this->assertSame(['external_prescribed_on'], array_keys($future->all()));

        [$state, $ok] = $this->normalize($this->content(['external_prescriber' => ' Dr Fictif ', 'external_prescribed_on' => '2026-10-22']), 'EXTERNAL');
        $this->assertTrue($ok->isEmpty());
        $this->assertSame(['Dr Fictif', '2026-10-22'], [$state['external_prescriber'], $state['external_prescribed_on']]);

        [, $internal] = $this->normalize($this->content(['external_prescriber' => 'Dr Fictif', 'external_prescribed_on' => '2026-10-01']));
        $this->assertSame(['external_prescriber', 'external_prescribed_on'], array_keys($internal->all()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(string $name, array $overrides = []): array
    {
        return [
            'id' => Ulid::generate(), 'catalog_item_id' => null, 'name' => $name, 'form' => 'DROPS', 'form_other' => null, 'strength' => null,
            'dosage' => null, 'frequency' => null, 'duration_text' => null, 'duration_value' => null, 'duration_unit' => null, 'instructions' => null,
            ...$overrides,
        ];
    }

    public function test_les_medicaments_gardent_leur_ordre_et_leur_saisie_sans_rien_deduire(): void
    {
        $errors = new FieldErrors;

        $state = MedicationsStep::normalize(['orders' => [
            $this->order(' Collyre B ', ['frequency' => ' 2 fois par jour ']),
            $this->order('Pommade A', ['form' => 'OINTMENT', 'duration_value' => 5, 'duration_unit' => 'DAY']),
        ]], [], $errors);

        $this->assertTrue($errors->isEmpty());
        $this->assertSame(['Collyre B', 'Pommade A'], array_column($state['orders'], 'name'));
        $this->assertSame('2 fois par jour', $state['orders'][0]['frequency']);
        $this->assertSame([null, null], [$state['orders'][0]['duration_value'], $state['orders'][0]['dosage']], 'Rien n\'est complété par le système.');
        $this->assertSame([5, 'DAY'], [$state['orders'][1]['duration_value'], $state['orders'][1]['duration_unit']]);
    }

    public function test_les_medicaments_refusent_chaque_defaut_a_sa_ligne(): void
    {
        $errors = new FieldErrors;
        $catalog = Ulid::generate();
        $dup = Ulid::generate();

        $state = MedicationsStep::normalize(['orders' => [
            $this->order('Valide', ['catalog_item_id' => $catalog]),
            $this->order('   '),
            $this->order('Autre forme', ['form' => 'OTHER']),
            $this->order('Précision en trop', ['form_other' => 'Sachet']),
            $this->order('Durée sans unité', ['duration_value' => 3]),
            $this->order('Unité sans durée', ['duration_unit' => 'WEEK']),
            $this->order('Article inconnu', ['catalog_item_id' => Ulid::generate()]),
            $this->order('Forme inconnue', ['form' => 'SPRAY']),
            ['id' => $dup] + $this->order('Doublon A'),
            ['id' => $dup] + $this->order('Doublon B'),
        ]], [$catalog => true], $errors);

        $this->assertSame([
            'step.data.orders.1.name', 'step.data.orders.2.form_other', 'step.data.orders.3.form_other', 'step.data.orders.4.duration_unit',
            'step.data.orders.5.duration_value', 'step.data.orders.6.catalog_item_id', 'step.data.orders.7.form', 'step.data.orders.9.id',
        ], array_keys($errors->all()));
        $this->assertSame(['Valide', 'Doublon A'], array_column($state['orders'], 'name'));
    }

    public function test_au_plus_vingt_medicaments(): void
    {
        $errors = new FieldErrors;
        $orders = [];
        for ($i = 0; $i < 21; $i++) {
            $orders[] = $this->order('Médicament '.$i);
        }

        MedicationsStep::normalize(['orders' => $orders], [], $errors);

        $this->assertSame(['step.data.orders'], array_keys($errors->all()));
    }
}
