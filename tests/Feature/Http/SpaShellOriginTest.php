<?php

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UsesTemporaryPublicDirectory;
use Tests\TestCase;

/**
 * La coquille SPA ne dépend ni du schéma ni de l'hôte de la requête (fichier 23 : « SPA servie par
 * Laravel avec une seule origine HTTPS »).
 *
 * Défaut d'origine : @vite écrivait des URL d'assets ABSOLUES déduites de la requête (helper asset()).
 * Derrière un proxy ou un CDN qui termine TLS, la requête arrive en HTTP : la page HTTPS référençait
 * http://…/build/assets/….js, le navigateur bloquait le script (contenu mixte), la coquille restait
 * vide et le service worker n'était jamais enregistré. APP_URL n'y changeait rien, et un en-tête Host
 * arbitraire faisait pointer les assets vers cet hôte.
 *
 * Ces tests utilisent le vrai Vite de Laravel (jamais withoutVite()) avec un manifeste de build déposé
 * dans un répertoire public jetable : la CI PHP ne construit pas le frontend.
 */
final class SpaShellOriginTest extends TestCase
{
    use UsesTemporaryPublicDirectory;

    private const ENTRY = 'resources/frontend/app/main.ts';

    /** Chemins attendus (triés) : depuis la racine du site, jamais d'hôte ni de schéma. */
    private const EXPECTED_ASSETS = [
        '/build/assets/main-AAAA1111.js',
        '/build/assets/main-BBBB2222.css',
        '/build/assets/vendor-CCCC3333.js',
        '/build/assets/vendor-DDDD4444.css',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Forme du manifeste produit par laravel-vite-plugin : une entrée, son CSS, un morceau importé
        // (chargement différé des futures pages) et le CSS de ce morceau. Chaque appel de Vite qui
        // construit une URL d'asset est ainsi exercé.
        $manifest = [
            self::ENTRY => [
                'file' => 'assets/main-AAAA1111.js',
                'name' => 'main',
                'src' => self::ENTRY,
                'isEntry' => true,
                'imports' => ['_vendor-CCCC3333.js'],
                'css' => ['assets/main-BBBB2222.css'],
            ],
            '_vendor-CCCC3333.js' => [
                'file' => 'assets/vendor-CCCC3333.js',
                'name' => 'vendor',
                'css' => ['assets/vendor-DDDD4444.css'],
            ],
        ];

        $build = $this->temporaryPublicDirectory().'/build';
        File::ensureDirectoryExists($build);
        File::put($build.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        // La liste des proxys approuvés est un état statique de Symfony : ne pas la laisser au test suivant.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    public function test_la_reference_de_comparaison_pointe_les_assets_depuis_la_racine_du_site(): void
    {
        $html = (string) $this->get('http://localhost/')->assertOk()->getContent();

        $this->assertSame(self::EXPECTED_ASSETS, $this->buildAssetUrls($html));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string|null, 3: string|null}>
     */
    public static function requestShapes(): array
    {
        $forwardedByTlsProxy = ['X-Forwarded-Proto' => 'https'];
        $forwardedForEvilHost = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.test', 'X-Forwarded-Port' => '443'];

        // Colonnes : URL de la requête, en-têtes, proxys approuvés (trustedproxy.proxies), ASSET_URL.
        return [
            'HTTPS direct sur le nom du cabinet' => ['https://cabinet.example.test/', [], null, null],
            'proxy TLS non approuvé (X-Forwarded-Proto: https)' => ['http://cabinet.example.test/', $forwardedByTlsProxy, null, null],
            'proxy TLS approuvé (X-Forwarded-Proto: https)' => ['http://cabinet.example.test/', $forwardedByTlsProxy, '*', null],
            'en-tête Host arbitraire' => ['http://evil.test/', [], null, null],
            'en-tête Host arbitraire en HTTPS' => ['https://evil.test:8443/', [], null, null],
            'X-Forwarded-Host arbitraire, proxy non approuvé' => ['http://localhost/', $forwardedForEvilHost, null, null],
            'X-Forwarded-Host arbitraire, proxy approuvé' => ['http://localhost/', $forwardedForEvilHost, '*', null],
            'X-Forwarded-Prefix arbitraire, proxy approuvé' => ['http://localhost/', ['X-Forwarded-Prefix' => '/evil'], '*', null],
            'ASSET_URL vers un CDN' => ['http://localhost/', [], null, 'https://cdn.example.test'],
            'URL du routeur Vue derrière un proxy TLS' => ['http://cabinet.example.test/ventes/factures/01HZY3K9Q2A7B6C5D4E3F2G1H0', $forwardedByTlsProxy, '*', null],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('requestShapes')]
    public function test_les_assets_de_la_coquille_ne_dependent_ni_du_schema_ni_de_l_hote(
        string $url,
        array $headers,
        ?string $trustedProxies,
        ?string $assetOrigin,
    ): void {
        config(['trustedproxy.proxies' => $trustedProxies]);
        $this->app->make('url')->useAssetOrigin($assetOrigin);

        $reference = (string) $this->get('http://localhost/')->getContent();

        $response = $this->withHeaders($headers)->get($url);
        $html = (string) $response->assertOk()->getContent();

        $this->assertSame(self::EXPECTED_ASSETS, $this->buildAssetUrls($html), 'Les assets doivent être des chemins depuis la racine du site.');

        // Aucune URL absolue ni relative au protocole dans toute la coquille : une seule origine.
        $this->assertDoesNotMatchRegularExpression('~(?:src|href)\s*=\s*["\']\s*(?:[a-z][a-z0-9+.-]*:|//)~i', $html);

        // Réponse identique pour tous : précachable par le service worker, quel que soit le chemin d'arrivée.
        $this->assertSame($reference, $html);

        foreach (['evil', 'cabinet.example.test', 'cdn.example.test'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $html);
        }
    }

    public function test_vite_asset_donne_un_chemin_depuis_la_racine_du_site(): void
    {
        $this->get('http://evil.test/')->assertOk();

        $this->assertSame('/build/assets/main-AAAA1111.js', Vite::asset(self::ENTRY));
    }

    /**
     * URL des fichiers du build (JS et CSS) référencées par la coquille : balises script, link
     * stylesheet, preload et modulepreload, sans doublon, triées (la comparaison ne dépend pas de
     * l'ordre des balises).
     *
     * @return list<string>
     */
    private function buildAssetUrls(string $html): array
    {
        preg_match_all('~<(?:script|link)\b[^>]*?\b(?:src|href)="([^"]*/build/[^"]*)"~i', $html, $matches);

        $urls = array_values(array_unique($matches[1]));
        sort($urls);

        return $urls;
    }
}
