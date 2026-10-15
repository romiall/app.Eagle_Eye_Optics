<?php

namespace Tests\Feature\Application;

use App\Application\Checkpoint;
use App\Application\Command;
use App\Application\CommandDefinition;
use App\Application\CommandRegistry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeLogWriter;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\LockOrderViolation;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\GrantSnapshot;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommandFixtures;
use Tests\Support\Commands\ConsumeCounterHandler;
use Tests\Support\Commands\TestCommands;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Chemin central des commandes (F07, contrat 23) sur MySQL réel : droits, contrat, verrous, idempotence,
 * audit, outbox et journal des changements dans la MÊME transaction, retour arrière complet à chaque point.
 *
 * Les scénarios AT11 (retry et même clé avec montant changé), AT13 (audit non modifiable), AT19 (pas de
 * dernier écrit gagnant) et AT38 (réponse perdue) sont prouvés ici au niveau du mécanisme, avec une action de
 * test qui consomme un compteur ; les entrelacements entre deux connexions réelles (AT12, interblocage,
 * publication du pull) sont dans tests/Concurrency/CommandBusConcurrencyTest.php. Les scénarios métier
 * complets suivent avec les actions de paiement, de consultation et de synchronisation (B04, C03, O05).
 */
final class CommandBusTest extends TestCase
{
    use CommandFixtures;
    use MigratedDatabase;

    private string $clinicId;

    private User $manager;

    private User $cashier;

    private User $receptionist;

