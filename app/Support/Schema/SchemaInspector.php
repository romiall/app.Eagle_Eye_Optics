<?php

namespace App\Support\Schema;

use Illuminate\Database\Connection;

/**
 * Photographie normalisée des métadonnées MySQL d'une base, pour comparer une base migrée et une base
 * importée depuis le SQL livré (F04).
 *
 * Seules les propriétés indépendantes de l'installation sont comparées : nom de base, DEFINER, dates de
 * création, compteurs AUTO_INCREMENT et statistiques sont exclus. Les attributs de session enregistrés
 * avec les triggers et vues (sql_mode, jeux de caractères) sont relevés à part : ils dépendent du client
 * d'import (fichier complet ou scripts séparés) et ne modifient pas le corps des gardes.
 */
final class SchemaInspector
{
    /** Tables techniques du framework admises à côté des 117 tables métier (contrat 23). */
    public const FRAMEWORK_TABLES = [
        'migrations',
        'fw_sessions',
        'fw_cache',
        'fw_cache_locks',
        'fw_jobs',
        'fw_job_batches',
        'fw_failed_jobs',
        'fw_password_reset_tokens',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly BaselineStatements $baseline,
    ) {}

    public static function for(Connection $connection): self
    {
        return new self($connection, BaselineStatements::load());
    }

