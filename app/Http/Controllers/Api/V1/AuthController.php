<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Actions\AuthenticateStaff;
use App\Domain\Access\Actions\ChangeOwnPassword;
use App\Domain\Access\AuditRecorder;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentReauthentication;
use App\Http\Resources\StaffMemberPresenter;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Session SPA du personnel (Sanctum, cookie du seul sous-domaine + CSRF) : aucun jeton permanent dans
 * la PWA (contrat 23). Les contrôleurs vérifient le format et délèguent aux actions du domaine Accès.
 */
final class AuthController extends Controller
{
    public function login(Request $request, AuthenticateStaff $authenticate): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:180'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        // Sanctum n'ouvre la session que pour une origine déclarée (SANCTUM_STATEFUL_DOMAINS) : sans
        // session, une « connexion » ne survivrait pas à la requête. Refus explicite plutôt qu'un succès
        // trompeur (configuration à corriger, ou requête qui ne vient pas de l'application).
        if (! $request->hasSession()) {
            throw new BusinessError(
                'SESSION_REQUIRED',
                'La connexion doit venir de l\'application du cabinet (origine non reconnue : vérifier SANCTUM_STATEFUL_DOMAINS).',
                400,
            );
        }

        $user = $authenticate((string) $data['login'], (string) $data['password'], (string) $request->ip());

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget(RequireRecentReauthentication::SESSION_KEY);

        return new JsonResponse(['data' => StaffMemberPresenter::me($user)]);
    }

    public function logout(Request $request, AuditRecorder $audit): Response
    {
        $user = $this->user($request);
        $audit->record($user->clinic_id, $user, 'auth.logout', 'users', $user->id);

        Auth::guard('web')->logout();
        // Le garde Sanctum garde l'utilisateur en cache pour la requête : sans cet oubli, la nouvelle
        // session anonyme serait enregistrée avec le user_id du compte déconnecté (fw_sessions).
        $sanctum = Auth::guard('sanctum');
        if (method_exists($sanctum, 'forgetUser')) {
            $sanctum->forgetUser();
        }
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => StaffMemberPresenter::me($this->user($request))]);
    }

    public function reauthenticate(Request $request, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);
        $user = $this->user($request);

        if (! Hash::check((string) $data['password'], $user->password_hash)) {
            $audit->record($user->clinic_id, $user, 'auth.reauthentication_failed', 'users', $user->id);

            throw BusinessError::validation(['password' => ['Mot de passe incorrect.']], 'Mot de passe incorrect.');
        }

        $now = now()->getTimestamp();
        if ($request->hasSession()) {
            $request->session()->put(RequireRecentReauthentication::SESSION_KEY, $now);
        }
        $audit->record($user->clinic_id, $user, 'auth.reauthenticated', 'users', $user->id);

        $ttl = (int) config('eeo.reauthentication_ttl', 900);

        return new JsonResponse(['data' => [
            'reauthenticated_until' => now('UTC')->setTimestamp($now + $ttl)->format('Y-m-d\TH:i:s.v\Z'),
        ]]);
    }

    public function changePassword(Request $request, ChangeOwnPassword $change): Response
    {
        $user = $this->user($request);
        $change($user, $request->all(), $request->hasSession() ? $request->session()->getId() : null);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
