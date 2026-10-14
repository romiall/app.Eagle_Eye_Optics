<?php

namespace App\Support\Schema;

/**
 * Compare deux photographies de SchemaInspector et liste chaque écart, sans tolérance silencieuse.
 *
 * Les tables techniques du framework sont inventoriées à part (contrat 23) ; toute autre table hors
 * baseline est un écart. Les attributs de session des triggers/vues sont signalés comme avertissements.
 */
final class SchemaComparator
{
    /** Clés comparées strictement. */
    private const STRICT_KEYS = ['tables', 'views', 'triggers', 'reference_data', 'missing_business_tables'];

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array{differences: list<array{path: string, expected: mixed, actual: mixed}>, warnings: list<array{path: string, expected: mixed, actual: mixed}>, framework_tables: list<string>, unexpected_tables: list<string>}
     */
    public function compare(array $expected, array $actual): array
    {
        $differences = [];
        foreach (self::STRICT_KEYS as $key) {
            $this->diff($key, $expected[$key] ?? null, $actual[$key] ?? null, $differences);
        }

        $others = array_values(array_map('strval', (array) ($actual['other_tables'] ?? [])));
        $framework = array_values(array_intersect($others, SchemaInspector::FRAMEWORK_TABLES));
        $unexpected = array_values(array_diff($others, SchemaInspector::FRAMEWORK_TABLES));
        foreach ($unexpected as $table) {
            $differences[] = ['path' => 'other_tables.'.$table, 'expected' => null, 'actual' => 'table hors baseline et hors framework'];
        }

        $warnings = [];
        $this->diff('session_attributes', $expected['session_attributes'] ?? null, $actual['session_attributes'] ?? null, $warnings);

        return [
            'differences' => $differences,
            'warnings' => $warnings,
            'framework_tables' => $framework,
            'unexpected_tables' => $unexpected,
        ];
    }

    /**
     * @param  list<array{path: string, expected: mixed, actual: mixed}>  $out
     */
    private function diff(string $path, mixed $expected, mixed $actual, array &$out): void
    {
        if (is_array($expected) && is_array($actual) && ! array_is_list($expected) && ! array_is_list($actual)) {
            foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
                $child = $path.'.'.$key;
                if (! array_key_exists($key, $actual)) {
                    $out[] = ['path' => $child, 'expected' => $expected[$key], 'actual' => '(absent)'];
                } elseif (! array_key_exists($key, $expected)) {
                    $out[] = ['path' => $child, 'expected' => '(absent)', 'actual' => $actual[$key]];
                } else {
                    $this->diff($child, $expected[$key], $actual[$key], $out);
                }
            }

            return;
        }

        if ($expected !== $actual) {
            $out[] = ['path' => $path, 'expected' => $expected, 'actual' => $actual];
        }
    }
}
