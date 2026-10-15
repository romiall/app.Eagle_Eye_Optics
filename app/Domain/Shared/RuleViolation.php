<?php

namespace App\Domain\Shared;

use DomainException;

/**
 * Refus d'une règle pure (F08) : montant, quantité, date, solde, stock. Le même refus existe côté TypeScript
 * (`resources/frontend/domain/errors.ts`) avec le même code d'API, le même champ et le même identifiant de règle :
 * les vecteurs de `contracts/vectors/` comparent ces trois valeurs dans les deux moteurs.
 *
 * `errorCode` est un code du fichier 08 (`contracts/schemas/error.json`) ; `rule` est l'identifiant stable de la
 * règle (ex. `quantity.integer_required`) ; `details` porte les valeurs utiles au client (ex. le reste dû à
 * proposer après un encaissement refusé), jamais une donnée personnelle.
 */
final class RuleViolation extends DomainException
{
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const PAYMENT_EXCEEDS_BALANCE = 'PAYMENT_EXCEEDS_BALANCE';

    public const STOCK_UNAVAILABLE = 'STOCK_UNAVAILABLE';

    public const LOT_EXPIRED = 'LOT_EXPIRED';

    /**
     * @param  array<string, int|string|null>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $rule,
        public readonly string $field,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, int|string|null>  $details
     */
    public static function invalid(string $field, string $rule, string $message, array $details = []): self
    {
        return new self(self::VALIDATION_FAILED, $rule, $field, $message, $details);
    }

    /**
     * Réponse API selon le fichier 08 : saisie invalide et lot interdit en 422 ; solde ou stock dépassé en 409, avec
     * les valeurs actualisées à proposer dans `details` (AT12 : le reste dû de 10 000).
     */
    public function toBusinessError(): BusinessError
    {
        $status = match ($this->errorCode) {
            self::VALIDATION_FAILED, self::LOT_EXPIRED => 422,
            default => 409,
        };

        return new BusinessError(
            $this->errorCode,
            $this->getMessage(),
            $status,
            [$this->field => [$this->getMessage()]],
            $this,
            [],
            $this->details,
        );
    }
}