    private string $counterId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinicId = $this->makeClinic();
        $this->manager = $this->makeStaff($this->clinicId, 'MANAGER', 'gerante.test');
        $this->cashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');
        $this->receptionist = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->counterId = $this->makeCounter($this->clinicId, 50000);
        $this->registerTestCommands();
    }

    public function test_une_commande_appliquee_ecrit_ses_faits_et_tous_ses_effets_au_commit(): void
    {
        $result = $this->bus()->dispatch($this->consume($this->counterId, 40000), $this->directContext($this->cashier), 'cle-paiement-0001');

        $this->assertFalse($result->replayed);
        $this->assertSame(['counter_id' => $this->counterId, 'amount' => 40000, 'remaining' => 10000, 'version' => 2], $result->result);
        $this->assertSame($this->counterId, $result->resultId);
        $this->assertSame('corr-test-0001', $result->correlationId);

        // Faits de l'action.
        $this->assertSame(10000, $this->counterBalance($this->counterId));

        // Audit : auteur et corrélation viennent du contexte.
        $audit = DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'test.counter.consumed')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($this->cashier->id, $audit[0]->actor_id);
        $this->assertSame('TEST CASHIER', $audit[0]->actor_name_snapshot);
        $this->assertSame('corr-test-0001', $audit[0]->correlation_id);
        $this->assertSame($this->counterId, $audit[0]->target_id);

        // Outbox : événement contractuel en attente, jamais exécuté dans la transaction.
        $outbox = DB::table('outbox_events')->where('clinic_id', $this->clinicId)->get();
        $this->assertCount(1, $outbox);
        $this->assertSame('PENDING', $outbox[0]->status);
        $this->assertSame('PaymentConfirmed', $outbox[0]->event_type);
        $this->assertSame(1, (int) $outbox[0]->event_version);
        $this->assertSame(2, (int) $outbox[0]->source_version);
        $this->assertSame('corr-test-0001', $outbox[0]->correlation_id);
        $this->assertSame(0, (int) $outbox[0]->attempt_count);
        $this->assertSame(40000, json_decode((string) $outbox[0]->payload, true)['amount']);

        // Journal des changements à diffuser.
        $changes = DB::table('sync_change_log')->where('clinic_id', $this->clinicId)->get();
        $this->assertCount(1, $changes);
        $this->assertSame('FINANCIAL', $changes[0]->visibility);
        $this->assertSame('UPDATED', $changes[0]->change_kind);
        $this->assertSame($this->counterId, $changes[0]->entity_id);
        $this->assertSame(2, (int) $changes[0]->entity_version);

        // Résultat durable de la clé d'idempotence.
        $idempotency = DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->get();
        $this->assertCount(1, $idempotency);
        $this->assertSame('COMMITTED', $idempotency[0]->status);
        $this->assertSame('CLE-PAIEMENT-0001', $idempotency[0]->request_key, 'Clé insensible à la casse, conservée en majuscules.');
        $this->assertSame('user:'.$this->cashier->id, $idempotency[0]->namespace);
        $this->assertSame(TestCommands::CONSUME, $idempotency[0]->command_name);
        $this->assertSame($this->counterId, $idempotency[0]->result_id);
        $this->assertSame(10000, json_decode((string) $idempotency[0]->result_payload, true)['remaining']);
        $this->assertNotNull($idempotency[0]->completed_at);
    }

    public function test_les_points_du_chemin_sont_atteints_dans_l_ordre(): void
    {
        $this->bus(probe: $probe)->dispatch($this->consume($this->counterId, 100), $this->directContext($this->cashier), 'cle-ordre-00001');

        $this->assertSame(
            ['clinic-locked', 'idempotency-checked', 'handler-returned', 'audit-written', 'outbox-written', 'change-log-written', 'idempotency-completed'],
            $probe->reached,
        );
    }

    public function test_une_cle_rejouee_avec_le_meme_contenu_rend_le_resultat_sans_nouvel_effet(): void
    {
        // AT11 (double clic) et AT38 (réponse perdue puis rejeu) : un seul effet, même réponse.
        $bus = $this->bus();
        $first = $bus->dispatch($this->consume($this->counterId, 40000), $this->directContext($this->cashier), 'cle-paiement-0002');
        $countsAfterFirst = $this->effectCounts($this->clinicId);

        // La première réponse s'est perdue : le client renvoie la même demande, deux fois.
        foreach ([1, 2] as $retry) {
            $replay = $bus->dispatch($this->consume($this->counterId, 40000), $this->directContext($this->cashier), 'cle-paiement-0002');

            $this->assertTrue($replay->replayed, 'Rejeu n°'.$retry);
            $this->assertEquals($first->result, $replay->result, 'Même contenu (MySQL normalise l\'ordre des clés JSON).');
            $this->assertSame($first->resultId, $replay->resultId);
        }

        $this->assertSame(1, ConsumeCounterHandler::$handled, 'L\'action n\'est pas rejouée.');
        $this->assertSame(10000, $this->counterBalance($this->counterId));
        $this->assertSame($countsAfterFirst, $this->effectCounts($this->clinicId));
    }

    public function test_une_cle_rejouee_avec_un_montant_different_est_refusee(): void
    {
        // AT11 : « même clé avec montant changé » — changement de contenu refusé, effet d'origine intact.
        $bus = $this->bus();
        $bus->dispatch($this->consume($this->counterId, 30000), $this->directContext($this->cashier), 'cle-paiement-0003');
        $counts = $this->effectCounts($this->clinicId);

        try {
            $bus->dispatch($this->consume($this->counterId, 31000), $this->directContext($this->cashier), 'cle-paiement-0003');
            $this->fail('Le changement de contenu sous une même clé devait être refusé.');
        } catch (BusinessError $error) {
            $this->assertSame('IDEMPOTENCY_PAYLOAD_MISMATCH', $error->errorCode);
            $this->assertSame(409, $error->httpStatus);
        }

        $this->assertSame(20000, $this->counterBalance($this->counterId));
        $this->assertSame($counts, $this->effectCounts($this->clinicId));
    }

    public function test_la_meme_cle_dans_une_autre_commande_ou_chez_un_autre_compte_est_independante(): void
    {
        $bus = $this->bus();
        $bus->dispatch($this->consume($this->counterId, 1000), $this->directContext($this->cashier), 'cle-commune-0001');

        // Autre compte, même clé : espace de noms distinct, nouvelle exécution.
        $bus->dispatch($this->consume($this->counterId, 1000), $this->directContext($this->manager), 'cle-commune-0001');
        // Autre commande (nom), même clé et même compte : nouvelle exécution.
        $bus->dispatch($this->consume($this->counterId, 1000, name: TestCommands::CONSUME_STRICT), $this->directContext($this->cashier), 'cle-commune-0001');

        $this->assertSame(47000, $this->counterBalance($this->counterId));
        $this->assertSame(3, DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_un_refus_metier_annule_tout_et_n_est_jamais_committe(): void
    {
        $bus = $this->bus();
        $counts = $this->effectCounts($this->clinicId);

        try {
            $bus->dispatch($this->consume($this->counterId, 60000), $this->directContext($this->cashier), 'cle-refus-000001');
            $this->fail('Le dépassement du solde devait être refusé.');
        } catch (BusinessError $error) {
            $this->assertSame('PAYMENT_EXCEEDS_BALANCE', $error->errorCode);
        }

        $this->assertSame(50000, $this->counterBalance($this->counterId));
        $this->assertSame($counts, $this->effectCounts($this->clinicId));
        $this->assertSame(0, DB::table('idempotency_requests')->where('request_key', 'cle-refus-000001')->count(), 'Un refus ne réserve pas la clé.');

        // L'état change (le solde est reconstitué) : la même intention, avec la même clé, réussit.
        DB::table('document_sequences')->where('id', $this->counterId)->update(['last_value' => 70000]);
        $retry = $bus->dispatch($this->consume($this->counterId, 60000), $this->directContext($this->cashier), 'cle-refus-000001');

        $this->assertFalse($retry->replayed);
        $this->assertSame(10000, $this->counterBalance($this->counterId));
    }

    /**
     * @return iterable<string, array{0: Checkpoint}>
     */
    public static function checkpoints(): iterable
    {
        foreach (Checkpoint::cases() as $checkpoint) {
            yield $checkpoint->value => [$checkpoint];
        }
    }

    #[DataProvider('checkpoints')]
    public function test_une_erreur_a_chaque_point_annule_tous_les_effets_deja_ecrits(Checkpoint $checkpoint): void
    {
        $before = $this->effectCounts($this->clinicId);

        try {
            $this->bus($checkpoint)->dispatch($this->consume($this->counterId, 40000), $this->directContext($this->cashier), 'cle-annulee-0001');
            $this->fail('L\'erreur injectée devait faire échouer la commande.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString($checkpoint->value, $error->getMessage());
        }

        $this->assertSame(50000, $this->counterBalance($this->counterId), 'Fait de l\'action annulé.');
        $this->assertSame(1, $this->counterVersion($this->counterId));
        $this->assertSame($before, $this->effectCounts($this->clinicId), 'Audit, outbox, journal des changements et clé annulés ensemble.');

        // La clé n'est pas « brûlée » : la même demande, sans erreur, s'applique normalement.
        $retry = $this->bus()->dispatch($this->consume($this->counterId, 40000), $this->directContext($this->cashier), 'cle-annulee-0001');
        $this->assertFalse($retry->replayed);
        $this->assertSame(10000, $this->counterBalance($this->counterId));
    }

    public function test_la_cle_d_idempotence_est_obligatoire_quand_la_commande_l_exige(): void
    {
        try {
            $this->bus()->dispatch($this->consume($this->counterId, 100, name: TestCommands::CONSUME_STRICT), $this->directContext($this->cashier));
            $this->fail('La clé était obligatoire.');
        } catch (BusinessError $error) {
            $this->assertSame('VALIDATION_FAILED', $error->errorCode);
            $this->assertArrayHasKey('Idempotency-Key', $error->fields);
        }

        $this->assertSame(0, ConsumeCounterHandler::$handled);
        $this->assertSame(0, DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_une_cle_mal_formee_est_refusee(): void
    {
        foreach (['court', str_repeat('a', 129), 'avec espace dans la cle', "retour\nligne-12345", 'accentuée-12345'] as $badKey) {
            try {
                $this->bus()->dispatch($this->consume($this->counterId, 100), $this->directContext($this->cashier), $badKey);
                $this->fail('Clé refusée attendue : '.json_encode($badKey));
            } catch (BusinessError $error) {
                $this->assertSame('VALIDATION_FAILED', $error->errorCode);
            }
        }

        $this->assertSame(0, ConsumeCounterHandler::$handled);
    }

    public function test_une_commande_facultative_s_execute_sans_cle_et_sans_ligne_d_idempotence(): void
    {
        $result = $this->bus()->dispatch($this->consume($this->counterId, 500), $this->directContext($this->cashier));

        $this->assertFalse($result->replayed);
        $this->assertSame(49500, $this->counterBalance($this->counterId));
        $this->assertSame(0, DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->count());
        $this->assertSame(1, DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'test.counter.consumed')->count());
    }

    public function test_une_charge_conforme_au_contrat_f06_est_acceptee_et_une_charge_non_conforme_refusee_avant_tout_verrou(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('contracts/fixtures/commands/register-payment.mobile-money.valid.json')), true, 512, JSON_THROW_ON_ERROR);
        $valid = $fixture['data'];

        $accepted = $this->bus()->dispatch(new Command(self::CONTRACT_CHECKED, $valid), $this->directContext($this->cashier), 'cle-contrat-00001');
        $this->assertSame(20000, $accepted->result['remaining']);

        $withoutInvoice = $valid;
        unset($withoutInvoice['invoice_id']);

        // Un défaut par charge : Opis s'arrête au premier mot-clé en échec d'un objet (un champ obligatoire
        // absent masque l'examen des autres champs), le client corrige donc les erreurs dans l'ordre.
        $defects = [
            'champ obligatoire absent' => [$withoutInvoice, 'invoice_id', 'Champ obligatoire absent.'],
            'montant décimal' => [[...$valid, 'amount' => 12.5], 'amount', 'Type de valeur invalide.'],
            'montant nul' => [[...$valid, 'amount' => 0], 'amount', 'Valeur hors des limites admises.'],
            'mode de paiement inconnu' => [[...$valid, 'method' => 'TROC'], 'method', 'Valeur non admise.'],
            'champ non prévu' => [[...$valid, 'extra' => 1], 'extra', 'Champ non prévu par le contrat.'],
            'identifiant mal formé' => [[...$valid, 'payment_id' => 'pas-un-ulid'], 'payment_id', 'Format invalide.'],
            'espèces sans session de caisse' => [[...$valid, 'method' => 'CASH', 'reference' => null], 'cash_session_id', 'Type de valeur invalide.'],
        ];
        $before = $this->effectCounts($this->clinicId);

        foreach ($defects as $label => [$payload, $field, $message]) {
            try {
                $this->bus()->dispatch(new Command(self::CONTRACT_CHECKED, $payload), $this->directContext($this->cashier), 'cle-contrat-'.substr(md5($label), 0, 8));
                $this->fail('Charge non conforme acceptée : '.$label);
            } catch (BusinessError $error) {
                $this->assertSame('VALIDATION_FAILED', $error->errorCode, $label);
                $this->assertSame(422, $error->httpStatus, $label);
                $this->assertSame([$field => [$message]], $error->fields, $label);
            }
        }

        $this->assertSame(1, ConsumeCounterHandler::$handled, 'Seule la charge conforme atteint l\'action.');
        $this->assertSame(20000, $this->counterBalance($this->counterId));
        $this->assertSame(['audit' => 1, 'outbox' => 1, 'changes' => 1, 'idempotency' => 1], $this->effectCounts($this->clinicId));
        $this->assertSame($before, $this->effectCounts($this->clinicId), 'Aucune charge refusée n\'a laissé d\'effet.');
    }

    public function test_sans_le_droit_la_commande_est_refusee_sans_aucun_effet(): void
    {
        try {
            $this->bus()->dispatch($this->consume($this->counterId, 100), $this->directContext($this->receptionist), 'cle-interdite-001');
            $this->fail('L\'accueil n\'a pas la permission payment.collect.');
        } catch (BusinessError $error) {
            $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
            $this->assertSame(403, $error->httpStatus);
        }

        $this->assertSame(0, ConsumeCounterHandler::$handled);
        $this->assertSame(0, DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->count());
        $this->assertSame(50000, $this->counterBalance($this->counterId));
    }

    public function test_un_compte_desactive_ne_peut_plus_agir_ni_relire_un_resultat_deja_committe(): void
    {
        $bus = $this->bus();
        $bus->dispatch($this->consume($this->counterId, 100), $this->directContext($this->cashier), 'cle-desactive-001');

        // Désactivé depuis : le modèle de la session, resté en mémoire, croit encore le compte actif.
        DB::table('users')->where('id', $this->cashier->id)->update(['active' => false]);

        foreach (['cle-desactive-001', 'cle-desactive-002'] as $key) {
            try {
                $bus->dispatch($this->consume($this->counterId, 100), $this->directContext($this->cashier), $key);
                $this->fail('Un compte désactivé ne doit plus agir (clé '.$key.').');
            } catch (BusinessError $error) {
                $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
            }
        }

        $this->assertSame(49900, $this->counterBalance($this->counterId));
    }

    public function test_une_commande_d_un_autre_cabinet_n_atteint_pas_les_donnees_de_celui_ci(): void
    {
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $stranger = $this->makeStaff($otherClinic, 'CASHIER', 'caisse.autre');

        // Le compteur appartient au cabinet A : pour le cabinet B il n'existe pas.
        try {
            $this->bus()->dispatch($this->consume($this->counterId, 100), $this->directContext($stranger), 'cle-etranger-001');
            $this->fail('Le compteur du cabinet A est introuvable pour le cabinet B.');
        } catch (BusinessError $error) {
            $this->assertSame('RESOURCE_NOT_FOUND', $error->errorCode);
        }

        $this->assertSame(50000, $this->counterBalance($this->counterId));
    }

    public function test_une_source_non_admise_est_refusee_et_une_tache_admise_agit_sous_l_identite_systeme(): void
    {
        $job = CommandContext::job($this->clinicId, 'rappel-test', 'corr-job-0001');

        try {
            $this->bus()->dispatch($this->consume($this->counterId, 100), $job, 'cle-tache-000001');
            $this->fail('La commande ne déclare pas la source JOB.');
        } catch (BusinessError $error) {
            $this->assertSame('ACTION_FORBIDDEN', $error->errorCode);
        }

        $result = $this->bus()->dispatch($this->consume($this->counterId, 100, name: TestCommands::CONSUME_JOB), $job, 'cle-tache-000002');
        $this->assertSame(49900, $result->result['remaining']);

        $audit = DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'test.counter.consumed')->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->actor_id);
        $this->assertSame('Système (rappel-test)', $audit->actor_name_snapshot);
        $this->assertSame('corr-job-0001', $audit->correlation_id);
        $this->assertSame('job:rappel-test', DB::table('idempotency_requests')->where('request_key', 'cle-tache-000002')->value('namespace'));
    }

    public function test_une_commande_reservee_a_la_route_directe_refuse_la_synchronisation(): void
    {
        [$deviceId, $grant] = $this->syncFixture();
        $context = $this->syncContext($this->clinicId, $deviceId, $this->cashier, $grant, '2026-10-14 12:00:00', '2026-10-14 08:30:00', '2026-10-14 15:00:00');

        $this->expectException(BusinessError::class);
        $this->expectExceptionMessage('Action non autorisée');
        $this->bus()->dispatch($this->consume($this->counterId, 100, name: TestCommands::CONSUME_DIRECT_ONLY), $context, Ulid::generate());
    }

    public function test_une_commande_de_poste_est_autorisee_par_le_contexte_historique_du_grant(): void
    {
        [$deviceId, $grant] = $this->syncFixture();
        $context = $this->syncContext($this->clinicId, $deviceId, $this->cashier, $grant, '2026-10-14 12:00:00', '2026-10-14 08:30:00', '2026-10-14 15:00:00');
        $commandId = Ulid::generate();

        $result = $this->bus()->dispatch($this->consume($this->counterId, 5000), $context, $commandId);

        $this->assertSame(45000, $result->result['remaining']);
        $this->assertSame('device:'.$deviceId, DB::table('idempotency_requests')->where('request_key', $commandId)->value('namespace'));
        $this->assertSame($this->cashier->id, DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'test.counter.consumed')->value('actor_id'));

        // ACK perdu : le poste rejoue la même commande, aucune seconde écriture.
        $replay = $this->bus()->dispatch($this->consume($this->counterId, 5000), $context, $commandId);
        $this->assertTrue($replay->replayed);
        $this->assertSame(45000, $this->counterBalance($this->counterId));
    }

    public function test_une_revocation_posterieure_n_invalide_pas_la_commande_du_poste_et_son_rejeu_reste_accepte(): void
    {
        [$deviceId, $grant] = $this->syncFixture();
        $context = $this->syncContext($this->clinicId, $deviceId, $this->cashier, $grant, '2026-10-14 12:00:00', '2026-10-14 08:30:00', '2026-10-14 15:00:00');
        $commandId = Ulid::generate();
        $this->bus()->dispatch($this->consume($this->counterId, 5000), $context, $commandId);

        // Le grant est révoqué à 14:00, APRÈS l'action de 12:00 (la révocation s'ajoute, elle ne réécrit rien).
        DB::table('offline_grants')->where('id', $grant->grantId)->update(['revoked_at' => '2026-10-14 14:00:00.000000']);

        // Rejeu du poste (qui n'a pas encore connu la révocation) : résultat durable rendu, sans nouvelle autorisation.
        $replay = $this->bus()->dispatch($this->consume($this->counterId, 5000), $context, $commandId);
        $this->assertTrue($replay->replayed);

        // Une NOUVELLE commande du même poste, déclarée à 12:30, reste antérieure à la révocation : valable.
        $later = $this->syncContext($this->clinicId, $deviceId, $this->cashier, $grant, '2026-10-14 12:30:00', '2026-10-14 08:30:00', '2026-10-14 15:00:00');
        $this->assertSame(40000, $this->bus()->dispatch($this->consume($this->counterId, 5000), $later, Ulid::generate())->result['remaining']);
    }

    public function test_une_commande_apres_une_revocation_connue_du_poste_est_refusee(): void
    {
        [$deviceId, $grant] = $this->syncFixture(revokedAt: '2026-10-14 14:00:00.000000');
        // Le poste avait resynchronisé à 14:30, donc connu la révocation, puis a quand même agi.
        $context = $this->syncContext($this->clinicId, $deviceId, $this->cashier, $grant, '2026-10-14 14:40:00', '2026-10-14 14:30:00', '2026-10-14 15:00:00');

        try {
            $this->bus()->dispatch($this->consume($this->counterId, 100), $context, Ulid::generate());
            $this->fail('La révocation était connue du poste.');
        } catch (BusinessError $error) {
            $this->assertSame('GRANT_EXPIRED_OR_REVOKED', $error->errorCode);
            $this->assertSame(403, $error->httpStatus);
        }

        $this->assertSame(50000, $this->counterBalance($this->counterId));
        $this->assertSame(0, ConsumeCounterHandler::$handled);
    }

    public function test_une_horloge_de_poste_incoherente_passe_en_revue_sans_effet(): void
    {
        [$deviceId, $grant] = $this->syncFixture();
        // Le poste connaissait 08:30 mais déclare avoir agi à 07:00 : antidatage possible → revue.
        $context = $this->syncContext($this->clinicId, $deviceId, $this->cashier, $grant, '2026-10-14 07:00:00', '2026-10-14 08:30:00', '2026-10-14 15:00:00');

        try {
            $this->bus()->dispatch($this->consume($this->counterId, 100), $context, Ulid::generate());
            $this->fail('Le cas ambigu devait passer en revue.');
        } catch (BusinessError $error) {
            $this->assertSame('AUTHORIZATION_REVIEW_REQUIRED', $error->errorCode);
            $this->assertSame(409, $error->httpStatus);
        }

        $this->assertSame(50000, $this->counterBalance($this->counterId));
        $this->assertSame(0, DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_un_poste_inconnu_du_cabinet_est_refuse(): void
    {
        [, $grant] = $this->syncFixture();
        $context = $this->syncContext($this->clinicId, Ulid::generate(), $this->cashier, $grant, '2026-10-14 12:00:00', '2026-10-14 08:30:00', '2026-10-14 15:00:00');

        $this->expectException(BusinessError::class);
        $this->expectExceptionMessage('Poste inconnu');
        $this->bus()->dispatch($this->consume($this->counterId, 100), $context, Ulid::generate());
    }

    public function test_un_grant_inconnu_est_refuse(): void
    {
        [$deviceId] = $this->syncFixture();
        // Un grant signé ne se supprime pas (historique append-only) : on présente un grant qui n'existe pas en base.
        $phantom = new GrantSnapshot(
            Ulid::generate(), $deviceId, $this->cashier->id, ['payment.collect'], 'rules-test-1', 'catalog-test-1',
            CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'), CarbonImmutable::parse('2026-10-14 20:00:00', 'UTC'),
        );
        $context = $this->syncContext($this->clinicId, $deviceId, $this->cashier, $phantom, '2026-10-14 12:00:00', '2026-10-14 08:30:00', '2026-10-14 15:00:00');

        try {
            $this->bus()->dispatch($this->consume($this->counterId, 100), $context, Ulid::generate());
            $this->fail('Grant inconnu.');
        } catch (BusinessError $error) {
            $this->assertSame('GRANT_EXPIRED_OR_REVOKED', $error->errorCode);
        }
    }

    public function test_deux_commandes_sur_la_meme_version_ne_donnent_pas_un_dernier_ecrit_gagnant(): void
    {
        // AT19 : modification concurrente depuis deux postes — conflit conservé, aucun écrasement silencieux.
        $bus = $this->bus();
        $first = $bus->dispatch($this->consume($this->counterId, 10000, expectedVersion: 1), $this->directContext($this->cashier), 'cle-version-0001');
        $this->assertSame(2, $first->result['version']);

        try {
            $bus->dispatch($this->consume($this->counterId, 10000, expectedVersion: 1), $this->directContext($this->manager), 'cle-version-0002');
            $this->fail('La seconde modification part d\'une version périmée.');
        } catch (BusinessError $error) {
            $this->assertSame('VERSION_CONFLICT', $error->errorCode);
            $this->assertSame(409, $error->httpStatus);
        }

        $this->assertSame(40000, $this->counterBalance($this->counterId), 'Seule la première modification est appliquée.');
        $this->assertSame(2, $this->counterVersion($this->counterId));
    }

    public function test_l_audit_ecrit_par_le_chemin_central_ne_peut_etre_ni_modifie_ni_supprime(): void
    {
        // AT13 : journal non supprimable ; une correction est un nouvel événement, jamais une réécriture.
        $this->bus()->dispatch($this->consume($this->counterId, 100), $this->directContext($this->cashier), 'cle-audit-0000001');
        $audit = DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'test.counter.consumed')->first();
        $this->assertNotNull($audit);

        foreach ([
            fn () => DB::table('audit_events')->where('id', $audit->id)->update(['reason' => 'réécrit']),
            fn () => DB::table('audit_events')->where('id', $audit->id)->delete(),
            fn () => DB::table('sync_change_log')->where('clinic_id', $this->clinicId)->update(['entity_version' => 99]),
            fn () => DB::table('sync_change_log')->where('clinic_id', $this->clinicId)->delete(),
        ] as $tamper) {
            try {
                $tamper();
                $this->fail('L\'historique doit refuser toute réécriture.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Append-only history', $error->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: class-string<\Throwable>, 2: string}>
     */
    public static function infractions(): iterable
    {
        yield 'verrou hors ordre' => ['lock-order', LockOrderViolation::class, 'Ordre des verrous violé'];
        yield 'événement hors contrat' => ['unknown-event', LogicException::class, 'Événement hors contrat'];
        yield 'événement non conforme' => ['bad-event', LogicException::class, 'non conforme'];
        yield 'exception inattendue' => ['crash', \DomainException::class, 'inattendue'];
    }

    /**
     * @param  class-string<\Throwable>  $exception
     */
    #[DataProvider('infractions')]
    public function test_une_infraction_a_la_discipline_du_chemin_central_est_refusee_et_annulee(string $mode, string $exception, string $message): void
    {
        try {
            $this->bus()->dispatch(new Command(self::MISBEHAVING, ['mode' => $mode]), $this->directContext($this->cashier), 'cle-infraction-001');
            $this->fail('L\'infraction devait faire échouer la commande.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
            $this->assertStringContainsString($message, $error->getMessage());
        }

        $this->assertSame(0, DB::table('document_sequences')->where('clinic_id', $this->clinicId)->where('document_type', 'RECEIPT')->count(), 'Fait écrit avant l\'infraction : annulé.');
        $this->assertSame(0, DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'test.misbehaving')->count());
        $this->assertSame(0, DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->count());
        $this->assertSame(0, DB::table('outbox_events')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_le_journal_des_changements_refuse_toute_ecriture_hors_du_verrou_exclusif_du_cabinet(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('verrou exclusif');

        $context = $this->directContext($this->cashier);
        app(ChangeLogWriter::class)->write(
            $context,
            new TransactionLocks($this->clinicId),
            [new ChangeRecord('document_sequences', $this->counterId, 2, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }

    public function test_une_commande_inconnue_ou_enregistree_deux_fois_est_un_defaut_de_cablage(): void
    {
        $registry = $this->app->make(CommandRegistry::class);
        $this->assertTrue($registry->has(TestCommands::CONSUME));
        $this->assertFalse($registry->has('Fantome'));

        try {
            $this->bus()->dispatch(new Command('Fantome', []), $this->directContext($this->cashier));
            $this->fail('Commande inconnue.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Fantome', $error->getMessage());
        }

        $this->expectException(LogicException::class);
        $registry->register(new CommandDefinition(TestCommands::CONSUME, ConsumeCounterHandler::class));
    }

    /**
     * @return array{0: string, 1: GrantSnapshot}
     */
    public function test_le_rejeu_d_une_commande_de_poste_est_lie_a_son_auteur_et_a_son_grant(): void
    {
        // Constat de revue F07 : l'espace de noms « device:<poste> » est commun aux profils du poste. Le même
        // identifiant de commande présenté par l'accueil (grant sans payment.collect) ne doit ni rejouer ni rendre
        // le résultat de la caisse : c'est un conflit d'intégrité (fichier 19), sans aucun effet.
        [$deviceId, $grant] = $this->syncFixture();
        $receptionGrant = $this->makeGrant(
            $this->clinicId, $deviceId, $this->receptionist->id, ['patient.create'],
            '2026-10-14 08:00:00.000000', '2026-10-14 20:00:00.000000',
        );
        $commandId = Ulid::generate();
        $this->bus()->dispatch(
            $this->consume($this->counterId, 5000),
            $this->syncContext($this->clinicId, $deviceId, $this->cashier, $grant, '2026-10-14 12:00:00', '2026-10-14 08:30:00', '2026-10-14 15:00:00'),
            $commandId,
        );
        $counts = $this->effectCounts($this->clinicId);

        try {
            $this->bus()->dispatch(
                $this->consume($this->counterId, 5000),
                $this->syncContext($this->clinicId, $deviceId, $this->receptionist, $receptionGrant, '2026-10-14 12:00:00', '2026-10-14 08:30:00', '2026-10-14 15:00:00'),
                $commandId,
            );
            $this->fail('Un autre profil du poste ne rejoue pas la commande de la caisse.');
        } catch (BusinessError $error) {
            $this->assertSame('IDEMPOTENCY_PAYLOAD_MISMATCH', $error->errorCode);
        }

        $this->assertSame(45000, $this->counterBalance($this->counterId));
        $this->assertSame($counts, $this->effectCounts($this->clinicId));
    }

    public function test_l_action_recoit_l_identifiant_de_sa_ligne_d_idempotence(): void
    {
        // Les faits financiers portent `idempotency_id` NOT NULL (payments, refunds, deliveries…) : l'action le reçoit.
        $this->bus()->dispatch($this->consume($this->counterId, 1000), $this->directContext($this->cashier), 'cle-faits-0001');

        $row = DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->where('request_key', 'cle-faits-0001')->value('id');
        $this->assertIsString($row);
        $this->assertSame($row, ConsumeCounterHandler::$lastIdempotencyId);

        // Commande dont la clé est facultative, envoyée sans clé : aucune ligne, donc aucun identifiant.
        $this->bus()->dispatch($this->consume($this->counterId, 1000), $this->directContext($this->cashier));
        $this->assertNull(ConsumeCounterHandler::$lastIdempotencyId);
    }

    public function test_la_cle_d_idempotence_est_insensible_a_la_casse_et_conservee_en_majuscules(): void
    {
        $bus = $this->bus();
        $bus->dispatch($this->consume($this->counterId, 1000), $this->directContext($this->cashier), 'cle-casse-0001');
        $replay = $bus->dispatch($this->consume($this->counterId, 1000), $this->directContext($this->cashier), 'CLE-CASSE-0001');

        $this->assertTrue($replay->replayed, 'Même clé à la casse près : même demande, comme la collation de la colonne.');
        $this->assertSame(1, ConsumeCounterHandler::$handled);
        $this->assertTrue(DB::table('idempotency_requests')->whereRaw('request_key = BINARY ?', ['CLE-CASSE-0001'])->exists());
    }

    private function syncFixture(?string $revokedAt = null): array
    {
        $deviceId = $this->makeDevice($this->clinicId, $this->manager->id);
        $grant = $this->makeGrant(
            $this->clinicId, $deviceId, $this->cashier->id, ['payment.collect'],
            '2026-10-14 08:00:00.000000', '2026-10-14 20:00:00.000000', $revokedAt,
        );

        return [$deviceId, $grant];
    }
}
