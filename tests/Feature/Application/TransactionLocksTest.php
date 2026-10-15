<?php

namespace Tests\Feature\Application;

use App\Application\Locking\LockOrderViolation;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Ordre unique des verrous de L0 (contrat 23) : cabinet → appareil → idempotence → séquences → propriété de
 * facture → facture → session de caisse → positions, lots, quotas triés → approbation. Deux transactions qui
 * le respectent ne peuvent pas s'interbloquer ; un verrou demandé hors ordre est refusé AVANT toute requête.
 */
final class TransactionLocksTest extends TestCase
{
    use AccessFixtures;
    use MigratedDatabase;

    private string $clinicId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinicId = $this->makeClinic();
    }

    public function test_l_ordre_complet_du_contrat_est_accepte_et_les_identifiants_sont_tries(): void
    {
        $locks = new TransactionLocks($this->clinicId);

        $this->assertSame($this->clinicId, $locks->clinic()->id);
        $this->assertTrue($locks->holdsClinicExclusively());
        $this->assertNull($locks->device(Ulid::generate()));
        $this->assertNull($locks->idempotency('user:ABC', 'RegisterPayment', 'cle-0000001'));
        $this->assertSame([], $locks->sequences([['RECEIPT', 2026], ['INVOICE', 2026], ['INVOICE', 2025]]));
        $this->assertSame([], $locks->invoiceOwners(Ulid::generate()));
        $this->assertNull($locks->invoice(Ulid::generate()));
        $this->assertNull($locks->cashSession(Ulid::generate()));
        $this->assertSame([], $locks->stockPositions(['B', 'A', 'B']));
        $this->assertSame([], $locks->stockLots(['D', 'C']));
        $this->assertSame([], $locks->stockQuotas(['F', 'E']));
        $this->assertNull($locks->approval(Ulid::generate()));

        $ranks = array_map(static fn (string $token): string => explode(':', $token, 2)[0], $locks->acquired());
        $this->assertSame(
            ['10', '20', '30', '40', '40', '40', '50', '60', '70', '80', '80', '81', '81', '82', '82', '90'],
            $ranks,
            'Un rang par verrou, jamais décroissant.',
        );

        $acquired = $locks->acquired();
        $this->assertSame(['40:INVOICE:2025', '40:INVOICE:2026', '40:RECEIPT:2026'], array_slice($acquired, 3, 3), 'Séquences par clé croissante.');
        $this->assertSame(['80:A', '80:B'], array_slice($acquired, 9, 2), 'Positions par identifiant trié, sans doublon.');
        $this->assertSame(['81:C', '81:D'], array_slice($acquired, 11, 2));
        $this->assertSame(['82:E', '82:F'], array_slice($acquired, 13, 2));
    }

    public function test_redemander_un_verrou_deja_tenu_est_permis(): void
    {
        $locks = new TransactionLocks($this->clinicId);
        $invoiceId = Ulid::generate();

        $locks->clinic();
        $locks->invoice($invoiceId);
        $locks->invoice($invoiceId);

        $this->assertSame($this->clinicId, $locks->clinic()->id, 'Le cabinet reste verrouillable après des verrous de rang supérieur.');
    }

    public function test_un_verrou_de_rang_inferieur_est_refuse_avant_toute_requete(): void
    {
        $pairs = [
            'appareil après facture' => [
                fn (TransactionLocks $locks) => $locks->invoice(Ulid::generate()),
                fn (TransactionLocks $locks) => $locks->device(Ulid::generate()),
            ],
            'séquence après session de caisse' => [
                fn (TransactionLocks $locks) => $locks->cashSession(Ulid::generate()),
                fn (TransactionLocks $locks) => $locks->sequences([['INVOICE', 2026]]),
            ],
            'facture après position de stock' => [
                fn (TransactionLocks $locks) => $locks->stockPositions([Ulid::generate()]),
                fn (TransactionLocks $locks) => $locks->invoice(Ulid::generate()),
            ],
            'lot après quota' => [
                fn (TransactionLocks $locks) => $locks->stockQuotas([Ulid::generate()]),
                fn (TransactionLocks $locks) => $locks->stockLots([Ulid::generate()]),
            ],
            'cabinet après approbation' => [
                fn (TransactionLocks $locks) => $locks->approval(Ulid::generate()),
                fn (TransactionLocks $locks) => $locks->clinic(),
            ],
            'idempotence après propriété de facture' => [
                fn (TransactionLocks $locks) => $locks->invoiceOwners(Ulid::generate()),
                fn (TransactionLocks $locks) => $locks->idempotency('user:ABC', 'RegisterPayment', 'cle-0000001'),
            ],
        ];

        foreach ($pairs as $label => [$first, $second]) {
            $locks = new TransactionLocks($this->clinicId);
            $first($locks);

            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $second($locks);
                $this->fail('Ordre violé accepté : '.$label);
            } catch (LockOrderViolation $violation) {
                $this->assertStringContainsString('Ordre des verrous violé', $violation->getMessage(), $label);
                $this->assertCount(0, DB::getQueryLog(), 'Refus avant toute requête : '.$label);
            } finally {
                DB::disableQueryLog();
            }
        }
    }

    public function test_deux_verrous_d_un_meme_rang_se_prennent_par_cle_croissante(): void
    {
        $locks = new TransactionLocks($this->clinicId);
        $locks->invoice('B');

        $this->expectException(LockOrderViolation::class);
        $this->expectExceptionMessage('clé croissante');
        $locks->invoice('A');
    }

    public function test_le_verrou_partage_du_cabinet_ne_vaut_pas_verrou_exclusif(): void
    {
        $locks = new TransactionLocks($this->clinicId);

        $this->assertSame($this->clinicId, $locks->clinicShared()->id);
        $this->assertFalse($locks->holdsClinicExclusively());
    }

    public function test_un_cabinet_inconnu_est_introuvable(): void
    {
        try {
            (new TransactionLocks(Ulid::generate()))->clinic();
            $this->fail('Cabinet inconnu.');
        } catch (BusinessError $error) {
            $this->assertSame('RESOURCE_NOT_FOUND', $error->errorCode);
        }
    }

    public function test_les_lectures_sont_filtrees_par_cabinet(): void
    {
        $otherClinic = $this->makeClinic('TEST CABINET B');
        $sequenceId = Ulid::generate();
        DB::table('document_sequences')->insert([
            'id' => $sequenceId, 'clinic_id' => $otherClinic, 'document_type' => 'INVOICE', 'document_year' => 2026, 'last_value' => 7,
        ]);

        // Le haut-niveau INVOICE 2026 existe, mais dans l'AUTRE cabinet : invisible pour celui-ci.
        $this->assertSame([], (new TransactionLocks($this->clinicId))->sequences([['INVOICE', 2026]]));
        $this->assertCount(1, (new TransactionLocks($otherClinic))->sequences([['INVOICE', 2026]]));
    }
}
