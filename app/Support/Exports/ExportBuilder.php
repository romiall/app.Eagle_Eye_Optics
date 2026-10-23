<?php

namespace App\Support\Exports;

use App\Domain\Operations\ExportPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use ZipArchive;

/**
 * Production de l'archive d'un export contrôlé (X02) : un fichier CSV par table (séparateur « ; », UTF-8 avec BOM,
 * lisible par un tableur), le dictionnaire des colonnes tiré du catalogue du schéma, un manifeste (lignes et
 * empreinte SHA-256 de chaque fichier) et, pour la seule remise complète, les pièces privées. Écrite sur le disque
 * PRIVÉ (storage/app/private/exports), jamais servie directement. Hors du domaine : lectures et fichiers sont des
 * effets externes, appelés par la tâche planifiée `eeo:exports:process`.
 */
final class ExportBuilder
{
    private const DELIMITER = ';';

    /**
     * @return array{relative_path: string, sha256: string, size: int, tables: int}
     */
    public function build(stdClass $request): array
    {
        $catalog = $this->catalog();
        $clinicId = (string) $request->clinic_id;
        $type = (string) $request->export_type;
        $tables = ExportPlan::tables($type, array_keys(array_filter($catalog, static fn (array $table): bool => $table['clinic'])));

        $relative = 'exports/'.$clinicId.'/'.$request->id.'.zip';
        $absolute = storage_path('app/private/'.$relative);
        if (! is_dir(dirname($absolute)) && ! mkdir(dirname($absolute), 0700, true) && ! is_dir(dirname($absolute))) {
            throw new RuntimeException('Dossier des exports impossible à créer.');
        }

        $zip = new ZipArchive;
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Archive impossible à créer.');
        }

        $filters = $this->recordFilters($clinicId, $type, $request->patient_id === null ? null : (string) $request->patient_id);
        $manifestTables = [];
        foreach ($tables as $table) {
            $columns = ExportPlan::exportedColumns($table, $catalog[$table]['columns']);
            [$csv, $rows] = $this->tableCsv($clinicId, $table, $columns, $type, $filters);
            $name = 'donnees/'.$table.'.csv';
            $zip->addFromString($name, $csv);
            $manifestTables[] = ['file' => $name, 'table' => $table, 'rows' => $rows, 'sha256' => hash('sha256', $csv)];
        }

        $dictionary = $this->dictionary($catalog, $tables);
        $zip->addFromString('dictionnaire.csv', $dictionary);

        $files = 0;
        if (ExportPlan::includesFiles($type)) {
            foreach (DB::table('stored_files')->where('clinic_id', $clinicId)->whereNull('archived_at')->orderBy('id')->get(['id', 'private_path']) as $file) {
                $path = storage_path('app/private/'.$file->private_path);
                if (is_file($path)) {
                    $zip->addFile($path, 'pieces/'.$file->id.'.'.pathinfo((string) $file->private_path, PATHINFO_EXTENSION));
                    $files++;
                }
            }
        }

        $manifest = [
            'export_id' => (string) $request->id,
            'export_type' => $type,
            'clinic_id' => $clinicId,
            'patient_id' => $request->patient_id,
            'requested_by' => (string) $request->requested_by,
            'generated_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'tables' => $manifestTables,
            'files' => $files,
            'dictionary' => ['file' => 'dictionnaire.csv', 'sha256' => hash('sha256', $dictionary)],
            'notice' => 'Export du serveur : les saisies encore en attente sur un poste hors ligne ne s\'y trouvent pas tant qu\'elles ne sont pas synchronisées.',
        ];
        $zip->addFromString('manifeste.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('LISEZMOI.txt', $this->readme($type));
        if ($zip->close() !== true) {
            throw new RuntimeException('Archive impossible à finaliser.');
        }

        $hash = hash_file('sha256', $absolute);
        if ($hash === false) {
            throw new RuntimeException('Empreinte de l\'archive impossible à calculer.');
        }

        return ['relative_path' => $relative, 'sha256' => $hash, 'size' => (int) filesize($absolute), 'tables' => count($tables)];
    }

    /**
     * Tables et colonnes du catalogue du schéma (même source que le MLD et le dictionnaire de données).
     *
     * @return array<string, array{clinic: bool, description: string, columns: list<string>, meta: list<array<string, mixed>>}>
     */
    private function catalog(): array
    {
        $raw = file_get_contents(base_path('database/schema_catalog.json'));
        /** @var array{tables: list<array{name: string, description?: string, columns: list<array<string, mixed>>}>} $decoded */
        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        $catalog = [];
        foreach ($decoded['tables'] as $table) {
            $names = array_map(static fn (array $column): string => (string) $column['name'], $table['columns']);
            $catalog[$table['name']] = [
                'clinic' => in_array('clinic_id', $names, true),
                'description' => (string) ($table['description'] ?? ''),
                'columns' => $names,
                'meta' => $table['columns'],
            ];
        }

        return $catalog;
    }

