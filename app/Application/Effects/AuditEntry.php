<?php

namespace App\Application\Effects;

/**
 * Entrée d'audit demandée par une action (fichier 09) : ce qui a changé, jamais un secret ni un contenu
 * clinique brut. Le chemin central l'écrit dans la transaction de la commande, avec l'auteur et la
 * corrélation du contexte (jamais ceux de la charge).
 */
final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $sanitizedDiff
     */
    public function __construct(
        public string $action,
        public string $targetType,
        public ?string $targetId = null,
        public ?string $reason = null,
        public array $sanitizedDiff = [],
    ) {}
}
