<?php

namespace App\Domain\Shared;

use RuntimeException;
use Throwable;

/**
 * Refus métier explicite, traduit tel quel en réponse API : code stable du fichier 08, message français,
 * erreurs par champ éventuelles. Un refus ne produit aucun effet (la transaction est annulée).
 */
final class BusinessError extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fields
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $fields = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function forbidden(string $message = 'Action non autorisée pour ce compte.'): self
    {
        return new self('ACTION_FORBIDDEN', $message, 403);
    }

    public static function notFound(string $message = 'Ressource introuvable.'): self
    {
        return new self('RESOURCE_NOT_FOUND', $message, 404);
    }

    public static function versionConflict(string $message = 'Une version plus récente existe : actualisez avant de modifier.'): self
    {
        return new self('VERSION_CONFLICT', $message, 409);
    }

    /**
     * @param  array<string, list<string>>  $fields
     */
    public static function validation(array $fields, string $message = 'Certaines informations sont invalides.'): self
    {
        return new self('VALIDATION_FAILED', $message, 422, $fields);
    }

    public static function reauthenticationRequired(): self
    {
        return new self(
            'REAUTHENTICATION_REQUIRED',
            'Confirmez votre mot de passe pour cette opération sensible.',
            423,
        );
    }
}
