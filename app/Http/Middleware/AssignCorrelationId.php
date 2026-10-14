<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Ulid;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifiant de corrélation de chaque requête : repris de l'en-tête X-Correlation-Id s'il est sûr,
 * sinon généré. Il figure dans les journaux (Context), l'audit et les réponses d'erreur (fichier 08).
 */
final class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public const ATTRIBUTE = 'correlation_id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $correlationId = preg_match('/^[A-Za-z0-9._-]{8,100}\z/', $incoming) === 1 ? $incoming : Ulid::generate();

        $request->attributes->set(self::ATTRIBUTE, $correlationId);
        Context::add(self::ATTRIBUTE, $correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    public static function current(?Request $request = null): string
    {
        $value = $request?->attributes->get(self::ATTRIBUTE) ?? Context::get(self::ATTRIBUTE);

        return is_string($value) && $value !== '' ? $value : 'hors-requete-'.Ulid::generate();
    }
}
