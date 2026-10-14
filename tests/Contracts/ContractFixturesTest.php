<?php

namespace Tests\Contracts;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contrats exécutables (F06) : chaque fixture de contracts/fixtures déclare son schéma et si elle doit
 * être acceptée ou refusée ; PHP (Opis, JSON Schema 2020-12) et TypeScript (Ajv 2020,
 * tests/frontend/contracts.test.ts) doivent rendre le même verdict sur les mêmes fichiers.
 */
final class ContractFixturesTest extends TestCase
{
    private const BASE_URI = 'https://eeo.invalid/contracts/';

    public const SCHEMA_DIRECTORIES = ['schemas', 'sync', 'commands', 'events', 'snapshots'];

    private static function root(): string
    {
        return dirname(__DIR__, 2).'/contracts/';
    }

    private static function validator(): Validator
    {
        $validator = new Validator;
        $validator->setMaxErrors(5);
        $validator->resolver()?->registerPrefix(self::BASE_URI, self::root());

        return $validator;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtures(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'fixtures', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'json') {
                $relative = substr($file->getPathname(), strlen(self::root().'fixtures/'));
                $files[$relative] = [$file->getPathname()];
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function schemaFiles(): array
    {
        $files = [];
        foreach (self::SCHEMA_DIRECTORIES as $directory) {
            foreach (glob(self::root().$directory.'/*.json') ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    public function test_chaque_schema_declare_son_identifiant_et_le_dialecte_2020_12(): void
    {
        $files = self::schemaFiles();
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $schema = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $relative = substr($file, strlen(self::root()));
            $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null, $relative);
            $this->assertSame(self::BASE_URI.$relative, $schema['$id'] ?? null, $relative.' : $id doit refléter son chemin');
        }
    }

    #[DataProvider('fixtures')]
    public function test_la_fixture_recoit_le_verdict_attendu(string $file): void
    {
        $fixture = self::readFixture($file);
        $this->assertIsString($fixture->schema ?? null, 'Champ « schema » requis');
        $this->assertIsBool($fixture->valid ?? null, 'Champ « valid » requis');
        $this->assertIsString($fixture->description ?? null, 'Champ « description » requis');

        if (property_exists($fixture, 'base')) {
            // Variante d'une fixture acceptée : un seul défaut, décrit par un patch JSON (RFC 6902 :
            // add, remove, replace), pour qu'un refus ne puisse pas venir d'une autre erreur.
            $this->assertFalse(property_exists($fixture, 'data'), 'Une variante porte « base » et « patch », pas « data »');
            $this->assertIsString($fixture->base);
            $this->assertIsArray($fixture->patch ?? null, 'Champ « patch » requis avec « base »');
            $this->assertNotEmpty($fixture->patch);
            $base = self::readFixture(self::root().'fixtures/'.$fixture->base);
            $this->assertTrue($base->valid ?? null, 'La base d\'une variante est une fixture acceptée');
            $this->assertFalse(property_exists($base, 'base'), 'Pas de variante de variante');
            $this->assertTrue(self::validator()->validate($base->data, self::BASE_URI.$base->schema)->isValid(), 'Base acceptée');
            $data = $base->data;
            foreach ($fixture->patch as $operation) {
                $this->assertIsObject($operation);
                $data = self::applyOperation($data, $operation);
            }
        } else {
            $this->assertTrue(property_exists($fixture, 'data'), 'Champ « data » requis');
            $data = $fixture->data;
        }

        $result = self::validator()->validate($data, self::BASE_URI.$fixture->schema);
        $errors = $result->error() === null ? [] : (new ErrorFormatter)->format($result->error(), false);

        $this->assertSame(
            $fixture->valid,
            $result->isValid(),
            $fixture->description.($errors === [] ? '' : ' — '.json_encode($errors, JSON_UNESCAPED_UNICODE)),
        );
    }

    private static function readFixture(string $file): \stdClass
    {
        $fixture = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $fixture, $file);

        return $fixture;
    }

    private static function applyOperation(mixed $document, object $operation): mixed
    {
        self::assertContains($operation->op ?? null, ['add', 'remove', 'replace'], 'Opération de patch admise');
        self::assertIsString($operation->path ?? null);
        self::assertStringStartsWith('/', $operation->path);
        $segments = array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            array_slice(explode('/', $operation->path), 1),
        );

        return self::applyAt($document, $segments, $operation);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function applyAt(mixed $node, array $segments, object $operation): mixed
    {
        $key = array_shift($segments);
        self::assertNotNull($key);

        if ($segments !== []) {
            if (is_array($node)) {
                self::assertArrayHasKey((int) $key, $node, 'Chemin de patch existant');
                $node[(int) $key] = self::applyAt($node[(int) $key], $segments, $operation);
            } else {
                self::assertInstanceOf(\stdClass::class, $node);
                self::assertTrue(property_exists($node, $key), 'Chemin de patch existant : '.$key);
                $node->{$key} = self::applyAt($node->{$key}, $segments, $operation);
            }

            return $node;
        }

        if (is_array($node)) {
            if ($operation->op === 'add' && $key === '-') {
                $node[] = $operation->value;
            } elseif ($operation->op === 'add') {
                array_splice($node, (int) $key, 0, [$operation->value]);
            } elseif ($operation->op === 'remove') {
                self::assertArrayHasKey((int) $key, $node, 'Élément de patch existant');
                array_splice($node, (int) $key, 1);
            } else {
                self::assertArrayHasKey((int) $key, $node, 'Élément de patch existant');
                $node[(int) $key] = $operation->value;
            }

            return $node;
        }

        self::assertInstanceOf(\stdClass::class, $node);
        if ($operation->op !== 'add') {
            self::assertTrue(property_exists($node, $key), 'Propriété de patch existante : '.$key);
        }
        if ($operation->op === 'remove') {
            unset($node->{$key});
        } else {
            $node->{$key} = $operation->value;
        }

        return $node;
    }
}
