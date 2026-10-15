<?php

namespace Tests\Unit\Application;

use App\Application\Command;
use App\Domain\Shared\CanonicalJson;
use PHPUnit\Framework\TestCase;

/**
 * L'empreinte de l'intention décide si une clé d'idempotence rejouée est la MÊME demande (réponse rejouée) ou
 * une demande différente (conflit). Elle ignore l'ordre des clés, jamais une différence de valeur.
 */
final class CommandHashTest extends TestCase
{
    public function test_l_ordre_des_cles_ne_change_pas_l_empreinte(): void
    {
        $a = new Command('RegisterPayment', ['amount' => 30000, 'method' => 'MTN_MOMO', 'nested' => ['b' => 1, 'a' => 2]], 'ID', 3);
        $b = new Command('RegisterPayment', ['nested' => ['a' => 2, 'b' => 1], 'method' => 'MTN_MOMO', 'amount' => 30000], 'ID', 3);

        $this->assertSame($a->hash(), $b->hash());
        $this->assertSame(64, strlen($a->hash()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $a->hash());
    }

    public function test_un_montant_different_change_l_empreinte(): void
    {
        $original = new Command('RegisterPayment', ['amount' => 30000], 'ID', 3);

        $this->assertNotSame($original->hash(), (new Command('RegisterPayment', ['amount' => 30001], 'ID', 3))->hash());
    }

    public function test_le_nom_l_agregat_et_la_version_attendue_font_partie_de_l_intention(): void
    {
        $payload = ['amount' => 30000];
        $original = new Command('RegisterPayment', $payload, 'ID', 3);

        $this->assertNotSame($original->hash(), (new Command('ReversePayment', $payload, 'ID', 3))->hash());
        $this->assertNotSame($original->hash(), (new Command('RegisterPayment', $payload, 'AUTRE', 3))->hash());
        $this->assertNotSame($original->hash(), (new Command('RegisterPayment', $payload, 'ID', 4))->hash());
        $this->assertNotSame($original->hash(), (new Command('RegisterPayment', $payload, 'ID', null))->hash());
    }

    public function test_le_vide_et_le_zero_restent_distincts(): void
    {
        // Fichier 23 : null est différent de zéro ; aucun remplacement du vide par zéro.
        $this->assertNotSame(
            (new Command('X', ['reference' => null]))->hash(),
            (new Command('X', ['reference' => 0]))->hash(),
        );
        $this->assertNotSame(
            (new Command('X', ['quantity' => '0.00']))->hash(),
            (new Command('X', ['quantity' => null]))->hash(),
        );
    }

    public function test_les_caracteres_unicode_sont_conserves(): void
    {
        $this->assertStringContainsString('Réfraction', CanonicalJson::encode(['note' => 'Réfraction œil droit']));
        $this->assertStringNotContainsString('\\u', CanonicalJson::encode(['note' => 'Réfraction']));
    }

    public function test_une_liste_garde_son_ordre(): void
    {
        $this->assertNotSame(CanonicalJson::hash(['lines' => [1, 2]]), CanonicalJson::hash(['lines' => [2, 1]]));
    }
}
