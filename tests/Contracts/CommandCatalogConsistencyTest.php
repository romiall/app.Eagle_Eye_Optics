<?php

namespace Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Les commandes du journal local (contracts/commands/index.json) s'appuient sur le catalogue canonique
 * du schéma : entité = table existante, version attendue = colonne `version` réelle (aucune colonne
 * inventée, contrat 23), permission = l'un des 49 codes seedés.
 */
final class CommandCatalogConsistencyTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function json(string $relative): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.$relative), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_entites_versions_et_permissions_des_commandes_existent(): void
    {
        /** @var array<string, array{entity_type: string, expected_version: string, permission: string, entity_id_field: string}> $commands */
        $commands = self::json('contracts/commands/index.json')['const'];
        /** @var list<array{name: string, columns: list<array{name: string}>}> $tables */
        $tables = self::json('database/schema_catalog.json')['tables'];
        $columns = [];
        foreach ($tables as $table) {
            $columns[$table['name']] = array_column($table['columns'], 'name');
        }
        /** @var array{steps: array{'03_reference_data': list<array{table: string, sql: string}>}} $statements */
        $statements = self::json('database/baseline/statements.json');
        $sql = '';
        foreach ($statements['steps']['03_reference_data'] as $step) {
            if ($step['table'] === 'permissions') {
                $sql = $step['sql'];
            }
        }
        preg_match_all("/\\('([a-z_.]+)', '/", $sql, $matches);
        $permissions = $matches[1];
        $this->assertCount(49, $permissions);

        $this->assertNotEmpty($commands);
        foreach ($commands as $name => $command) {
            $this->assertArrayHasKey($command['entity_type'], $columns, $name.' : table inconnue du catalogue');
            $this->assertContains($command['permission'], $permissions, $name.' : permission non seedée');
            $this->assertContains($command['expected_version'], ['none', 'state', 'entity', 'draft', 'ledger'], $name);
            if (in_array($command['expected_version'], ['entity', 'draft', 'ledger'], true)) {
                $this->assertContains('version', $columns[$command['entity_type']], $name.' : version attendue sans colonne version');
            }
            if (in_array($command['expected_version'], ['draft', 'ledger'], true)) {
                $this->assertSame('invoices', $command['entity_type'], $name.' : version brouillon/ledger réservée aux factures');
            }
        }
    }
}
