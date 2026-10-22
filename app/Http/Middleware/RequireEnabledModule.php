<?php

namespace App\Http\Middleware;

use App\Domain\Shared\BusinessError;
use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route d'un module non activé : 404 « ressource introuvable », comme si la route n'existait pas
 * (aucune indication sur ce qui est prêt mais pas encore ouvert). Usage : `module:patients`.
 */
final class RequireEnabledModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! Modules::isEnabled($module)) {
            throw BusinessError::notFound();
        }

        return $next($request);
    }
}
