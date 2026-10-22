<?php

namespace Tests\Unit\Support;

use App\Support\Modules;
use Tests\TestCase;

/**
 * Mise en service progressive : la liste vient de la configuration du déploiement, dans l'ordre de la
 * navigation, et une valeur inconnue ne rend jamais un module accessible.
 */
final class ModulesTest extends TestCase
{
    public function test_par_defaut_aucun_module_n_est_expose(): void
    {
        config(['eeo.enabled_modules' => []]);

        $this->assertSame([], Modules::enabled());
        $this->assertFalse(Modules::isEnabled('patients'));
    }

    public function test_les_modules_actives_sont_rendus_dans_l_ordre_de_la_navigation(): void
    {
        config(['eeo.enabled_modules' => ['sales', 'patients', 'today']]);

        $this->assertSame(['today', 'patients', 'sales'], Modules::enabled());
        $this->assertTrue(Modules::isEnabled('sales'));
        $this->assertFalse(Modules::isEnabled('stock'));
    }

    public function test_une_valeur_inconnue_est_ignoree(): void
    {
        config(['eeo.enabled_modules' => ['patient', 'PATIENTS', 'patients', '']]);

        $this->assertSame(['patients'], Modules::enabled());
        $this->assertFalse(Modules::isEnabled('patient'));
    }

    public function test_une_configuration_invalide_n_ouvre_rien(): void
    {
        config(['eeo.enabled_modules' => 'patients']);

        $this->assertSame([], Modules::enabled());
    }

    public function test_la_variable_d_environnement_est_lue_comme_une_liste_separee_par_des_virgules(): void
    {
        $previous = getenv('EEO_ENABLED_MODULES');
        putenv('EEO_ENABLED_MODULES= patients , sales,, ');
        $_ENV['EEO_ENABLED_MODULES'] = ' patients , sales,, ';
        try {
            $config = require base_path('config/eeo.php');
        } finally {
            if ($previous === false) {
                putenv('EEO_ENABLED_MODULES');
                unset($_ENV['EEO_ENABLED_MODULES']);
            } else {
                putenv('EEO_ENABLED_MODULES='.$previous);
                $_ENV['EEO_ENABLED_MODULES'] = $previous;
            }
        }

        $this->assertSame(['patients', 'sales'], $config['enabled_modules']);
    }
}
