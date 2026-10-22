<?php

namespace Tests\Feature\Clinical;

use App\Console\Commands\ManageAcuityScales;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Échelles d'acuité visuelle du cabinet (C02, Q06) : lecture réservée à `clinical.read`, limitée au cabinet, module
 * fermé = 404 ; commande `eeo:clinical:scales` (échelles de démonstration fictives hors production, échelle réelle
 * choisie par le cabinet dans tout environnement, idempotente, aucune conversion écrite). Données fictives.
 */
final class VisualAcuityScalesTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    public function test_la_liste_rend_les_echelles_du_cabinet_actives_d_abord_et_conforme_au_contrat(): void
    {
        $this->seedScale('B_ECHELLE');
        $this->seedScale('A_ECHELLE');
        $this->seedScale('Z_ANCIENNE', false);

        $response = $this->actingAsFresh($this->clinician)->getJson('/api/v1/visual-acuity-scales')->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/clinical.json#/$defs/VisualAcuityScaleList');
        $this->assertSame(['A_ECHELLE', 'B_ECHELLE', 'Z_ANCIENNE'], array_column($response->json('data'), 'code'));
        $this->assertSame([true, true, false], array_column($response->json('data'), 'active'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_les_echelles_d_un_autre_cabinet_ne_sont_jamais_rendues(): void
    {
        $this->seedScale('LA_MIENNE');
        $this->seedScale('LA_LEUR', true, $this->makeClinic());

        $codes = array_column($this->actingAsFresh($this->clinician)->getJson('/api/v1/visual-acuity-scales')->assertOk()->json('data'), 'code');

        $this->assertSame(['LA_MIENNE'], $codes);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function roles(): array
    {
        return ['gérante' => ['MANAGER', 200], 'professionnel' => ['CLINICIAN', 200], 'accueil' => ['RECEPTION', 403], 'caisse' => ['CASHIER', 403]];
    }

    #[DataProvider('roles')]
    public function test_la_lecture_exige_la_permission_clinique(string $role, int $status): void
    {
        $user = $this->makeStaff($this->clinicId, $role);

        $this->actingAsFresh($user)->getJson('/api/v1/visual-acuity-scales')->assertStatus($status);
    }

    public function test_module_ferme_404_et_sans_connexion_401(): void
    {
        $this->flushSession();
        $this->nextRequest()->getJson('/api/v1/visual-acuity-scales')->assertStatus(401);

        config(['eeo.enabled_modules' => ['today', 'patients']]);
        $this->actingAsFresh($this->manager)->getJson('/api/v1/visual-acuity-scales')->assertNotFound();
    }

    public function test_la_commande_cree_les_trois_echelles_de_demonstration_fictives_une_seule_fois(): void
    {
        $this->artisan('eeo:clinical:scales', ['--demo' => true, '--clinic' => $this->clinicId])->assertSuccessful();
        $this->artisan('eeo:clinical:scales', ['--demo' => true, '--clinic' => $this->clinicId])->assertSuccessful();

        $scales = DB::table('visual_acuity_scales')->where('clinic_id', $this->clinicId)->orderBy('code')->get();
        $codes = $scales->pluck('code')->all();
        $expected = array_keys(ManageAcuityScales::DEMO_SCALES);
        sort($codes);
        sort($expected);
        $this->assertSame($expected, $codes);
        $this->assertCount(3, $scales, 'Idempotente : un code déjà présent n\'est ni dupliqué ni modifié.');
        foreach ($scales as $scale) {
            $this->assertStringContainsString('DÉMONSTRATION', (string) $scale->label, 'Aucune échelle de démonstration ne se fait passer pour une échelle validée.');
            $this->assertTrue((bool) $scale->active);
        }
        $this->assertSame(3, DB::table('audit_events')->where('clinic_id', $this->clinicId)->where('action', 'clinical.acuity_scale_created')->count());
    }

    public function test_les_echelles_de_demonstration_sont_refusees_en_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('eeo:clinical:scales', ['--demo' => true, '--clinic' => $this->clinicId])->assertFailed();

        $this->assertSame(0, DB::table('visual_acuity_scales')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_l_echelle_reelle_du_cabinet_s_ajoute_dans_tout_environnement_sans_conversion(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('eeo:clinical:scales', ['--code' => 'DIXIEMES_CABINET', '--label' => 'Dixièmes du cabinet', '--description' => 'Choisie par la cliente (fictif)', '--clinic' => $this->clinicId])->assertSuccessful();

        $row = DB::table('visual_acuity_scales')->where('clinic_id', $this->clinicId)->where('code', 'DIXIEMES_CABINET')->first();
        $this->assertNotNull($row);
        $this->assertSame('Dixièmes du cabinet', $row->label);
        $this->assertSame('Choisie par la cliente (fictif)', $row->description);
        $this->assertTrue((bool) $row->active);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidInvocations(): array
    {
        return [
            'ni démonstration ni code' => [[]],
            'les deux à la fois' => [['--demo' => true, '--code' => 'X', '--label' => 'X']],
            'code en minuscules' => [['--code' => 'minuscules', '--label' => 'Libellé']],
            'code trop long' => [['--code' => str_repeat('A', 33), '--label' => 'Libellé']],
            'libellé absent' => [['--code' => 'SANS_LIBELLE']],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('invalidInvocations')]
    public function test_une_invocation_incorrecte_echoue_sans_rien_ecrire(array $options): void
    {
        $this->artisan('eeo:clinical:scales', [...$options, '--clinic' => $this->clinicId])->assertFailed();

        $this->assertSame(0, DB::table('visual_acuity_scales')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_un_cabinet_inconnu_est_refuse(): void
    {
        $this->artisan('eeo:clinical:scales', ['--demo' => true, '--clinic' => Ulid::generate()])->assertFailed();

        $this->assertSame(0, DB::table('visual_acuity_scales')->count());
    }
}
