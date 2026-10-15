<?php

namespace App\Domain\Shared;

/**
 * Version des règles pures de calcul (F08), portée par chaque commande locale (`rules_version` du cœur signé) et
 * déclarée dans `contracts/compatibility.json`. Toute règle modifiée prend une nouvelle version, ajoutée sans
 * retirer l'ancienne tant qu'un poste détient des pièces calculées avec elle (contrat 23).
 *
 * Même constante côté TypeScript : `resources/frontend/domain/rulesVersion.ts`.
 */
final class RulesVersion
{
    public const CURRENT = '2026-10-14.1';
}
