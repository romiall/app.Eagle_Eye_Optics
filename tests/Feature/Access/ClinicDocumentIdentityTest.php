<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\Support\ValidatesContracts;
use Tests\TestCase;

/**
 * Identité du cabinet imprimée sur les documents (GET /clinic/document-identity) : lisible par tout profil qui
 * produit un document (professionnel : ordonnance ; caisse : reçu ; gérante), refusée à l'accueil qui n'en
 * produit aucun ; jamais les paramètres administratifs d'un autre cabinet ; indépendante des modules ; un objet
 * de réglages vide reste un objet. Les valeurs sont saisies par la gérante (ici fictives), jamais inventées.
 */
final class ClinicDocumentIdentityTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;
    use ValidatesContracts;

    private string $clinicId;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinicId = $this->makeClinic('CABINET FICTIF DES TESTS');
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        DB::table('clinics')->where('id', $this->clinicId)->update([
            'address' => 'Rue fictive 1, Ville fictive',
            'phone' => '+237 600 00 00 00',
            'email' => 'contact@cabinet-fictif.invalid',
            'tax_identifier' => 'NIU-FICTIF-0001',
            'professional_mentions' => 'Mentions fictives des tests',
            'document_settings' => json_encode(['footer_lines' => ['Pied de page fictif']]),
        ]);
    }

    public function test_les_profils_qui_produisent_des_documents_lisent_l_identite_du_cabinet(): void
    {
        foreach (['CLINICIAN', 'CASHIER'] as $role) {
            $user = $this->makeStaff($this->clinicId, $role);
            $response = $this->actingAsFresh($user)->getJson('/api/v1/clinic/document-identity')->assertOk();

            $this->assertMatchesContract($response, 'schemas/resources/access.json#/$defs/ClinicDocumentIdentityResponse');
            $response->assertJsonPath('data.name', 'CABINET FICTIF DES TESTS')
                ->assertJsonPath('data.address', 'Rue fictive 1, Ville fictive')
                ->assertJsonPath('data.tax_identifier', 'NIU-FICTIF-0001')
                ->assertJsonPath('data.professional_mentions', 'Mentions fictives des tests')
                ->assertJsonPath('data.document_settings.footer_lines.0', 'Pied de page fictif')
                ->assertJsonPath('data.timezone', 'Africa/Douala')
                ->assertJsonPath('data.currency', 'XAF')
                ->assertJsonPath('data.production_documents_enabled', false);
            // Jamais d'identifiant interne du cabinet ni de paramètre réservé à la gérante.
            $this->assertArrayNotHasKey('id', $response->json('data'), $role);
        }

        $this->actingAsFresh($this->manager)->getJson('/api/v1/clinic/document-identity')->assertOk();
    }

    public function test_l_accueil_qui_ne_produit_aucun_document_est_refuse(): void
    {
        $reception = $this->makeStaff($this->clinicId, 'RECEPTION');

        $this->actingAsFresh($reception)->getJson('/api/v1/clinic/document-identity')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACTION_FORBIDDEN');
    }

    public function test_chaque_compte_lit_son_propre_cabinet_et_les_modules_n_y_changent_rien(): void
    {
        config(['eeo.enabled_modules' => []]);
        $other = $this->makeClinic('AUTRE CABINET FICTIF');
        $foreignCashier = $this->makeStaff($other, 'CASHIER', 'caisse.autre');

        $this->actingAsFresh($foreignCashier)->getJson('/api/v1/clinic/document-identity')
            ->assertOk()
            ->assertJsonPath('data.name', 'AUTRE CABINET FICTIF')
            ->assertJsonPath('data.address', null);
    }

    public function test_un_objet_de_reglages_vide_reste_un_objet(): void
    {
        DB::table('clinics')->where('id', $this->clinicId)->update(['document_settings' => '{}']);

        $response = $this->actingAsFresh($this->manager)->getJson('/api/v1/clinic/document-identity')->assertOk();

        $this->assertStringContainsString('"document_settings":{}', (string) $response->getContent());
        $this->assertMatchesContract($response, 'schemas/resources/access.json#/$defs/ClinicDocumentIdentityResponse');
    }

    public function test_sans_session_la_route_demande_une_connexion(): void
    {
        $this->fromSpa()->getJson('/api/v1/clinic/document-identity')->assertUnauthorized();
    }
}
