<?php

namespace Tests\Feature\Patients;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\Support\PatientFixtures;
use Tests\Support\ValidatesContracts;
use Tests\TestCase;

/**
 * Visites et file du jour (P03, AT05) : arrivée enregistrée avec son motif, statut décidé par le serveur,
 * jour civil du fuseau du cabinet, identité administrative seulement, droits par rôle, isolation entre
 * cabinets. Le contrôle du paiement de l'examen n'existe pas encore : aucun statut ne le laisse croire.
 * Données fictives.
 */
final class VisitTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;
    use PatientFixtures;
    use ValidatesContracts;

    private string $clinicId;

    private User $reception;

    private string $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['eeo.enabled_modules' => ['today', 'patients']]);
        $this->clinicId = $this->makeClinic();
        $this->reception = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->patientId = $this->seedPatient($this->clinicId);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'expected_version' => null,
            'visit_id' => Ulid::generate(),
            'patient_id' => $this->patientId,
            'reason' => 'EXAM',
            'occurred_at' => '2026-10-22T09:15:00.000Z',
            'appointment_id' => null,
            'administrative_note' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function register(array $body, ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->reception)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->postJson('/api/v1/visits', $body);
    }

    private function queue(string $query = '', ?User $as = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->reception)->getJson('/api/v1/visits'.$query);
    }

    public function test_une_arrivee_enregistre_la_visite_avec_le_statut_decide_par_le_serveur(): void
    {
        $body = $this->body(['administrative_note' => '  Vient pour un contrôle  ']);

        $response = $this->register($body);

        $response->assertCreated()
            ->assertJsonPath('data.id', $body['visit_id'])
            ->assertJsonPath('data.status', 'ARRIVED')
            ->assertJsonPath('data.reason', 'EXAM')
            ->assertJsonPath('data.occurred_at', '2026-10-22T09:15:00.000Z')
            ->assertJsonPath('data.arrival_at', '2026-10-22T09:15:00.000Z')
            ->assertJsonPath('data.administrative_note', 'Vient pour un contrôle')
            ->assertJsonPath('data.exam_invoice_id', null)
            ->assertJsonPath('data.exam_waiver_approval_id', null)
            ->assertJsonPath('data.patient.last_name', 'Patient Fictif')
            ->assertJsonPath('data.patient.record_number', 'EE-2026-0001')
            ->assertJsonPath('data.version', 1);
        $this->assertMatchesContract($response, 'schemas/resources/patients.json#/$defs/VisitResponse');

        $this->assertSame(1, DB::table('audit_events')->where('action', 'visit.registered')->where('target_id', $body['visit_id'])->count());
        $this->assertSame(1, DB::table('sync_change_log')->where('entity_type', 'visits')->where('entity_id', $body['visit_id'])->count());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function reasons(): array
    {
        return [
            'examen' => ['EXAM', 'NOT_CONTROLLED'],
            'suivi' => ['FOLLOW_UP', 'NOT_CONTROLLED'],
            'achat direct' => ['DIRECT_PURCHASE', 'NOT_APPLICABLE'],
            'retrait' => ['PICKUP', 'NOT_APPLICABLE'],
        ];
    }

    #[DataProvider('reasons')]
    public function test_le_paiement_de_l_examen_n_est_jamais_dit_controle_dans_ce_jalon(string $reason, string $marker): void
    {
        $response = $this->register($this->body(['reason' => $reason]));

        $response->assertCreated()->assertJsonPath('data.exam_fee_control', $marker)->assertJsonPath('data.status', 'ARRIVED');
        $this->assertSame(0, DB::table('visits')->whereIn('status', ['AWAITING_EXAM_FEE', 'READY_FOR_EXAM'])->count(), 'Aucun statut ne suggère un contrôle inexistant.');
    }

    public function test_un_achat_ne_cree_jamais_de_consultation(): void
    {
        $this->register($this->body(['reason' => 'DIRECT_PURCHASE']))->assertCreated();

        $this->assertSame(0, DB::table('consultations')->count());
    }

    public function test_un_rejeu_de_la_meme_cle_ne_cree_qu_une_visite(): void
    {
        $body = $this->body();
        $key = Ulid::generate();

        $first = $this->register($body, null, $key)->assertCreated();
        $replay = $this->register($body, null, $key)->assertCreated();

        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(1, DB::table('visits')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_une_autre_visite_du_meme_patient_le_meme_jour_ne_bloque_pas(): void
    {
        $this->register($this->body())->assertCreated();
        $this->register($this->body(['reason' => 'DIRECT_PURCHASE']))->assertCreated();

        $this->assertSame(2, DB::table('visits')->where('patient_id', $this->patientId)->count());
    }

    public function test_un_patient_inconnu_d_un_autre_cabinet_archive_ou_fusionne_est_refuse(): void
    {
        $otherClinic = $this->makeClinic();
        $this->makeStaff($otherClinic, 'MANAGER', 'autre.gerante');
        $foreign = $this->seedPatient($otherClinic);
        $archived = $this->seedPatient($this->clinicId, 'Archivée', null, ['archived_at' => '2026-10-01 08:00:00.000000']);
        $merged = $this->seedPatient($this->clinicId, 'Fusionnée', null, ['merged_into_patient_id' => $this->patientId]);

        foreach ([Ulid::generate(), $foreign, $archived, $merged] as $patientId) {
            $this->register($this->body(['patient_id' => $patientId]))
                ->assertStatus(422)
                ->assertJsonStructure(['error' => ['fields' => ['patient_id']]]);
        }
        $this->assertSame(0, DB::table('visits')->count());
    }

    public function test_un_identifiant_de_visite_deja_pris_est_refuse_sans_erreur_sql(): void
    {
        $body = $this->body();
        $this->register($body)->assertCreated();

        $this->register($body)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['visit_id']]]);
    }

    public function test_un_rendez_vous_inconnu_est_refuse(): void
    {
        $this->register($this->body(['appointment_id' => Ulid::generate()]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['appointment_id']]]);
    }

    public function test_l_heure_d_arrivee_future_est_refusee_et_la_tolerance_d_horloge_acceptee(): void
    {
        config(['eeo.offline.max_clock_skew_seconds' => 300]);

        $this->register($this->body(['occurred_at' => '2026-10-22T09:34:00.000Z']))->assertCreated();
        $this->register($this->body(['occurred_at' => '2026-10-22T09:36:00.000Z']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['occurred_at']]]);
        $this->register($this->body(['occurred_at' => '2026-10-21T16:00:00.000Z']))->assertCreated();
    }

    public function test_le_corps_de_la_route_directe_est_valide_contre_son_contrat(): void
    {
        $this->register($this->body(['reason' => 'AUTRE']))->assertStatus(422);
        $this->register($this->body(['expected_version' => 2]))->assertStatus(422);
        $this->register($this->body(['status' => 'READY_FOR_EXAM']))->assertStatus(422);
        $this->register($this->body(['occurred_at' => '22/10/2026 09:15']))->assertStatus(422);

        $this->assertSame(0, DB::table('visits')->count());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function registrationRoles(): array
    {
        return ['gérante' => ['MANAGER', 201], 'accueil' => ['RECEPTION', 201], 'professionnel' => ['CLINICIAN', 201], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('registrationRoles')]
    public function test_les_droits_d_enregistrement_suivent_visit_register(string $role, int $status): void
    {
        $this->register($this->body(), $this->makeStaff($this->clinicId, $role))->assertStatus($status);
    }

    public function test_la_file_du_jour_suit_le_jour_civil_du_cabinet_et_non_minuit_utc(): void
    {
        // Douala = UTC+1 : le 22 octobre local va du 21 octobre 23 h UTC au 22 octobre 23 h UTC (exclu).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC'));
        foreach ([
            '2026-10-21T22:59:59.000Z' => 'hier',
            '2026-10-21T23:00:00.000Z' => 'ouverture',
            '2026-10-22T08:00:00.000Z' => 'matin',
            '2026-10-22T22:59:59.000Z' => 'dernière seconde',
            '2026-10-22T23:00:00.000Z' => 'demain',
        ] as $instant => $label) {
            $this->register($this->body(['occurred_at' => $instant, 'administrative_note' => $label]), null)->assertCreated();
        }

        $today = $this->queue('?date=2026-10-22')->assertOk();
        $this->assertSame(['ouverture', 'matin', 'dernière seconde'], array_column($today->json('data'), 'administrative_note'));
        $this->assertMatchesContract($today, 'schemas/resources/patients.json#/$defs/VisitPage');

        $this->assertSame(['hier'], array_column($this->queue('?date=2026-10-21')->json('data'), 'administrative_note'));
        $this->assertSame(['demain'], array_column($this->queue('?date=2026-10-23')->json('data'), 'administrative_note'));
    }

    public function test_sans_date_la_file_est_celle_d_aujourd_hui_au_fuseau_du_cabinet(): void
    {
        // 31 décembre, 23 h 30 UTC = 1er janvier, 00 h 30 à Douala.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC'));
        $this->register($this->body(['occurred_at' => '2026-12-31T23:20:00.000Z', 'administrative_note' => 'nouvel an']))->assertCreated();

        $this->assertSame(['nouvel an'], array_column($this->queue()->json('data'), 'administrative_note'));
    }

    public function test_la_file_est_triee_par_arrivee_et_limitee_au_cabinet(): void
    {
        $this->register($this->body(['occurred_at' => '2026-10-22T09:10:00.000Z', 'administrative_note' => 'second']))->assertCreated();
        $this->register($this->body(['occurred_at' => '2026-10-22T08:00:00.000Z', 'administrative_note' => 'premier']))->assertCreated();

        $otherClinic = $this->makeClinic();
        $otherManager = $this->makeStaff($otherClinic, 'MANAGER', 'autre.gerante');
        $foreignPatient = $this->seedPatient($otherClinic);
        $this->register($this->body(['patient_id' => $foreignPatient, 'administrative_note' => 'étranger']), $otherManager)->assertCreated();

        $this->assertSame(['premier', 'second'], array_column($this->queue()->json('data'), 'administrative_note'));
    }

    public function test_la_file_porte_l_identite_de_base_et_aucune_donnee_clinique(): void
    {
        $this->register($this->body())->assertCreated();

        $row = $this->queue()->json('data.0');

        $this->assertSame(['id', 'record_number', 'last_name', 'first_names', 'sex', 'birth_date'], array_keys($row['patient']));
        $this->assertArrayNotHasKey('address', $row['patient']);
        $this->assertArrayNotHasKey('consultation_id', $row);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function queueRoles(): array
    {
        return ['gérante' => ['MANAGER', 200], 'accueil' => ['RECEPTION', 200], 'professionnel' => ['CLINICIAN', 200], 'caisse' => ['CASHIER', 200]];
    }

    #[DataProvider('queueRoles')]
    public function test_la_file_se_lit_avec_visit_register_ou_agenda_read(string $role, int $status): void
    {
        $this->queue('', $this->makeStaff($this->clinicId, $role))->assertStatus($status);
    }

    public function test_sans_aucune_des_deux_permissions_la_file_est_refusee(): void
    {
        $user = $this->makeStaff($this->clinicId, 'CASHIER');
        // Dérogation nominative : la caisse perd agenda.read (visit.register ne lui a jamais été donné).
        DB::table('user_permissions')->insert([
            'id' => Ulid::generate(),
            'clinic_id' => $this->clinicId,
            'user_id' => $user->id,
            'permission_code' => 'agenda.read',
            'allowed' => false,
            'assigned_by' => $this->reception->id,
            'reason' => 'Test',
            'expires_at' => null,
            'created_at' => '2026-01-01 00:00:00.000000',
            'updated_at' => '2026-01-01 00:00:00.000000',
        ]);

        $this->queue('', $user)->assertStatus(403)->assertJsonPath('error.code', 'ACTION_FORBIDDEN');
    }

    public function test_une_date_ou_une_page_invalide_est_refusee(): void
    {
        foreach (['?date=2026-02-30', '?date=22/10/2026', '?date=abc', '?page=0', '?page=x'] as $query) {
            $this->queue($query)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
    }

    public function test_un_module_ferme_repond_404(): void
    {
        config(['eeo.enabled_modules' => ['patients']]);

        $this->queue()->assertNotFound();
        $this->register($this->body())->assertNotFound();
    }

    public function test_sans_connexion_401(): void
    {
        $this->getJson('/api/v1/visits')->assertStatus(401);
    }
}
