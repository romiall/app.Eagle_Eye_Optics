<?php

namespace App\Http\Middleware;

use App\Domain\Shared\BusinessError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opérations sensibles (droits, approbations, exports globaux) : mot de passe confirmé récemment dans
 * cette session (POST /api/v1/auth/reauthenticate), sinon 423 REAUTHENTICATION_REQUIRED (fichier 09).
 */
final class RequireRecentReauthentication
{
    public const SESSION_KEY = 'eeo.reauthenticated_at';

    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $ttl = (int) config('eeo.reauthentication_ttl', 900);

        if (! is_int($confirmedAt) || $confirmedAt + $ttl < now()->getTimestamp()) {
            throw BusinessError::reauthenticationRequired();
        }

        return $next($request);
    }
}
