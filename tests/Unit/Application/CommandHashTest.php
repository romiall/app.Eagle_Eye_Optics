<?php

namespace Tests\Unit\Application;

use App\Application\Command;
use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\GrantSnapshot;
use Carbon\CarbonImmutable;
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

    public function test_le_tri_des_cles_est_un_ordre_total_meme_avec_des_cles_numeriques(): void
    {
        // Constat de revue F07 : avec la comparaison mixte de PHP, {"10","9","1a"} donnait trois empreintes selon
        // l'ordre d'arrivée. Le tri par octets donne la même dans les six ordres.
        $keys = ['10', '9', '1a'];
        $hashes = [];
        foreach ([[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]] as $order) {
            $object = [];
            foreach ($order as $index) {
                $object[$keys[$index]] = $index;
            }
            $hashes[] = CanonicalJson::hash($object);
        }

        $this->assertCount(1, array_unique($hashes));
    }

    public function test_un_objet_vide_reste_un_objet(): void
    {
        $this->assertSame('{"a":{}}', CanonicalJson::encode(['a' => new \stdClass]));
        $this->assertSame('{"a":[]}', CanonicalJson::encode(['a' => []]));
    }

    public function test_l_empreinte_de_rejeu_lie_l_auteur_le_poste_et_le_grant(): void
    {
        $command = new Command('RegisterPayment', ['amount' => 30000], 'ID', 3);
        $grant = static fn (string $id, string $user): GrantSnapshot => new GrantSnapshot(
            $id, '01J9ZXK4M2Q8R7S6T5V4W30001', $user, ['payment.collect'], 'rules-1', 'catalog-1',
            CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'), CarbonImmutable::parse('2026-10-14 20:00:00', 'UTC'),
        );
        $sync = static fn (string $user, GrantSnapshot $snapshot): CommandContext => CommandContext::sync(
            '01J9ZXK4M2Q8R7S6T5V4W30003', '01J9ZXK4M2Q8R7S6T5V4W30001', $user, 'Profil', $snapshot, 'corr-0001',
            CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'), CarbonImmutable::parse('2026-10-14 08:30:00', 'UTC'),
            CarbonImmutable::parse('2026-10-14 15:00:00', 'UTC'),
        );
        $cashier = '01J9ZXK4M2Q8R7S6T5V4W30002';
        $reception = '01J9ZXK4M2Q8R7S6T5V4W30005';
        $original = $command->fingerprint($sync($cashier, $grant('01J9ZXK4M2Q8R7S6T5V4W30004', $cashier)));

        // Même commande, même grant, même auteur : même empreinte (rejeu légitime d'un ACK perdu).
        $this->assertSame($original, $command->fingerprint($sync($cashier, $grant('01J9ZXK4M2Q8R7S6T5V4W30004', $cashier))));
        // Même identifiant de commande présenté par un autre profil du poste ou sous un autre grant : conflit.
        $this->assertNotSame($original, $command->fingerprint($sync($reception, $grant('01J9ZXK4M2Q8R7S6T5V4W30006', $reception))));
        $this->assertNotSame($original, $command->fingerprint($sync($cashier, $grant('01J9ZXK4M2Q8R7S6T5V4W30007', $cashier))));
        // L'intention seule reste celle de hash().
        $this->assertNotSame($command->hash(), $original);
    }
}
