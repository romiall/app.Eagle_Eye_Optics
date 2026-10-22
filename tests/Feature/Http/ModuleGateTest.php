<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Mise en service progressive : un module non activé répond comme une route inexistante (404 dans
 * l'enveloppe du fichier 08), un module activé passe ; `GET /auth/me` annonce les modules ouverts.
 */
final class ModuleGateTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->makeStaff($this->makeClinic(), 'MANAGER', 'gerante.test');
        Route::middleware(['api', 'auth:sanctum', 'active', 'module:patients'])
            ->get('/api/v1/test-module', static fn () => response()->json(['data' => 'ouvert']));
    }

    public function test_un_module_non_active_repond_404_comme_une_route_inexistante(): void
    {
        config(['eeo.enabled_modules' => []]);

        $response = $this->actingAsFresh($this->manager)->getJson('/api/v1/test-module');

        $response->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_un_module_active_laisse_passer_la_requete(): void
    {
        config(['eeo.enabled_modules' => ['patients']]);

        $this->actingAsFresh($this->manager)->getJson('/api/v1/test-module')
            ->assertOk()
            ->assertJsonPath('data', 'ouvert');
    }

    public function test_un_autre_module_active_n_ouvre_pas_celui_ci(): void
    {
        config(['eeo.enabled_modules' => ['sales', 'stock']]);

        $this->actingAsFresh($this->manager)->getJson('/api/v1/test-module')->assertNotFound();
    }

    public function test_me_annonce_les_modules_ouverts_dans_l_ordre_de_la_navigation(): void
    {
        config(['eeo.enabled_modules' => ['sales', 'patients']]);

        $this->actingAsFresh($this->manager)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.clinic.enabled_modules', ['patients', 'sales']);
    }

    public function test_me_annonce_une_liste_vide_quand_rien_n_est_ouvert(): void
    {
        config(['eeo.enabled_modules' => []]);

        $this->actingAsFresh($this->manager)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.clinic.enabled_modules', []);
    }
}
