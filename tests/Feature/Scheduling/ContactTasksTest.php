<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgendaFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Tâches de contact internes (S02) sur MySQL réel : liste quotidienne (retards compris, tâches futures exclues),
 * tentatives historisées et immuables, combinaisons résultat → état, report daté, RDV pris lié à un rendez-vous du même
 * patient, tâche close, version attendue, rejeu idempotent, unicité par source et échéance. Aucun envoi. Fictif.
 */
final class ContactTasksTest extends TestCase
{
    use AgendaFixtures;
    use MigratedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgenda();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /** Tâche de rappel d'un contrôle recommandé, due le 2026-10-21 (hier, en retard) sauf indication. */
    private function task(string $dueAt = '2026-10-20 23:00:00'): string
    {
        $consultation = $this->seedConsultation('2026-10-01 08:00:00');
        $plan = $this->send($this->clinician, 'POST', '/api/v1/follow-ups', $this->followUpBody($consultation, [
            'anchor_kind' => 'MANUAL', 'anchor_date' => '2026-10-21', 'interval_value' => null, 'interval_unit' => null,
            'recommended_date' => '2026-10-21', 'date_origin' => 'MANUAL', 'manual_reason' => 'Date fictive', 'advance_value' => null,
            'advance_unit' => null,
        ]))->assertCreated()->json('data.id');
        $id = (string) DB::table('contact_tasks')->where('follow_up_id', $plan)->value('id');
        DB::table('contact_tasks')->where('id', $id)->update(['due_at' => $dueAt.'.000000']);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attempt(string $taskId, array $overrides = []): array
    {
        return $overrides + [
            'expected_version' => 1, 'attempt_id' => Ulid::generate(), 'task_id' => $taskId, 'occurred_at' => '2026-10-22T09:25:00.000Z',
            'result' => 'NO_RESPONSE', 'appointment_id' => null, 'administrative_note' => null, 'next_status' => 'NO_RESPONSE',
            'next_attempt_at' => null,
        ];
    }

    public function test_la_liste_du_jour_comprend_les_retards_et_exclut_l_avenir(): void
    {
        $late = $this->task();
        $future = $this->task('2026-10-24 23:00:00');

        $list = $this->read($this->reception, '/api/v1/contact-tasks?due_date=2026-10-22')->assertOk();
        $this->assertMatchesContract($list, 'schemas/resources/scheduling.json#/$defs/ContactTaskList');
        $ids = array_column($list->json('data'), 'id');
        $this->assertContains($late, $ids);
        $this->assertNotContains($future, $ids);
        $list->assertJsonPath('data.0.source_type', 'FOLLOW_UP')->assertJsonPath('data.0.channel', 'CALL');
        $this->read($this->cashier, '/api/v1/contact-tasks')->assertOk();
    }

    public function test_une_tentative_est_historisee_et_la_tache_prend_son_etat(): void
    {
        $id = $this->task();

        $response = $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, ['administrative_note' => 'Messagerie']))->assertCreated();
        $this->assertMatchesContract($response, 'schemas/resources/scheduling.json#/$defs/ContactAttemptResultResponse');
        $response->assertJsonPath('data.task.status', 'NO_RESPONSE')->assertJsonPath('data.task.version', 2)
            ->assertJsonPath('data.task.attempts.0.result', 'NO_RESPONSE')->assertJsonPath('data.task.attempts.0.actor_name', $this->reception->display_name);

        // Toujours ouverte : un report daté, puis un contact.
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, ['expected_version' => 2, 'result' => 'POSTPONED', 'next_status' => 'POSTPONED']))
            ->assertStatus(422)->assertJsonPath('error.fields.next_attempt_at.0', 'Indiquez quand rappeler.');
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, [
            'expected_version' => 2, 'result' => 'POSTPONED', 'next_status' => 'POSTPONED', 'next_attempt_at' => '2026-10-24T08:00:00.000Z',
        ]))->assertCreated()->assertJsonPath('data.task.next_attempt_at', '2026-10-24T08:00:00.000Z');
        $this->assertNotContains($id, array_column($this->read($this->reception, '/api/v1/contact-tasks?due_date=2026-10-23')->json('data'), 'id'));

        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, ['expected_version' => 3, 'result' => 'CONTACTED', 'next_status' => 'CONTACTED']))
            ->assertCreated()->assertJsonPath('data.task.status', 'CONTACTED');
        // Close : plus de tentative.
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, ['expected_version' => 4]))
            ->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
        $this->assertSame(3, DB::table('contact_attempts')->where('task_id', $id)->count());
    }

    public function test_combinaisons_version_et_rejeu(): void
    {
        $id = $this->task();

        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, ['result' => 'REFUSED', 'next_status' => 'CONTACTED']))
            ->assertStatus(422)->assertJsonPath('error.fields.next_status.0', 'Ce résultat ne mène pas à cet état de la tâche.');
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, ['expected_version' => 5]))
            ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, ['occurred_at' => '2026-10-23T09:25:00.000Z']))
            ->assertStatus(422);
        $this->send($this->cashier, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id))->assertForbidden();

        $key = Ulid::generate();
        $body = $this->attempt($id);
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $body, $key)->assertCreated();
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $body, $key)->assertCreated();
        $this->assertSame(1, DB::table('contact_attempts')->where('task_id', $id)->count());
    }

    public function test_rdv_pris_exige_un_rendez_vous_du_meme_patient(): void
    {
        $id = $this->task();
        $other = $this->seedPatient($this->clinicId, 'Autre Fictif');
        $foreign = $this->book(['patient_id' => $other])->assertCreated()->json('data.id');
        $own = $this->book(['starts_at' => '2026-10-23T08:00:00.000Z', 'ends_at' => '2026-10-23T08:30:00.000Z'])->assertCreated()->json('data.id');

        $booked = ['result' => 'BOOKED', 'next_status' => 'BOOKED'];
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, $booked))->assertStatus(422);
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, ['appointment_id' => $foreign] + $booked))->assertStatus(422);
        $this->send($this->reception, 'POST', "/api/v1/contact-tasks/$id/attempts", $this->attempt($id, ['appointment_id' => $own] + $booked))
            ->assertCreated()->assertJsonPath('data.task.status', 'BOOKED');
    }

    public function test_une_source_et_une_echeance_ne_donnent_qu_une_tache(): void
    {
        $consultation = $this->seedConsultation();
        $body = $this->followUpBody($consultation);
        $key = Ulid::generate();
        $this->send($this->clinician, 'POST', '/api/v1/follow-ups', $body, $key)->assertCreated();
        $this->send($this->clinician, 'POST', '/api/v1/follow-ups', $body, $key)->assertCreated();

        $this->assertSame(1, DB::table('follow_up_plans')->count());
        $this->assertSame(1, DB::table('contact_tasks')->count());
        $this->assertSame(1, DB::table('contact_tasks')->where('dedupe_key', 'FOLLOW_UP:'.$body['follow_up_plan_id'].':2027-01-15')->count());
    }
}
