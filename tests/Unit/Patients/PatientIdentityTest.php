<?php

namespace Tests\Unit\Patients;

use App\Domain\Patients\Rules\PatientIdentity;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\ValueObjects\CivilDate;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Règles d'identité d'un patient (P01) et rendu du numéro de dossier : fonctions pures, sans base. Données
 * fictives.
 */
final class PatientIdentityTest extends TestCase
{
    private function today(): CivilDate
    {
        return CivilDate::of(2026, 10, 22);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, list<string>>
     */
    private function errors(array $overrides, string $prefix = ''): array
    {
        try {
            PatientIdentity::columns($this->input($overrides), $this->today(), $prefix);
        } catch (BusinessError $error) {
            return $error->fields;
        }

        $this->fail('La saisie devait être refusée.');
    }

    public function test_les_colonnes_derivees_sont_calculees_par_le_serveur(): void
    {
        $columns = PatientIdentity::columns($this->input([
            'last_name' => "  N'Guessan-Kouamé ",
            'first_names' => 'Élodie',
            'phone_primary' => '00237 600-00-00-01',
            'phone_secondary' => '(699) 00 00 02',
        ]), $this->today());

        $this->assertSame("N'Guessan-Kouamé", $columns['last_name']);
        $this->assertSame('n guessan kouame elodie', $columns['name_search']);
        $this->assertSame('+237600000001', $columns['phone_primary_normalized']);
        $this->assertSame('699000002', $columns['phone_secondary_normalized']);
        $this->assertSame('00237 600-00-00-01', $columns['phone_primary'], 'Le téléphone saisi est conservé.');
        $this->assertSame('1990-05-12', $columns['birth_date']);
    }

    public function test_les_textes_vides_deviennent_null_jamais_une_chaine_vide(): void
    {
        $columns = PatientIdentity::columns($this->input(['first_names' => '   ', 'address' => '', 'profession' => "\u{00A0}"]), $this->today());

        $this->assertNull($columns['first_names']);
        $this->assertNull($columns['address']);
        $this->assertNull($columns['profession']);
    }

    public function test_une_raison_n_est_gardee_que_si_elle_explique_une_valeur_absente(): void
    {
        $known = PatientIdentity::columns($this->input(['birth_date_missing_reason' => 'inutile', 'phone_missing_reason' => 'inutile']), $this->today());
        $this->assertNull($known['birth_date_missing_reason']);
        $this->assertNull($known['phone_missing_reason']);

        $missing = PatientIdentity::columns($this->input(['birth_date' => null, 'birth_date_missing_reason' => 'Âge inconnu', 'phone_primary' => null, 'phone_missing_reason' => 'Pas de téléphone']), $this->today());
        $this->assertNull($missing['birth_date']);
        $this->assertSame('Âge inconnu', $missing['birth_date_missing_reason']);
        $this->assertNull($missing['phone_primary_normalized']);
        $this->assertSame('Pas de téléphone', $missing['phone_missing_reason']);
    }

    public function test_la_date_francaise_est_acceptee_et_rendue_au_format_iso(): void
    {
        $this->assertSame('1990-10-22', PatientIdentity::columns($this->input(['birth_date' => '22/10/1990']), $this->today())['birth_date']);
    }

    public function test_aujourd_hui_est_accepte_et_demain_refuse(): void
    {
        $this->assertSame('2026-10-22', PatientIdentity::columns($this->input(['birth_date' => '2026-10-22']), $this->today())['birth_date']);
        $this->assertArrayHasKey('birth_date', $this->errors(['birth_date' => '2026-10-23']));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): array
    {
        return [
            'nom vide' => [['last_name' => ''], 'last_name'],
            'nom de blancs' => [['last_name' => " \t "], 'last_name'],
            'nom de ponctuation' => [['last_name' => '- . -'], 'last_name'],
            'nom trop long' => [['last_name' => str_repeat('é', 121)], 'last_name'],
            'sexe inconnu' => [['sex' => 'X'], 'sex'],
            'sexe absent du contrat' => [['sex' => 1], 'sex'],
            'naissance impossible' => [['birth_date' => '2026-02-30'], 'birth_date'],
            'naissance mal formée' => [['birth_date' => '1990/10/22'], 'birth_date'],
            'naissance vide sans raison' => [['birth_date' => '  '], 'birth_date_missing_reason'],
            'naissance inconnue sans raison' => [['birth_date' => null], 'birth_date_missing_reason'],
            'téléphone avec lettres' => [['phone_primary' => '60O 00 00 01'], 'phone_primary'],
            'téléphone de 2 chiffres' => [['phone_primary' => '12'], 'phone_primary'],
            'téléphone secondaire invalide' => [['phone_secondary' => 'abc'], 'phone_secondary'],
            'téléphone inconnu sans raison' => [['phone_primary' => null], 'phone_missing_reason'],
            'contact non objet' => [['guardian_contact' => 'Responsable'], 'guardian_contact'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalid')]
    public function test_une_saisie_invalide_est_refusee_sur_le_bon_champ(array $overrides, string $field): void
    {
        $this->assertArrayHasKey($field, $this->errors($overrides));
    }

    public function test_toutes_les_erreurs_sont_rendues_ensemble_avec_le_prefixe_de_modification(): void
    {
        $fields = $this->errors(['last_name' => '', 'sex' => 'X', 'birth_date' => null, 'phone_primary' => null], 'changes.');

        $this->assertSame(['changes.last_name', 'changes.sex', 'changes.birth_date_missing_reason', 'changes.phone_missing_reason'], array_keys($fields));
    }

    public function test_le_contact_du_responsable_est_un_objet_json_ou_null(): void
    {
        $this->assertNull(PatientIdentity::columns($this->input(['guardian_contact' => []]), $this->today())['guardian_contact']);

        $columns = PatientIdentity::columns($this->input(['guardian_contact' => ['nom' => 'Responsable fictif']]), $this->today());
        $this->assertSame('{"nom":"Responsable fictif"}', $columns['guardian_contact']);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function renderedNumbers(): array
    {
        return [
            'premier' => [2026, 1, 'EE-2026-0001'],
            'quatre chiffres' => [2026, 9999, 'EE-2026-9999'],
            'cinq chiffres sans troncature' => [2026, 10000, 'EE-2026-10000'],
            'sept chiffres' => [2027, 1234567, 'EE-2027-1234567'],
        ];
    }

    #[DataProvider('renderedNumbers')]
    public function test_le_numero_de_dossier_a_le_format_ee_annee_numero(int $year, int $value, string $expected): void
    {
        $this->assertSame($expected, DocumentNumbers::render(DocumentNumbers::PATIENT, $year, $value));
        $this->assertSame(1, preg_match('/^EE-(\d{4})-(\d{4,})\z/', $expected));
    }

    public function test_aucun_format_n_est_invente_pour_les_series_non_validees(): void
    {
        $this->expectException(LogicException::class);

        DocumentNumbers::render('INVOICE', 2026, 1);
    }
}
