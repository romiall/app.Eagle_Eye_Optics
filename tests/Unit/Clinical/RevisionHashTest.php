<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\RevisionHash;
use App\Domain\Clinical\RevisionSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

/**
 * Empreinte d'une révision clinique (C03, hypothèse « G8 ») : SHA-256 de la forme canonique JCS. Les vecteurs communs
 * `contracts/vectors/clinical/snapshot-hash.json` sont rejoués à l'identique côté navigateur
 * (tests/frontend/domain/clinical-snapshot.test.ts). Données fictives.
 */
final class RevisionHashTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string, 2: string}>
     */
    public static function vectors(): array
    {
        $file = dirname(__DIR__, 3).'/contracts/vectors/clinical/snapshot-hash.json';
        $vectors = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($vectors['cases'] as $case) {
            $cases[(string) $case['id']] = [$case['input'], (string) $case['canonical'], (string) $case['sha256']];
        }

        return $cases;
    }

    #[DataProvider('vectors')]
    public function test_les_vecteurs_communs_donnent_la_meme_forme_canonique_et_la_meme_empreinte(mixed $input, string $canonical, string $sha256): void
    {
        $this->assertSame($canonical, RevisionHash::canonical($input));
        $this->assertSame($sha256, RevisionHash::hash($input));
    }

    public function test_l_ordre_des_cles_ne_change_pas_l_empreinte_mais_l_ordre_d_une_liste_la_change(): void
    {
        $this->assertSame(RevisionHash::hash(['b' => 1, 'a' => ['y' => null, 'x' => true]]), RevisionHash::hash(['a' => ['x' => true, 'y' => null], 'b' => 1]));
        $this->assertNotSame(RevisionHash::hash([1, 2]), RevisionHash::hash([2, 1]));
    }

    public function test_un_objet_stdclass_se_canonise_comme_un_tableau_associatif(): void
    {
        $object = new stdClass;
        $object->b = 'x';
        $object->a = 1;

        $this->assertSame('{"a":1,"b":"x"}', RevisionHash::canonical($object));
    }

    public function test_un_flottant_est_refuse_plutot_que_de_produire_une_empreinte_ambigue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RevisionHash::hash(['sphere' => -1.25]);
    }

    public function test_l_empreinte_des_rubriques_est_celle_de_leur_forme_canonique(): void
    {
        $steps = ['CONCLUSION' => ['conclusion' => 'Fin (fictif)', 'referral' => false, 'referral_urgency' => null, 'referral_reasons' => []]];

        $this->assertSame(hash('sha256', '{"CONCLUSION":{"conclusion":"Fin (fictif)","referral":false,"referral_reasons":[],"referral_urgency":null}}'), RevisionSnapshot::stepsHash($steps));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', RevisionSnapshot::stepsHash($steps));
    }
}
