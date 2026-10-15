<?php

namespace Tests\Feature\Rules;

use Illuminate\Support\Facades\DB;
use Tests\Support\RequiresIsolatedMysql;
use Tests\TestCase;

/**
 * Troisième moteur des vecteurs F08 : MySQL 8.4 réel. Les arrondis de ligne doivent être ceux de la contrainte
 * ck_invoice_lines_04 (`ROUND(quantity * unit_price_gross, 0)`), les dates au mois et à l'année ceux de DATE_ADD,
 * les décimaux canoniques ceux que MySQL rend pour la colonne. Sans cela, une ligne calculée juste en PHP et en
 * TypeScript pourrait être refusée par le CHECK au moment du commit.
 */
final class MysqlRuleParityTest extends TestCase
{
    use RequiresIsolatedMysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireIsolatedMysql();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function cases(string $file, string $operation): array
    {
        /** @var array{cases: list<array<string, mixed>>} $document */
        $document = json_decode((string) file_get_contents(base_path('contracts/vectors/'.$file)), true, 512, JSON_THROW_ON_ERROR);

        return array_values(array_filter(
            $document['cases'],
            static fn (array $case): bool => $case['operation'] === $operation && array_key_exists('expected', $case),
        ));
    }

    private static function scalar(string $sql, mixed ...$bindings): string
    {
        $row = DB::selectOne($sql, $bindings);

        return (string) array_values((array) $row)[0];
    }

    public function test_l_arrondi_de_ligne_est_celui_du_check_mysql(): void
    {
        $cases = self::cases('invoice.json', 'invoice.line');
        $this->assertGreaterThan(10, count($cases));

        foreach ($cases as $case) {
            /** @var array{quantity: string, unit_price_gross: int, discount_gross: int} $input */
            $input = $case['input'];
            /** @var array{gross: int, net: int} $expected */
            $expected = $case['expected'];
            $gross = self::scalar('SELECT ROUND(CAST(? AS DECIMAL(12,3)) * CAST(? AS SIGNED), 0)', $input['quantity'], $input['unit_price_gross']);
            $this->assertSame((string) $expected['gross'], $gross, (string) $case['id']);
            // ck_invoice_lines_03 et _04, évaluées par MySQL sur les valeurs calculées par les règles.
            $checks = self::scalar(
                'SELECT CAST(? AS SIGNED) <= ROUND(CAST(? AS DECIMAL(12,3)) * CAST(? AS SIGNED), 0) AND CAST(? AS SIGNED) = ROUND(CAST(? AS DECIMAL(12,3)) * CAST(? AS SIGNED), 0) - CAST(? AS SIGNED)',
                $input['discount_gross'], $input['quantity'], $input['unit_price_gross'],
                $expected['net'], $input['quantity'], $input['unit_price_gross'], $input['discount_gross'],
            );
            $this->assertSame('1', $checks, (string) $case['id'].' : CHECK de invoice_lines');
        }
    }

    public function test_les_dates_au_calendrier_civil_sont_celles_de_date_add(): void
    {
        $count = 0;
        foreach (self::cases('dates.json', 'date.add') as $case) {
            /** @var array{date: string, amount: int, unit: string} $input */
            $input = $case['input'];
            $unit = ['DAY' => 'DAY', 'MONTH' => 'MONTH', 'YEAR' => 'YEAR'][$input['unit']];
            $this->assertSame($case['expected'], self::scalar("SELECT DATE_ADD(CAST(? AS DATE), INTERVAL ? {$unit})", $input['date'], $input['amount']), (string) $case['id']);
            $count++;
        }
        foreach (self::cases('follow-up.json', 'followUp.recommended') as $case) {
            /** @var array{anchor: string, value: int, unit: string} $input */
            $input = $case['input'];
            $unit = ['DAY' => 'DAY', 'MONTH' => 'MONTH', 'YEAR' => 'YEAR'][$input['unit']];
            $this->assertSame($case['expected'], self::scalar("SELECT DATE_ADD(CAST(? AS DATE), INTERVAL ? {$unit})", $input['anchor'], $input['value']), (string) $case['id']);
            $count++;
        }
        $this->assertGreaterThan(15, $count);
    }

    public function test_les_decimaux_canoniques_sont_ceux_que_mysql_rend(): void
    {
        $types = ['quantity' => 'DECIMAL(12,3)', 'rate' => 'DECIMAL(7,4)', 'diopter' => 'DECIMAL(6,2)'];
        $count = 0;
        foreach (self::cases('decimal.json', 'decimal.parse') as $case) {
            if ($case['expected'] === null) {
                continue;
            }
            /** @var array{kind: string} $input */
            $input = $case['input'];
            $this->assertSame($case['expected'], self::scalar('SELECT CAST(? AS '.$types[$input['kind']].')', $case['expected']), (string) $case['id']);
            $count++;
        }
        $this->assertGreaterThan(15, $count);
    }
}
