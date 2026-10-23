<?php

namespace Tests\Unit\Offline;

use App\Offline\Compartments;
use Tests\TestCase;

/**
 * Contrat des compartiments (`contracts/compartments.json`, vault.md D16 à D18) : chacune des 49 permissions du
 * référentiel est classée exactement une fois, chaque commande du journal d'un poste est scellée dans un seul
 * compartiment, et les rôles du référentiel ouvrent les compartiments attendus (l'accueil jamais la finance ni la
 * clinique, la caisse jamais la clinique).
 */
final class CompartmentsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function seededPermissions(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3).'/database/02_reference_data.sql');
        $start = strpos($sql, 'INSERT INTO `permissions`');
        self::assertNotFalse($start);
        $end = strpos($sql, ';', $start);
        preg_match_all("/\\('([a-z_]+\\.[a-z_.]+)'/", substr($sql, $start, $end === false ? null : $end - $start), $matches);

        return $matches[1];
    }

    public function test_chaque_permission_du_referentiel_est_classee_une_fois_et_seulement_elles(): void
    {
        $seeded = self::seededPermissions();
        $classified = array_keys(Compartments::permissionMap());
        sort($seeded);
        sort($classified);

        $this->assertCount(49, $seeded);
        $this->assertSame($seeded, $classified);

        $raw = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/contracts/compartments.json'), true);
        $all = [];
        foreach ($raw['permission_classes'] as $class) {
            array_push($all, ...$class['permissions']);
        }
        $this->assertSame(count($all), count(array_unique($all)), 'Une permission classée deux fois');
    }

    public function test_chaque_commande_du_journal_est_scellee_dans_un_seul_compartiment(): void
    {
        $files = glob(dirname(__DIR__, 3).'/contracts/commands/*.v1.json') ?: [];
        $commands = array_map(static fn (string $file): string => basename($file, '.v1.json'), $files);
        $sealed = array_keys(Compartments::commandMap());
        sort($commands);
        sort($sealed);

        $this->assertSame($commands, $sealed);
        foreach ($sealed as $command) {
            $this->assertContains(Compartments::ofCommand($command), Compartments::ORDER, $command);
        }
    }

    public function test_les_roles_du_referentiel_ouvrent_les_compartiments_attendus(): void
    {
        $this->assertSame(['ADMINISTRATIVE'], Compartments::of(['patient.read', 'patient.create', 'visit.register', 'exam_fee.collect', 'agenda.read']));
        $this->assertSame(['ADMINISTRATIVE', 'CLINICAL'], Compartments::of(['patient.read', 'clinical.read', 'clinical.write']));
        $this->assertSame(['ADMINISTRATIVE', 'FINANCIAL'], Compartments::of(['patient.read', 'invoice.read', 'payment.collect', 'cash.own_session']));
        $this->assertSame(['ADMINISTRATIVE', 'CLINICAL', 'FINANCIAL', 'MANAGER'], Compartments::of(['offline.enroll', 'invoice.read', 'clinical.read', 'patient.read']));
    }

    public function test_les_frais_d_examen_ne_donnent_pas_la_cle_financiere_et_une_permission_inconnue_rien(): void
    {
        $this->assertSame(['ADMINISTRATIVE'], Compartments::of(['exam_fee.collect']));
        $this->assertSame([], Compartments::of(['workshop.manage', 'integration.manage', 'inconnue.permission']));
    }
}
