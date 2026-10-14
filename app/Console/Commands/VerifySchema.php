<?php

namespace App\Console\Commands;

use App\Support\Schema\ExpectedSchema;
use App\Support\Schema\SchemaComparator;
use App\Support\Schema\SchemaInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commande « eagle:verify-schema » du fichier 25 (alias eeo:schema:verify).
 *
 * Compare la base courante aux métadonnées de la baseline (F04) : 117 tables métier, colonnes, index,
 * CHECK, FK, vues, guards et contenu des référentiels. Lecture seule. Code de sortie non nul au moindre
 * écart ; les tables techniques du framework sont inventoriées séparément (contrat 23).
 */
#[Signature('eagle:verify-schema
    {--database= : Nom de la connexion (par défaut : connexion par défaut)}
    {--expected= : Métadonnées attendues (par défaut : database/baseline/metadata.json)}
    {--json= : Écrire le rapport complet en JSON à ce chemin}', aliases: ['eeo:schema:verify'])]
#[Description('Vérifie que le schéma de la base est équivalent à la baseline complète (lecture seule)')]
final class VerifySchema extends Command
{
    private const MAX_LISTED = 40;

    public function handle(): int
    {
        $name = $this->connectionName();
        $connection = DB::connection($name);
        $expectedOption = $this->option('expected');
        $expected = ExpectedSchema::load(is_string($expectedOption) && $expectedOption !== '' ? $expectedOption : null);

        $actual = SchemaInspector::for($connection)->snapshot();
        $result = (new SchemaComparator)->compare($expected->snapshot, $actual);
        $recorded = $this->recordedBaselineMigrations($name);

        $report = [
            'mysql_version' => (string) $connection->selectOne('SELECT VERSION() AS v')->v,
            'expected_source' => $expected->provenance['source'] ?? null,
            'counts_expected' => $expected->snapshot['counts'],
            'counts_actual' => $actual['counts'],
            'reference_data_actual' => $actual['reference_data'],
            'framework_tables' => $result['framework_tables'],
            'unexpected_tables' => $result['unexpected_tables'],
            'baseline_migrations_recorded' => $recorded,
            'differences_count' => count($result['differences']),
            'differences' => $result['differences'],
            'warnings_count' => count($result['warnings']),
            'warnings' => $result['warnings'],
        ];

        $json = $this->option('json');
        if (is_string($json) && $json !== '') {
            file_put_contents($json, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }

        $this->components->twoColumnDetail('Tables métier', $actual['counts']['business_tables'].' / '.$expected->snapshot['counts']['business_tables']);
        $this->components->twoColumnDetail('Clés étrangères', $actual['counts']['foreign_keys'].' / '.$expected->snapshot['counts']['foreign_keys']);
        $this->components->twoColumnDetail('Contraintes CHECK', $actual['counts']['checks'].' / '.$expected->snapshot['counts']['checks']);
        $this->components->twoColumnDetail('Vues', $actual['counts']['views'].' / '.$expected->snapshot['counts']['views']);
        $this->components->twoColumnDetail('Guards (triggers)', $actual['counts']['triggers'].' / '.$expected->snapshot['counts']['triggers']);
        foreach ($actual['reference_data'] as $table => $digest) {
            $this->components->twoColumnDetail('Référentiel '.$table, $digest['rows'].' ligne(s)');
        }
        $this->components->twoColumnDetail('Tables techniques', $result['framework_tables'] === [] ? 'aucune' : implode(', ', $result['framework_tables']));
        $this->components->twoColumnDetail('Migrations de baseline enregistrées', count($recorded).' / '.count(ExpectedSchema::BASELINE_MIGRATIONS));

        foreach (array_slice($result['warnings'], 0, 5) as $warning) {
            $this->components->warn('Attribut de session différent : '.$warning['path']);
        }

        if ($result['differences'] !== []) {
            $this->components->error(count($result['differences']).' écart(s) avec la baseline.');
            foreach (array_slice($result['differences'], 0, self::MAX_LISTED) as $difference) {
                $this->line(sprintf(
                    '  - %s : attendu %s, trouvé %s',
                    $difference['path'],
                    $this->short($difference['expected']),
                    $this->short($difference['actual']),
                ));
            }

            return self::FAILURE;
        }

        $this->components->info('Schéma équivalent à la baseline : aucun écart.');

        return self::SUCCESS;
    }

    private function connectionName(): string
    {
        $option = $this->option('database');

        return is_string($option) && $option !== '' ? $option : (string) config('database.default');
    }

    /**
     * @return list<string>
     */
    private function recordedBaselineMigrations(string $connection): array
    {
        $table = config('database.migrations.table', 'migrations');
        $table = is_string($table) ? $table : 'migrations';

        if (! Schema::connection($connection)->hasTable($table)) {
            return [];
        }

        $ran = DB::connection($connection)->table($table)->pluck('migration')->map(fn ($m): string => (string) $m)->all();

        return array_values(array_intersect(ExpectedSchema::BASELINE_MIGRATIONS, $ran));
    }

    private function short(mixed $value): string
    {
        $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157).'...' : $text;
    }
}
