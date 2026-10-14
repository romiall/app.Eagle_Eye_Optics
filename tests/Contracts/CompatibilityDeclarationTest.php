<?php

namespace Tests\Contracts;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * contracts/compatibility.json (contrat 23) : valide selon son schéma et cohérent avec les contrats publiés
 * — chaque commande et chaque événement courant y figure avec sa version, la release est celle de
 * l'OpenAPI et le catalogue celui du backlog.
 */
final class CompatibilityDeclarationTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2).'/';
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $path): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(self::root().$path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_la_declaration_est_valide_selon_son_schema(): void
    {
        $validator = new Validator;
        $validator->resolver()?->registerPrefix('https://eeo.invalid/contracts/', self::root().'contracts/');
        $data = json_decode((string) file_get_contents(self::root().'contracts/compatibility.json'), false, 512, JSON_THROW_ON_ERROR);

        $result = $validator->validate($data, 'https://eeo.invalid/contracts/schemas/compatibility.json');

        $this->assertTrue($result->isValid(), json_encode($result->error() === null ? [] : (new ErrorFormatter)->format($result->error()), JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_commandes_evenements_release_et_catalogue_sont_coherents(): void
    {
        $compatibility = self::json('contracts/compatibility.json');
        /** @var array<string, array{schema_version: string}> $commands */
        $commands = self::json('contracts/commands/index.json')['const'];
        /** @var list<string> $events */
        $events = self::json('contracts/events/envelope.json')['$defs']['EventType']['enum'];
        /** @var array{info: array{version: string}} $openapi */
        $openapi = Yaml::parseFile(self::root().'contracts/openapi.yaml');

        $this->assertSame(array_keys($commands), array_keys((array) $compatibility['commands']));
        foreach ($commands as $name => $entry) {
            $this->assertContains($entry['schema_version'], $compatibility['commands'][$name], $name);
        }
        $this->assertSame($events, array_keys((array) $compatibility['events']));
        $this->assertSame($openapi['info']['version'], $compatibility['release']);
        $this->assertSame(self::json('specifications/developpement/backlog.json')['baseline_catalog_sha256'], $compatibility['catalog_sha256']);

        // Les fixtures du protocole n'emploient que des versions de règles déclarées.
        foreach (glob(self::root().'contracts/fixtures/sync/push-request*.json') ?: [] as $file) {
            preg_match_all('/"rules_version":\s*"([^"]+)"/', (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $version) {
                $this->assertContains($version, $compatibility['rules_versions'], basename($file));
            }
        }
    }
}
