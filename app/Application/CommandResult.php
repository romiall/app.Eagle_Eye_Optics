<?php

namespace App\Application;

/**
 * Réponse du chemin central, fournie seulement APRÈS le commit. `replayed` vaut true lorsque la commande
 * était déjà committée sous cette clé : aucun effet n'a été rejoué et `result` est celui de la première
 * exécution, même si sa réponse d'origine s'est perdue en route.
 */
final readonly class CommandResult
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public bool $replayed,
        public array $result,
        public ?string $resultType,
        public ?string $resultId,
        public string $correlationId,
    ) {}
}
