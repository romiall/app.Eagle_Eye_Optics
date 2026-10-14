<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * En-têtes de protection (fichier 09) : CSP d'origine unique sur la coquille, API jamais mise en cache
 * ni indexée, aucune autorisation CORS pour une origine tierce.
 */
final class SecurityHeadersTest extends TestCase
{
    public function test_la_coquille_porte_une_csp_d_origine_unique_et_les_protections_de_contenu(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('http', $csp, 'Aucune origine tierce autorisée.');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_les_reponses_api_ne_sont_jamais_mises_en_cache(): void
    {
        $response = $this->getJson('/api/v1/inconnu');

        $response->assertNotFound();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_aucune_origine_tierce_n_est_autorisee_par_cors(): void
    {
        $preflight = $this->call('OPTIONS', '/api/v1/patients', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));

        $simple = $this->get('/api/v1/inconnu', ['Origin' => 'https://evil.example']);
        $this->assertFalse($simple->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_la_sonde_et_le_service_worker_portent_aussi_les_protections(): void
    {
        foreach (['/up', '/sw.js', '/manifest.webmanifest'] as $uri) {
            $response = $this->get($uri);
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertNotNull($response->headers->get('Content-Security-Policy'), $uri);
        }
    }
}
