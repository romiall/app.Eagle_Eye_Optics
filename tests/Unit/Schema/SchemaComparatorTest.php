<?php

namespace Tests\Unit\Schema;

use App\Support\Schema\SchemaComparator;
use PHPUnit\Framework\TestCase;

/**
 * Comparaison stricte : tout écart de métadonnées métier est listé avec son chemin ; seules les tables
 * techniques déclarées sont admises en plus ; les attributs de session restent des avertissements.
 */
final class SchemaComparatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'counts' => ['business_tables' => 1],
            'missing_business_tables' => [],
            'other_tables' => [],
            'tables' => [
                'invoices' => [
                    'engine' => 'InnoDB',
                    'columns' => ['total_gross' => ['position' => 5, 'type' => 'bigint', 'nullable' => 'NO']],
                    'checks' => ['ck_invoices_01' => ['clause' => '(`total_gross` >= 0)', 'enforced' => 'YES']],
                    'foreign_keys' => [],
                    'indexes' => [],
                ],
            ],
            'views' => ['vw_invoice_balances' => ['definition' => 'select 1', 'security_type' => 'INVOKER']],
            'triggers' => ['tr_invoices_update_guard' => ['table' => 'invoices', 'statement' => 'BEGIN END']],
            'reference_data' => ['roles' => ['rows' => 4, 'sha256' => 'abc']],
            'session_attributes' => ['triggers' => ['tr_invoices_update_guard' => ['sql_mode' => 'STRICT_TRANS_TABLES']]],
        ];
    }

    public function test_deux_photographies_identiques_ne_donnent_aucun_ecart(): void
    {
        $result = (new SchemaComparator)->compare($this->snapshot(), $this->snapshot());

        $this->assertSame([], $result['differences']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_un_type_de_colonne_modifie_est_un_ecart_localise(): void
    {
        $actual = $this->snapshot();
        $actual['tables']['invoices']['columns']['total_gross']['type'] = 'int';

        $result = (new SchemaComparator)->compare($this->snapshot(), $actual);

        $this->assertSame([[
            'path' => 'tables.invoices.columns.total_gross.type',
            'expected' => 'bigint',
            'actual' => 'int',
        ]], $result['differences']);
    }

    public function test_un_check_ou_un_trigger_absent_est_un_ecart(): void
    {
        $actual = $this->snapshot();
        unset($actual['tables']['invoices']['checks']['ck_invoices_01'], $actual['triggers']['tr_invoices_update_guard']);

        $paths = array_column((new SchemaComparator)->compare($this->snapshot(), $actual)['differences'], 'path');

        $this->assertContains('tables.invoices.checks', $paths);
        $this->assertContains('triggers', $paths);
    }

    public function test_un_referentiel_modifie_est_un_ecart(): void
    {
        $actual = $this->snapshot();
        $actual['reference_data']['roles']['sha256'] = 'autre';

        $paths = array_column((new SchemaComparator)->compare($this->snapshot(), $actual)['differences'], 'path');

        $this->assertSame(['reference_data.roles.sha256'], $paths);
    }

    public function test_les_tables_techniques_sont_inventoriees_et_les_autres_refusees(): void
    {
        $actual = $this->snapshot();
        $actual['other_tables'] = ['fw_sessions', 'migrations', 'patients'];

        $result = (new SchemaComparator)->compare($this->snapshot(), $actual);

        $this->assertSame(['fw_sessions', 'migrations'], $result['framework_tables']);
        $this->assertSame(['patients'], $result['unexpected_tables']);
        $this->assertSame(['other_tables.patients'], array_column($result['differences'], 'path'));
    }

    public function test_un_attribut_de_session_different_reste_un_avertissement(): void
    {
        $actual = $this->snapshot();
        $actual['session_attributes']['triggers']['tr_invoices_update_guard']['sql_mode'] = 'NO_ZERO_DATE';

        $result = (new SchemaComparator)->compare($this->snapshot(), $actual);

        $this->assertSame([], $result['differences']);
        $this->assertSame(['session_attributes.triggers.tr_invoices_update_guard.sql_mode'], array_column($result['warnings'], 'path'));
    }
}
