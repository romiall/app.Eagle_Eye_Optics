<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de protection de l'application privée (fichier 09, « Protection de l'application »).
 *
 * - CSP stricte d'origine unique : aucun script, style, fonte ni connexion tiers ; la coquille Vue, son
 *   service worker et le futur coffre local ne chargent que des ressources de l'origine. Elle n'est pas
 *   envoyée quand le serveur de développement Vite (autre origine, rechargement à chaud) est actif.
 * - Pas d'intégration en iframe, pas de détection de type, pas de référent vers l'extérieur.
 * - API : aucune réponse mise en cache par le navigateur (no-store) ; tout le site est noindex.
 * - Documents PDF (D01, D02) : ils sont produits SUR LE POSTE et ouverts dans la visionneuse du navigateur par une
 *   URL d'objet `blob:` (nouvel onglet, impression depuis la visionneuse). Le document `blob:` hérite de cette CSP ;
 *   avec `object-src 'none'`, la visionneuse PDF (greffon) de Chromium resterait vide. `object-src blob:` est le
 *   strict nécessaire : seules les URL d'objet créées par le code de l'origine (`script-src 'self'`) sont admises,
 *   jamais un greffon ni un objet d'une autre origine, ni `data:`. Les cadres restent limités à l'origine
 *   (`default-src 'self'`) : aucun PDF n'est intégré dans un `<iframe>`.
 */
final class SecurityHeaders
{
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; "
        ."img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self'; "
        ."manifest-src 'self'; object-src blob:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');

        if (! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }

        if ($request->is('api', 'api/*')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
