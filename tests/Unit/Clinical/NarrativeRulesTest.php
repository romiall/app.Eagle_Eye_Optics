<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\ExamFeeDecision;
use App\Domain\Clinical\Rules\AnamnesisStep;
use App\Domain\Clinical\Rules\ClinicalInput;
use App\Domain\Clinical\Rules\ConclusionStep;
use App\Domain\Clinical\Rules\FieldErrors;
use App\Domain\Clinical\Rules\HistoriesStep;
use App\Domain\Clinical\Rules\TermList;
use App\Domain\Clinical\UncontrolledExamFee;
use App\Domain\Shared\Ulid;
use stdClass;
use Tests\TestCase;

/**
 * Règles pures des rubriques narratives du dossier (C01) : saisies élémentaires, listes de termes, anamnèse,
 * antécédents, conclusion. Sans base : les codes du référentiel sont fournis par le test. Données fictives.
 */
final class NarrativeRulesTest extends TestCase
{
    private const COMPLAINTS = ['VISION_DECLINE', 'HEADACHE', 'PAIN', 'OTHER'];

    private const CODES = [
        'OCULAR_HISTORY' => ['NONE', 'TRAUMA', 'GLAUCOMA', 'OTHER'],
        'MEDICAL_HISTORY' => ['NONE', 'DIABETES', 'OTHER'],
        'FAMILY_HISTORY' => ['NONE', 'GLAUCOMA', 'OTHER'],
    ];

    private const REASONS = ['CATARACT', 'OTHER'];

    /**
     * @return array{id: string, term_code: string, other_text: string|null}
     */
    private function term(string $code, ?string $text = null): array
    {
        return ['id' => Ulid::generate(), 'term_code' => $code, 'other_text' => $text];
    }

    public function test_un_texte_libre_est_ramene_a_null_s_il_est_vide_et_garde_ses_retours_a_la_ligne(): void
    {
        $errors = new FieldErrors;

        $this->assertNull(ClinicalInput::text(null, 'p', $errors));
        $this->assertNull(ClinicalInput::text("  \n\t ", 'p', $errors));
        $this->assertSame("a\nb", ClinicalInput::text("  a\nb \n", 'p', $errors));
        $this->assertSame('é', ClinicalInput::line(' é ', 'p', $errors));
        $this->assertTrue($errors->isEmpty());
    }

    public function test_un_texte_trop_long_ou_avec_caractere_de_controle_ou_mal_type_est_refuse(): void
    {
        $errors = new FieldErrors;

        $this->assertNull(ClinicalInput::text(str_repeat('é', 4001), 'long', $errors));
        $this->assertNull(ClinicalInput::text("a\u{0007}b", 'controle', $errors));
        $this->assertNull(ClinicalInput::line("a\nb", 'ligne', $errors));
        $this->assertNull(ClinicalInput::text(12, 'type', $errors));
        $this->assertSame(str_repeat('é', 4000), ClinicalInput::text(str_repeat('é', 4000), 'ok', $errors));

        $this->assertSame(['long', 'controle', 'ligne', 'type'], array_keys($errors->all()));
    }

    public function test_oui_non_et_non_renseigne_sont_trois_reponses_distinctes(): void
    {
        $errors = new FieldErrors;

        $this->assertTrue(ClinicalInput::triState(true, 'p', $errors));
        $this->assertFalse(ClinicalInput::triState(false, 'p', $errors));
        $this->assertNull(ClinicalInput::triState(null, 'p', $errors));
        $this->assertTrue($errors->isEmpty());

        $this->assertNull(ClinicalInput::triState(0, 'zero', $errors));
        $this->assertNull(ClinicalInput::triState('true', 'texte', $errors));
        $this->assertSame(['zero', 'texte'], array_keys($errors->all()), 'Ni 0 ni « true » ne valent une réponse.');
    }

    public function test_une_liste_de_termes_valide_garde_ses_lignes_telles_quelles(): void
    {
        $errors = new FieldErrors;
        $used = [];
        $items = [$this->term('HEADACHE'), $this->term('OTHER', ' Précision ')];

        $rows = TermList::normalize($items, self::COMPLAINTS, 'c', $errors, $used);

        $this->assertTrue($errors->isEmpty());
        $this->assertSame([$items[0]['id'], $items[1]['id']], array_column($rows, 'id'));
        $this->assertSame('Précision', $rows[1]['other_text']);
        $this->assertCount(2, $used);
    }

