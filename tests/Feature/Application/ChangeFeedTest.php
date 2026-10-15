<?php

namespace Tests\Feature\Application;

use App\Application\Effects\ChangeVisibility;
use App\Application\Publication\ChangeFeed;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Lecture ordonnée du journal des changements pour le pull (fichier 19, contrat 23) : page bornée, borne
 * supérieure qui avance aussi sur les entrées filtrées, jamais de changement d'un autre cabinet. L'ordre de
 * publication face à une mutation en cours (verrou du cabinet) est prouvé entre deux connexions réelles dans
 * tests/Concurrency/CommandBusConcurrencyTest.php.
 */
final class ChangeFeedTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;

    private string $clinicId;

    private ChangeFeed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinicId = $this->makeClinic();
        $this->feed = $this->app->make(ChangeFeed::class);
    }

    public function test_un_journal_vide_garde_le_curseur_la_ou_il_est(): void
    {
        $page = $this->feed->page($this->clinicId, 0);

        $this->assertSame(['changes' => [], 'upper_bound' => 0, 'has_more' => false], $page);
        $this->assertSame(41, $this->feed->page($this->clinicId, 41)['upper_bound']);
    }

    public function test_les_changements_sont_rendus_dans_l_ordre_des_numeros_avec_une_borne_superieure(): void
    {
        $sequences = $this->record(['ADMINISTRATIVE', 'CLINICAL', 'FINANCIAL']);

        $page = $this->feed->page($this->clinicId, 0);

        $this->assertSame($sequences, array_map(static fn (object $row): int => (int) $row->change_sequence, $page['changes']));
        $this->assertSame($sequences[2], $page['upper_bound']);
        $this->assertFalse($page['has_more']);
    }

    public function test_une_page_bornee_indique_qu_il_en_reste_et_la_suivante_reprend_apres_la_borne(): void
    {
        $sequences = $this->record(['ADMINISTRATIVE', 'CLINICAL', 'FINANCIAL', 'MANAGER', 'PUBLIC']);

        $first = $this->feed->page($this->clinicId, 0, 2);
        $this->assertCount(2, $first['changes']);
        $this->assertTrue($first['has_more']);
        $this->assertSame($sequences[1], $first['upper_bound']);

        $second = $this->feed->page($this->clinicId, $first['upper_bound'], 2);
        $this->assertSame([$sequences[2], $sequences[3]], array_map(static fn (object $row): int => (int) $row->change_sequence, $second['changes']));
        $this->assertTrue($second['has_more']);

        $third = $this->feed->page($this->clinicId, $second['upper_bound'], 2);
        $this->assertSame([$sequences[4]], array_map(static fn (object $row): int => (int) $row->change_sequence, $third['changes']));
        $this->assertFalse($third['has_more']);
    }

    public function test_le_curseur_avance_aussi_sur_les_entrees_filtrees_et_ne_boucle_jamais(): void
    {
        // Un profil d'accueil ne lit que l'administratif : les changements cliniques et financiers lui sont
        // masqués, mais la borne les dépasse pour qu'il ne les rebalaie pas indéfiniment.
        $sequences = $this->record(['CLINICAL', 'FINANCIAL', 'CLINICAL', 'ADMINISTRATIVE', 'CLINICAL']);

        $page = $this->feed->page($this->clinicId, 0, 3, [ChangeVisibility::Administrative]);
        $this->assertSame([], $page['changes'], 'Les trois premières entrées sont masquées à ce profil.');
        $this->assertSame($sequences[2], $page['upper_bound'], 'Mais la borne les dépasse.');
        $this->assertTrue($page['has_more']);

        $next = $this->feed->page($this->clinicId, $page['upper_bound'], 3, [ChangeVisibility::Administrative]);
        $this->assertSame([$sequences[3]], array_map(static fn (object $row): int => (int) $row->change_sequence, $next['changes']));
        $this->assertSame($sequences[4], $next['upper_bound']);
        $this->assertFalse($next['has_more']);
    }

    public function test_les_changements_d_un_autre_cabinet_ne_sont_jamais_publies(): void
    {
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $mine = $this->record(['ADMINISTRATIVE']);
        $this->record(['ADMINISTRATIVE', 'ADMINISTRATIVE'], $otherClinic);

        $page = $this->feed->page($this->clinicId, 0);

        $this->assertCount(1, $page['changes']);
        $this->assertSame($mine[0], $page['upper_bound'], 'La borne ne dépasse pas le dernier changement de CE cabinet.');
        $this->assertSame($this->clinicId, $page['changes'][0]->clinic_id);
    }

    public function test_la_taille_de_page_est_bornee(): void
    {
        $this->record(['ADMINISTRATIVE', 'ADMINISTRATIVE']);

        $this->assertCount(2, $this->feed->page($this->clinicId, 0, 1_000)['changes']);
        $this->assertCount(1, $this->feed->page($this->clinicId, 0, 0)['changes'], 'Une taille nulle est ramenée à 1.');
    }

    /**
     * @param  list<string>  $visibilities
     * @return list<int> numéros de changement attribués, dans l'ordre
     */
    private function record(array $visibilities, ?string $clinicId = null): array
    {
        $clinicId ??= $this->clinicId;
        $sequences = [];

        foreach ($visibilities as $visibility) {
            $id = Ulid::generate();
            DB::table('sync_change_log')->insert([
                'id' => $id,
                'clinic_id' => $clinicId,
                'entity_type' => 'patient_records',
                'entity_id' => Ulid::generate(),
                'entity_version' => 1,
                'recorded_at' => '2026-10-14 10:00:00.000000',
                'visibility' => $visibility,
                'change_kind' => 'CREATED',
            ]);
            $sequences[] = (int) DB::table('sync_change_log')->where('id', $id)->value('change_sequence');
        }

        return $sequences;
    }
}