    /**
     * Identifiants du dossier d'un patient : consultations, rubriques d'antécédents et prescriptions (les autres
     * rubriques s'y rattachent).
     *
     * @return array{patient: list<string>, consultation: list<string>, history: list<string>, prescription: list<string>}|null
     */
    private function recordFilters(string $clinicId, string $type, ?string $patientId): ?array
    {
        if ($type !== 'PATIENT_RECORD' || $patientId === null) {
            return null;
        }
        $consultations = $this->idsOf(DB::table('consultations')->where('clinic_id', $clinicId)->where('patient_id', $patientId));

        return [
            'patient' => [$patientId],
            'consultation' => $consultations,
            'history' => $consultations === [] ? [] : $this->idsOf(DB::table('consultation_histories')->where('clinic_id', $clinicId)->whereIn('consultation_id', $consultations)),
            'prescription' => $this->idsOf(DB::table('optical_prescriptions')->where('clinic_id', $clinicId)->where('patient_id', $patientId)),
        ];
    }

    /**
     * @return list<string>
     */
    private function idsOf(Builder $query): array
    {
        return array_values(array_map('strval', $query->pluck('id')->all()));
    }

    /**
     * @param  list<string>  $columns
     * @param  array{patient: list<string>, consultation: list<string>, history: list<string>, prescription: list<string>}|null  $filters
     * @return array{0: string, 1: int}
     */
    private function tableCsv(string $clinicId, string $table, array $columns, string $type, ?array $filters): array
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Flux temporaire impossible à ouvrir.');
        }
        fwrite($stream, "\u{FEFF}");
        fputcsv($stream, $columns, self::DELIMITER, '"', '');

        $query = DB::table($table)->where('clinic_id', $clinicId)->select($columns);
        if ($type === 'PATIENT_RECORD') {
            $filter = ExportPlan::recordFilter($table);
            $ids = $filter === null || $filters === null ? [] : $filters[$filter['source']];
            if ($filter === null || $ids === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn($filter['column'], $ids);
            }
        }
        if (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }

        $rows = 0;
        foreach ($query->cursor() as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row->{$column} ?? null;
                $values[] = $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
            fputcsv($stream, $values, self::DELIMITER, '"', '');
            $rows++;
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return [$csv, $rows];
    }

    /**
     * @param  array<string, array{clinic: bool, description: string, columns: list<string>, meta: list<array<string, mixed>>}>  $catalog
     * @param  list<string>  $tables
     */
    private function dictionary(array $catalog, array $tables): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Flux temporaire impossible à ouvrir.');
        }
        fwrite($stream, "\u{FEFF}");
        fputcsv($stream, ['table', 'description_table', 'colonne', 'type', 'obligatoire', 'description'], self::DELIMITER, '"', '');
        foreach ($tables as $table) {
            $exported = ExportPlan::exportedColumns($table, $catalog[$table]['columns']);
            foreach ($catalog[$table]['meta'] as $column) {
                if (! in_array((string) $column['name'], $exported, true)) {
                    continue;
                }
                fputcsv($stream, [
                    $table,
                    $catalog[$table]['description'],
                    (string) $column['name'],
                    (string) ($column['type'] ?? ''),
                    ($column['nullable'] ?? true) ? 'non' : 'oui',
                    (string) ($column['description'] ?? ''),
                ], self::DELIMITER, '"', '');
            }
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function readme(string $type): string
    {
        return implode("\r\n", [
            'Export Eagle’s Eye Optics Medical ('.$type.')',
            '',
            'donnees/ : un fichier CSV par table (séparateur point-virgule, UTF-8), à ouvrir dans un tableur.',
            'dictionnaire.csv : le sens et le type de chaque colonne.',
            'manifeste.json : le nombre de lignes et l’empreinte SHA-256 de chaque fichier (contrôle d’intégrité).',
            ExportPlan::includesFiles($type) ? 'pieces/ : les originaux PDF et justificatifs conservés par le cabinet.' : '',
            '',
            'Données personnelles et de santé : à conserver de façon sécurisée, à ne pas transmettre sans autorisation.',
            'Les saisies encore en attente sur un poste hors ligne ne figurent pas dans cet export.',
            '',
        ]);
    }
}
