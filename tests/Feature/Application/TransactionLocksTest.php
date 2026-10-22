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
        $this->assertNull($locks->patient(Ulid::generate()));
        $this->assertSame([], $locks->invoiceOwners(Ulid::generate()));
        $this->assertNull($locks->invoice(Ulid::generate()));
        $this->assertNull($locks->cashSession(Ulid::generate()));
        $this->assertSame([], $locks->stockPositions(['B', 'A', 'B']));
        $this->assertSame([], $locks->stockLots(['D', 'C']));
        $this->assertSame([], $locks->stockQuotas(['F', 'E']));
        $this->assertNull($locks->approval(Ulid::generate()));

        $ranks = array_map(static fn (string $token): string => explode(':', $token, 2)[0], $locks->acquired());
        $this->assertSame(
            ['10', '20', '30', '40', '40', '40', '45', '50', '60', '70', '80', '80', '81', '81', '82', '82', '90'],
            $ranks,
            'Un rang par verrou, jamais décroissant.',
        );

        $acquired = $locks->acquired();
        $this->assertSame(['40:INVOICE:2025', '40:INVOICE:2026', '40:RECEIPT:2026'], array_slice($acquired, 3, 3), 'Séquences par clé croissante.');
        $this->assertSame(['80:A', '80:B'], array_slice($acquired, 10, 2), 'Positions par identifiant trié, sans doublon.');
        $this->assertSame(['81:C', '81:D'], array_slice($acquired, 12, 2));
        $this->assertSame(['82:E', '82:F'], array_slice($acquired, 14, 2));
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

    public function test_le_verrou_partage_du_cabinet_ne_devient_jamais_exclusif(): void
    {
        // Constat de revue F07 : partagé → facture → exclusif passait et émettait le FOR UPDATE du cabinet après un
        // verrou de rang 60. La montée est refusée avant toute requête, même sans verrou intermédiaire.
        $after = new TransactionLocks($this->clinicId);
        $after->clinicShared();
        $after->invoice(Ulid::generate());
        $this->assertLockOrderViolation(static fn () => $after->clinic());

        $direct = new TransactionLocks($this->clinicId);
        $direct->clinicShared();
        $this->assertLockOrderViolation(static fn () => $direct->clinic());

        // L'inverse reste permis : l'exclusif couvre déjà la lecture partagée.
        $exclusive = new TransactionLocks($this->clinicId);
        $exclusive->clinic();
        $exclusive->clinicShared();
        $this->assertTrue($exclusive->holdsClinicExclusively());
    }

    public function test_le_compte_se_verrouille_apres_le_cabinet_et_avant_le_reste(): void
    {
        $user = $this->makeStaff($this->clinicId, 'CASHIER', 'rang.compte');

        $locks = new TransactionLocks($this->clinicId);
        $locks->clinic();
        $this->assertSame($user->id, $locks->account($user->id)?->id);
        $locks->device(Ulid::generate());
        $this->assertSame(['10', '15', '20'], array_map(static fn (string $token): string => explode(':', $token, 2)[0], $locks->acquired()));

        $late = new TransactionLocks($this->clinicId);
        $late->clinic();
        $late->invoice(Ulid::generate());
        $this->assertLockOrderViolation(static fn () => $late->account($user->id));
    }

    public function test_un_compte_ou_une_cle_d_un_autre_cabinet_reste_invisible(): void
    {
        // Mutations non détectées relevées par la revue : retirer le filtre clinic_id de byId() ou d'idempotency().
        $otherClinic = $this->makeClinic('TEST CABINET C');
        $stranger = $this->makeStaff($otherClinic, 'CASHIER', 'autre.cabinet');
        DB::table('idempotency_requests')->insert([
            'id' => Ulid::generate(), 'clinic_id' => $otherClinic, 'namespace' => 'job:rapprochement', 'command_name' => 'TestJob',
            'request_key' => 'CLE-PARTAGEE-0001', 'payload_hash' => str_repeat('a', 64), 'status' => 'COMMITTED',
            'created_at' => '2026-10-14 08:00:00.000000', 'updated_at' => '2026-10-14 08:00:00.000000',
        ]);

        $locks = new TransactionLocks($this->clinicId);
        $locks->clinic();
        $this->assertNull($locks->account($stranger->id), 'Compte d\'un autre cabinet.');
        $this->assertNull($locks->idempotency('job:rapprochement', 'TestJob', 'CLE-PARTAGEE-0001'), 'Même espace « job: » dans un autre cabinet.');

        $owner = new TransactionLocks($otherClinic);
        $owner->clinic();
        $this->assertSame($stranger->id, $owner->account($stranger->id)?->id);
        $this->assertNotNull($owner->idempotency('job:rapprochement', 'TestJob', 'CLE-PARTAGEE-0001'));
    }

    private function assertLockOrderViolation(\Closure $acquire): void
    {
        try {
            $acquire();
            $this->fail('Ordre des verrous violé sans LockOrderViolation.');
        } catch (LockOrderViolation) {
            $this->addToAssertionCount(1);
        }
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
