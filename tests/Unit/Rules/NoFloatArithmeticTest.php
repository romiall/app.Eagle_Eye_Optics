<?php

namespace Tests\Unit\Rules;

use PHPUnit\Framework\TestCase;

/**
 * « Aucun flottant monétaire » (F08) : les règles pures et leurs valeurs ne contiennent ni conversion en flottant,
 * ni littéral décimal, ni division `/` (qui rend un flottant en PHP), ni fonction d'arrondi flottant. Les calculs
 * passent par des entiers, `intdiv`, `FixedDecimal` et `Brick\Math\BigInteger`.
 */
final class NoFloatArithmeticTest extends TestCase
{
    private const DIRECTORIES = [
        'app/Domain/Shared/ValueObjects',
        'app/Domain/Billing/Rules',
        'app/Domain/Inventory/Rules',
        'app/Domain/Scheduling/Rules',
    ];

    private const FORBIDDEN_FUNCTIONS = ['floatval', 'doubleval', 'round', 'floor', 'ceil', 'fdiv', 'number_format', 'fmod', 'sprintf'];

    public function test_les_regles_pures_n_emploient_aucune_arithmetique_flottante(): void
    {
        $files = [];
        foreach (self::DIRECTORIES as $directory) {
            $files = array_merge($files, glob(dirname(__DIR__, 3).'/'.$directory.'/*.php') ?: []);
        }
        $this->assertGreaterThanOrEqual(12, count($files));

        foreach ($files as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $index => $token) {
                $where = basename($file).' : ';
                if (is_array($token)) {
                    $this->assertNotSame(T_DOUBLE_CAST, $token[0], $where.'conversion en flottant ligne '.$token[2]);
                    $this->assertNotSame(T_DNUMBER, $token[0], $where.'littéral décimal ligne '.$token[2]);
                    $this->assertNotSame(T_DIV_EQUAL, $token[0], $where.'division flottante « /= » ligne '.$token[2]);
                    if ($token[0] === T_STRING && in_array(strtolower($token[1]), self::FORBIDDEN_FUNCTIONS, true) && self::isCall($tokens, $index)) {
                        // sprintf n'est admis que pour formater des entiers (%d) : CivilDate et Instant.
                        $allowed = strtolower($token[1]) === 'sprintf' && in_array(basename($file), ['CivilDate.php', 'Instant.php'], true);
                        $this->assertTrue($allowed, $where.'fonction '.$token[1].' ligne '.$token[2]);
                    }
                } else {
                    $this->assertNotSame('/', $token, $where.'division flottante « / »');
                }
            }
        }
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function isCall(array $tokens, int $index): bool
    {
        for ($next = $index + 1; $next < count($tokens); $next++) {
            $token = $tokens[$next];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return $token === '(';
        }

        return false;
    }
}
