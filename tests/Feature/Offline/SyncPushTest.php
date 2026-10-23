<?php

namespace Tests\Feature\Offline;

use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\Support\OfflineResourceFixtures;
use Tests\Support\SyncFixtures;
use Tests\TestCase;

/**
 * Push, ACK et conflits durables (O05 ; fichier 19 « Synchronisation » ; contrat 23 « Push, ACK et résolution » ; AT38,
 * AT39, AT42, AT45 ; EE017, EE042, EE045) sur MySQL réel, avec une VRAIE clé ES256 de poste :
 *
 *  - un journal patient → brouillon → émission → deux paiements est appliqué dans l'ordre par le chemin central, avec
 *    les numéros des plages du poste (jamais renumérotés), la propriété de facture importée et le préfixe finalisé ;
 *  - le rejeu du même lot rend ALREADY_ACCEPTED sans second fait ; une coupure au milieu reprend au rang suivant ;
 *  - un rang manquant, une empreinte ou une signature altérée, un même identifiant avec une autre empreinte : refus
 *    sans rien stocker ;
 *  - un refus métier ouvre un conflit durable et bloque les rangs suivants ; un rejet décidé finalise le rang et la
 *    suite reprend (N puis N+1) sans paiement inventé ; ACCEPT_LOCAL rejoue après revue d'horloge ;
 *  - pièce liée absente : WAITING_DEPENDENCY ; module, poste perdu, compte non préparé.
 *
 * Données fictives ; horloge figée à 09 h 30 UTC le 22/10/2026 (BillingFixtures).
 */
final class SyncPushTest extends TestCase
{
    use BillingFixtures;
    use MigratedDatabase;
    use OfflineResourceFixtures;
    use SyncFixtures;

    private const RESPONSE = 'sync/push-response.json';

    private string $receptionGrant;

    private string $cashierGrant;

    private string $patientRange;

    private string $invoiceRange;

    private string $receiptRange;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBilling(['patients', 'sales', 'stock', 'offline']);
        $this->signingDevice();
        $this->receptionGrant = $this->seedGrant($this->syncDevice, $this->reception);
        $this->cashierGrant = $this->seedGrant($this->syncDevice, $this->cashier);
        $this->patientRange = $this->seedRange($this->receptionGrant, 'PATIENT', 101, 150);
        $this->invoiceRange = $this->seedRange($this->cashierGrant, 'INVOICE', 501, 600);
        $this->receiptRange = $this->seedRange($this->cashierGrant, 'RECEIPT', 501, 600);
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /**
     * Journal complet du poste : patient, brouillon (examen 15 000), émission, paiements de 5 000 puis 10 000.
     *
     * @return array{commands: list<array{core: array<string, mixed>, event_hash: string, signature: string}>, patient: string, invoice: string}
     */
    private function journal(): array
    {
        $patient = $this->patientPayload('EE-2026-0101');
        $invoice = Ulid::generate();

        return [
            'patient' => (string) $patient['patient_id'],
            'invoice' => $invoice,
            'commands' => [
                $this->signed('CreatePatient', $patient, 'patient_records', (string) $patient['patient_id'], $this->reception, $this->receptionGrant, null, $this->patientRange),
                $this->signed('CreateInvoiceDraft', $this->syncDraftPayload($invoice, (string) $patient['patient_id'], [$this->line($this->item['exam'])]), 'invoices', $invoice, $this->cashier, $this->cashierGrant),
                $this->signed('IssueInvoice', $this->syncIssuePayload($invoice, 'TEST-FAC-2026-000501', 15000), 'invoices', $invoice, $this->cashier, $this->cashierGrant, 1, $this->invoiceRange, [], '2026-10-22T09:21:00.000Z'),
                $this->signed('RegisterPayment', $this->syncPaymentPayload($invoice, 'TEST-REC-2026-000501', 5000), 'invoices', $invoice, $this->cashier, $this->cashierGrant, 1, $this->receiptRange, [], '2026-10-22T09:22:00.000Z'),
                $this->signed('RegisterPayment', $this->syncPaymentPayload($invoice, 'TEST-REC-2026-000502', 10000, '2026-10-22T09:23:00.000Z'), 'invoices', $invoice, $this->cashier, $this->cashierGrant, 2, $this->receiptRange, [], '2026-10-22T09:23:00.000Z'),
            ],
        ];
    }

