<?php

namespace Tests\Feature\Application;

use App\Application\Checkpoint;
use App\Application\CommandBusProbe;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\CommandFixtures;
use Tests\Support\Commands\TestCommands;
use Tests\Support\Http\ConsumeCounterController;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Le chemin central vu par un client HTTP (AT11, AT38 au niveau de l'API) : en-tête Idempotency-Key, réponse
 * identique au rejeu, conflit de contenu, erreur réessayable, tout dans l'enveloppe d'erreur du fichier 08.
 * Une route de test joue le rôle d'un contrôleur de mutation (hérite de CommandController).
 */
final class CommandHttpTest extends TestCase
{
    use CommandFixtures;
    use MigratedDatabase;

    private const URL = '/api/v1/test-commands/counters/';

    private string $clinicId;

    private User $cashier;

    private string $counterId;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'active'])
            ->post(self::URL.'{counter}', [ConsumeCounterController::class, 'store']);

        $this->clinicId = $this->makeClinic();
        $this->cashier = $this->makeStaff($this->clinicId, 'CASHIER', 'caisse.test');
        $this->counterId = $this->makeCounter($this->clinicId, 50000);
        $this->registerTestCommands();
    }

    public function test_un_paiement_est_applique_une_fois_puis_rejoue_a_l_identique_quand_la_reponse_s_est_perdue(): void
    {
        $first = $this->post40000('cle-http-0000001');
        $first->assertOk()->assertJsonPath('data.remaining', 10000)->assertJsonPath('data.version', 2);

        // Le client n'a pas reçu la réponse : il renvoie la même demande, avec la même clé.
        foreach ([1, 2] as $retry) {
            $replay = $this->post40000('cle-http-0000001');
            $replay->assertOk();
            $this->assertEquals($first->json('data'), $replay->json('data'), 'Rejeu n°'.$retry);
        }

        $this->assertSame(10000, $this->counterBalance($this->counterId));
        $this->assertSame(['audit' => 1, 'outbox' => 1, 'changes' => 1, 'idempotency' => 1], $this->effectCounts($this->clinicId));
    }

    public function test_la_meme_cle_avec_un_montant_different_rend_un_conflit_dans_l_enveloppe_d_erreur(): void
    {
        $this->post40000('cle-http-0000002')->assertOk();

        $response = $this->actingAsFresh($this->cashier)->postJson(self::URL.$this->counterId, ['amount' => 41000], ['Idempotency-Key' => 'cle-http-0000002']);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH')
            ->assertJsonStructure(['error' => ['code', 'message'], 'correlation_id']);
        $this->assertSame(10000, $this->counterBalance($this->counterId));
    }

    public function test_un_refus_metier_rend_son_code_et_ne_laisse_aucun_effet(): void
    {
        $response = $this->actingAsFresh($this->cashier)->postJson(self::URL.$this->counterId, ['amount' => 60000], ['Idempotency-Key' => 'cle-http-0000003']);

        $response->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_EXCEEDS_BALANCE');
        $this->assertSame(0, DB::table('idempotency_requests')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_l_en_tete_est_obligatoire_pour_une_commande_qui_l_exige_et_refuse_s_il_est_mal_forme(): void
    {
        $strict = self::URL.$this->counterId.'?command='.TestCommands::CONSUME_STRICT;

        $missing = $this->actingAsFresh($this->cashier)->postJson($strict, ['amount' => 100]);
        $missing->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonPath('error.fields.Idempotency-Key.0', 'L\'en-tête Idempotency-Key est obligatoire pour cette opération.');

        $malformed = $this->actingAsFresh($this->cashier)->postJson($strict, ['amount' => 100], ['Idempotency-Key' => 'court']);
        $malformed->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(50000, $this->counterBalance($this->counterId));
    }

    public function test_sans_session_la_route_repond_401_et_sans_droit_403(): void
    {
        $this->flushSession();
        $this->nextRequest()->postJson(self::URL.$this->counterId, ['amount' => 100], ['Idempotency-Key' => 'cle-http-0000004'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_REQUIRED');

        $receptionist = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.test');
        $this->actingAsFresh($receptionist)->postJson(self::URL.$this->counterId, ['amount' => 100], ['Idempotency-Key' => 'cle-http-0000005'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACTION_FORBIDDEN');

        $this->assertSame(50000, $this->counterBalance($this->counterId));
    }

    public function test_une_erreur_reessayable_rend_503_avec_retry_after(): void
    {
        // Verrou du cabinet pris, puis l'épuisement des tentatives (simulé) : le client reçoit SERVER_BUSY et
        // renvoie la même demande avec la même clé. Le détail des tentatives est prouvé par TransactionRunnerTest
        // et, sur de vrais interblocages MySQL, par CommandBusConcurrencyTest.
        $this->app->instance(CommandBusProbe::class, new class implements CommandBusProbe
        {
            public function reached(Checkpoint $checkpoint, CommandContext $context): void
            {
                if ($checkpoint === Checkpoint::ClinicLocked) {
                    throw BusinessError::serverBusy();
                }
            }
        });

        $response = $this->actingAsFresh($this->cashier)->postJson(self::URL.$this->counterId, ['amount' => 100], ['Idempotency-Key' => 'cle-http-0000006']);

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'SERVER_BUSY')
            ->assertHeader('Retry-After', '2');
        $this->assertSame(50000, $this->counterBalance($this->counterId));
    }

    private function post40000(string $key): TestResponse
    {
        return $this->actingAsFresh($this->cashier)->postJson(self::URL.$this->counterId, ['amount' => 40000], ['Idempotency-Key' => $key]);
    }
}
