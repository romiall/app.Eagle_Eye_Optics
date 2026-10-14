<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Preuve de fumée du serveur : l'application démarre, la coquille SPA est servie sur les URL du
 * routeur Vue, et /api ne retombe jamais sur la coquille.
 */
final class SpaShellTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Le build Vite n'est pas nécessaire côté PHP : @vite devient vide (l'e2e couvre le build).
        $this->withoutVite();
    }

    public function test_l_application_demarre_et_repond_a_la_sonde_de_sante(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_la_racine_sert_la_coquille_spa_en_francais(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('<html lang="fr">', false);
        $response->assertSee('<div id="app"></div>', false);
        $response->assertSee('<title>Eagle’s Eye Optics', false);
        $response->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);
    }

    public function test_la_coquille_est_identique_pour_tous_sans_cookie_ni_session(): void
    {
        $response = $this->get('/');

        $response->assertCookieMissing(config('session.cookie'));
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function clientRoutes(): array
    {
        return [
            'liste' => ['/patients'],
            'profondeur' => ['/ventes/factures/01HZY3K9Q2A7B6C5D4E3F2G1H0'],
            'segment commençant comme api' => ['/apis'],
            'barre finale' => ['/patients/'],
        ];
    }

    #[DataProvider('clientRoutes')]
    public function test_les_urls_du_routeur_vue_recoivent_la_coquille(string $path): void
    {
        $this->get($path)->assertOk()->assertSee('<div id="app"></div>', false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function apiPaths(): array
    {
        return [
            'racine api' => ['/api'],
            'racine api avec barre' => ['/api/'],
            'v1' => ['/api/v1'],
            'route inconnue' => ['/api/v1/inconnu'],
            'profonde' => ['/api/v1/patients/01HZY3K9Q2A7B6C5D4E3F2G1H0/history'],
        ];
    }

    #[DataProvider('apiPaths')]
    public function test_une_url_api_inconnue_repond_en_json_404_sans_la_coquille(string $path): void
    {
        $response = $this->get($path);

        $response->assertNotFound();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure(['message']);
        $this->assertStringNotContainsString('id="app"', (string) $response->getContent());
    }

    public function test_une_url_api_reste_en_json_meme_si_le_client_demande_du_html(): void
    {
        $response = $this->get('/api/v1/inconnu', ['Accept' => 'text/html']);

        $response->assertNotFound();
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_les_methodes_d_ecriture_sur_une_url_api_inconnue_repondent_aussi_en_json_404(): void
    {
        $this->postJson('/api/v1/inconnu', [])->assertNotFound()->assertJsonStructure(['message']);
        $this->patchJson('/api/v1/inconnu', [])->assertNotFound();
        $this->deleteJson('/api/v1/inconnu')->assertNotFound();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function staticLookingPaths(): array
    {
        return [
            'actif de build absent' => ['/build/assets/absent-1a2b3c.js'],
            'dossier build' => ['/build'],
            'stockage' => ['/storage/documents/facture.pdf'],
            'fichier avec extension' => ['/robots-inexistant.txt'],
            'carte source' => ['/sw.js.map'],
        ];
    }

    #[DataProvider('staticLookingPaths')]
    public function test_les_ressources_statiques_absentes_ne_retombent_pas_sur_la_coquille(string $path): void
    {
        $response = $this->get($path);

        $response->assertNotFound();
        $this->assertStringNotContainsString('id="app"', (string) $response->getContent());
    }

    public function test_le_service_worker_est_servi_a_la_racine_avec_sa_portee_complete(): void
    {
        $public = $this->temporaryPublicDirectory();
        File::ensureDirectoryExists($public.'/build');
        File::put($public.'/build/sw.js', "self.addEventListener('install', () => {});\n");

        $response = $this->get('/sw.js');

        $response->assertOk();
        $response->assertHeader('Service-Worker-Allowed', '/');
        $response->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function test_le_manifeste_est_servi_avec_son_type_mime(): void
    {
        $public = $this->temporaryPublicDirectory();
        File::ensureDirectoryExists($public.'/build');
        File::put($public.'/build/manifest.webmanifest', '{"name":"test"}');

        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();
        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));
    }

    public function test_sans_build_le_service_worker_et_le_manifeste_repondent_404(): void
    {
        $this->temporaryPublicDirectory();

        $this->get('/sw.js')->assertNotFound();
        $this->get('/manifest.webmanifest')->assertNotFound();
    }

    private function temporaryPublicDirectory(): string
    {
        $directory = sys_get_temp_dir().'/eeo-public-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory);
        $this->app->usePublicPath($directory);
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($directory));

        return $directory;
    }
}
