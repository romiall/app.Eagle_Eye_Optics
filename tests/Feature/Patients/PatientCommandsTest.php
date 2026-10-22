<?php

namespace Tests\Feature\Patients;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandBusProbe;
use App\Application\Locking\TransactionLocks;
use App\Domain\Patients\Handlers\CreatePatientHandler;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccessFixtures;
use Tests\Support\Commands\RecordingProbe;
use Tests\Support\MigratedDatabase;
use Tests\Support\ValidatesContracts;
use Tests\TestCase;

/**
 * Création et modification de patients (P01, AT03, AT04) sur MySQL réel : numéro central `EE-AAAA-NNNN` sans trou,
 * idempotence, version attendue, état résultant revalidé, droits par rôle, isolation entre cabinets, audit,
 * événement et journal des changements. Données fictives.
 */
final class PatientCommandsTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;
    use ValidatesContracts;

    private string $clinicId;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée AVANT la création des comptes : l'attribution d'un profil porte l'instant courant et une
        // permission n'est effective qu'à partir de cet instant (une horloge figée dans le passé les rendrait futures).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:30:00', 'UTC'));
        config(['eeo.enabled_modules' => ['patients']]);
        $this->clinicId = $this->makeClinic();
        $this->reception = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Corps de la route directe POST /patients (DirectRequest), données fictives.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'expected_version' => null,
            'patient_id' => Ulid::generate(),
            'record_number_usage_id' => Ulid::generate(),
            'record_number' => null,
            'last_name' => 'Patient Fictif',
            'first_names' => 'Test',
            'sex' => 'F',
            'birth_date' => '1990-05-12',
            'birth_date_missing_reason' => null,
            'phone_primary' => '+237 600 00 00 01',
            'phone_secondary' => null,
            'phone_missing_reason' => null,
            'address' => null,
            'profession' => null,
            'guardian_contact' => null,
            'legacy_record' => false,
            'first_known_visit_on' => null,
            'first_visit_certified' => false,
            'legacy_record_number' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function createPatient(array $body, ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->reception)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->postJson('/api/v1/patients', $body);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updatePatient(string $id, int $version, array $changes, ?User $as = null, ?string $key = null): TestResponse
    {
        return $this->actingAsFresh($as ?? $this->reception)
            ->withHeaders(['Idempotency-Key' => $key ?? Ulid::generate()])
            ->patchJson('/api/v1/patients/'.$id, ['expected_version' => $version, 'patient_id' => $id, 'changes' => $changes]);
    }

    public function test_une_creation_attribue_le_numero_central_et_ecrit_tous_les_effets(): void
    {
        $body = $this->body(['last_name' => "  N'Guessan-Kouamé ", 'first_names' => 'Élodie']);

        $response = $this->createPatient($body);

        $response->assertCreated()
            ->assertJsonPath('data.id', $body['patient_id'])
            ->assertJsonPath('data.record_number', 'EE-2026-0001')
            ->assertJsonPath('data.last_name', "N'Guessan-Kouamé")
            ->assertJsonPath('data.version', 1);
        $this->assertMatchesContract($response, 'schemas/resources/patients.json#/$defs/PatientResponse');

        $row = DB::table('patient_records')->where('id', $body['patient_id'])->first();
        $this->assertNotNull($row);
        $this->assertSame($this->clinicId, $row->clinic_id);
        $this->assertSame('n guessan kouame elodie', $row->name_search, 'La clé de recherche est recalculée par le serveur.');
        $this->assertSame('+237600000001', $row->phone_primary_normalized);
        $this->assertSame('+237 600 00 00 01', $row->phone_primary, 'Le téléphone saisi est conservé tel quel.');
        $this->assertSame($this->reception->id, $row->created_by);

        $sequence = DB::table('document_sequences')->where('clinic_id', $this->clinicId)->where('document_type', 'PATIENT')->first();
        $this->assertNotNull($sequence);
        $this->assertSame(2026, (int) $sequence->document_year);
        $this->assertSame(1, (int) $sequence->last_value);

        $usage = DB::table('document_number_usages')->where('id', $body['record_number_usage_id'])->first();
        $this->assertNotNull($usage);
        $this->assertSame('EE-2026-0001', $usage->rendered_number);
        $this->assertSame('USED', $usage->status);
        $this->assertNull($usage->allocation_id);
        $this->assertSame($body['patient_id'], $usage->entity_id);

        $audit = DB::table('audit_events')->where('action', 'patient.created')->where('target_id', $body['patient_id'])->first();
        $this->assertNotNull($audit, 'Audit de la création.');
        $this->assertSame($this->reception->id, $audit->actor_id);

        $event = DB::table('outbox_events')->where('event_type', 'PatientCreated')->first();
        $this->assertNotNull($event);
        $this->assertSame(1, DB::table('sync_change_log')->where('entity_type', 'patient_records')->where('entity_id', $body['patient_id'])->count());
    }

    public function test_les_numeros_se_suivent_sans_trou_et_depassent_quatre_chiffres_sans_troncature(): void
    {
        $this->createPatient($this->body())->assertCreated()->assertJsonPath('data.record_number', 'EE-2026-0001');
        $this->createPatient($this->body())->assertCreated()->assertJsonPath('data.record_number', 'EE-2026-0002');

        DB::table('document_sequences')->where('clinic_id', $this->clinicId)->update(['last_value' => 9999]);

        $this->createPatient($this->body())->assertCreated()->assertJsonPath('data.record_number', 'EE-2026-10000');
    }

    public function test_l_annee_du_numero_suit_le_fuseau_du_cabinet_et_non_utc(): void
    {
        // 31 décembre 2026, 23 h 30 UTC = 1er janvier 2027, 00 h 30 à Douala (UTC+1).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC'));

        $this->createPatient($this->body())->assertCreated()->assertJsonPath('data.record_number', 'EE-2027-0001');
        $this->assertSame(2027, (int) DB::table('document_sequences')->where('clinic_id', $this->clinicId)->value('document_year'));
    }

    public function test_un_rejeu_de_la_meme_cle_ne_cree_ni_second_patient_ni_second_numero(): void
    {
        $body = $this->body();
        $key = Ulid::generate();

        $first = $this->createPatient($body, null, $key)->assertCreated();
        $replay = $this->createPatient($body, null, $key)->assertCreated();

        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(1, DB::table('patient_records')->where('clinic_id', $this->clinicId)->count());
        $this->assertSame(1, (int) DB::table('document_sequences')->where('clinic_id', $this->clinicId)->value('last_value'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'patient.created')->count());
    }

    public function test_la_meme_cle_avec_un_autre_contenu_est_refusee_et_rien_n_est_ecrit(): void
    {
        $key = Ulid::generate();
        $this->createPatient($this->body(), null, $key)->assertCreated();

        $this->createPatient($this->body(['last_name' => 'Autre']), null, $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->assertSame(1, DB::table('patient_records')->count());
    }

    public function test_un_identifiant_de_patient_deja_pris_sous_une_nouvelle_cle_est_refuse_sans_erreur_sql(): void
    {
        $body = $this->body();
        $this->createPatient($body)->assertCreated();

        $this->createPatient($body + [])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['patient_id']]]);

        $this->assertSame(1, (int) DB::table('document_sequences')->where('clinic_id', $this->clinicId)->value('last_value'), 'Le numéro du refus est annulé.');
    }

    public function test_un_homonyme_et_un_telephone_partage_sont_acceptes_sans_fusion(): void
    {
        $this->createPatient($this->body())->assertCreated();
        $second = $this->createPatient($this->body())->assertCreated();

        $this->assertSame('EE-2026-0002', $second->json('data.record_number'));
        $this->assertSame(2, DB::table('patient_records')->count());
        $this->assertSame(0, DB::table('patient_records')->whereNotNull('merged_into_patient_id')->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'nom absent' => [['last_name' => '   '], 'last_name'],
            'nom de ponctuation seule' => [['last_name' => '---'], 'last_name'],
            'naissance dans le futur' => [['birth_date' => '2027-01-01'], 'birth_date'],
            'naissance inconnue sans raison' => [['birth_date' => null, 'birth_date_missing_reason' => null], 'birth_date_missing_reason'],
            'téléphone inconnu sans raison' => [['phone_primary' => null, 'phone_missing_reason' => null], 'phone_missing_reason'],
            'téléphone invalide' => [['phone_primary' => 'abc def'], 'phone_primary'],
            'téléphone trop court' => [['phone_primary' => '12'], 'phone_primary'],
            'sexe inconnu du contrat' => [['sex' => 'X'], 'sex'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidBodies')]
    public function test_une_identite_invalide_est_refusee_champ_par_champ_sans_rien_ecrire(array $overrides, string $field): void
    {
        $response = $this->createPatient($this->body($overrides));

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey($field, $response->json('error.fields'));
        $this->assertSame(0, DB::table('patient_records')->count());
        $this->assertSame(0, DB::table('document_sequences')->count(), 'Aucun numéro consommé par un refus.');
    }

    public function test_la_naissance_aujourd_hui_est_acceptee_et_demain_refusee(): void
    {
        $this->createPatient($this->body(['birth_date' => '2026-10-22']))->assertCreated();
        $this->createPatient($this->body(['birth_date' => '2026-10-23']))->assertStatus(422);
    }

    public function test_une_naissance_inconnue_explique_son_absence_sans_date_inventee(): void
    {
        $response = $this->createPatient($this->body(['birth_date' => null, 'birth_date_missing_reason' => 'Patient âgé, date non connue']));

        $response->assertCreated()->assertJsonPath('data.birth_date', null)
            ->assertJsonPath('data.birth_date_missing_reason', 'Patient âgé, date non connue');
    }

    public function test_la_raison_est_effacee_quand_la_valeur_qu_elle_explique_est_connue(): void
    {
        $response = $this->createPatient($this->body(['birth_date_missing_reason' => 'Motif devenu inutile', 'phone_missing_reason' => 'Motif devenu inutile']));

        $response->assertCreated()->assertJsonPath('data.birth_date_missing_reason', null)->assertJsonPath('data.phone_missing_reason', null);
    }

    public function test_le_corps_de_la_route_directe_est_valide_contre_son_contrat(): void
    {
        $this->createPatient($this->body(['record_number' => 'EE-2026-0099']))->assertStatus(422);
        $this->createPatient($this->body(['clinic_id' => $this->clinicId]))->assertStatus(422);
        $this->createPatient($this->body(['expected_version' => 3]))->assertStatus(422);

        $this->assertSame(0, DB::table('patient_records')->count());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function rolesByWriteAccess(): array
    {
        return ['gérante' => ['MANAGER', 201], 'accueil' => ['RECEPTION', 201], 'professionnel' => ['CLINICIAN', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('rolesByWriteAccess')]
    public function test_seuls_la_gerante_et_l_accueil_creent_des_patients(string $role, int $status): void
    {
        $user = $this->makeStaff($this->clinicId, $role);

        $this->createPatient($this->body(), $user)->assertStatus($status);
        $this->assertSame($status === 201 ? 1 : 0, DB::table('patient_records')->count());
    }

    public function test_un_module_ferme_repond_404_meme_pour_une_gerante(): void
    {
        config(['eeo.enabled_modules' => []]);
        $manager = $this->makeStaff($this->clinicId, 'MANAGER');

        $this->createPatient($this->body(), $manager)->assertNotFound();
        $this->actingAsFresh($manager)->getJson('/api/v1/patients?query=ab')->assertNotFound();
    }

    public function test_une_modification_incremente_la_version_recalcule_la_recherche_et_conserve_l_historique(): void
    {
        $body = $this->body();
        $this->createPatient($body)->assertCreated();

        $response = $this->updatePatient($body['patient_id'], 1, ['last_name' => 'Dupont', 'phone_primary' => '00237 699 00 00 02']);

        $response->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.last_name', 'Dupont');
        $this->assertMatchesContract($response, 'schemas/resources/patients.json#/$defs/PatientResponse');
        $row = DB::table('patient_records')->where('id', $body['patient_id'])->first();
        $this->assertSame('dupont test', $row->name_search);
        $this->assertSame('+237699000002', $row->phone_primary_normalized);
        $this->assertSame('EE-2026-0001', $row->record_number, 'Le numéro de dossier ne change jamais.');

        $audit = DB::table('audit_events')->where('action', 'patient.updated')->where('target_id', $body['patient_id'])->first();
        $this->assertNotNull($audit);
        $diff = json_decode((string) $audit->sanitized_diff, true);
        $this->assertEquals(['avant' => 'Patient Fictif', 'apres' => 'Dupont'], $diff['last_name']);
    }

    public function test_une_version_perimee_est_un_conflit_avec_la_version_actuelle_et_rien_n_est_ecrit(): void
    {
        $body = $this->body();
        $this->createPatient($body)->assertCreated();
        $this->updatePatient($body['patient_id'], 1, ['profession' => 'Enseignante'])->assertOk();

        $this->updatePatient($body['patient_id'], 1, ['profession' => 'Médecin'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT')
            ->assertJsonPath('error.details.current_version', 2);

        $this->assertSame('Enseignante', DB::table('patient_records')->where('id', $body['patient_id'])->value('profession'));
    }

    public function test_un_changement_sans_effet_ne_change_pas_la_version(): void
    {
        $body = $this->body();
        $this->createPatient($body)->assertCreated();

        $this->updatePatient($body['patient_id'], 1, ['last_name' => 'Patient Fictif'])->assertOk()->assertJsonPath('data.version', 1);

        $this->assertSame(0, DB::table('audit_events')->where('action', 'patient.updated')->count());
    }

    public function test_l_etat_resultant_est_revalide_et_aucune_erreur_sql_ne_remonte(): void
    {
        $body = $this->body();
        $this->createPatient($body)->assertCreated();

        $response = $this->updatePatient($body['patient_id'], 1, ['birth_date' => null]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('changes.birth_date_missing_reason', $response->json('error.fields'));
        $this->assertSame(1, (int) DB::table('patient_records')->where('id', $body['patient_id'])->value('version'));
    }

    public function test_le_numero_et_les_champs_historiques_ne_se_modifient_pas(): void
    {
        $body = $this->body();
        $this->createPatient($body)->assertCreated();

        $this->updatePatient($body['patient_id'], 1, ['record_number' => 'EE-2026-0500'])->assertStatus(422);
        $this->updatePatient($body['patient_id'], 1, ['legacy_record' => true])->assertStatus(422);
        $this->updatePatient($body['patient_id'], 1, [])->assertStatus(422);
    }

    public function test_l_identifiant_du_corps_doit_etre_celui_de_l_adresse(): void
    {
        $body = $this->body();
        $this->createPatient($body)->assertCreated();

        $this->actingAsFresh($this->reception)
            ->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->patchJson('/api/v1/patients/'.$body['patient_id'], ['expected_version' => 1, 'patient_id' => Ulid::generate(), 'changes' => ['profession' => 'X']])
            ->assertStatus(422);
    }

    public function test_une_fiche_inconnue_ou_d_un_autre_cabinet_repond_introuvable(): void
    {
        $otherClinic = $this->makeClinic();
        $otherManager = $this->makeStaff($otherClinic, 'MANAGER', 'autre.gerante');
        $foreign = $this->body();
        $this->createPatient($foreign, $otherManager)->assertCreated();

        $this->updatePatient($foreign['patient_id'], 1, ['profession' => 'X'])->assertNotFound();
        $this->actingAsFresh($this->reception)->getJson('/api/v1/patients/'.$foreign['patient_id'])->assertNotFound();
        $this->updatePatient(Ulid::generate(), 1, ['profession' => 'X'])->assertNotFound();
    }

    public function test_les_numeros_de_deux_cabinets_sont_independants(): void
    {
        $this->createPatient($this->body())->assertCreated()->assertJsonPath('data.record_number', 'EE-2026-0001');

        $otherClinic = $this->makeClinic();
        $other = $this->makeStaff($otherClinic, 'RECEPTION', 'autre.accueil');

        $this->createPatient($this->body(), $other)->assertCreated()->assertJsonPath('data.record_number', 'EE-2026-0001');
    }

    /**
     * @return array<string, array{0: Checkpoint}>
     */
    public static function checkpoints(): array
    {
        $cases = [];
        foreach (Checkpoint::cases() as $checkpoint) {
            $cases[$checkpoint->value] = [$checkpoint];
        }

        return $cases;
    }

    #[DataProvider('checkpoints')]
    public function test_un_echec_a_n_importe_quel_point_annule_tout_sans_trou_de_numero(Checkpoint $failAt): void
    {
        $this->app->instance(CommandBusProbe::class, new RecordingProbe($failAt));
        $this->withoutExceptionHandling();

        try {
            $this->createPatient($this->body());
            $this->fail('Une erreur injectée devait annuler la commande.');
        } catch (\RuntimeException) {
            // Attendu : la transaction est annulée en entier.
        }

        $this->assertSame(0, DB::table('patient_records')->count());
        $this->assertSame(0, DB::table('document_number_usages')->count());
        $this->assertSame(0, DB::table('document_sequences')->count(), 'Ni séquence créée ni numéro consommé.');
        $this->assertSame(0, DB::table('audit_events')->where('action', 'patient.created')->count());
        $this->assertSame(0, DB::table('outbox_events')->where('event_type', 'PatientCreated')->count());

        // La création suivante reçoit bien le premier numéro : aucun trou.
        $this->app->instance(CommandBusProbe::class, new RecordingProbe);
        $this->createPatient($this->body())->assertCreated()->assertJsonPath('data.record_number', 'EE-2026-0001');
    }

    public function test_la_fiche_se_relit_avec_ses_champs_complets(): void
    {
        $body = $this->body(['address' => 'Quartier fictif, Douala', 'profession' => 'Enseignante', 'guardian_contact' => ['name' => 'Responsable fictif', 'relationship' => 'Mère']]);
        $this->createPatient($body)->assertCreated();

        $response = $this->actingAsFresh($this->makeStaff($this->clinicId, 'CASHIER'))->getJson('/api/v1/patients/'.$body['patient_id']);

        $response->assertOk()->assertJsonPath('data.address', 'Quartier fictif, Douala')->assertJsonPath('data.guardian_contact.name', 'Responsable fictif');
        $this->assertMatchesContract($response, 'schemas/resources/patients.json#/$defs/PatientResponse');
    }

    public function test_le_contact_du_responsable_est_borne_et_n_entre_pas_dans_l_audit(): void
    {
        foreach ([
            ['password' => 'secret'],
            ['name' => str_repeat('x', 181)],
            ['name' => ''],
            ['name' => 'Responsable', 'extra' => 'non admis'],
            ['name' => 'a', 'relationship' => 'b', 'phone' => 'c', 'x' => 'd'],
        ] as $contact) {
            $this->createPatient($this->body(['guardian_contact' => $contact]))->assertStatus(422);
        }
        $this->assertSame(0, DB::table('patient_records')->count());

        $body = $this->body(['guardian_contact' => ['name' => 'Responsable fictif']]);
        $this->createPatient($body)->assertCreated();
        $this->updatePatient($body['patient_id'], 1, ['guardian_contact' => ['name' => 'Autre responsable']])->assertOk();

        $diff = json_decode((string) DB::table('audit_events')->where('action', 'patient.updated')->value('sanitized_diff'), true);
        $this->assertEquals(['guardian_contact' => ['modifie' => true]], $diff);
    }

    public function test_les_champs_d_import_historique_sont_valides_et_coherents(): void
    {
        foreach ([
            ['first_known_visit_on' => '2026-02-30'],
            ['first_known_visit_on' => '2026-10-23'],
            ['first_known_visit_on' => '1980-01-01'],
            ['first_visit_certified' => true],
            ['legacy_record_number' => 'ANC-001'],
        ] as $overrides) {
            $response = $this->createPatient($this->body($overrides));
            $this->assertSame(422, $response->status(), json_encode($overrides, JSON_UNESCAPED_UNICODE).' => '.$response->getContent());
        }
        $this->assertSame(0, DB::table('patient_records')->count());

        $this->createPatient($this->body(['legacy_record' => true, 'legacy_record_number' => 'ANC-001', 'first_known_visit_on' => '2015-03-04', 'first_visit_certified' => true]))
            ->assertCreated()
            ->assertJsonPath('data.legacy_record', true)
            ->assertJsonPath('data.legacy_record_number', 'ANC-001')
            ->assertJsonPath('data.first_known_visit_on', '2015-03-04');
    }

    public function test_les_caracteres_de_controle_et_les_noms_trop_longs_pour_la_recherche_sont_refuses(): void
    {
        $this->createPatient($this->body(['last_name' => "Nom\u{0007}piégé"]))->assertStatus(422);
        $this->createPatient($this->body(['profession' => "Pro\u{001B}fession"]))->assertStatus(422);
        // Une adresse peut comporter des retours à la ligne.
        $this->createPatient($this->body(['address' => "Quartier fictif\nPorte 3"]))->assertCreated();
        // Une décomposition NFD qui dépasse 320 caractères (syllabes hangul : trois lettres chacune) est un 422,
        // jamais une erreur SQL « Data too long ».
        $this->createPatient($this->body(['last_name' => str_repeat("\u{D55C}", 120), 'first_names' => str_repeat("\u{D55C}", 180)]))->assertStatus(422);
    }

    public function test_le_droit_de_lecture_retire_par_derogation_n_est_pas_contourne_par_l_ecriture(): void
    {
        DB::table('user_permissions')->insert([
            'id' => Ulid::generate(),
            'clinic_id' => $this->clinicId,
            'user_id' => $this->reception->id,
            'permission_code' => 'patient.read',
            'allowed' => false,
            'assigned_by' => $this->reception->id,
            'reason' => 'Test',
            'expires_at' => null,
            'created_at' => '2026-01-01 00:00:00.000000',
            'updated_at' => '2026-01-01 00:00:00.000000',
        ]);

        $this->createPatient($this->body())->assertStatus(403);
        $this->assertSame(0, DB::table('patient_records')->count());
    }

    public function test_la_version_attendue_est_obligatoire_dans_le_corps_et_l_identifiant_doit_etre_un_ulid(): void
    {
        $body = $this->body();
        unset($body['expected_version']);
        $this->createPatient($body)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['expected_version']]]);

        $this->actingAsFresh($this->reception)->getJson('/api/v1/patients/not-a-ulid')->assertNotFound();
        $this->actingAsFresh($this->reception)->getJson('/api/v1/patients/%C3%A9%C3%A9')->assertNotFound();
    }

    public function test_une_sequence_en_retard_sur_les_numeros_deja_consommes_ne_redonne_jamais_un_numero(): void
    {
        $this->createPatient($this->body())->assertCreated();
        $this->createPatient($this->body())->assertCreated();
        DB::table('document_sequences')->where('clinic_id', $this->clinicId)->update(['last_value' => 0]);

        $this->createPatient($this->body())->assertCreated()->assertJsonPath('data.record_number', 'EE-2026-0003');
    }

    public function test_une_source_autre_que_la_route_directe_est_refusee_tant_que_les_plages_n_existent_pas(): void
    {
        $this->expectException(BusinessError::class);
        $this->expectExceptionMessage('plages de numéros');

        $payload = $this->body();
        unset($payload['expected_version']);
        $payload['record_number'] = 'EE-2026-0001';
        $context = CommandContext::job($this->clinicId, 'test', 'corr-test', CarbonImmutable::now('UTC'));

        $this->app->make(CreatePatientHandler::class)
            ->handle(new Command('CreatePatient', $payload), $context, new TransactionLocks($this->clinicId), null);
    }
}
