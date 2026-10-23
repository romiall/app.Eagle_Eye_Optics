<?php

namespace Tests\Unit\Scheduling;

use App\Domain\Scheduling\FollowUpCalculator;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\CivilDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calcul du contrôle recommandé (S02, AT22) : départ choisi + délai au calendrier civil, rappel avant la date ; aucune
 * durée supposée, aucune date calculée sans délai, date manuelle sans calcul.
 */
final class FollowUpCalculatorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public static function intervals(): array
    {
        return [
            '10 jours' => ['2026-10-22', 10, 'DAY', '2026-11-01'],
            '14 jours en fin d’année' => ['2026-12-25', 14, 'DAY', '2027-01-08'],
            '3 mois' => ['2026-10-22', 3, 'MONTH', '2027-01-22'],
            '31 janvier + 1 mois' => ['2027-01-31', 1, 'MONTH', '2027-02-28'],
            '31 janvier + 1 mois, année bissextile' => ['2028-01-31', 1, 'MONTH', '2028-02-29'],
            '29 février + 1 an' => ['2028-02-29', 1, 'YEAR', '2029-02-28'],
        ];
    }

    #[DataProvider('intervals')]
    public function test_la_date_calculee_suit_le_calendrier_civil(string $anchor, int $value, string $unit, string $expected): void
    {
        $this->assertSame($expected, FollowUpCalculator::expected('CALCULATED', CivilDate::fromString($anchor), $value, $unit)?->toString());
    }

    public function test_une_date_manuelle_n_est_pas_calculee_et_un_calcul_exige_son_delai(): void
    {
        $this->assertNull(FollowUpCalculator::expected('MANUAL', CivilDate::fromString('2026-10-22'), null, null));
        $this->expectException(RuleViolation::class);
        FollowUpCalculator::expected('CALCULATED', CivilDate::fromString('2026-10-22'), null, null);
    }

    public function test_le_rappel_precede_la_date_ou_tombe_le_jour_meme(): void
    {
        $date = CivilDate::fromString('2027-01-22');
        $this->assertSame('2027-01-15', FollowUpCalculator::reminderDate($date, 7, 'DAY')->toString());
        $this->assertSame('2026-12-22', FollowUpCalculator::reminderDate($date, 1, 'MONTH')->toString());
        $this->assertSame('2027-01-22', FollowUpCalculator::reminderDate($date, null, null)->toString());
    }
}
