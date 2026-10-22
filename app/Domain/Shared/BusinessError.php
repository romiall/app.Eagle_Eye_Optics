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
     * @param  array<string, string>  $headers  en-têtes de réponse (ex. Retry-After)
     * @param  array<string, int|string|null>  $details  valeurs actualisées à proposer (reste dû, quantité disponible)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $fields = [],
        ?Throwable $previous = null,
        public readonly array $headers = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Interblocage ou attente de verrou épuisés (chemin central, contrat 23) : aucune modification n'a été
     * appliquée. Le client renvoie la MÊME demande, avec la même clé d'idempotence, sans risque de doublon.
     */
    public static function serverBusy(?Throwable $previous = null): self
    {
        return new self(
            'SERVER_BUSY',
            'Le serveur est occupé : renvoyez la même demande dans un instant. Aucune modification n\'a été appliquée.',
            503,
            [],
            $previous,
            ['Retry-After' => '2'],
        );
    }

    /**
     * Clé d'idempotence déjà employée pour une AUTRE demande : l'effet d'origine est conservé tel quel.
     */
    public static function idempotencyMismatch(): self
    {
        return new self(
            'IDEMPOTENCY_PAYLOAD_MISMATCH',
            'Cette clé d\'idempotence a déjà servi pour une demande différente : utilisez une nouvelle clé pour une nouvelle intention.',
            409,
        );
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

    /**
     * Refus d'état (409 `STATE_TRANSITION_REFUSED`) : l'état actuel de la ressource ne permet pas l'opération
     * (consultation déjà démarrée, visite non démarrable, consultation terminée). `details.current_status` porte
     * ce statut (valeur simple, jamais une donnée clinique) pour que l'écran se rafraîchisse.
     */
    public static function stateRefused(string $message, string $currentStatus): self
    {
        return new self('STATE_TRANSITION_REFUSED', $message, 409, [], null, [], ['current_status' => $currentStatus]);
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