    public function test_un_journal_patient_facture_deux_paiements_est_applique_dans_l_ordre_avec_les_numeros_du_poste(): void
    {
        ['commands' => $commands, 'patient' => $patient, 'invoice' => $invoice] = $this->journal();

        $response = $this->push($commands)->assertOk();
        $this->assertMatchesContract($response, self::RESPONSE);

        $this->assertSame(['ACCEPTED', 'ACCEPTED', 'ACCEPTED', 'ACCEPTED', 'ACCEPTED'], array_column($response->json('results'), 'status'));
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($response->json('results'), 'device_sequence'));
        $this->assertSame('5', $response->json('finalized_prefix.last_sequence'));
        $this->assertSame($commands[4]['event_hash'], $response->json('finalized_prefix.last_hash'));
        $this->assertSame($invoice, $response->json('results.2.result_references.invoice_id'));
        $this->assertSame(3, $response->json('results.4.versions.ledger_version'));

        // Numéros du poste, tels quels : jamais renumérotés à la synchronisation.
        $this->assertSame('EE-2026-0101', DB::table('patient_records')->where('id', $patient)->value('record_number'));
        $this->assertSame('TEST-FAC-2026-000501', DB::table('invoices')->where('id', $invoice)->value('number'));
        $this->assertSame(['TEST-REC-2026-000501', 'TEST-REC-2026-000502'], DB::table('receipts')->where('invoice_id', $invoice)->orderBy('number')->pluck('number')->all());
        $usages = DB::table('document_number_usages')->where('clinic_id', $this->clinicId)->whereNotNull('allocation_id')->get();
        $this->assertCount(4, $usages);
        foreach ($usages as $usage) {
            $this->assertSame($this->syncDevice, $usage->source_device_id);
        }
        $this->assertSame(101, (int) DB::table('document_number_allocations')->where('id', $this->patientRange)->value('last_consumed_value'));
        $this->assertSame(502, (int) DB::table('document_number_allocations')->where('id', $this->receiptRange)->value('last_consumed_value'));
        $this->assertSame(0, DB::table('document_sequences')->where('clinic_id', $this->clinicId)->count(), 'aucun numéro central pris');

        // Propriété importée par l'émission, paiements rattachés au poste et à leur commande, pièces datées du poste.
        $owner = DB::table('invoice_write_owners')->where('invoice_id', $invoice)->first();
        $this->assertNotNull($owner);
        $this->assertSame([$this->syncDevice, 1, 'ACTIVE'], [(string) $owner->device_id, (int) $owner->epoch, (string) $owner->status]);
        $payments = DB::table('payments')->where('invoice_id', $invoice)->orderBy('amount')->get();
        $this->assertSame([5000, 10000], $payments->map(static fn (object $row): int => (int) $row->amount)->all());
        $this->assertSame([$commands[3]['core']['command_id'], $commands[4]['core']['command_id']], $payments->pluck('sync_command_id')->all());
        $this->assertSame('2026-10-22 09:21:00.000000', (string) DB::table('invoices')->where('id', $invoice)->value('issued_at'));

