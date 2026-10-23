<?php

namespace App\Offline;

use RuntimeException;

/**
 * Compartiments du coffre local (O01, `docs/implementation/vault.md` D16 à D18) lus dans le contrat partagé
 * `contracts/compartments.json` : chaque permission du référentiel appartient à une classe (`ADMIN`, `CLINICAL`,
 * `FINANCIAL`, `MANAGER`, `NONE`), chaque commande du journal d'un poste à un compartiment. Le serveur s'en sert pour
 * inscrire les compartiments d'un grant signé ; le poste vérifie la même fonction avant d'ouvrir une clé.
 *
 * Résultat attendu avec les rôles du référentiel : accueil → ADMINISTRATIVE ; professionnel → ADMINISTRATIVE +
 * CLINICAL ; caisse → ADMINISTRATIVE + FINANCIAL ; gérante → les quatre. `exam_fee.collect` ne donne pas FINANCIAL
 * (D17). Une permission inconnue du contrat ne donne aucun compartiment.
 */
final class Compartments
{
    /** Ordre fixe des compartiments (contrat, fichier 23). */
    public const ORDER = ['ADMINISTRATIVE', 'CLINICAL', 'FINANCIAL', 'MANAGER'];

    /** @var array{permissions: array<string, ?string>, commands: array<string, string>}|null */
    private static ?array $contract = null;

    /**
     * Compartiments ouverts par un ensemble de permissions, dans l'ordre du contrat.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function of(array $permissions): array
    {
        $map = self::contract()['permissions'];
        $found = [];
        foreach ($permissions as $permission) {
            $compartment = $map[$permission] ?? null;
            if ($compartment !== null) {
                $found[$compartment] = true;
            }
        }

        return array_values(array_filter(self::ORDER, static fn (string $code): bool => isset($found[$code])));
    }

    /** Compartiment où une commande du journal est scellée ; null si la commande n'est pas classée. */
    public static function ofCommand(string $commandName): ?string
    {
        return self::contract()['commands'][$commandName] ?? null;
    }

    /**
     * Classe de chaque permission connue du contrat (null = aucun compartiment).
     *
     * @return array<string, ?string>
     */
    public static function permissionMap(): array
    {
        return self::contract()['permissions'];
    }

    /**
     * @return array<string, string>
     */
    public static function commandMap(): array
    {
        return self::contract()['commands'];
    }

    /**
     * @return array{permissions: array<string, ?string>, commands: array<string, string>}
     */
    private static function contract(): array
    {
        if (self::$contract !== null) {
            return self::$contract;
        }
        $path = base_path('contracts/compartments.json');
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded) || ! is_array($decoded['permission_classes'] ?? null) || ! is_array($decoded['commands'] ?? null)) {
            throw new RuntimeException('Contrat des compartiments illisible : contracts/compartments.json.');
        }

        $permissions = [];
        foreach ($decoded['permission_classes'] as $class) {
            if (! is_array($class) || ! is_array($class['permissions'] ?? null)) {
                continue;
            }
            $compartment = is_string($class['compartment'] ?? null) ? $class['compartment'] : null;
            foreach ($class['permissions'] as $permission) {
                if (is_string($permission)) {
                    $permissions[$permission] = $compartment;
                }
            }
        }
        $commands = [];
        foreach ($decoded['commands'] as $compartment => $names) {
            if (! is_string($compartment) || ! is_array($names)) {
                continue;
            }
            foreach ($names as $name) {
                if (is_string($name)) {
                    $commands[$name] = $compartment;
                }
            }
        }

        return self::$contract = ['permissions' => $permissions, 'commands' => $commands];
    }
}
