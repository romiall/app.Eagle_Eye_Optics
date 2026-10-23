<?php

namespace Tests\Feature\Documents;

use App\Domain\Shared\Ulid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Originaux PDF des documents générés (D01) sur MySQL réel : dépôt de l'original d'une facture émise (PENDING →
 * READY), contrôle des octets (signature PDF, empreinte annoncée), un READY ne se remplace jamais (même contenu :
 * rejoué sans écriture ; autre contenu : 409), Policy par famille, module, téléchargement privé et sans cache.
 * Disque privé simulé (Storage::fake) : aucun fichier réel écrit. Données fictives.
 */
final class DocumentContentTest extends TestCase
{
    use BillingFixtures;
    use MigratedDatabase;

    private const PDF = "%PDF-1.4\n% document de test fictif\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBilling();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    /** Document en attente de la facture émise : [identifiant du document, numéro]. */
    private function pendingInvoiceDocument(): string
    {
        [$invoiceId] = $this->issuedInvoice();
        $id = DB::table('generated_documents')->where('clinic_id', $this->clinicId)->where('invoice_id', $invoiceId)->value('id');
        $this->assertIsString($id, 'l\'émission prépare le document de la facture');

        return $id;
    }

    private function upload(string $documentId, string $bytes, ?User $as = null, ?string $sha = null): TestResponse
    {
        $headers = ['Idempotency-Key' => Ulid::generate(), 'Content-Type' => 'application/pdf', 'Accept' => 'application/json'];
        if ($sha !== null) {
            $headers['X-Content-Sha256'] = $sha;
        }

        return $this->actingAsFresh($as ?? $this->cashier)
            ->call('POST', '/api/v1/documents/'.$documentId.'/content', [], [], [], $this->transformHeadersToServerVars($headers), $bytes);
    }

    public function test_l_original_d_une_facture_emise_est_conserve_et_le_document_devient_pret(): void
    {
        $id = $this->pendingInvoiceDocument();

        $response = $this->upload($id, self::PDF, sha: hash('sha256', self::PDF))->assertOk();

        $this->assertMatchesContract($response, 'schemas/resources/operations.json#/$defs/GeneratedDocumentResponse');
        $response->assertJsonPath('data.status', 'READY')->assertJsonPath('data.sha256', hash('sha256', self::PDF));
        $file = DB::table('stored_files')->where('clinic_id', $this->clinicId)
            ->where('id', DB::table('generated_documents')->where('id', $id)->value('file_id'))->first();
        $this->assertNotNull($file);
        $this->assertSame('FINANCIAL', $file->classification);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertStringNotContainsString('Patient', (string) $file->private_path, 'aucune donnée personnelle dans le chemin');
        Storage::disk('local')->assertExists((string) $file->private_path);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'document.stored')->where('target_id', $id)->count());
    }

    public function test_un_original_conserve_ne_se_remplace_jamais(): void
    {
        $id = $this->pendingInvoiceDocument();
        $this->upload($id, self::PDF)->assertOk();

        $this->upload($id, self::PDF)->assertOk()->assertJsonPath('data.sha256', hash('sha256', self::PDF));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'document.stored')->where('target_id', $id)->count(), 'le même contenu rejoué n\'écrit rien');

        $other = str_replace('fictif', 'modifié', self::PDF);
        $this->upload($id, $other)->assertStatus(409)->assertJsonPath('error.code', 'STATE_TRANSITION_REFUSED');
        $this->assertSame(hash('sha256', self::PDF), DB::table('generated_documents')->where('id', $id)->value('sha256'));
    }

    public function test_des_octets_qui_ne_sont_pas_un_pdf_ou_une_empreinte_fausse_sont_refuses_sans_rien_ecrire(): void
    {
        $id = $this->pendingInvoiceDocument();

        $this->upload($id, 'pas un pdf')->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['content']]]);
        $this->upload($id, self::PDF, sha: str_repeat('0', 64))->assertStatus(422);
        $this->upload($id, '')->assertStatus(422);
        $this->assertSame('PENDING', DB::table('generated_documents')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('stored_files')->where('clinic_id', $this->clinicId)->count());
    }

    public function test_la_policy_de_la_famille_et_le_module_sont_respectes(): void
    {
        $id = $this->pendingInvoiceDocument();

        // Le professionnel détient un droit de la route (clinical.complete) mais pas celui des pièces financières.
        $clinician = $this->makeStaff($this->clinicId, 'CLINICIAN', 'pro.documents');
        $this->upload($id, self::PDF, $clinician)->assertForbidden();

        config(['eeo.enabled_modules' => ['stock']]);
        $this->upload($id, self::PDF)->assertNotFound();
        $this->upload(Ulid::generate(), self::PDF)->assertNotFound();
    }

    public function test_le_telechargement_rend_l_original_prive_sans_cache_apres_depot_seulement(): void
    {
        $id = $this->pendingInvoiceDocument();
        $this->actingAsFresh($this->cashier)->get('/api/v1/documents/'.$id.'/download', ['Accept' => 'application/json'])->assertNotFound();

        $this->upload($id, self::PDF)->assertOk();
        $response = $this->actingAsFresh($this->cashier)->get('/api/v1/documents/'.$id.'/download');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(self::PDF, $response->getContent());
        $this->assertMatchesRegularExpression('/filename="document-invoice-[0-9]{8}\.pdf"/', (string) $response->headers->get('Content-Disposition'));

        $reception = $this->makeStaff($this->clinicId, 'RECEPTION', 'accueil.documents');
        $this->actingAsFresh($reception)->get('/api/v1/documents/'.$id.'/download', ['Accept' => 'application/json'])->assertForbidden();
    }
}
