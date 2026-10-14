<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Valide une réponse HTTP réelle contre un schéma de contracts/ (JSON Schema 2020-12, Opis) : le contrat
 * publié des routes implémentées décrit exactement ce que l'application renvoie.
 */
trait ValidatesContracts
{
    private const CONTRACTS_BASE_URI = 'https://eeo.invalid/contracts/';

    protected function assertMatchesContract(TestResponse $response, string $schema): void
    {
        $data = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertDataMatchesContract($data, $schema);
    }

    protected function assertDataMatchesContract(mixed $data, string $schema): void
    {
        $validator = new Validator;
        $validator->setMaxErrors(10);
        $validator->resolver()?->registerPrefix(self::CONTRACTS_BASE_URI, base_path('contracts').'/');

        $result = $validator->validate($data, self::CONTRACTS_BASE_URI.$schema);
        $errors = $result->error() === null ? [] : (new ErrorFormatter)->format($result->error(), true);

        $this->assertTrue($result->isValid(), $schema.' : '.json_encode($errors, JSON_UNESCAPED_UNICODE).' — '.json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
