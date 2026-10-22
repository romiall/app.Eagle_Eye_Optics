<?php

namespace Tests\Unit\Billing;

use App\Domain\Billing\Rules\DiscountPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plafond de remise de ligne selon le droit de l'auteur (fichier 09) : entiers exacts, arrondi à l'inférieur, échec
 * fermé sur une configuration illisible. Données fictives.
 */
final class DiscountPolicyTest extends TestCase
{
    public function test_l_autorite_de_prix_remise_jusqu_au_brut_de_la_ligne(): void
    {
        $cap = DiscountPolicy::capFor(true, '0');

        $this->assertSame('100.0000', $cap->canonical());
        $this->assertSame(80000, DiscountPolicy::maxDiscount(80000, $cap));
        $this->assertTrue(DiscountPolicy::allows(80000, 80000, $cap));
        $this->assertFalse(DiscountPolicy::allows(80000, 80001, $cap));
    }

    public function test_sans_autorite_de_prix_le_plafond_par_defaut_est_zero(): void
    {
        $cap = DiscountPolicy::capFor(false, '0');

        $this->assertSame(0, DiscountPolicy::maxDiscount(80000, $cap));
        $this->assertTrue(DiscountPolicy::allows(80000, 0, $cap));
        $this->assertFalse(DiscountPolicy::allows(80000, 1, $cap));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function caps(): array
    {
        return [
            '5 % de 60 000' => ['5', 60000, 3000],
            '12,5 % de 10 000' => ['12.5', 10000, 1250],
            'arrondi à l\'inférieur' => ['10', 1001, 100],
            '0,0001 % de 1 000 000 000 000' => ['0.0001', 1_000_000_000_000, 1_000_000],
            '100 %' => ['100', 123456, 123456],
        ];
    }

    #[DataProvider('caps')]
    public function test_la_remise_maximale_est_le_brut_fois_le_plafond_arrondi_a_l_inferieur(string $percent, int $gross, int $expected): void
    {
        $this->assertSame($expected, DiscountPolicy::maxDiscount($gross, DiscountPolicy::capFor(false, $percent)));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function unreadable(): array
    {
        return [
            'texte' => ['beaucoup'],
            'virgule' => ['5%'],
            'négatif' => ['-5'],
            'au-delà de 100' => ['150'],
            'trop de décimales' => ['5.12345'],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_une_configuration_illisible_vaut_zero_echec_ferme(?string $configured): void
    {
        $this->assertSame(0, DiscountPolicy::maxDiscount(80000, DiscountPolicy::capFor(false, $configured)));
    }

    public function test_une_configuration_absente_vaut_zero(): void
    {
        $this->assertSame(0, DiscountPolicy::maxDiscount(80000, DiscountPolicy::capFor(false, null)));
    }
}
