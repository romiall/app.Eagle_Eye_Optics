<?php

namespace Tests\Unit\Application;

use App\Application\Authorization\AuthorizationDecision;
use App\Application\Authorization\HistoricalAuthorization;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\GrantSnapshot;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Contexte d'autorisation HISTORIQUE d'une commande de poste (contrat 23) : ce que le profil pouvait faire quand
 * le poste a agi. Une révocation d'aujourd'hui ne détruit pas une pièce valable hier ; les cas ambigus passent
 * en revue plutôt que d'être acceptés ou rejetés en silence. Fonction pure : aucune base, aucune horloge.
 */
final class HistoricalAuthorizationTest extends TestCase
{
    private const CLINIC = '01J9ZXK4M2Q8R7S6T5V4W30003';

    private const DEVICE = '01J9ZXK4M2Q8R7S6T5V4W30001';

    private const USER = '01J9ZXK4M2Q8R7S6T5V4W30002';

    private const SKEW = 300;

    public function test_une_action_dans_la_fenetre_du_grant_est_accordee(): void
    {
        $this->assertDecision(AuthorizationDecision::Allowed, $this->grant(), $this->context());
    }

    public function test_une_revocation_posterieure_n_invalide_pas_une_action_anterieure(): void
    {
        // Action à 12:00, grant révoqué à 14:00, poste jamais resynchronisé depuis 08:30 : la pièce d'hier reste valable.
        $grant = $this->grant(revokedAt: '2026-10-14 14:00:00');

        $this->assertDecision(AuthorizationDecision::Allowed, $grant, $this->context());
    }

    public function test_un_poste_qui_connaissait_la_revocation_est_refuse(): void
    {
        // Dernière synchro à 14:30, après la révocation de 14:00 : le poste savait.
        $grant = $this->grant(revokedAt: '2026-10-14 14:00:00');
        $context = $this->context(occurred: '2026-10-14 14:40:00', lastServer: '2026-10-14 14:30:00', now: '2026-10-14 15:00:00');

        $this->assertDecision(AuthorizationDecision::Denied, $grant, $context);
    }

    public function test_une_action_autour_de_la_revocation_sans_synchro_passe_en_revue(): void
    {
        $grant = $this->grant(revokedAt: '2026-10-14 12:00:00');

        // Pile à la révocation (tolérance comprise) puis nettement après, le poste n'ayant pas resynchronisé.
        $this->assertDecision(AuthorizationDecision::NeedsReview, $grant, $this->context(occurred: '2026-10-14 12:02:00'));
        $this->assertDecision(AuthorizationDecision::NeedsReview, $grant, $this->context(occurred: '2026-10-14 13:00:00'));
    }

    public function test_un_grant_d_un_autre_appareil_ou_d_un_autre_auteur_est_refuse(): void
    {
        $this->assertDecision(AuthorizationDecision::Denied, $this->grant(device: '01J9ZXK4M2Q8R7S6T5V4W30009'), $this->context());
        $this->assertDecision(AuthorizationDecision::Denied, $this->grant(user: '01J9ZXK4M2Q8R7S6T5V4W30008'), $this->context());
    }

    public function test_une_permission_absente_du_grant_est_refusee_meme_si_le_compte_l_a_aujourd_hui(): void
    {
        $this->assertDecision(AuthorizationDecision::Denied, $this->grant(permissions: ['patient.create']), $this->context());
        $this->assertDecision(AuthorizationDecision::Denied, $this->grant(permissions: []), $this->context());
    }

    public function test_une_commande_sans_permission_declaree_est_refusee(): void
    {
        $verdict = HistoricalAuthorization::decide($this->grant(), $this->context(), [], self::SKEW);

        $this->assertSame(AuthorizationDecision::Denied, $verdict->decision);
    }

