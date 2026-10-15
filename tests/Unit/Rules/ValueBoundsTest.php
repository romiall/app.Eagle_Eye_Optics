<?php

namespace Tests\Unit\Rules;

use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Bornes et formes strictes des valeurs que les vecteurs communs n'atteignent pas (constats de revue F08) : plafond
 * des quantités calculées, précision des valeurs de confiance, saut de ligne final, instants avant 1970.
 */
final class ValueBoundsTest extends TestCase
{
    public function test_une_quantite_calculee_ne_depasse_jamais_la_colonne(): void
    {
        $this->assertSame('999999999.999', Quantity::bounded(Quantity::MAX_UNITS, 'consumed_quantity')->canonical());
        $this->assertRule('decimal.out_of_range', static fn () => Quantity::bounded(Quantity::MAX_UNITS + 1, 'consumed_quantity'));
        $this->assertRule('quantity.negative_result', static fn () => Quantity::bounded(-1, 'consumed_quantity'));
    }

    public function test_une_quantite_de_confiance_reste_dans_decimal_12_3(): void
    {
        $this->assertSame('-999999999.999', Quantity::of('-999999999.999')->canonical());
        $this->assertRule('decimal.out_of_range', static fn () => Quantity::of('1000000000.000'));
    }

    public function test_une_valeur_de_confiance_trop_precise_est_refusee_au_lieu_de_deborder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FixedDecimal::fromString('99999999999999999', 3);
    }

    public function test_un_saut_de_ligne_final_n_est_jamais_accepte(): void
    {
        $this->assertRule('date.format', static fn () => CivilDate::fromString("2026-10-15\n"));
        $this->expectException(InvalidArgumentException::class);
        Quantity::of("1.500\n");
    }

    public function test_un_instant_ne_precede_pas_1970(): void
    {
        $this->assertSame('1970-01-01T00:00:00.000Z', Instant::fromEpochMilliseconds(0)->toString());
        $this->expectException(InvalidArgumentException::class);
        Instant::fromEpochMilliseconds(-1);
    }

    private function assertRule(string $rule, callable $action): void
    {
        try {
            $action();
            $this->fail('Refus attendu : '.$rule);
        } catch (RuleViolation $violation) {
            $this->assertSame($rule, $violation->rule);
        }
    }
}