    public function test_une_liste_de_termes_signale_chaque_defaut_a_sa_ligne_et_ecarte_la_ligne(): void
    {
        $errors = new FieldErrors;
        $used = [];
        $dup = Ulid::generate();

        $rows = TermList::normalize([
            ['id' => $dup, 'term_code' => 'PAIN', 'other_text' => null],
            ['id' => $dup, 'term_code' => 'HEADACHE', 'other_text' => null],
            $this->term('PAIN'),
            $this->term('INVENTED'),
            $this->term('OTHER'),
            $this->term('VISION_DECLINE', 'Texte hors autre'),
            ['id' => 'x', 'term_code' => 'HEADACHE', 'other_text' => null],
            'pas un objet',
        ], self::COMPLAINTS, 'c', $errors, $used);

        $this->assertSame(['c.1.id', 'c.2.term_code', 'c.3.term_code', 'c.4.other_text', 'c.5.other_text', 'c.6.id', 'c.6.term_code', 'c.7'], array_keys($errors->all()));
        $this->assertCount(1, $rows, 'Seule la première ligne est valide.');
    }

    public function test_une_charge_qui_n_est_pas_une_liste_est_refusee(): void
    {
        $errors = new FieldErrors;
        $used = [];

        $this->assertSame([], TermList::normalize(['a' => 1], self::COMPLAINTS, 'c', $errors, $used));
        $this->assertSame([], TermList::normalize('texte', self::COMPLAINTS, 'd', $errors, $used));

        $this->assertSame(['c', 'd'], array_keys($errors->all()));
    }

    public function test_l_anamnese_normalise_et_refuse_les_valeurs_sans_objet_au_lieu_de_les_effacer(): void
    {
        $errors = new FieldErrors;

        $state = AnamnesisStep::normalize([
            'symptoms' => ' Gêne ',
            'wears_glasses' => true,
            'glasses_duration' => ' 3 ans ',
            'previous_correction_available' => false,
            'complaints' => [$this->term('PAIN')],
        ], self::COMPLAINTS, $errors);

        $this->assertTrue($errors->isEmpty());
        $this->assertSame('Gêne', $state['symptoms']);
        $this->assertSame('3 ans', $state['glasses_duration']);
        $this->assertFalse($state['previous_correction_available']);

        foreach ([false, null] as $wears) {
            $refused = new FieldErrors;
            AnamnesisStep::normalize(['symptoms' => null, 'wears_glasses' => $wears, 'glasses_duration' => '3 ans', 'previous_correction_available' => true, 'complaints' => []], self::COMPLAINTS, $refused);
            $this->assertSame(['step.data.glasses_duration', 'step.data.previous_correction_available'], array_keys($refused->all()));
        }
    }

    public function test_les_antecedents_refusent_aucun_connu_comme_terme_et_son_melange_avec_d_autres_elements(): void
    {
        $none = new FieldErrors;
        HistoriesStep::normalize(['histories' => [['id' => Ulid::generate(), 'history_group' => 'OCULAR_HISTORY', 'known_none' => false, 'comments' => null, 'terms' => [$this->term('NONE')]]]], self::CODES, $none);
        $this->assertSame(['step.data.histories.0.terms.0.term_code'], array_keys($none->all()));

        $mixed = new FieldErrors;
        $rows = HistoriesStep::normalize(['histories' => [['id' => Ulid::generate(), 'history_group' => 'OCULAR_HISTORY', 'known_none' => true, 'comments' => null, 'terms' => [$this->term('TRAUMA')]]]], self::CODES, $mixed);
        $this->assertSame(['step.data.histories.0.terms'], array_keys($mixed->all()));
        $this->assertSame([], $rows);
    }

