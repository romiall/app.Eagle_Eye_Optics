<?php

namespace Tests\Unit\Rules;

use App\Domain\Shared\ValueObjects\Diopter;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Quantity;
use App\Domain\Shared\ValueObjects\Rate;
use PHPUnit\Framework\TestCase;

/**
 * Bornes des règles pures = bornes du schéma livré (F08, « fixer bornes SQL ») : une colonne élargie ou une
 * contrainte modifiée dans `database/` fait échouer ce test au lieu de laisser les deux moteurs diverger du SQL.
 */
final class SqlBoundsTest extends TestCase
{
    private static function schemaSql(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/database/01_schema.sql');
    }

    /**
     * @return array<string, string> table.colonne => type SQL
     */
    private static function columnTypes(): array
    {
        /** @var array{tables: list<array{name: string, columns: list<array{name: string, type: string}>}>} $catalog */
        $catalog = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/database/schema_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        $types = [];
        foreach ($catalog['tables'] as $table) {
            foreach ($table['columns'] as $column) {
                $types[$table['name'].'.'.$column['name']] = $column['type'];
            }
        }

        return $types;
    }

    public function test_les_montants_bornes_du_schema_valent_la_borne_des_regles(): void
    {
        preg_match_all('/CHECK \(`(\w+)` BETWEEN (-?\d+) AND (\d+)\)/', self::schemaSql(), $matches, PREG_SET_ORDER);
        $moneyBounds = array_filter($matches, static fn (array $match): bool => $match[3] !== '100' && (int) $match[3] > 9999);

        $this->assertCount(28, $moneyBounds, 'Colonnes monétaires bornées attendues dans 01_schema.sql');
        foreach ($moneyBounds as $match) {
            $this->assertSame(['0', (string) Money::MAX], [$match[2], $match[3]], "Borne de {$match[1]}");
        }
    }

    public function test_les_taux_du_schema_sont_ceux_des_regles(): void
    {
        $types = self::columnTypes();
        foreach (['tax_rules.rate_percent', 'invoice_lines.tax_rate_snapshot'] as $column) {
            $this->assertSame('DECIMAL(7,4)', $types[$column], $column);
        }
        // DECIMAL(7,4) : 3 chiffres entiers, 4 décimales ; CHECK 0..100 sur les deux colonnes.
        $this->assertSame(4, Rate::SCALE);
        $this->assertSame(2, preg_match_all('/BETWEEN 0 AND 100\)/', self::schemaSql()));
        $this->assertSame('100.0000', Rate::of('100')->canonical());
    }

    public function test_les_quantites_et_mesures_du_schema_sont_celles_des_regles(): void
    {
        $byType = [];
        foreach (self::columnTypes() as $column => $type) {
            if (str_starts_with($type, 'DECIMAL(')) {
                $byType[$type][] = $column;
            }
        }
        ksort($byType);

        // Chaque échelle décimale du schéma a sa règle ; aucune autre n'existe.
        $this->assertSame(['DECIMAL(12,3)', 'DECIMAL(4,2)', 'DECIMAL(6,2)', 'DECIMAL(7,4)', 'DECIMAL(8,4)'], array_keys($byType));
        $this->assertSame([Quantity::SCALE, Quantity::INTEGER_DIGITS], [3, 12 - 3]);
        $this->assertSame([Diopter::SCALE, Diopter::INTEGER_DIGITS], [2, 6 - 2]);
        $this->assertContains('invoice_lines.quantity', $byType['DECIMAL(12,3)']);
        $this->assertContains('device_stock_allocations.allocated_quantity', $byType['DECIMAL(12,3)']);
        $this->assertContains('optical_prescription_eyes.sphere', $byType['DECIMAL(6,2)']);
        $this->assertSame(Quantity::MAX_UNITS, 10 ** 12 - 1, 'Plus grande quantité DECIMAL(12,3), en millièmes');

        // Indice de verre (4,2) et acuité normalisée (8,4) : règles de mesure de C02, mêmes FixedDecimal.
        $this->assertSame(['optical_prescriptions.lens_index', 'workshop_order_eyes.lens_index'], $byType['DECIMAL(4,2)']);
        $this->assertSame(['visual_acuities.normalized_value'], $byType['DECIMAL(8,4)']);
    }
}
