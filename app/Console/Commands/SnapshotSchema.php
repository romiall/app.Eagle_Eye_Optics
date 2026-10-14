<?php

namespace App\Console\Commands;

use App\Support\Schema\BaselineStatements;
use App\Support\Schema\SchemaInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Photographie normalisée des métadonnées d'une base (lecture seule).
 *
 * Sert à produire database/baseline/metadata.json depuis une base de test isolée créée par import de
 * database/eagle_eye_optics_complet.sql (tools/dev/schema-parity.sh), et à comparer des bases entre elles.
 * La sortie ne contient ni date, ni nom de base, ni DEFINER : deux photographies d'un même schéma sont
 * identiques octet pour octet.
 */
#[Signature('eeo:schema:snapshot
    {--database= : Nom de la connexion (par défaut : connexion par défaut)}
    {--source= : Description de la provenance de la base photographiée}
    {--output= : Fichier de sortie (par défaut : sortie standard)}')]
#[Description('Écrit les métadonnées normalisées de la base en JSON (lecture seule)')]
final class SnapshotSchema extends Command
{
    public function handle(): int
    {
        $option = $this->option('database');
        $connection = DB::connection(is_string($option) && $option !== '' ? $option : null);
        $baseline = BaselineStatements::load();
        $source = $this->option('source');

        $document = [
            'provenance' => [
                'source' => is_string($source) && $source !== '' ? $source : 'non précisée',
                'mysql_version' => (string) $connection->selectOne('SELECT VERSION() AS v')->v,
                'baseline_catalog_sha256' => $baseline->catalogSha256(),
                'sources_sha256' => $baseline->sourcesSha256(),
                'generator' => 'php artisan eeo:schema:snapshot',
            ],
            'snapshot' => SchemaInspector::for($connection)->snapshot(),
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            file_put_contents($output, $json);
            $this->components->info('Métadonnées écrites dans '.$output.'.');
        } else {
            $this->output->write($json);
        }

        return self::SUCCESS;
    }
}
