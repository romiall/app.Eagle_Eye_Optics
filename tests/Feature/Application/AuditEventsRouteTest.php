<?php

namespace Tests\Feature\Application;

use App\Domain\Access\AuditRecorder;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\Support\ValidatesContracts;
use Tests\TestCase;

/**
 * GET /api/v1/audit-events (F07, permission audit.read) : journal append-only en lecture seule, gérante
 * seulement, limité au cabinet, du plus récent au plus ancien, conforme au contrat publié par F06.
 */
final class AuditEventsRouteTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;
    use ValidatesContracts;

    private const FIRST_TARGET = '01J9ZXK4M2Q8R7S6T5V4W30026';

    private const SECOND_TARGET = '01J9ZXK4M2Q8R7S6T5V4W30027';

    private string $clinicId;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
    }

    public function test_la_gerante_consulte_le_journal_conformement_au_contrat(): void
    {
        $recorder = app(AuditRecorder::class);
        $recorder->record($this->clinicId, $this->manager, 'payment.registered', 'invoices', self::FIRST_TARGET, 'Acompte', ['amount' => ['avant' => 0, 'apres' => 30000]]);
        $recorder->record($this->clinicId, null, 'system.job', 'invoices', null, null, [], 'Système (rappel)');

        $response = $this->actingAsFresh($this->manager)->getJson('/api/v1/audit-events');

        $response->assertOk();
        $this->assertMatchesContract($response, 'schemas/resources/operations.json#/$defs/AuditEventPage');
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(50, $response->json('meta.per_page'));

        $payment = collect($response->json('data'))->firstWhere('action', 'payment.registered');
        $this->assertNotNull($payment);
        $this->assertSame($this->manager->id, $payment['actor_id']);
        $this->assertSame(self::FIRST_TARGET, $payment['target_id']);
        $this->assertSame('Acompte', $payment['reason']);
        $this->assertEquals(['amount' => ['avant' => 0, 'apres' => 30000]], $payment['sanitized_diff']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $payment['recorded_at']);

        $system = collect($response->json('data'))->firstWhere('action', 'system.job');
        $this->assertNotNull($system);
        $this->assertNull($system['actor_id']);
        $this->assertNull($system['sanitized_diff']);
        $this->assertSame('Système (rappel)', $system['actor_name_snapshot']);
    }

    public function test_seul_un_compte_avec_audit_read_y_accede(): void
    {
        $cashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');
        $clinician = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.test');

        foreach ([$cashier, $clinician] as $user) {
            $this->actingAsFresh($user)->getJson('/api/v1/audit-events')->assertForbidden()->assertJsonPath('error.code', 'ACTION_FORBIDDEN');
        }

        $this->flushSession();
        $this->nextRequest()->getJson('/api/v1/audit-events')->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_le_journal_d_un_autre_cabinet_n_est_jamais_visible(): void
    {
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $otherManager = $this->makeStaff($otherClinic, 'MANAGER', 'gerante.autre');
        app(AuditRecorder::class)->record($otherClinic, $otherManager, 'secret.autre.cabinet', 'invoices', null);
        app(AuditRecorder::class)->record($this->clinicId, $this->manager, 'visible.ici', 'invoices', null);

        $response = $this->actingAsFresh($this->manager)->getJson('/api/v1/audit-events')->assertOk();

        $this->assertSame(['visible.ici'], collect($response->json('data'))->pluck('action')->all());
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_les_filtres_et_l_ordre_du_plus_recent_au_plus_ancien(): void
    {
        $this->insertAudit('a.premier', 'invoices', self::FIRST_TARGET, '2026-10-14 08:00:00.000000');
        $this->insertAudit('a.deuxieme', 'payments', self::FIRST_TARGET, '2026-10-14 09:00:00.000000');
        $this->insertAudit('a.troisieme', 'invoices', self::SECOND_TARGET, '2026-10-14 10:00:00.000000');

        $expected = [
            '' => ['a.troisieme', 'a.deuxieme', 'a.premier'],
            '?target_type=invoices' => ['a.troisieme', 'a.premier'],
            '?target_id='.self::FIRST_TARGET => ['a.deuxieme', 'a.premier'],
            '?from=2026-10-14T08:30:00.000Z' => ['a.troisieme', 'a.deuxieme'],
            '?to=2026-10-14T09:30:00.000Z' => ['a.deuxieme', 'a.premier'],
            '?from=2026-10-14T08:30:00.000Z&to=2026-10-14T09:30:00.000Z' => ['a.deuxieme'],
            '?target_type=invoices&target_id='.self::FIRST_TARGET => ['a.premier'],
        ];

        foreach ($expected as $query => $actions) {
            $response = $this->actingAsFresh($this->manager)->getJson('/api/v1/audit-events'.$query)->assertOk();
            $this->assertSame($actions, collect($response->json('data'))->pluck('action')->all(), 'Filtre '.($query === '' ? '(aucun)' : $query));
        }
    }

    public function test_la_borne_to_inclut_toute_la_milliseconde_affichee(): void
    {
        // Constat de revue F07 : `recorded_at` garde les microsecondes, le contrat ne montre que les millisecondes.
        $this->insertAudit('a.microsecondes', 'invoices', self::FIRST_TARGET, '2026-10-14 10:00:00.123456');
        $this->insertAudit('a.suivant', 'invoices', self::FIRST_TARGET, '2026-10-14 10:00:00.124000');

        $response = $this->actingAsFresh($this->manager)->getJson('/api/v1/audit-events?to=2026-10-14T10:00:00.123Z')->assertOk();

        $this->assertSame(['a.microsecondes'], collect($response->json('data'))->pluck('action')->all());
        $this->assertSame('2026-10-14T10:00:00.123Z', $response->json('data.0.recorded_at'));
    }

    public function test_la_pagination_se_fait_par_pages_de_cinquante(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->insertAudit('lot.'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'invoices', null, '2026-10-14 08:00:00.'.str_pad((string) $i, 6, '0', STR_PAD_LEFT));
        }

        $first = $this->actingAsFresh($this->manager)->getJson('/api/v1/audit-events')->assertOk();
        $second = $this->actingAsFresh($this->manager)->getJson('/api/v1/audit-events?page=2')->assertOk();

        $this->assertCount(50, $first->json('data'));
        $this->assertCount(5, $second->json('data'));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 50, 'total' => 55], $first->json('meta'));
        $this->assertSame('lot.54', $first->json('data.0.action'), 'Le plus récent d\'abord.');
        $this->assertSame('lot.00', $second->json('data.4.action'));
    }

    public function test_des_parametres_invalides_sont_refuses(): void
    {
        foreach (['page=0', 'page=abc', 'from=hier', 'to=2026-10-14', 'target_id=pas-un-ulid', 'target_type='.str_repeat('x', 65)] as $query) {
            $this->actingAsFresh($this->manager)->getJson('/api/v1/audit-events?'.$query)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
    }

    private function insertAudit(string $action, string $targetType, ?string $targetId, string $recordedAt): void
    {
        $id = Ulid::generate();
        DB::table('audit_events')->insert([
            'id' => $id,
            'clinic_id' => $this->clinicId,
            'actor_id' => $this->manager->id,
            'actor_name_snapshot' => $this->manager->display_name,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'recorded_at' => $recordedAt,
            'correlation_id' => 'corr-test-audit',
            'event_hash' => hash('sha256', $id),
        ]);
    }
}
