<?php

namespace Tests\Unit\Rules;

use App\Domain\Shared\RulesVersion;
use App\Domain\Shared\RuleViolation;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Rules\RuleVectorOperations;

/**
 * Vecteurs communs des règles pures (F08, IT03) : chaque cas de `contracts/vectors/` est rejoué par les règles PHP ;
 * `tests/frontend/domain/rule-vectors.test.ts` rejoue les mêmes fichiers côté TypeScript. Résultat exact attendu,
 * ou refus attendu avec le même code d'API, le même champ, la même règle et les mêmes détails.
 */
final class RuleVectorsTest extends TestCase
{
    private const BASE_URI = 'https://eeo.invalid/contracts/';

    private static function root(): string
    {
        return dirname(__DIR__, 3).'/contracts/';
    }

    /**
     * @return list<string>
     */
    private static function files(): array
    {
        $files = glob(self::root().'vectors/*.json');
        if ($files === false) {
            return [];
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $file): array
    {
        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return $document;
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::files() as $file) {
            /** @var list<array<string, mixed>> $documentCases */
            $documentCases = self::read($file)['cases'] ?? [];
            foreach ($documentCases as $case) {
                $cases[(string) $case['id']] = [$case];
            }
        }

        return $cases;
    }

    public function test_chaque_fichier_respecte_le_format_et_la_version_des_regles(): void
    {
        $files = self::files();
        $this->assertGreaterThanOrEqual(9, count($files), 'Fichiers de vecteurs attendus dans contracts/vectors/');

        $validator = new Validator;
        $validator->setMaxErrors(5);
        $validator->resolver()?->registerPrefix(self::BASE_URI, self::root());
        /** @var array{rules_versions: list<string>} $compatibility */
        $compatibility = json_decode((string) file_get_contents(self::root().'compatibility.json'), true, 512, JSON_THROW_ON_ERROR);

        $ids = [];
        foreach ($files as $file) {
            $name = basename($file);
            $result = $validator->validate(json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR), self::BASE_URI.'schemas/rule-vectors.json');
            $error = $result->error();
            $this->assertTrue($result->isValid(), $name.' : '.json_encode($error === null ? [] : (new ErrorFormatter)->format($error), JSON_UNESCAPED_UNICODE));

            $document = self::read($file);
            $this->assertSame(RulesVersion::CURRENT, $document['rules_version'], $name);
            /** @var list<array{id: string}> $documentCases */
            $documentCases = $document['cases'];
            foreach ($documentCases as $case) {
                $this->assertArrayNotHasKey($case['id'], $ids, "Identifiant en double : {$case['id']}");
                $ids[$case['id']] = true;
            }
        }
        $this->assertContains(RulesVersion::CURRENT, $compatibility['rules_versions'], 'Version de règles déclarée dans compatibility.json');
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('cases')]
    public function test_le_vecteur_donne_le_meme_resultat_en_php(array $case): void
    {
        $id = (string) $case['id'];
        /** @var array<string, mixed> $input */
        $input = $case['input'];

        try {
            $result = RuleVectorOperations::run((string) $case['operation'], $input);
        } catch (RuleViolation $violation) {
            $this->assertArrayHasKey('error', $case, "{$id} : refus inattendu {$violation->errorCode} {$violation->field} {$violation->rule} ({$violation->getMessage()})");
            /** @var array{code: string, field: string, rule: string, details?: array<string, mixed>} $expected */
            $expected = $case['error'];
            $this->assertSame(
                [$expected['code'], $expected['field'], $expected['rule']],
                [$violation->errorCode, $violation->field, $violation->rule],
                "{$id} : refus différent",
            );
            if (array_key_exists('details', $expected)) {
                $this->assertSame(self::canonical($expected['details']), self::canonical($violation->details), "{$id} : détails du refus");
            }

            return;
        }

        $this->assertArrayNotHasKey('error', $case, "{$id} : refus attendu, résultat obtenu ".json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame(self::canonical($case['expected']), self::canonical($result), "{$id} : résultat différent");
    }

    /**
     * Ordre des clés d'objet sans importance, ordre des listes significatif.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
