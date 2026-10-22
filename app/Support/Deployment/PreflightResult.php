<?php

namespace App\Support\Deployment;

/**
 * Résultat d'un contrôle d'hébergement : réussi, alerte (à lire, ne bloque pas), bloquant (l'installation ou la
 * mise en service ne doit pas continuer) ou information.
 */
final readonly class PreflightResult
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const BLOCKING = 'blocking';

    public const INFO = 'info';

    public function __construct(
        public string $status,
        public string $label,
        public string $detail,
    ) {}

    public static function ok(string $label, string $detail = ''): self
    {
        return new self(self::OK, $label, $detail);
    }

    public static function warning(string $label, string $detail): self
    {
        return new self(self::WARNING, $label, $detail);
    }

    public static function blocking(string $label, string $detail): self
    {
        return new self(self::BLOCKING, $label, $detail);
    }

    public static function info(string $label, string $detail): self
    {
        return new self(self::INFO, $label, $detail);
    }

    public function isBlocking(): bool
    {
        return $this->status === self::BLOCKING;
    }

    /**
     * @return array{status: string, label: string, detail: string}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'label' => $this->label, 'detail' => $this->detail];
    }
}
