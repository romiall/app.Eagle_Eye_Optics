<?php

namespace Tests\Feature\Application;

use App\Application\Contracts\ContractValidator;
use Tests\TestCase;

/**
 * Validation serveur des charges contre les contrats F06. Une requête JSON est décodée en tableaux PHP, qui ne
 * distinguent plus `{}` de `[]` : le validateur relit un tableau VIDE comme l'objet vide là où le schéma attend un
 * objet (constat de revue F07 : `guardian_contact: {}`, valide pour le schéma, était refusé par le serveur).
 */
final class ContractValidatorTest extends TestCase
{
    private const SCHEMA = 'commands/UpdatePatient.v1.json';

    /**
     * @return array<string, mixed>
     */
    private static function validUpdate(): array
    {
        /** @var array{data: array<string, mixed>} $fixture */
        $fixture = json_decode((string) file_get_contents(base_path('contracts/fixtures/commands/update-patient.valid.json')), true, 512, JSON_THROW_ON_ERROR);

        return $fixture['data'];
    }

    public function test_un_objet_vide_attendu_par_le_schema_est_accepte(): void
    {
        $payload = self::validUpdate();
        $this->assertIsArray($payload['changes']);
        $payload['changes']['guardian_contact'] = [];

        $this->assertSame([], app(ContractValidator::class)->violations(self::SCHEMA, $payload));
    }

    public function test_une_liste_non_vide_n_est_jamais_relue_comme_un_objet(): void
    {
        $payload = self::validUpdate();
        $this->assertIsArray($payload['changes']);
        $payload['changes']['guardian_contact'] = ['valeur'];

        $this->assertNotSame([], app(ContractValidator::class)->violations(self::SCHEMA, $payload));
    }

    public function test_la_relecture_ne_rend_pas_valide_une_charge_qui_ne_l_est_pas(): void
    {
        // `changes` vide : refusé pour son contenu (fixture update-patient.empty.invalid), pas pour son type.
        $payload = self::validUpdate();
        $payload['changes'] = [];

        $this->assertNotSame([], app(ContractValidator::class)->violations(self::SCHEMA, $payload));
    }
}
