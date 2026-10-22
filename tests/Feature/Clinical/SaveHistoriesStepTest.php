<?php

namespace Tests\Feature\Clinical;

use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Rubrique HISTORIES (C01, AT18) : antécédents oculaires, médicaux et familiaux, état observé pendant la
 * consultation. « Aucun connu » exclusif, jamais stocké comme terme et distinct de « non renseigné » (groupe
 * absent) ; « Autre » expliqué ; un élément par groupe ; remplacement complet par identifiant de ligne. Données
 * fictives.
 */
final class SaveHistoriesStepTest extends TestCase
{
    use ClinicalFixtures;
    use MigratedDatabase;

    private string $consultationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootClinical();
        [, $this->consultationId] = $this->startedExam();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $histories
     * @return array<string, mixed>
     */
    private function data(array $histories): array
    {
        return ['histories' => $histories];
    }

    public function test_les_antecedents_sont_enregistres_et_relus_dans_l_ordre_des_groupes(): void
    {
        $family = $this->history('FAMILY_HISTORY', false, [$this->term('GLAUCOMA')]);
        $ocular = $this->history('OCULAR_HISTORY', true);
        $medical = $this->history('MEDICAL_HISTORY', false, [$this->term('OTHER', 'Asthme (fictif)'), $this->term('DIABETES')], 'Suivi régulier (fictif)');

        $saved = $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$family, $ocular, $medical]))->assertOk();

        $this->assertMatchesContract($saved, 'schemas/resources/clinical.json#/$defs/ConsultationResponse');
        $histories = $saved->json('data.steps.HISTORIES.histories');
        $this->assertSame(['OCULAR_HISTORY', 'MEDICAL_HISTORY', 'FAMILY_HISTORY'], array_column($histories, 'history_group'));
        $this->assertTrue($histories[0]['known_none']);
        $this->assertSame([], $histories[0]['terms']);
        $this->assertFalse($histories[1]['known_none']);
        $this->assertSame('Suivi régulier (fictif)', $histories[1]['comments']);
        $this->assertSame(['DIABETES', 'OTHER'], array_column($histories[1]['terms'], 'term_code'));
        $this->assertSame('Asthme (fictif)', $histories[1]['terms'][1]['other_text']);
        $this->assertSame($histories, $this->readConsultation($this->consultationId)->json('data.steps.HISTORIES.histories'));

        $this->assertSame(0, DB::table('consultation_history_terms')->where('term_code', 'NONE')->count(), '« Aucun connu » n\'est jamais stocké comme terme.');
        $this->assertSame(3, DB::table('consultation_histories')->count());
        $this->assertSame(3, DB::table('consultation_history_terms')->count());
    }

    public function test_un_groupe_absent_est_non_renseigne_et_distinct_d_aucun_connu(): void
    {
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$this->history('MEDICAL_HISTORY', true)]))->assertOk();

        $histories = $this->readConsultation($this->consultationId)->json('data.steps.HISTORIES.histories');

        $this->assertCount(1, $histories, 'Les groupes jamais saisis n\'ont aucune ligne (non renseigné).');
        $this->assertSame('MEDICAL_HISTORY', $histories[0]['history_group']);
        $this->assertTrue($histories[0]['known_none']);
    }

    public function test_un_element_vide_avec_commentaire_seul_est_admis(): void
    {
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$this->history('OCULAR_HISTORY', false, [], 'Commentaire seul')]))
            ->assertOk()
            ->assertJsonPath('data.steps.HISTORIES.histories.0.comments', 'Commentaire seul')
            ->assertJsonPath('data.steps.HISTORIES.histories.0.known_none', false);
    }

    public function test_aucun_connu_exclut_les_autres_elements_du_groupe(): void
    {
        $response = $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$this->history('OCULAR_HISTORY', true, [$this->term('GLAUCOMA')])]));

        $response->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['step.data.histories.0.terms']]]);
        $this->assertSame(0, DB::table('consultation_histories')->count());
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string}>
     */
    public static function invalidHistories(): array
    {
        $id = Ulid::generate();
        $ocular = static fn (array $terms, string $group = 'OCULAR_HISTORY'): array => ['id' => Ulid::generate(), 'history_group' => $group, 'known_none' => false, 'comments' => null, 'terms' => $terms];
        $term = static fn (string $code, ?string $text = null, ?string $termId = null): array => ['id' => $termId ?? Ulid::generate(), 'term_code' => $code, 'other_text' => $text];

        return [
            'aucun connu comme terme' => [[$ocular([$term('NONE')])], 'step.data.histories.0.terms.0.term_code'],
            'terme d\'un autre groupe' => [[$ocular([$term('DIABETES')])], 'step.data.histories.0.terms.0.term_code'],
            'terme familial dans les oculaires' => [[$ocular([$term('HIGH_MYOPIA')])], 'step.data.histories.0.terms.0.term_code'],
            'terme inconnu' => [[$ocular([$term('INVENTED')])], 'step.data.histories.0.terms.0.term_code'],
            'autre sans texte' => [[$ocular([$term('OTHER')])], 'step.data.histories.0.terms.0.other_text'],
            'texte sans autre' => [[$ocular([$term('CATARACT', 'Précision')])], 'step.data.histories.0.terms.0.other_text'],
            'terme en double' => [[$ocular([$term('CATARACT'), $term('CATARACT')])], 'step.data.histories.0.terms.1.term_code'],
            'groupe en double' => [[$ocular([]), $ocular([])], 'step.data.histories.1.history_group'],
            'identifiant de terme en double entre deux groupes' => [[$ocular([$term('CATARACT', null, $id)]), $ocular([$term('DIABETES', null, $id)], 'MEDICAL_HISTORY')], 'step.data.histories.1.terms.0.id'],
            'identifiant d\'élément en double' => [[['id' => $id, 'history_group' => 'OCULAR_HISTORY', 'known_none' => false, 'comments' => null, 'terms' => []], ['id' => $id, 'history_group' => 'MEDICAL_HISTORY', 'known_none' => false, 'comments' => null, 'terms' => []]], 'step.data.histories.1.id'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $histories
     */
    #[DataProvider('invalidHistories')]
    public function test_des_antecedents_invalides_sont_refuses_champ_par_champ_sans_rien_ecrire(array $histories, string $field): void
    {
        $response = $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data($histories));

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey($field, $response->json('error.fields'));
        $this->assertSame(0, DB::table('consultation_histories')->count());
        $this->assertSame(0, DB::table('consultation_history_terms')->count());
        $this->assertSame(1, (int) DB::table('consultations')->where('id', $this->consultationId)->value('version'));
    }

    public function test_un_groupe_inconnu_ou_plus_de_trois_elements_sont_refuses_par_le_contrat(): void
    {
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$this->history('SURGICAL_HISTORY')]))->assertStatus(422);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([
            $this->history('OCULAR_HISTORY'), $this->history('MEDICAL_HISTORY'), $this->history('FAMILY_HISTORY'), $this->history('OCULAR_HISTORY'),
        ]))->assertStatus(422);
        $this->assertSame(0, DB::table('consultation_histories')->count());
    }

    public function test_le_remplacement_complet_supprime_les_groupes_et_termes_omis_et_garde_les_autres(): void
    {
        $diabetes = $this->term('DIABETES');
        $allergies = $this->term('ALLERGIES');
        $medical = $this->history('MEDICAL_HISTORY', false, [$diabetes, $allergies]);
        $ocular = $this->history('OCULAR_HISTORY', false, [$this->term('SURGERY')]);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$medical, $ocular]))->assertOk();

        // Le groupe oculaire et l'allergie disparaissent ; le diabète garde son identifiant ; le commentaire change.
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([[...$medical, 'comments' => 'Mis à jour', 'terms' => [$diabetes]]]))->assertOk();

        $this->assertSame([$medical['id']], DB::table('consultation_histories')->pluck('id')->all());
        $this->assertSame([$diabetes['id']], DB::table('consultation_history_terms')->pluck('id')->all());
        $this->assertSame('Mis à jour', DB::table('consultation_histories')->value('comments'));

        // Tout omettre efface tout.
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([]))->assertOk()->assertJsonPath('data.steps.HISTORIES.histories', []);
        $this->assertSame(0, DB::table('consultation_histories')->count() + DB::table('consultation_history_terms')->count());
    }

    public function test_passer_a_aucun_connu_retire_les_termes_du_groupe(): void
    {
        $medical = $this->history('MEDICAL_HISTORY', false, [$this->term('HYPERTENSION')]);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$medical]))->assertOk();

        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([[...$medical, 'known_none' => true, 'terms' => []]]))->assertOk();

        $this->assertSame(1, (int) DB::table('consultation_histories')->value('known_none'));
        $this->assertSame(0, DB::table('consultation_history_terms')->count());
    }

    public function test_un_element_peut_changer_de_groupe_et_deux_elements_echanger_leurs_groupes(): void
    {
        $a = $this->history('OCULAR_HISTORY', false, [$this->term('CATARACT')]);
        $b = $this->history('MEDICAL_HISTORY', false, [$this->term('DIABETES')]);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$a, $b]))->assertOk();

        // Échange des groupes : l'unicité (consultation, groupe) et la clé étrangère (élément, groupe) tiennent.
        $swappedA = [...$a, 'history_group' => 'MEDICAL_HISTORY', 'terms' => [$this->term('ALLERGIES')]];
        $swappedB = [...$b, 'history_group' => 'OCULAR_HISTORY', 'terms' => [$this->term('GLAUCOMA')]];
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$swappedA, $swappedB]))->assertOk();

        $this->assertSame('MEDICAL_HISTORY', DB::table('consultation_histories')->where('id', $a['id'])->value('history_group'));
        $this->assertSame('OCULAR_HISTORY', DB::table('consultation_histories')->where('id', $b['id'])->value('history_group'));
        $this->assertSame(
            ['ALLERGIES', 'GLAUCOMA'],
            DB::table('consultation_history_terms')->orderBy('term_code')->pluck('term_code')->all(),
        );
    }

    public function test_un_terme_peut_changer_d_element_en_gardant_son_identifiant(): void
    {
        $moving = $this->term('GLAUCOMA');
        $ocular = $this->history('OCULAR_HISTORY', false, [$moving]);
        $family = $this->history('FAMILY_HISTORY', false, []);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$ocular, $family]))->assertOk();

        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([[...$ocular, 'terms' => []], [...$family, 'terms' => [$moving]]]))->assertOk();

        $this->assertSame($family['id'], DB::table('consultation_history_terms')->where('id', $moving['id'])->value('history_id'));
        $this->assertSame('FAMILY_HISTORY', DB::table('consultation_history_terms')->where('id', $moving['id'])->value('term_group'));
    }

    public function test_un_identifiant_deja_utilise_ailleurs_est_un_422_par_champ_et_non_une_erreur_sql(): void
    {
        $patient = $this->seedPatient($this->clinicId, 'Second Patient');
        $visitId = $this->seedVisit([], null, $patient);
        $body = $this->startBody($visitId, ['patient_id' => $patient]);
        $this->startConsultation($visitId, $body)->assertCreated();
        $takenTerm = $this->term('DIABETES');
        $taken = $this->history('MEDICAL_HISTORY', false, [$takenTerm]);
        $this->saveCurrent((string) $body['consultation_id'], 'HISTORIES', $this->data([$taken]))->assertOk();

        $response = $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([
            $this->history('OCULAR_HISTORY', false, [], null, $taken['id']),
            $this->history('MEDICAL_HISTORY', false, [$this->term('ALLERGIES', null, $takenTerm['id'])]),
        ]));

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('step.data.histories.0.id', $response->json('error.fields'));
        $this->assertArrayHasKey('step.data.histories.1.terms.0.id', $response->json('error.fields'));
        $this->assertSame(0, DB::table('consultation_histories')->where('consultation_id', $this->consultationId)->count());
        $this->assertSame('DIABETES', DB::table('consultation_history_terms')->where('id', $takenTerm['id'])->value('term_code'));
    }

    public function test_un_enregistrement_identique_ne_change_pas_la_version(): void
    {
        $data = $this->data([$this->history('MEDICAL_HISTORY', false, [$this->term('DIABETES'), $this->term('OTHER', 'Précision')], 'Commentaire'), $this->history('OCULAR_HISTORY', true)]);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $data)->assertOk()->assertJsonPath('data.version', 2);

        $reordered = $this->data(array_reverse($data['histories']));
        $this->saveCurrent($this->consultationId, 'HISTORIES', $reordered)->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_le_texte_autre_d_un_antecedent_existant_se_met_a_jour_en_base_et_a_la_relecture(): void
    {
        $term = $this->term('OTHER', 'Avant');
        $history = $this->history('MEDICAL_HISTORY', false, [$term]);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$history]))->assertOk();

        // Même élément, même terme (mêmes identifiants), seul le texte « Autre » change.
        $changed = $this->history('MEDICAL_HISTORY', false, [$this->term('OTHER', 'Après', $term['id'])], null, $history['id']);
        $response = $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$changed]))->assertOk();

        $response->assertJsonPath('data.version', 3)->assertJsonPath('data.steps.HISTORIES.histories.0.terms.0.other_text', 'Après');
        $this->assertSame('Après', DB::table('consultation_history_terms')->where('id', $term['id'])->value('other_text'), 'Une modification clinique ne se perd pas sans erreur.');
        $this->assertSame('Après', $this->readConsultation($this->consultationId)->json('data.steps.HISTORIES.histories.0.terms.0.other_text'));
    }

    public function test_aucun_connu_peut_repasser_a_faux_avec_des_termes(): void
    {
        $history = $this->history('OCULAR_HISTORY', true);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$history]))->assertOk();

        $changed = $this->history('OCULAR_HISTORY', false, [$this->term('GLAUCOMA')], null, $history['id']);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$changed]))->assertOk()
            ->assertJsonPath('data.steps.HISTORIES.histories.0.known_none', false)
            ->assertJsonPath('data.steps.HISTORIES.histories.0.terms.0.term_code', 'GLAUCOMA');
        $this->assertSame(0, (int) DB::table('consultation_histories')->where('id', $history['id'])->value('known_none'));
    }

    public function test_un_code_desactive_depuis_la_saisie_reste_enregistrable_pour_la_meme_ligne_mais_pas_pour_une_ligne_neuve(): void
    {
        $term = $this->term('CATARACT');
        $history = $this->history('OCULAR_HISTORY', false, [$term]);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$history]))->assertOk();
        DB::table('clinical_terms')->where('group_code', 'OCULAR_HISTORY')->whereIn('code', ['CATARACT', 'GLAUCOMA'])->update(['active' => false]);

        // La ligne déjà enregistrée (même élément, même identifiant, même code) reste ré-enregistrable avec un commentaire.
        $kept = $this->history('OCULAR_HISTORY', false, [$this->term('CATARACT', null, $term['id'])], 'Commentaire ajouté', $history['id']);
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$kept]))->assertOk()
            ->assertJsonPath('data.steps.HISTORIES.histories.0.terms.0.term_code', 'CATARACT');

        // Une ligne neuve avec un code désactivé reste refusée.
        $withNew = $this->history('OCULAR_HISTORY', false, [$this->term('CATARACT', null, $term['id']), $this->term('GLAUCOMA')], 'Commentaire ajouté', $history['id']);
        $response = $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$withNew]));
        $response->assertStatus(422);
        $this->assertArrayHasKey('step.data.histories.0.terms.1.term_code', $response->json('error.fields'));
        $this->assertArrayNotHasKey('step.data.histories.0.terms.0.term_code', $response->json('error.fields'));
    }

    public function test_aucune_valeur_clinique_ne_sort_des_tables_de_la_consultation(): void
    {
        $this->saveCurrent($this->consultationId, 'HISTORIES', $this->data([$this->history('MEDICAL_HISTORY', false, [$this->term('OTHER', 'SENTINELLE-ANTECEDENT-KP3')], 'SENTINELLE-COMMENTAIRE-LM8')]))->assertOk();

        $dump = $this->sideEffectsDump();
        $this->assertStringNotContainsString('SENTINELLE-ANTECEDENT-KP3', $dump);
        $this->assertStringNotContainsString('SENTINELLE-COMMENTAIRE-LM8', $dump);
        $this->assertEquals(['step' => 'HISTORIES', 'version' => 2], json_decode((string) DB::table('audit_events')->where('action', 'consultation.step_saved')->value('sanitized_diff'), true));
    }
}
