<?php

namespace App\Domain\Patients;

use stdClass;

/**
 * Une page de l'activité d'un patient (`PatientActivity`) : les éléments de la page dans l'ordre, le nombre
 * d'éléments de chaque section VISIBLE (aucune autre), le filtre appliqué et la pagination.
 */
final readonly class PatientActivityPage
{
    /**
     * @param  list<array{type: string, occurred_at: string, row: stdClass}>  $items
     * @param  array<string, int>  $totals  section visible → nombre d'éléments
     */
    public function __construct(
        public array $items,
        public array $totals,
        public ?string $section,
        public int $currentPage,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
