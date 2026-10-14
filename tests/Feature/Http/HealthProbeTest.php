<?php

namespace Tests\Feature\Http;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * Sonde de santé GET /up.
 *
 * Défaut d'origine : la sonde native de Laravel (`health:`) rend une vue qui charge un script depuis
 * cdn.jsdelivr.net et des fontes depuis fonts.bunny.net, sur la même origine que la SPA, son service
 * worker et le coffre local. La sonde est maintenant une route maison qui répond un JSON minimal.
 */
final class HealthProbeTest extends TestCase
{
    public function test_la_sonde_repond_200_avec_un_json_minimal(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertSame('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_la_reponse_ne_reference_aucune_ressource_externe_ni_page_html(): void
    {
        $body = (string) $this->get('/up')->getContent();

        $this->assertStringNotContainsStringIgnoringCase('http', $body);
        $this->assertStringNotContainsStringIgnoringCase('<script', $body);
        $this->assertStringNotContainsStringIgnoringCase('<link', $body);
        $this->assertStringNotContainsStringIgnoringCase('<html', $body);
    }

    public function test_la_reponse_n_est_jamais_mise_en_cache(): void
    {
        $response = $this->get('/up');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_la_sonde_n_ouvre_ni_session_ni_cookie(): void
    {
        $response = $this->get('/up');

        $response->assertCookieMissing((string) config('session.cookie'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_la_route_est_nommee_hors_du_groupe_web_et_sans_intergiciel_de_route(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('health');

        $this->assertNotNull($route);
        $this->assertSame('up', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame([], $route->gatherMiddleware());
    }

    public function test_la_requete_head_est_acceptee(): void
    {
        $this->call('HEAD', '/up')->assertOk();
    }

    public function test_la_sonde_n_est_jamais_remplacee_par_la_coquille_spa(): void
    {
        $response = $this->get('/up', ['Accept' => 'text/html']);

        $response->assertOk();
        $this->assertStringNotContainsString('id="app"', (string) $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function test_une_exception_de_diagnostic_donne_un_500_journalise_sans_divulguer_son_message(): void
    {
        Exceptions::fake();
        Event::listen(DiagnosingHealth::class, static function (): never {
            throw new RuntimeException('mysql://utilisateur:motdepasse@10.0.0.5/eagle_eye_optics injoignable');
        });

        $response = $this->get('/up');

        $response->assertStatus(500);
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertSame('{"status":"error"}', (string) $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('motdepasse', (string) $response->getContent());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_la_sonde_reste_joignable_en_mode_maintenance_pas_le_reste_de_l_application(): void
    {
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            /**
             * @return array<string, mixed>
             */
            public function data(): array
            {
                return ['retry' => 60];
            }
        });

        $this->get('/up')->assertOk();
        $this->get('/patients')->assertStatus(503);
    }
}