    public function test_les_antecedents_valides_gardent_leur_groupe_et_leurs_termes(): void
    {
        $errors = new FieldErrors;
        $history = ['id' => Ulid::generate(), 'history_group' => 'MEDICAL_HISTORY', 'known_none' => false, 'comments' => ' Suivi ', 'terms' => [$this->term('DIABETES'), $this->term('OTHER', 'Asthme')]];

        $rows = HistoriesStep::normalize(['histories' => [$history, ['id' => Ulid::generate(), 'history_group' => 'FAMILY_HISTORY', 'known_none' => true, 'comments' => null, 'terms' => []]]], self::CODES, $errors);

        $this->assertTrue($errors->isEmpty());
        $this->assertCount(2, $rows);
        $this->assertSame('Suivi', $rows[0]['comments']);
        $this->assertTrue($rows[1]['known_none']);
    }

    public function test_les_antecedents_refusent_un_groupe_en_double_ou_inconnu_et_un_terme_d_un_autre_groupe(): void
    {
        $errors = new FieldErrors;
        $make = static fn (string $group, array $terms = []): array => ['id' => Ulid::generate(), 'history_group' => $group, 'known_none' => false, 'comments' => null, 'terms' => $terms];

        HistoriesStep::normalize(['histories' => [
            $make('OCULAR_HISTORY'),
            $make('OCULAR_HISTORY'),
            $make('SURGICAL_HISTORY'),
            $make('MEDICAL_HISTORY', [$this->term('TRAUMA')]),
        ]], self::CODES, $errors);

        $this->assertSame(['step.data.histories.1.history_group', 'step.data.histories.2.history_group', 'step.data.histories.3.terms.0.term_code'], array_keys($errors->all()));
    }

    public function test_la_conclusion_exige_le_niveau_quand_le_patient_est_oriente(): void
    {
        $errors = new FieldErrors;
        $state = ConclusionStep::normalize(['conclusion' => ' Fin ', 'referral' => true, 'referral_urgency' => 'RAPID', 'referral_reasons' => [$this->term('CATARACT')]], self::REASONS, $errors);

        $this->assertTrue($errors->isEmpty());
        $this->assertSame('Fin', $state['conclusion']);
        $this->assertSame('RAPID', $state['referral_urgency']);

        $missing = new FieldErrors;
        ConclusionStep::normalize(['conclusion' => null, 'referral' => true, 'referral_urgency' => null, 'referral_reasons' => []], self::REASONS, $missing);
        $this->assertSame(['step.data.referral_urgency'], array_keys($missing->all()));
    }

    public function test_la_conclusion_refuse_niveau_et_motifs_sans_orientation_oui(): void
    {
        foreach ([false, null] as $referral) {
            $errors = new FieldErrors;
            $state = ConclusionStep::normalize(['conclusion' => null, 'referral' => $referral, 'referral_urgency' => 'EMERGENCY', 'referral_reasons' => [$this->term('CATARACT')]], self::REASONS, $errors);
            $this->assertSame(['step.data.referral_urgency', 'step.data.referral_reasons'], array_keys($errors->all()));
            $this->assertSame($referral, $state['referral']);
        }

        $unknown = new FieldErrors;
        ConclusionStep::normalize(['conclusion' => null, 'referral' => true, 'referral_urgency' => 'ASAP', 'referral_reasons' => []], self::REASONS, $unknown);
        $this->assertSame(['step.data.referral_urgency'], array_keys($unknown->all()), 'Un niveau inconnu est signalé une seule fois.');
    }

    public function test_sans_controle_de_paiement_le_verdict_est_toujours_non_controle(): void
    {
        $control = new UncontrolledExamFee;
        $visit = new stdClass;

        $free = $control->decide($visit, ['exam_invoice_id' => null, 'exam_waiver_approval_id' => null]);
        $this->assertSame(ExamFeeDecision::NOT_CONTROLLED, $free->status);
        $this->assertSame([], $free->errors);

        $proof = $control->decide($visit, ['exam_invoice_id' => Ulid::generate(), 'exam_waiver_approval_id' => null]);
        $this->assertSame(ExamFeeDecision::NOT_CONTROLLED, $proof->status, 'Jamais « payé » ni « dérogé » : le contrôle n\'existe pas.');
        $this->assertSame(['exam_invoice_id'], array_keys($proof->errors));
    }
}
