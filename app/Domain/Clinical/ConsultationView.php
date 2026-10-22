<?php

namespace App\Domain\Clinical;

use stdClass;

/**
 * Consultation lue d'un seul tenant (voir `ConsultationReader::view`) : la ligne, ses sept rubriques, les
 * métadonnées de ses révisions et l'identité administrative de base du patient. Les quatre éléments décrivent le
 * MÊME état de la consultation (sa version).
 */
final readonly class ConsultationView
{
    /**
     * @param  array<string, array<string, mixed>>  $steps
     * @param  list<array<string, mixed>>  $revisions
     * @param  array<string, mixed>  $patient
     */
    public function __construct(
        public stdClass $row,
        public array $steps,
        public array $revisions,
        public array $patient,
    ) {}
}