    public function test_l_une_des_permissions_de_la_commande_suffit(): void
    {
        $verdict = HistoricalAuthorization::decide($this->grant(permissions: ['invoice.issue']), $this->context(), ['payment.collect', 'invoice.issue'], self::SKEW);

        $this->assertSame(AuthorizationDecision::Allowed, $verdict->decision);
    }

    public function test_une_horloge_en_retard_sur_la_derniere_heure_serveur_connue_passe_en_revue(): void
    {
        // Le poste connaissait 08:30 mais déclare avoir agi à 07:00 : antidatage possible.
        $this->assertDecision(AuthorizationDecision::NeedsReview, $this->grant(), $this->context(occurred: '2026-10-14 07:00:00'));
    }

    public function test_une_horloge_en_avance_sur_le_serveur_passe_en_revue(): void
    {
        $this->assertDecision(AuthorizationDecision::NeedsReview, $this->grant(), $this->context(occurred: '2026-10-14 16:00:00', now: '2026-10-14 15:00:00'));
    }

    public function test_une_action_anterieure_a_l_emission_du_grant_passe_en_revue(): void
    {
        $context = $this->context(occurred: '2026-10-14 07:00:00', lastServer: '2026-10-14 06:00:00', now: '2026-10-14 15:00:00');

        $this->assertDecision(AuthorizationDecision::NeedsReview, $this->grant(issuedAt: '2026-10-14 08:00:00'), $context);
    }

    public function test_une_action_apres_l_expiration_est_refusee_et_la_tolerance_d_horloge_est_respectee(): void
    {
        $grant = $this->grant(validUntil: '2026-10-14 12:00:00');

        // 12:04 : dans la tolérance de 5 minutes ; 12:06 : au-delà.
        $this->assertDecision(AuthorizationDecision::Allowed, $grant, $this->context(occurred: '2026-10-14 12:04:00', now: '2026-10-14 13:00:00'));
        $this->assertDecision(AuthorizationDecision::Denied, $grant, $this->context(occurred: '2026-10-14 12:06:00', now: '2026-10-14 13:00:00'));
    }

    public function test_un_contexte_de_poste_incomplet_est_refuse(): void
    {
        $context = CommandContext::job(self::CLINIC, 'test', 'corr-job-0001');

        $this->assertSame(AuthorizationDecision::Denied, HistoricalAuthorization::decide($this->grant(), $context, ['payment.collect'], self::SKEW)->decision);
    }

    private function assertDecision(AuthorizationDecision $expected, GrantSnapshot $grant, CommandContext $context): void
    {
        $verdict = HistoricalAuthorization::decide($grant, $context, ['payment.collect'], self::SKEW);

        $this->assertSame($expected, $verdict->decision, $verdict->reason);
        $this->assertNotSame('', $verdict->reason);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function grant(
        string $device = self::DEVICE,
        string $user = self::USER,
        array $permissions = ['payment.collect', 'invoice.issue'],
        string $issuedAt = '2026-10-14 08:00:00',
        string $validUntil = '2026-10-14 20:00:00',
        ?string $revokedAt = null,
    ): GrantSnapshot {
        return new GrantSnapshot(
            '01J9ZXK4M2Q8R7S6T5V4W30004', $device, $user, $permissions, 'rules-1', 'catalog-1',
            CarbonImmutable::parse($issuedAt, 'UTC'), CarbonImmutable::parse($validUntil, 'UTC'),
            $revokedAt === null ? null : CarbonImmutable::parse($revokedAt, 'UTC'),
        );
    }

    private function context(
        string $occurred = '2026-10-14 12:00:00',
        string $lastServer = '2026-10-14 08:30:00',
        string $now = '2026-10-14 15:00:00',
    ): CommandContext {
        return CommandContext::sync(
            self::CLINIC, self::DEVICE, self::USER, 'Profil de test',
            $this->grant(), 'corr-sync-0001',
            CarbonImmutable::parse($occurred, 'UTC'), CarbonImmutable::parse($lastServer, 'UTC'), CarbonImmutable::parse($now, 'UTC'),
        );
    }
}
