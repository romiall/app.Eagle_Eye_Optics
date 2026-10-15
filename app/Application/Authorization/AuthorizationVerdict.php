<?php

namespace App\Application\Authorization;

final readonly class AuthorizationVerdict
{
    public function __construct(
        public AuthorizationDecision $decision,
        public string $reason,
    ) {}

    public static function allowed(): self
    {
        return new self(AuthorizationDecision::Allowed, 'Autorisation valide au moment de l\'action.');
    }

    public static function denied(string $reason): self
    {
        return new self(AuthorizationDecision::Denied, $reason);
    }

    public static function needsReview(string $reason): self
    {
        return new self(AuthorizationDecision::NeedsReview, $reason);
    }
}
