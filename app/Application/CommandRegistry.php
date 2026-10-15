<?php

namespace App\Application;

use LogicException;

/**
 * Commandes connues du chemin central. Une commande non enregistrée n'existe pas : une route, une commande
 * synchronisée ou une tâche qui en nomme une est un défaut de câblage, pas un cas métier.
 */
final class CommandRegistry
{
    /** @var array<string, CommandDefinition> */
    private array $definitions = [];

    public function register(CommandDefinition $definition): void
    {
        if (isset($this->definitions[$definition->name])) {
            throw new LogicException('Commande déjà enregistrée : '.$definition->name);
        }

        $this->definitions[$definition->name] = $definition;
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function definition(string $name): CommandDefinition
    {
        return $this->definitions[$name] ?? throw new LogicException('Commande inconnue du chemin central : '.$name);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->definitions);
        sort($names);

        return $names;
    }
}
