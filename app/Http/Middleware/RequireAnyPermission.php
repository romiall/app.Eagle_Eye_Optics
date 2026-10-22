<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route ouverte à un compte qui détient AU MOINS UNE des permissions listées (le middleware `can:` exige
 * la sienne seule). Usage : `any-permission:visit.register,agenda.read`. Un compte sans aucune d'elles reçoit
 * 403 `ACTION_FORBIDDEN`, comme avec `can:`.
 */
final class RequireAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (! Gate::forUser($request->user())->any($permissions)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
