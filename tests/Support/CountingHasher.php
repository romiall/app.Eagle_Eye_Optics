<?php

namespace Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;

/**
 * Hacheur de test qui délègue au vrai hacheur et compte les calculs : vérifie qu'un chemin ne fait
 * qu'une vérification (temps de réponse comparable) sans dépendre d'une mesure de durée.
 */
final class CountingHasher implements Hasher
{
    public int $makes = 0;

    public int $checks = 0;

    public function __construct(private readonly Hasher $inner) {}

    /**
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        return $this->inner->info($hashedValue);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function make(#[\SensitiveParameter] $value, array $options = []): string
    {
        $this->makes++;

        return $this->inner->make($value, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->checks++;

        return $this->inner->check($value, $hashedValue, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }
}