        // Préfixe finalisé et ACK durables.
        $device = DB::table('enrolled_devices')->where('id', $this->syncDevice)->first();
        $this->assertSame(5, (int) $device->last_accepted_sequence);
        $this->assertSame($commands[4]['event_hash'], $device->last_accepted_hash);
        $this->assertSame(5, DB::table('sync_command_results')->where('status', 'ACCEPTED')->count());
        $this->assertSame(5, DB::table('sync_commands')->where('device_id', $this->syncDevice)->count());
    }

    public function test_le_rejeu_du_meme_lot_rend_already_accepted_sans_second_fait(): void
    {
        ['commands' => $commands, 'invoice' => $invoice] = $this->journal();
        $this->push($commands)->assertOk();
        $before = $this->billingEffects();

        $replay = $this->push($commands)->assertOk();

        $this->assertMatchesContract($replay, self::RESPONSE);
        $this->assertSame(array_fill(0, 5, 'ALREADY_ACCEPTED'), array_column($replay->json('results'), 'status'));
        $this->assertSame($before, $this->billingEffects());
        $this->assertSame(2, DB::table('payments')->where('invoice_id', $invoice)->count());
        $this->assertSame(5, DB::table('sync_commands')->count());
    }

    public function test_une_coupure_au_milieu_du_lot_reprend_au_rang_suivant(): void
    {
        ['commands' => $commands] = $this->journal();

        // Premier envoi coupé après deux commandes (seules celles-ci sont parvenues au serveur).
        $this->push(array_slice($commands, 0, 2))->assertOk()->assertJsonPath('finalized_prefix.last_sequence', '2');

        // Le poste renvoie depuis la plus ancienne commande non confirmée : rien n'est appliqué deux fois.
        $resumed = $this->push(array_slice($commands, 1))->assertOk();
        $this->assertSame(['ALREADY_ACCEPTED', 'ACCEPTED', 'ACCEPTED', 'ACCEPTED'], array_column($resumed->json('results'), 'status'));
        $this->assertSame('5', $resumed->json('finalized_prefix.last_sequence'));
        $this->assertSame(1, DB::table('patient_records')->where('record_number', 'EE-2026-0101')->count());
    }

    public function test_un_rang_jamais_recu_est_refuse_sans_rien_stocker(): void
    {
        ['commands' => $commands] = $this->journal();

        $this->push([$commands[1]])->assertStatus(409)->assertJsonPath('error.code', 'SYNC_SEQUENCE_GAP')
            ->assertJsonPath('error.details.expected_sequence', '1');
        $this->assertSame(0, DB::table('sync_commands')->count());
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_une_empreinte_ou_une_signature_alteree_est_refusee_sans_rien_stocker(): void
    {
        ['commands' => $commands] = $this->journal();

        $tampered = $commands[0];
        $tampered['core']['payload']['last_name'] = 'NOM MODIFIE APRES SIGNATURE';
        $this->push([$tampered])->assertStatus(422)->assertJsonPath('error.code', 'SYNC_INTEGRITY_FAILED');

        $foreignKey = $this->sign($commands[0]['core'], self::newDeviceKey());
        $this->push([$foreignKey])->assertStatus(422)->assertJsonPath('error.code', 'SYNC_INTEGRITY_FAILED');

        $noneAlg = $commands[0];
        $parts = explode('.', $noneAlg['signature']);
        $noneAlg['signature'] = rtrim(strtr(base64_encode('{"alg":"none","kid":"x","typ":"eeo-command+jws"}'), '+/', '-_'), '=').'.'.$parts[1].'.'.$parts[2];
        $this->push([$noneAlg])->assertStatus(422)->assertJsonPath('error.code', 'SYNC_INTEGRITY_FAILED');

        $this->assertSame(0, DB::table('sync_commands')->count());
        $this->assertSame(0, DB::table('patient_records')->where('record_number', 'EE-2026-0101')->count());
        $this->assertSame(0, (int) DB::table('enrolled_devices')->where('id', $this->syncDevice)->value('last_accepted_sequence'));
    }

    public function test_meme_identifiant_avec_une_autre_empreinte_est_un_conflit_d_integrite(): void
    {
        ['commands' => $commands] = $this->journal();
        $this->push([$commands[0]])->assertOk();

        $other = $commands[0]['core'];
        $other['payload']['first_names'] = 'Autre prénom';
        $this->push([$this->sign($other)])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->assertSame(1, DB::table('sync_commands')->count());
        $this->assertSame('Fictive', DB::table('patient_records')->where('record_number', 'EE-2026-0101')->value('first_names'));
    }

    public function test_un_refus_metier_ouvre_un_conflit_durable_et_bloque_les_rangs_suivants(): void
    {
        ['commands' => $base, 'invoice' => $invoice] = $this->journal();
        $commands = array_slice($base, 0, 3);
        $this->forkJournal(3, $commands[2]['event_hash']);
        // Rang 4 : 20 000 sur un reste dû de 15 000 (refus PAYMENT_EXCEEDS_BALANCE) ; rang 5 : paiement valable.
        $commands[] = $this->signed('RegisterPayment', $this->syncPaymentPayload($invoice, 'TEST-REC-2026-000501', 20000), 'invoices', $invoice, $this->cashier, $this->cashierGrant, 1, $this->receiptRange);
        $commands[] = $this->signed('RegisterPayment', $this->syncPaymentPayload($invoice, 'TEST-REC-2026-000502', 15000), 'invoices', $invoice, $this->cashier, $this->cashierGrant, 1, $this->receiptRange);

        $response = $this->push($commands)->assertOk();

        $this->assertMatchesContract($response, self::RESPONSE);
        $this->assertSame(['ACCEPTED', 'ACCEPTED', 'ACCEPTED', 'CONFLICT_REQUIRES_REVIEW'], array_column($response->json('results'), 'status'));
        $this->assertSame('PAYMENT_EXCEEDS_BALANCE', $response->json('results.3.error_code'));
        $this->assertNotNull($response->json('results.3.conflict_id'));
        $this->assertSame('3', $response->json('finalized_prefix.last_sequence'), 'un conflit ne fait jamais sauter la séquence');

        $conflict = DB::table('sync_conflicts')->where('command_id', $commands[3]['core']['command_id'])->first();
        $this->assertNotNull($conflict);
        $this->assertSame('OPEN', $conflict->status);
        $this->assertStringNotContainsString('MOMO-FICTIF', (string) $conflict->local_snapshot_ciphertext, 'instantané chiffré');
        $this->assertStringNotContainsString('MOMO-FICTIF', (string) DB::table('sync_commands')->where('id', $commands[3]['core']['command_id'])->value('payload_ciphertext'), 'charge rechiffrée');
        $this->assertSame('RECEIVED', DB::table('sync_command_results')->where('command_id', $commands[4]['core']['command_id'])->value('status'));
        $this->assertSame(0, DB::table('payments')->where('invoice_id', $invoice)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sync.conflict_opened')->count());
    }

    public function test_un_rejet_decide_finalise_le_rang_et_la_suite_reprend_sans_paiement_invente(): void
    {
        ['commands' => $base, 'invoice' => $invoice] = $this->journal();
        $commands = array_slice($base, 0, 3);
        $this->forkJournal(3, $commands[2]['event_hash']);
        $commands[] = $this->signed('RegisterPayment', $this->syncPaymentPayload($invoice, 'TEST-REC-2026-000501', 20000), 'invoices', $invoice, $this->cashier, $this->cashierGrant, 1, $this->receiptRange);
        $commands[] = $this->signed('RegisterPayment', $this->syncPaymentPayload($invoice, 'TEST-REC-2026-000502', 15000), 'invoices', $invoice, $this->cashier, $this->cashierGrant, 1, $this->receiptRange);
        $conflictId = (string) $this->push($commands)->assertOk()->json('results.3.conflict_id');

        // Liste de la gérante : la commande, son rang et le motif du refus, jamais la charge.
        $list = $this->actingAsFresh($this->manager)->getJson('/api/v1/sync/conflicts?status=OPEN')->assertOk();
        $this->assertMatchesContract($list, 'schemas/resources/operations.json#/$defs/ConflictPage');
        $this->assertSame([$conflictId], array_column($list->json('data'), 'id'));
        $this->assertSame('RegisterPayment', $list->json('data.0.command_name'));
        $this->assertSame('4', $list->json('data.0.device_sequence'));

        // Décision motivée de la gérante : rejet ; le rang 4 est finalisé, le rang 5 est appliqué aussitôt.
        $resolved = $this->actingAsReauthenticated($this->manager)
            ->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson('/api/v1/sync/conflicts/'.$conflictId.'/resolution', ['expected_version' => null, 'decision' => 'REJECT', 'reason' => 'Montant saisi par erreur, encaissement réel de 15 000 (test)', 'correction_command_id' => null])
            ->assertOk();
        $this->assertMatchesContract($resolved, 'schemas/resources/operations.json#/$defs/ConflictResponse');
        $this->assertSame('RESOLVED', $resolved->json('data.status'));
        $this->assertSame('REJECT', $resolved->json('data.decision'));

        $this->assertSame([15000], DB::table('payments')->where('invoice_id', $invoice)->pluck('amount')->map(static fn ($amount): int => (int) $amount)->all());
        $this->assertSame(5, (int) DB::table('enrolled_devices')->where('id', $this->syncDevice)->value('last_accepted_sequence'));

        // Le poste renvoie ses commandes non finalisées : REJECTED motivé pour N, ACK existant de N+1 (appliqué à la décision).
        $again = $this->push(array_slice($commands, 3))->assertOk();
        $this->assertMatchesContract($again, self::RESPONSE);
        $this->assertSame(['REJECTED', 'ALREADY_ACCEPTED'], array_column($again->json('results'), 'status'));
        $this->assertSame('Montant saisi par erreur, encaissement réel de 15 000 (test)', $again->json('results.0.reason'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sync.conflict_resolved')->count());

        // Une seconde décision sur le même conflit est refusée.
        $this->actingAsReauthenticated($this->manager)
            ->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson('/api/v1/sync/conflicts/'.$conflictId.'/resolution', ['expected_version' => null, 'decision' => 'REJECT', 'reason' => 'Deuxième décision (test)', 'correction_command_id' => null])
            ->assertStatus(409);
    }

    public function test_accept_local_applique_apres_revue_d_horloge(): void
    {
        // Horloge du poste antérieure à la dernière heure serveur qu'il connaissait : la commande passe en revue.
        $patient = $this->patientPayload('EE-2026-0101');
        $command = $this->signed('CreatePatient', $patient, 'patient_records', (string) $patient['patient_id'], $this->reception, $this->receptionGrant, null, $this->patientRange,
            [], '2026-10-22T09:10:00.000Z', ['last_server_time' => '2026-10-22T09:25:00.000Z']);

        $response = $this->push([$command], $this->reception)->assertOk();
        $this->assertSame('CONFLICT_REQUIRES_REVIEW', $response->json('results.0.status'));
        $this->assertSame('AUTHORIZATION_REVIEW_REQUIRED', $response->json('results.0.error_code'));
        $this->assertSame(0, DB::table('patient_records')->where('id', $patient['patient_id'])->count());

        $this->actingAsReauthenticated($this->manager)
            ->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson('/api/v1/sync/conflicts/'.$response->json('results.0.conflict_id').'/resolution', ['expected_version' => null, 'decision' => 'ACCEPT_LOCAL', 'reason' => 'Horloge du poste vérifiée avec la caissière (test)', 'correction_command_id' => null])
            ->assertOk();

        $this->assertSame('EE-2026-0101', DB::table('patient_records')->where('id', $patient['patient_id'])->value('record_number'));
        $this->assertSame('ACCEPTED', DB::table('sync_command_results')->where('command_id', $command['core']['command_id'])->value('status'));
        $this->push([$command], $this->reception)->assertOk()->assertJsonPath('results.0.status', 'ALREADY_ACCEPTED');
    }

    public function test_un_numero_hors_de_la_plage_du_poste_passe_en_revue_sans_rien_creer(): void
    {
        $patient = $this->patientPayload('EE-2026-0999');
        $command = $this->signed('CreatePatient', $patient, 'patient_records', (string) $patient['patient_id'], $this->reception, $this->receptionGrant, null, $this->patientRange);

        $this->push([$command], $this->reception)->assertOk()
            ->assertJsonPath('results.0.status', 'CONFLICT_REQUIRES_REVIEW')
            ->assertJsonPath('results.0.error_code', 'NUMBER_RANGE_INVALID');
        $this->assertSame(0, DB::table('patient_records')->where('id', $patient['patient_id'])->count());
        $this->assertNull(DB::table('document_number_allocations')->where('id', $this->patientRange)->value('last_consumed_value'));
    }

    public function test_une_piece_liee_absente_attend_dans_l_ordre(): void
    {
        $missingPatient = Ulid::generate();
        $invoice = Ulid::generate();
        $command = $this->signed('CreateInvoiceDraft', $this->syncDraftPayload($invoice, $missingPatient, [$this->line($this->item['exam'])]), 'invoices', $invoice, $this->cashier, $this->cashierGrant);

        $response = $this->push([$command])->assertOk();

        $this->assertMatchesContract($response, self::RESPONSE);
        $this->assertSame('WAITING_DEPENDENCY', $response->json('results.0.status'));
        $this->assertSame([['entity_type' => 'patient_records', 'entity_id' => $missingPatient]], $response->json('results.0.dependencies'));
        $this->assertSame('0', $response->json('finalized_prefix.last_sequence'));
        $this->assertSame(0, DB::table('invoices')->where('id', $invoice)->count());
    }

    public function test_module_poste_perdu_et_compte_non_prepare(): void
    {
        ['commands' => $commands] = $this->journal();

        config(['eeo.enabled_modules' => ['patients', 'sales', 'stock']]);
        $this->push([$commands[0]])->assertNotFound();
        config(['eeo.enabled_modules' => ['patients', 'sales', 'stock', 'offline']]);

        // Le professionnel n'a aucun grant sur ce poste.
        $this->push([$commands[0]], $this->clinician)->assertForbidden();

        DB::table('enrolled_devices')->where('id', $this->syncDevice)->update(['status' => 'LOST']);
        $this->push([$commands[0]])->assertForbidden();
        $this->assertSame(0, DB::table('sync_commands')->count());
    }

    public function test_la_resolution_exige_la_gerante_et_un_motif(): void
    {
        $patient = $this->patientPayload('EE-2026-0999');
        $command = $this->signed('CreatePatient', $patient, 'patient_records', (string) $patient['patient_id'], $this->reception, $this->receptionGrant, null, $this->patientRange);
        $conflictId = (string) $this->push([$command], $this->reception)->assertOk()->json('results.0.conflict_id');
        $uri = '/api/v1/sync/conflicts/'.$conflictId.'/resolution';

        $this->actingAsFresh($this->cashier)->getJson('/api/v1/sync/conflicts')->assertForbidden();
        $this->actingAsReauthenticated($this->cashier)->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson($uri, ['expected_version' => null, 'decision' => 'REJECT', 'reason' => 'Refus par la caisse', 'correction_command_id' => null])->assertForbidden();
        $this->actingAsFresh($this->manager)->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson($uri, ['expected_version' => null, 'decision' => 'REJECT', 'reason' => 'Sans réauthentification', 'correction_command_id' => null])->assertStatus(423);
        $this->actingAsReauthenticated($this->manager)->withHeaders(['Idempotency-Key' => Ulid::generate()])
            ->postJson($uri, ['expected_version' => null, 'decision' => 'CORRECT', 'reason' => 'Correction sans commande', 'correction_command_id' => null])->assertStatus(422);

        $this->assertSame('OPEN', DB::table('sync_conflicts')->where('id', $conflictId)->value('status'));
    }
}
