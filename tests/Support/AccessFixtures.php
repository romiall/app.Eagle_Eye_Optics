<?php

namespace Tests\Support;

use App\Domain\Access\StaffRoles;
use App\Domain\Shared\Ulid;
use App\Http\Middleware\RequireRecentReauthentication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * Cabinets et comptes FICTIFS pour les tests d'accès. Le mot de passe ci-dessous n'existe que dans les
 * tests (jamais dans l'application ni en production).
 */
trait AccessFixtures
{
    protected const TEST_PASSWORD = 'MotDePasseTest2026';

    protected function makeClinic(string $name = 'TEST CABINET A'): string
    {
        $id = Ulid::generate();
        DB::table('clinics')->insert(['id' => $id, 'name' => $name]);

        return $id;
    }

    protected function makeStaff(string $clinicId, string $role, ?string $login = null, bool $active = true, string $password = self::TEST_PASSWORD): User
    {
        $id = Ulid::generate();
        $login ??= strtolower($role).'.'.strtolower(substr($id, -6));

        DB::table('users')->insert([
            'id' => $id,
            'clinic_id' => $clinicId,
            'display_name' => 'TEST '.$role,
            'login' => $login,
            'password_hash' => Hash::make($password),
            'active' => $active,
        ]);

        $assigner = DB::table('user_roles')->where('clinic_id', $clinicId)->where('role_code', StaffRoles::MANAGER)->value('user_id');
        StaffRoles::sync($clinicId, $id, [$role], is_string($assigner) ? $assigner : $id);

        /** @var User */
        return User::query()->where('clinic_id', $clinicId)->findOrFail($id);
    }

    /**
     * Requêtes « venant de la SPA » : Sanctum active alors la session (cookie + CSRF) comme en production.
     */
    protected function fromSpa(): static
    {
        return $this->withHeaders(['Referer' => 'http://localhost/', 'Accept' => 'application/json']);
    }

    protected function login(string $login, string $password = self::TEST_PASSWORD): TestResponse
    {
        return $this->fromSpa()->postJson('/api/v1/auth/login', ['login' => $login, 'password' => $password]);
    }

    /**
     * Agit comme un autre compte dans une session neuve. En test, plusieurs « requêtes » partagent un même
     * processus : sans cela, la session et le garde Sanctum de la requête précédente seraient réutilisés
     * (et AuthenticateSession fermerait à juste titre une session dont le hash de mot de passe change).
     */
    protected function actingAsFresh(User $user): static
    {
        $this->flushSession();
        $this->forgetRequestGuards();

        return $this->fromSpa()->actingAs($user);
    }

    protected function actingAsReauthenticated(User $user): static
    {
        return $this->actingAsFresh($user)
            ->withSession([RequireRecentReauthentication::SESSION_KEY => CarbonImmutable::now()->getTimestamp()]);
    }

    /**
     * Requête suivante d'un vrai navigateur : nouveaux objets de garde, même cookie de session.
     */
    protected function nextRequest(): static
    {
        $this->forgetRequestGuards();

        return $this->fromSpa();
    }

    /**
     * En production, chaque requête part d'une application neuve. En test, l'application persiste : les
     * gardes et le singleton « auth.driver » (contrat Guard, utilisé par exemple par le gestionnaire de
     * sessions « database ») garderaient l'utilisateur de la requête précédente.
     */
    private function forgetRequestGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
    }

    protected function auditCount(string $action, ?string $targetId = null): int
    {
        $query = DB::table('audit_events')->where('action', $action);
        if ($targetId !== null) {
            $query->where('target_id', $targetId);
        }

        return $query->count();
    }
}
