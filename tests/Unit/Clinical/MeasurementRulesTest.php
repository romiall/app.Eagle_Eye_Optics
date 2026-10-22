<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\Rules\CenteringStep;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Clinical\Rules\Measures;
use App\Domain\Clinical\Rules\RefractionStep;
use App\Domain\Clinical\Rules\VisualAcuityStep;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\Diopter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Règles pures des rubriques mesurées (C02) : axe, forme canonique des mesures, tolérance de DP (vecteurs communs
 * `contracts/vectors/clinical/measures.json`, rejoués par tests/frontend/domain/clinical-measures.test.ts), acuités,
 * réfraction et centrage. Sans base. Données fictives.
 *
 * Le poste convertit la virgule décimale en point et retire les blancs de bord AVANT l'envoi (le contrat n'admet que
 * le point) : côté serveur, les vecteurs de saisie sont rejoués après cette même conversion.
 */
final class MeasurementRulesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/contracts/vectors/clinical/measures.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function asSent(?string $text): ?string
    {
        return $text === null ? null : str_replace(',', '.', trim($text));
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function axisVectors(): array
    {
        $cases = [];
        foreach (self::vectors()['axis'] as $case) {
            $cases[(string) $case['id']] = [(int) $case['value'], (bool) $case['valid']];
        }

        return $cases;
    }

    #[DataProvider('axisVectors')]
    public function test_l_axe_est_un_entier_de_0_a_180(int $value, bool $valid): void
    {
        $errors = new FieldErrors;

        $axis = Measures::axis($value, 'axis', $errors);

        $this->assertSame($valid, $errors->isEmpty());
        $this->assertSame($valid ? $value : null, $axis);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canonicalVectors(): array
    {
        $cases = [];
        foreach (self::vectors()['canonical'] as $case) {
            $cases[(string) $case['id']] = [(string) $case['text'], (string) $case['canonical']];
        }

        return $cases;
    }

    #[DataProvider('canonicalVectors')]
    public function test_une_mesure_est_rendue_en_forme_canonique_sans_arrondi(string $text, string $canonical): void
    {
        $errors = new FieldErrors;

        $this->assertSame($canonical, Measures::canonical(Measures::diopter(self::asSent($text), 'sphere', $errors)));
        $this->assertTrue($errors->isEmpty());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedVectors(): array
    {
        $cases = [];
        foreach (self::vectors()['refused'] as $case) {
            $cases[(string) $case['id']] = [(string) $case['text']];
        }

        return $cases;
    }

    #[DataProvider('refusedVectors')]
    public function test_une_mesure_hors_format_est_refusee_jamais_arrondie(string $text): void
    {
        $errors = new FieldErrors;

        $this->assertNull(Measures::diopter(self::asSent($text), 'sphere', $errors));
        $this->assertSame(['sphere'], array_keys($errors->all()));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string|null, 3: bool}>
     */
    public static function pdVectors(): array
    {
        $cases = [];
        foreach (self::vectors()['pd_mismatch'] as $case) {
            $cases[(string) $case['id']] = [$case['total'], $case['od'], $case['og'], (bool) $case['mismatch']];
        }

        return $cases;
    }

    #[DataProvider('pdVectors')]
    public function test_l_ecart_de_dp_n_est_juge_que_si_les_trois_valeurs_sont_saisies(?string $total, ?string $od, ?string $og, bool $mismatch): void
    {
        $parse = static fn (?string $text) => $text === null ? null : Diopter::parse(self::asSent($text), 'pd');

        $this->assertSame($mismatch, CenteringStep::mismatch($parse($total), $parse($od), $parse($og)));
    }

    public function test_une_mesure_null_est_non_mesuree_et_un_nombre_flottant_est_refuse(): void
    {
        $errors = new FieldErrors;

        $this->assertNull(Measures::diopter(null, 'a', $errors));
        $this->assertTrue($errors->isEmpty(), 'Non mesuré n\'est pas une erreur.');
        $this->assertNull(Measures::diopter(-1.25, 'b', $errors));
        $this->assertNull(Measures::diopter('', 'c', $errors));
        $this->assertNull(Measures::axis(90.0, 'd', $errors));
        $this->assertNull(Measures::axis('90', 'e', $errors));
        $this->assertSame(['b', 'c', 'd', 'e'], array_keys($errors->all()));
    }

    public function test_les_acuites_conservent_la_valeur_lue_et_refusent_les_combinaisons_incoherentes(): void
    {
        $scale = Ulid::generate();
        $row = static fn (array $overrides = []): array => [
            'id' => Ulid::generate(), 'context' => 'PRELIMINARY', 'distance' => 'FAR', 'correction' => 'WITHOUT', 'eye' => 'OD',
            'scale_id' => $scale, 'raw_value' => ' 10/10 ', 'normalized_value' => null, ...$overrides,
        ];
        $errors = new FieldErrors;

        $rows = VisualAcuityStep::normalize(['acuities' => [
            $row(),
            $row(['eye' => 'OG', 'raw_value' => '0']),
            $row(['context' => 'POST_REFRACTION', 'distance' => 'FAR', 'correction' => 'WITH']),
            $row(['eye' => 'OD']),
            $row(['eye' => 'OU', 'raw_value' => '   ']),
            $row(['eye' => 'OU', 'distance' => 'NEAR', 'normalized_value' => '1.0']),
        ]], [$scale], [$scale], [], $errors);

        $this->assertSame(['10/10', '0'], array_column($rows, 'raw_value'), 'La valeur lue est conservée (zéro compris), blancs de bord retirés.');
        $this->assertSame(['step.data.acuities.2.distance', 'step.data.acuities.3.eye', 'step.data.acuities.4.raw_value', 'step.data.acuities.5.normalized_value'], array_keys($errors->all()));
    }

    public function test_une_echelle_inactive_n_est_admise_que_pour_la_ligne_qui_l_utilise_deja(): void
    {
        $inactive = Ulid::generate();
        $kept = Ulid::generate();
        $make = static fn (string $id): array => ['id' => $id, 'context' => 'PRELIMINARY', 'distance' => 'NEAR', 'correction' => 'WITH', 'eye' => 'OD', 'scale_id' => $inactive, 'raw_value' => 'P2', 'normalized_value' => null];

        $ok = new FieldErrors;
        VisualAcuityStep::normalize(['acuities' => [$make($kept)]], [], [$inactive], [$kept => ['scale_id' => $inactive, 'normalized_value' => null]], $ok);
        $this->assertTrue($ok->isEmpty());

        $refused = new FieldErrors;
        VisualAcuityStep::normalize(['acuities' => [$make(Ulid::generate())]], [], [$inactive], [$kept => ['scale_id' => $inactive, 'normalized_value' => null]], $refused);
        $this->assertSame(['step.data.acuities.0.scale_id'], array_keys($refused->all()));
    }

    public function test_la_refraction_separe_les_stades_et_n_infere_rien(): void
    {
        $errors = new FieldErrors;
        $measure = static fn (string $stage, string $eye, array $values = []): array => ['id' => Ulid::generate(), 'stage' => $stage, 'eye' => $eye, 'sphere' => null, 'cylinder' => null, 'axis' => null, 'addition' => null, ...$values];

        $state = RefractionStep::normalize([
            'measurements' => [
                $measure('AUTOREFRACTOR', 'OD', ['sphere' => '-1.25', 'cylinder' => '+0.50']),
                $measure('SUBJECTIVE', 'OD', ['sphere' => '-1', 'addition' => '2']),
                $measure('OBJECTIVE', 'OG', ['addition' => '1.00']),
                $measure('OBJECTIVE', 'OD'),
                $measure('AUTOREFRACTOR', 'OD', ['sphere' => '0']),
            ],
            'refraction_observations' => ' Observation ',
            'ametropias' => [],
        ], ['MYOPIA', 'OTHER'], [], $errors);

        $this->assertSame([['AUTOREFRACTOR', '-1.25', '0.50', null], ['SUBJECTIVE', '-1.00', null, '2.00']], array_map(static fn (array $m): array => [$m['stage'], $m['sphere'], $m['cylinder'], $m['addition']], $state['measurements']));
        $this->assertSame('Observation', $state['refraction_observations']);
        $this->assertSame(['step.data.measurements.2.addition', 'step.data.measurements.3', 'step.data.measurements.4.eye'], array_keys($errors->all()));
    }

    public function test_le_centrage_ne_fabrique_rien_et_exige_une_justification_ou_un_identifiant(): void
    {
        $id = Ulid::generate();

        $alone = new FieldErrors;
        $this->assertSame(['centering_id' => $id, 'pd_total' => '62.00', 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null], CenteringStep::normalize(['centering_id' => $id, 'pd_total' => '62', 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null], null, $alone));
        $this->assertTrue($alone->isEmpty());

        $mismatch = new FieldErrors;
        CenteringStep::normalize(['centering_id' => $id, 'pd_total' => '62', 'pd_od' => '31', 'pd_og' => '30', 'inconsistency_reason' => null], null, $mismatch);
        $this->assertSame(['step.data.inconsistency_reason'], array_keys($mismatch->all()));

        $justified = new FieldErrors;
        CenteringStep::normalize(['centering_id' => $id, 'pd_total' => '62', 'pd_od' => '31', 'pd_og' => '30', 'inconsistency_reason' => 'Mesures reprises (fictif)'], null, $justified);
        $this->assertTrue($justified->isEmpty());

        $empty = new FieldErrors;
        $this->assertSame(['centering_id' => null, 'pd_total' => null, 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null], CenteringStep::normalize(['centering_id' => $id, 'pd_total' => null, 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null], null, $empty), 'Sans valeur, pas de ligne : l\'identifiant fourni n\'est pas retenu.');
        $this->assertTrue($empty->isEmpty());

        $noId = new FieldErrors;
        CenteringStep::normalize(['centering_id' => null, 'pd_total' => '62', 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null], null, $noId);
        $this->assertSame(['step.data.centering_id'], array_keys($noId->all()));

        $otherId = new FieldErrors;
        CenteringStep::normalize(['centering_id' => Ulid::generate(), 'pd_total' => '62', 'pd_od' => null, 'pd_og' => null, 'inconsistency_reason' => null], $id, $otherId);
        $this->assertSame(['step.data.centering_id'], array_keys($otherId->all()));

        $negative = new FieldErrors;
        CenteringStep::normalize(['centering_id' => $id, 'pd_total' => '0', 'pd_od' => '-31', 'pd_og' => null, 'inconsistency_reason' => null], null, $negative);
        $this->assertSame(['step.data.pd_total', 'step.data.pd_od'], array_keys($negative->all()));
    }
}
