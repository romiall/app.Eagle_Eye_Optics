<?php

namespace Tests\Unit\Access;

use App\Domain\Access\PermissionResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Règle pure des permissions effectives : rôles non révoqués, dérogations non expirées (refus prioritaire),
 * compte désactivé sans aucune permission, codes inconnus ignorés.
 */
final class PermissionResolverTest extends TestCase
{
    private const KNOWN = ['clinical.read', 'exam_fee.collect', 'invoice.issue', 'patient.read', 'payment.collect'];

    private const ROLE_PERMISSIONS = [
        'CASHIER' => ['patient.read', 'invoice.issue', 'payment.collect'],
        'CLINICIAN' => ['patient.read', 'clinical.read'],
        'RECEPTION' => ['patient.read'],
    ];

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC');
    }

    /**
     * @param  list<array{role: string, assigned_at: CarbonImmutable, revoked_at: ?CarbonImmutable}>  $roles
     * @param  list<array{permission: string, allowed: bool, expires_at: ?CarbonImmutable}>  $overrides
     * @return list<string>
     */
    private function resolve(array $roles, array $overrides = [], bool $active = true): array
    {
        return PermissionResolver::resolve($active, $roles, self::ROLE_PERMISSIONS, $overrides, self::KNOWN, $this->now);
    }

    /**
     * @return array{role: string, assigned_at: CarbonImmutable, revoked_at: ?CarbonImmutable}
     */
    private function role(string $role, ?string $revokedAt = null, string $assignedAt = '2026-01-01 00:00:00'): array
    {
        return [
            'role' => $role,
            'assigned_at' => CarbonImmutable::parse($assignedAt, 'UTC'),
            'revoked_at' => $revokedAt === null ? null : CarbonImmutable::parse($revokedAt, 'UTC'),
        ];
    }

    public function test_un_role_actif_donne_ses_permissions_triees(): void
    {
        $this->assertSame(['invoice.issue', 'patient.read', 'payment.collect'], $this->resolve([$this->role('CASHIER')]));
    }

    public function test_plusieurs_roles_s_additionnent_sans_doublon(): void
    {
        $this->assertSame(
            ['clinical.read', 'invoice.issue', 'patient.read', 'payment.collect'],
            $this->resolve([$this->role('CASHIER'), $this->role('CLINICIAN')]),
        );
    }

    public function test_un_role_revoque_ou_futur_ne_compte_pas(): void
    {
        $this->assertSame([], $this->resolve([$this->role('CASHIER', '2026-10-14 09:59:59')]));
        $this->assertSame(['invoice.issue', 'patient.read', 'payment.collect'], $this->resolve([$this->role('CASHIER', '2026-10-14 10:00:01')]));
        $this->assertSame([], $this->resolve([$this->role('CASHIER', null, '2026-10-15 00:00:00')]));
    }

    public function test_un_compte_desactive_n_a_aucune_permission_meme_avec_derogation(): void
    {
        $overrides = [['permission' => 'exam_fee.collect', 'allowed' => true, 'expires_at' => null]];

        $this->assertSame([], $this->resolve([$this->role('CASHIER')], $overrides, false));
    }

    public function test_une_derogation_refus_l_emporte_sur_le_role(): void
    {
        $overrides = [['permission' => 'payment.collect', 'allowed' => false, 'expires_at' => null]];

        $this->assertSame(['invoice.issue', 'patient.read'], $this->resolve([$this->role('CASHIER')], $overrides));
    }

    public function test_une_derogation_autorisation_s_ajoute_jusqu_a_son_expiration(): void
    {
        $active = [['permission' => 'exam_fee.collect', 'allowed' => true, 'expires_at' => CarbonImmutable::parse('2026-10-14 10:00:01', 'UTC')]];
        $expired = [['permission' => 'exam_fee.collect', 'allowed' => true, 'expires_at' => CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC')]];

        $this->assertSame(['exam_fee.collect', 'patient.read'], $this->resolve([$this->role('RECEPTION')], $active));
        $this->assertSame(['patient.read'], $this->resolve([$this->role('RECEPTION')], $expired));
    }

    public function test_un_code_hors_referentiel_est_ignore(): void
    {
        $overrides = [['permission' => 'admin.everything', 'allowed' => true, 'expires_at' => null]];

        $this->assertSame(['patient.read'], $this->resolve([$this->role('RECEPTION')], $overrides));
    }
}
