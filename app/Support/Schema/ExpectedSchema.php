<?php

namespace App\Support\Schema;

use RuntimeException;

/**
 * Métadonnées attendues de la baseline : photographie d'une base créée par import de
 * database/eagle_eye_optics_complet.sql sur MySQL 8.4.9 (eeo:schema:snapshot), versionnée dans
 * database/baseline/metadata.json avec sa provenance.
 */
final class ExpectedSchema
{
    public const RELATIVE_PATH = 'database/baseline/metadata.json';

    /** Noms des migrations de baseline : les seules qu'eeo:schema:adopt peut marquer comme exécutées. */
    public const BASELINE_MIGRATIONS = [
        '2026_10_14_000001_eeo_baseline_01_tables',
        '2026_10_14_000002_eeo_baseline_02_foreign_keys',
        '2026_10_14_000003_eeo_baseline_03_reference_data_and_views',
        '2026_10_14_000004_eeo_baseline_04_history_guards',
    ];

    /**
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $snapshot
     */
    private function __construct(
        public readonly array $provenance,
        public readonly array $snapshot,
    ) {}

    public static function load(?string $path = null): self
    {
        $file = $path ?? base_path(self::RELATIVE_PATH);
        $raw = is_file($file) ? file_get_contents($file) : false;

        if ($raw === false) {
            throw new RuntimeException('Métadonnées attendues introuvables : '.$file);
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! is_array($data['snapshot'] ?? null) || ! is_array($data['provenance'] ?? null)) {
            throw new RuntimeException('Métadonnées attendues illisibles : '.$file);
        }

        $counts = $data['snapshot']['counts'] ?? [];
        $required = [
            'business_tables' => BaselineStatements::EXPECTED_COUNTS['tables'],
            'foreign_keys' => BaselineStatements::EXPECTED_COUNTS['foreign_keys'],
            'checks' => BaselineStatements::EXPECTED_COUNTS['checks'],
            'views' => BaselineStatements::EXPECTED_COUNTS['views'],
            'triggers' => BaselineStatements::EXPECTED_COUNTS['triggers'],
        ];
        foreach ($required as $key => $value) {
            if (($counts[$key] ?? null) !== $value) {
                throw new RuntimeException(sprintf('Métadonnées attendues incohérentes : %s = %s, attendu %d.', $key, var_export($counts[$key] ?? null, true), $value));
            }
        }

        return new self($data['provenance'], $data['snapshot']);
    }
}
