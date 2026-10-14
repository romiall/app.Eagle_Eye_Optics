<?php

namespace App\Support\Testing;

use RuntimeException;

/**
 * Levée lorsqu'une opération destructive (remise à zéro, suppression de tables, annulation de
 * migrations : migrate:fresh, migrate:rollback…) vise une base qui n'est pas explicitement une base
 * de test isolée.
 */
final class DestructiveDatabaseOperationRefused extends RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public static function for(string $operation, array $violations): self
    {
        return new self(sprintf(
            "Opération destructive refusée : « %s ».\n- %s\n"
            .'Seule une base de test isolée peut être réinitialisée ou voir ses migrations annulées : APP_ENV=testing, '
            .'nom de base se terminant par « _test » et hôte local. Utiliser la procédure dédiée '
            .'« php artisan eeo:test-db:reset » ; en production, toute correction passe par une migration additive.',
            $operation,
            implode("\n- ", $violations),
        ));
    }
}
