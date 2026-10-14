<?php

namespace App\Console\Commands;

use App\Support\Schema\BaselineInstaller;
use App\Support\Schema\ExpectedSchema;
use App\Support\Schema\SchemaComparator;
use App\Support\Schema\SchemaInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adoption contrôlée d'une base déjà créée par le SQL livré (contrat 23, F04).
 *
 * La base doit être strictement équivalente à la baseline (tables, colonnes, index, CHECK, FK, vues,
 * guards, référentiels) ; au moindre écart, rien n'est écrit. Si elle l'est, les quatre migrations de
 * baseline sont enregistrées comme exécutées, sans rien réimporter : le SQL et les migrations ne sont
 * jamais exécutés deux fois. Les tables techniques sont créées ensuite par « php artisan migrate ».
 */
#[Signature('eeo:schema:adopt
    {--database= : Nom de la connexion (par défaut : connexion par défaut)}
    {--dry-run : Vérifier seulement, sans rien enregistrer}
    {--force : Exécuter en production sans confirmation interactive}')]
#[Description('Adopte une base créée par le SQL livré après vérification stricte de son équivalence')]
final class AdoptBaseline extends Command
{
    use ConfirmableTrait;

    public function handle(Migrator $migrator): int
    {
        $option = $this->option('database');
        $name = is_string($option) && $option !== '' ? $option : (string) config('database.default');
        $connection = DB::connection($name);

        BaselineInstaller::for($connection)->assertSupportedServer();

        $repository = $migrator->getRepository();
        $repository->setSource($name);

        if ($repository->repositoryExists()) {
            $already = array_values(array_intersect(ExpectedSchema::BASELINE_MIGRATIONS, $repository->getRan()));
            if ($already !== []) {
                $this->components->error('Adoption refusée : la baseline est déjà enregistrée dans les migrations ('.implode(', ', $already).').');

                return self::FAILURE;
            }
        }

        $expected = ExpectedSchema::load();
        $actual = SchemaInspector::for($connection)->snapshot();
        $result = (new SchemaComparator)->compare($expected->snapshot, $actual);

        if ($result['differences'] !== []) {
            $this->components->error('Adoption refusée : '.count($result['differences']).' écart(s) avec la baseline. Aucune donnée n\'a été modifiée.');
            foreach (array_slice($result['differences'], 0, 30) as $difference) {
                $this->line('  - '.$difference['path']);
            }
            $this->line('Ne pas masquer l\'écart (pas de IF NOT EXISTS ni d\'INSERT IGNORE) : diagnostiquer puis repartir d\'une base vide.');

            return self::FAILURE;
        }

        foreach (array_slice($result['warnings'], 0, 5) as $warning) {
            $this->components->warn('Attribut de session différent (sans effet sur les gardes) : '.$warning['path']);
        }

        if ($this->option('dry-run')) {
            $this->components->info('Base équivalente à la baseline : adoption possible (aucune écriture, --dry-run).');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('Enregistrer la baseline comme migrée pour cette base ?')) {
            return self::FAILURE;
        }

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $batch = $repository->getNextBatchNumber();
        $connection->transaction(function () use ($repository, $batch): void {
            foreach (ExpectedSchema::BASELINE_MIGRATIONS as $migration) {
                $repository->log($migration, $batch);
            }
        });

        Log::notice('Baseline adoptée sur une base créée par le SQL livré.', ['connection' => $name, 'batch' => $batch]);
        $this->components->info('Baseline adoptée (lot '.$batch.'). Lancer ensuite « php artisan migrate » pour les tables techniques.');

        return self::SUCCESS;
    }
}