    public function schemaName(): string
    {
        return (string) $this->connection->selectOne('SELECT DATABASE() AS s')->s;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $schema = $this->schemaName();
        $business = $this->baseline->businessTables();
        $allTables = $this->baseTables();

        $tables = [];
        foreach ($allTables as $name => $table) {
            if (in_array($name, $business, true)) {
                $tables[$name] = $table + ['columns' => [], 'indexes' => [], 'checks' => [], 'foreign_keys' => []];
            }
        }
        ksort($tables);

        $this->addColumns($tables);
        $this->addIndexes($tables);
        $this->addChecks($tables);
        $this->addForeignKeys($tables);

        $views = $this->views($schema);
        $triggers = $this->triggers();

        $foreignKeys = 0;
        $checks = 0;
        foreach ($tables as $table) {
            $foreignKeys += count($table['foreign_keys']);
            $checks += count($table['checks']);
        }

        return [
            'counts' => [
                'business_tables' => count($tables),
                'foreign_keys' => $foreignKeys,
                'checks' => $checks,
                'views' => count($views['definitions']),
                'triggers' => count($triggers['definitions']),
            ],
            'missing_business_tables' => array_values(array_diff($business, array_keys($tables))),
            'other_tables' => array_values(array_diff(array_keys($allTables), $business)),
            'tables' => $tables,
            'views' => $views['definitions'],
            'triggers' => $triggers['definitions'],
            'reference_data' => $this->referenceData($tables),
            'session_attributes' => [
                'views' => $views['session'],
                'triggers' => $triggers['session'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function baseTables(): array
    {
        $rows = $this->connection->select(
            "SELECT table_name AS name, engine AS engine, table_collation AS collation, table_comment AS comment
               FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
              ORDER BY table_name",
        );

        $tables = [];
        foreach ($rows as $row) {
            $tables[(string) $row->name] = [
                'engine' => $row->engine,
                'collation' => $row->collation,
                'comment' => $row->comment,
            ];
        }

        return $tables;
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables
     */
    private function addColumns(array &$tables): void
    {
        $rows = $this->connection->select(
            'SELECT table_name AS tbl, column_name AS name, ordinal_position AS position, column_default AS default_value,
                    is_nullable AS nullable, column_type AS type, character_set_name AS charset,
                    collation_name AS collation, extra AS extra, generation_expression AS generation, column_comment AS comment,
                    srs_id AS srs_id
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
              ORDER BY table_name, ordinal_position',
        );

        foreach ($rows as $row) {
            $table = (string) $row->tbl;
            if (! isset($tables[$table])) {
                continue;
            }
            $tables[$table]['columns'][(string) $row->name] = [
                'position' => (int) $row->position,
                'type' => $row->type,
                'nullable' => $row->nullable,
                'default' => $row->default_value,
                'extra' => $row->extra,
                'generation' => $row->generation,
                'charset' => $row->charset,
                'collation' => $row->collation,
                'comment' => $row->comment,
                'srs_id' => $row->srs_id,
            ];
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables
     */
    private function addIndexes(array &$tables): void
    {
        $rows = $this->connection->select(
            'SELECT table_name AS tbl, index_name AS name, non_unique AS non_unique, seq_in_index AS seq, column_name AS col,
                    collation AS direction, sub_part AS sub_part, index_type AS type, is_visible AS visible, expression AS expression,
                    index_comment AS comment
               FROM information_schema.statistics
              WHERE table_schema = DATABASE()
              ORDER BY table_name, index_name, seq_in_index',
        );

        foreach ($rows as $row) {
            $table = (string) $row->tbl;
            if (! isset($tables[$table])) {
                continue;
            }
            $name = (string) $row->name;
            $tables[$table]['indexes'][$name] ??= [
                'unique' => (int) $row->non_unique === 0,
                'type' => $row->type,
                'visible' => $row->visible,
                'comment' => $row->comment,
                'columns' => [],
            ];
            $tables[$table]['indexes'][$name]['columns'][] = [
                'column' => $row->col,
                'expression' => $row->expression,
                'direction' => $row->direction,
                'sub_part' => $row->sub_part === null ? null : (int) $row->sub_part,
            ];
        }

        foreach ($tables as &$table) {
            ksort($table['indexes']);
        }
        unset($table);
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables
     */
    private function addChecks(array &$tables): void
    {
        $rows = $this->connection->select(
            "SELECT tc.table_name AS tbl, tc.constraint_name AS name, tc.enforced AS enforced, cc.check_clause AS clause
               FROM information_schema.table_constraints tc
               JOIN information_schema.check_constraints cc
                 ON cc.constraint_schema = tc.constraint_schema AND cc.constraint_name = tc.constraint_name
              WHERE tc.constraint_schema = DATABASE() AND tc.constraint_type = 'CHECK'
              ORDER BY tc.table_name, tc.constraint_name",
        );

        foreach ($rows as $row) {
            $table = (string) $row->tbl;
            if (isset($tables[$table])) {
                $tables[$table]['checks'][(string) $row->name] = ['clause' => $row->clause, 'enforced' => $row->enforced];
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $tables
     */
    private function addForeignKeys(array &$tables): void
    {
        $rules = [];
        foreach ($this->connection->select(
            'SELECT table_name AS tbl, constraint_name AS name, referenced_table_name AS target, update_rule AS update_rule, delete_rule AS delete_rule, match_option AS match_option
               FROM information_schema.referential_constraints
              WHERE constraint_schema = DATABASE()',
        ) as $row) {
            $rules[$row->tbl."\0".$row->name] = $row;
        }

        $rows = $this->connection->select(
            'SELECT table_name AS tbl, constraint_name AS name, column_name AS col, ordinal_position AS position,
                    referenced_table_name AS target, referenced_column_name AS target_col
               FROM information_schema.key_column_usage
              WHERE constraint_schema = DATABASE() AND referenced_table_name IS NOT NULL
              ORDER BY table_name, constraint_name, ordinal_position',
        );

        foreach ($rows as $row) {
            $table = (string) $row->tbl;
            if (! isset($tables[$table])) {
                continue;
            }
            $name = (string) $row->name;
            $rule = $rules[$table."\0".$name] ?? null;
            $tables[$table]['foreign_keys'][$name] ??= [
                'target' => $row->target,
                'columns' => [],
                'target_columns' => [],
                'update_rule' => $rule?->update_rule,
                'delete_rule' => $rule?->delete_rule,
                'match_option' => $rule?->match_option,
            ];
            $tables[$table]['foreign_keys'][$name]['columns'][] = $row->col;
            $tables[$table]['foreign_keys'][$name]['target_columns'][] = $row->target_col;
        }

        foreach ($tables as &$table) {
            ksort($table['foreign_keys']);
        }
        unset($table);
    }

    /**
     * @return array{definitions: array<string, array<string, mixed>>, session: array<string, array<string, mixed>>}
     */
    private function views(string $schema): array
    {
        $rows = $this->connection->select(
            'SELECT table_name AS name, view_definition AS definition, check_option AS check_option, is_updatable AS is_updatable, security_type AS security_type,
                    character_set_client AS charset, collation_connection AS collation
               FROM information_schema.views
              WHERE table_schema = DATABASE()
              ORDER BY table_name',
        );

        $definitions = [];
        $session = [];
        foreach ($rows as $row) {
            $definitions[(string) $row->name] = [
                // Le nom de la base apparaît qualifié dans la définition stockée : on le retire.
                'definition' => str_replace('`'.$schema.'`.', '', (string) $row->definition),
                'check_option' => $row->check_option,
                'is_updatable' => $row->is_updatable,
                'security_type' => $row->security_type,
            ];
            $session[(string) $row->name] = ['charset' => $row->charset, 'collation' => $row->collation];
        }

        return ['definitions' => $definitions, 'session' => $session];
    }

    /**
     * @return array{definitions: array<string, array<string, mixed>>, session: array<string, array<string, mixed>>}
     */
    private function triggers(): array
    {
        $rows = $this->connection->select(
            'SELECT trigger_name AS name, event_manipulation AS event, event_object_table AS tbl, action_timing AS timing,
                    action_orientation AS orientation, action_statement AS statement, sql_mode AS sql_mode,
                    character_set_client AS charset, collation_connection AS collation, database_collation AS database_collation
               FROM information_schema.triggers
              WHERE trigger_schema = DATABASE()
              ORDER BY trigger_name',
        );

        $definitions = [];
        $session = [];
        foreach ($rows as $row) {
            $definitions[(string) $row->name] = [
                'table' => $row->tbl,
                'event' => $row->event,
                'timing' => $row->timing,
                'orientation' => $row->orientation,
                'statement' => $row->statement,
            ];
            $session[(string) $row->name] = [
                'sql_mode' => $row->sql_mode,
                'charset' => $row->charset,
                'collation' => $row->collation,
                'database_collation' => $row->database_collation,
            ];
        }

        return ['definitions' => $definitions, 'session' => $session];
    }

    /**
     * Contenu exact des référentiels seedés : nombre de lignes et empreinte des lignes ordonnées par clé.
     *
     * @param  array<string, array<string, mixed>>  $tables
     * @return array<string, array{rows: int, sha256: string}>
     */
    private function referenceData(array $tables): array
    {
        $result = [];
        foreach (BaselineStatements::REFERENCE_TABLES as $table) {
            if (! isset($tables[$table])) {
                continue;
            }
            $columns = array_keys($tables[$table]['columns']);
            $primary = array_map(
                static fn (array $column): string => (string) $column['column'],
                $tables[$table]['indexes']['PRIMARY']['columns'] ?? [],
            );
            $quote = static fn (int|string $column): string => '`'.$column.'`';
            $order = implode(', ', array_map($quote, $primary ?: $columns));
            $select = implode(', ', array_map($quote, $columns));

            $lines = [];
            foreach ($this->connection->select('SELECT '.$select.' FROM `'.$table.'` ORDER BY '.$order) as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    $values[] = $value === null ? null : (string) $value;
                }
                $lines[] = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            $result[$table] = ['rows' => count($lines), 'sha256' => hash('sha256', implode("\n", $lines))];
        }

        return $result;
    }
}
