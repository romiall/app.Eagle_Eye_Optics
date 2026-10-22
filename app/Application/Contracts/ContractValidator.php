<?php

namespace App\Application\Contracts;

use App\Domain\Shared\BusinessError;
use LogicException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Valide une charge contre un schéma de `contracts/` (JSON Schema 2020-12, Opis) : les contrats publiés par F06
 * ne sont pas qu'une documentation, le serveur applique les mêmes schémas que les fixtures et que le client
 * TypeScript (Ajv), quelle que soit l'origine de la commande (route directe, synchronisation, tâche).
 *
 * Une charge de COMMANDE invalide est un refus d'API (VALIDATION_FAILED, erreurs par champ) ; une charge
 * d'ÉVÉNEMENT invalide est un défaut de programmation (l'outbox ne reçoit que des événements contractuels).
 */
final class ContractValidator
{
    private const BASE_URI = 'https://eeo.invalid/contracts/';

    private ?Validator $validator = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertCommandPayload(string $schema, array $payload): void
    {
        $fields = $this->violations($schema, $payload);
        if ($fields !== []) {
            throw BusinessError::validation($fields);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertEventPayload(string $type, int $version, array $payload): void
    {
        if (preg_match('/^[A-Z][A-Za-z]{2,63}\z/', $type) !== 1) {
            throw new LogicException('Nom d\'événement invalide : '.$type);
        }
        $schema = 'events/'.$type.'.v'.$version.'.json';
        if (! is_file(base_path('contracts/'.$schema))) {
            throw new LogicException('Événement hors contrat : '.$type.' v'.$version.' n\'a pas de schéma dans contracts/events/.');
        }

        $fields = $this->violations($schema, $payload);
        if ($fields !== []) {
            throw new LogicException('Charge d\'événement non conforme à '.$schema.' : '.json_encode($fields, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @return array<string, list<string>> chemins pointés (`lines.0.quantity`) → messages français
     */
    public function violations(string $schema, mixed $data): array
    {
        $validator = $this->validator();
        $decoded = json_decode((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        // Un tableau PHP vide ne dit pas s'il venait de `{}` ou de `[]` (la requête est décodée en tableaux) : là où
        // le schéma attend un objet et ne reçoit qu'un tableau VIDE, il est relu comme l'objet vide `{}`, puis la
        // validation est rejouée. Une liste non vide n'est jamais transformée.
        for ($pass = 0; ; $pass++) {
            $result = $validator->validate($decoded, self::BASE_URI.ltrim($schema, '/'));
            $error = $result->error();
            if ($result->isValid() || $error === null) {
                return [];
            }
            $paths = [];
            self::emptyListsExpectedAsObjects($error, $decoded, $paths);
            if ($paths === [] || $pass >= 8) {
                break;
            }
            foreach ($paths as $path) {
                $decoded = self::replaceAt($decoded, $path);
            }
        }

        $fields = [];
        $this->collect($error, $fields);

        // Un champ « texte ou null » borné (anyOf) échoue deux fois quand le texte est trop long : la limite ET le type
        // de la branche « null ». Seul le message de limite est juste ; l'autre ne fait que brouiller le message.
        foreach ($fields as $path => $messages) {
            if (count($messages) > 1 && in_array(self::message('maxLength'), $messages, true)) {
                $fields[$path] = array_values(array_filter($messages, static fn (string $message): bool => $message !== self::message('type')));
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, list<int|string>>  $paths
     */
    private static function emptyListsExpectedAsObjects(ValidationError $error, mixed $root, array &$paths): void
    {
        foreach ($error->subErrors() as $child) {
            self::emptyListsExpectedAsObjects($child, $root, $paths);
        }
        if ($error->keyword() !== 'type') {
            return;
        }
        $expected = (array) ($error->args()['expected'] ?? []);
        $path = array_values($error->data()->fullPath());
        if (in_array('object', $expected, true) && self::valueAt($root, $path) === []) {
            $paths[implode("\0", array_map('strval', $path))] = $path;
        }
    }

    /**
     * @param  list<int|string>  $path
     */
    private static function valueAt(mixed $node, array $path): mixed
    {
        foreach ($path as $key) {
            if ($node instanceof \stdClass && property_exists($node, (string) $key)) {
                $node = $node->{(string) $key};
            } elseif (is_array($node) && array_key_exists($key, $node)) {
                $node = $node[$key];
            } else {
                return null;
            }
        }

        return $node;
    }

    /**
     * @param  list<int|string>  $path
     */
    private static function replaceAt(mixed $node, array $path): mixed
    {
        if ($path === []) {
            return new \stdClass;
        }
        $key = array_shift($path);
        if ($node instanceof \stdClass) {
            $node->{(string) $key} = self::replaceAt($node->{(string) $key}, $path);
        } elseif (is_array($node)) {
            $node[$key] = self::replaceAt($node[$key], $path);
        }

        return $node;
    }

    private function validator(): Validator
    {
        if ($this->validator === null) {
            $validator = new Validator;
            $validator->setMaxErrors(20);
            $validator->resolver()?->registerPrefix(self::BASE_URI, base_path('contracts').'/');
            $this->validator = $validator;
        }

        return $this->validator;
    }

    /**
     * @param  array<string, list<string>>  $fields
     */
    private function collect(ValidationError $error, array &$fields): void
    {
        $children = $error->subErrors();
        if ($children !== []) {
            foreach ($children as $child) {
                $this->collect($child, $fields);
            }

            return;
        }

        $path = array_map('strval', $error->data()->fullPath());
        $keyword = $error->keyword();
        $args = $error->args();

        $named = match ($keyword) {
            'required' => $args['missing'] ?? [],
            'additionalProperties' => $args['properties'] ?? [],
            default => null,
        };

        if (is_array($named) && $named !== []) {
            foreach ($named as $name) {
                $fields[implode('.', [...$path, (string) $name])][] = $keyword === 'required' ? 'Champ obligatoire absent.' : 'Champ non prévu par le contrat.';
            }

            return;
        }

        $fields[$path === [] ? '_' : implode('.', $path)][] = self::message($keyword);
    }

    private static function message(string $keyword): string
    {
        return match ($keyword) {
            'type' => 'Type de valeur invalide.',
            'enum', 'const' => 'Valeur non admise.',
            'pattern', 'format' => 'Format invalide.',
            'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minItems', 'maxItems', 'multipleOf' => 'Valeur hors des limites admises.',
            'not' => 'Valeur interdite dans ce contexte.',
            default => 'Valeur invalide.',
        };
    }
}
