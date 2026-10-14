<?php

namespace App\Domain\Access;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Permissions effectives d'un compte, relues en base à chaque requête (instance « scoped ») : une
 * désactivation, une révocation ou une dérogation s'applique dès la requête suivante.
 */
final class EffectivePermissions
{
    /** @var array<string, list<string>> */
    private array $resolved = [];

    /** @var list<string>|null */
    private ?array $known = null;

    /**
     * @return list<string>
     */
    public function for(User $user): array
    {
        return $this->resolved[$user->getKey()] ??= $this->load($user);
    }

    public function has(User $user, string $permission): bool
    {
        return in_array($permission, $this->for($user), true);
    }

    public function isKnown(string $permission): bool
    {
        return in_array($permission, $this->known(), true);
    }

    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->resolved = [];

            return;
        }
        unset($this->resolved[$user->getKey()]);
    }

    /**
     * @return list<string>
     */
    public function known(): array
    {
        return $this->known ??= array_values(DB::table('permissions')->orderBy('code')->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)->all());
    }

    /**
     * @return list<string>
     */
    private function load(User $user): array
    {
        $assignments = array_values(DB::table('user_roles')
            ->where('clinic_id', $user->clinic_id)
            ->where('user_id', $user->getKey())
            ->get(['role_code', 'assigned_at', 'revoked_at'])
            ->map(static fn (object $row): array => [
                'role' => (string) $row->role_code,
                'assigned_at' => CarbonImmutable::parse((string) $row->assigned_at, 'UTC'),
                'revoked_at' => $row->revoked_at === null ? null : CarbonImmutable::parse((string) $row->revoked_at, 'UTC'),
            ])->all());

        $rolePermissions = [];
        foreach (DB::table('role_permissions')->get(['role_code', 'permission_code']) as $row) {
            $rolePermissions[(string) $row->role_code][] = (string) $row->permission_code;
        }

        $overrides = array_values(DB::table('user_permissions')
            ->where('clinic_id', $user->clinic_id)
            ->where('user_id', $user->getKey())
            ->get(['permission_code', 'allowed', 'expires_at'])
            ->map(static fn (object $row): array => [
                'permission' => (string) $row->permission_code,
                'allowed' => (bool) $row->allowed,
                'expires_at' => $row->expires_at === null ? null : CarbonImmutable::parse((string) $row->expires_at, 'UTC'),
            ])->all());

        return PermissionResolver::resolve(
            (bool) $user->active,
            $assignments,
            $rolePermissions,
            $overrides,
            $this->known(),
            CarbonImmutable::now('UTC'),
        );
    }
}
