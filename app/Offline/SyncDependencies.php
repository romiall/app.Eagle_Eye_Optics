<?php

namespace App\Offline;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pièces liées qu'une commande de poste suppose existantes (O05 ; fichier 19 : « Parent ou profil précédent manquant :
 * WAITING_DEPENDENCY, liste des dépendances accessibles ; conserver et reprendre dans l'ordre »). Lues dans le contrat
 * des commandes (`contracts/commands/index.json`) :
 *
 *  - l'agrégat visé lui-même, sauf pour une création (version attendue `none`) ;
 *  - les références usuelles de la charge (dossier patient, visite, consultation, facture, rendez-vous, session de
 *    caisse), sauf l'identifiant que la commande crée.
 *
 * Une pièce absente du serveur mais qu'une commande de poste REJETÉE après revue devait créer ne viendra jamais : la
 * commande passe alors en revue (`DEPENDENCY_REJECTED`) au lieu d'attendre indéfiniment.
 */
final class SyncDependencies
{
    /** Champ de la charge → table de la pièce référencée. */
    private const REFERENCES = [
        'patient_id' => 'patient_records',
        'visit_id' => 'visits',
        'consultation_id' => 'consultations',
        'invoice_id' => 'invoices',
        'appointment_id' => 'appointments',
        'cash_session_id' => 'cash_sessions',
    ];

    /** @var array<string, array{entity_id_field: string, expected_version: string}>|null */
    private static ?array $index = null;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{missing: list<array{entity_type: string, entity_id: string}>, rejected: list<array{entity_type: string, entity_id: string}>}
     */
    public static function check(string $clinicId, string $commandName, string $entityType, string $entityId, array $payload): array
    {
        $contract = self::index()[$commandName] ?? null;
        $creation = $contract === null || $contract['expected_version'] === 'none';
        $createdField = $contract['entity_id_field'] ?? null;

        $wanted = [];
        if (! $creation) {
            $wanted[$entityType.':'.$entityId] = ['entity_type' => $entityType, 'entity_id' => $entityId];
        }
        foreach (self::REFERENCES as $field => $table) {
            $value = $payload[$field] ?? null;
            if (! is_string($value) || ($creation && $field === $createdField)) {
                continue;
            }
            $wanted[$table.':'.$value] = ['entity_type' => $table, 'entity_id' => $value];
        }

        $missing = [];
        $rejected = [];
        foreach ($wanted as $dependency) {
            if (DB::table($dependency['entity_type'])->where('clinic_id', $clinicId)->where('id', $dependency['entity_id'])->exists()) {
                continue;
            }
            $abandoned = DB::table('sync_commands as c')
                ->join('sync_command_results as r', static function ($join): void {
                    $join->on('r.clinic_id', '=', 'c.clinic_id')->on('r.command_id', '=', 'c.id');
                })
                ->where('c.clinic_id', $clinicId)
                ->where('c.entity_type', $dependency['entity_type'])
                ->where('c.entity_id', $dependency['entity_id'])
                ->where('r.status', 'REJECTED')
                ->exists();
            if ($abandoned) {
                $rejected[] = $dependency;
            } else {
                $missing[] = $dependency;
            }
        }

        return ['missing' => $missing, 'rejected' => $rejected];
    }

    /**
     * @return array<string, array{entity_id_field: string, expected_version: string}>
     */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $path = base_path('contracts/commands/index.json');
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded) || ! is_array($decoded['const'] ?? null)) {
            throw new RuntimeException('Contrat des commandes illisible : contracts/commands/index.json.');
        }
        $index = [];
        foreach ($decoded['const'] as $name => $entry) {
            if (is_string($name) && is_array($entry) && is_string($entry['entity_id_field'] ?? null) && is_string($entry['expected_version'] ?? null)) {
                $index[$name] = ['entity_id_field' => $entry['entity_id_field'], 'expected_version' => $entry['expected_version']];
            }
        }

        return self::$index = $index;
    }
}
