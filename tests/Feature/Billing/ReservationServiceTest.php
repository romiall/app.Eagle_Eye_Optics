<?php

namespace Tests\Feature\Billing;

use App\Application\Locking\TransactionLocks;
use App\Domain\Inventory\PlannedReservation;
use App\Domain\Inventory\ReservationRefused;
use App\Domain\Inventory\ReservationRequest;
use App\Domain\Inventory\ReservationService;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BillingFixtures;
use Tests\Support\MigratedDatabase;
use Tests\TestCase;

/**
 * Service de réserve de stock (I00) sur MySQL réel : réserve centrale (physique intact, disponible réduit), atomicité
 * entre lignes, idempotence par ligne, libération, réserve sous quota local (jamais soustraite deux fois du central) et
 * ordre des verrous positions (80), lots (81), quotas (82). Le service tourne dans la transaction du chemin central ;
 * ces tests l'appellent directement, sous le verrou du cabinet. Données fictives.
 */
final class ReservationServiceTest extends TestCase
{
    use BillingFixtures;
    use MigratedDatabase;

    private ReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBilling();
        $this->service = $this->app->make(ReservationService::class);
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        parent::tearDown();
    }

    private function locks(): TransactionLocks
    {
        $locks = new TransactionLocks($this->clinicId);
        $locks->clinic();

        return $locks;
    }

    private function today(): CivilDate
    {
        return CivilDate::of(2026, 10, 22);
    }

    /**
     * Identifiants des lignes d'un brouillon, dans l'ordre de leurs positions.
     *
     * @return list<string>
     */
    private function lineIds(string $invoiceId): array
    {
        return array_map('strval', DB::table('invoice_lines')->where('invoice_id', $invoiceId)->orderBy('position')->pluck('id')->all());
    }

    private function request(string $lineId, string $itemId, string $quantity, bool $expiry = false): ReservationRequest
    {
        return new ReservationRequest($lineId, $itemId, Quantity::of($quantity), $expiry);
    }

    /**
     * @param  list<ReservationRequest>  $requests
     * @return list<PlannedReservation>
     */
    private function reserve(array $requests, ?TransactionLocks $locks = null): array
    {
        return DB::transaction(fn (): array => $this->service->reserveCentral($this->clinicId, $locks ?? $this->locks(), $this->today(), $requests, CarbonImmutable::now('UTC')));
    }

    private function seedQuota(string $deviceId, string $positionId, ?string $lotId, string $allocated, string $consumed = '0'): string
    {
        $id = Ulid::generate();
        DB::table('device_stock_allocations')->insert([
            'id' => $id, 'clinic_id' => $this->clinicId, 'device_id' => $deviceId, 'stock_position_id' => $positionId, 'stock_lot_id' => $lotId,
            'allocated_quantity' => $allocated, 'consumed_quantity' => $consumed, 'returned_quantity' => '0', 'status' => 'ACTIVE',
            'allocated_at' => '2026-10-22 08:00:00.000000', 'version' => 1,
        ]);

        return $id;
    }

    public function test_la_reserve_centrale_reduit_le_disponible_sans_toucher_au_physique(): void
    {
        $position = $this->seedPosition($this->item['frame'], '5');
        [$invoice] = $this->draft([$this->line($this->item['frame'], '2')]);
        [$line] = $this->lineIds($invoice);

        $planned = $this->reserve([$this->request($line, $this->item['frame'], '2')]);

        $this->assertCount(1, $planned);
        $this->assertSame([$position, null, null, '2.000'], [$planned[0]->stockPositionId, $planned[0]->stockLotId, $planned[0]->deviceAllocationId, $planned[0]->quantity->canonical()]);
        $row = DB::table('stock_positions')->where('id', $position)->first();
        $this->assertNotNull($row);
        $this->assertSame(['5.000', '2.000', '3.000', 2], [$row->on_hand, $row->reserved, $row->central_available, (int) $row->version]);
        $reservation = DB::table('stock_reservations')->where('id', $planned[0]->reservationId)->first();
        $this->assertNotNull($reservation);
        $this->assertSame([$line, 'ACTIVE', '2.000', '0.000', '0.000'], [$reservation->invoice_line_id, $reservation->status, $reservation->quantity, $reservation->consumed_quantity, $reservation->released_quantity]);
        $this->assertSame(0, DB::table('stock_movements')->count(), 'Seule la remise sort du physique.');
    }

    public function test_un_nouvel_appel_pour_la_meme_ligne_rend_la_meme_reserve_sans_la_doubler(): void
    {
        $position = $this->seedPosition($this->item['frame'], '5');
        [$invoice] = $this->draft([$this->line($this->item['frame'], '2')]);
        [$line] = $this->lineIds($invoice);

        $first = $this->reserve([$this->request($line, $this->item['frame'], '2')]);
        $second = $this->reserve([$this->request($line, $this->item['frame'], '2')]);

        $this->assertSame($first[0]->reservationId, $second[0]->reservationId);
        $this->assertSame(1, DB::table('stock_reservations')->count());
        $this->assertSame('2.000', DB::table('stock_positions')->where('id', $position)->value('reserved'));
    }

    public function test_une_autre_quantite_pour_une_ligne_deja_reservee_est_un_defaut_de_programmation(): void
    {
        $this->seedPosition($this->item['frame'], '5');
        [$invoice] = $this->draft([$this->line($this->item['frame'], '2')]);
        [$line] = $this->lineIds($invoice);
        $this->reserve([$this->request($line, $this->item['frame'], '2')]);

        $this->expectException(LogicException::class);
        $this->reserve([$this->request($line, $this->item['frame'], '3')]);
    }

    public function test_un_refus_sur_une_ligne_ne_laisse_aucune_reserve_partielle(): void
    {
        $framePosition = $this->seedPosition($this->item['frame'], '5');
        $this->seedPosition($this->item['drops'], '0');
        [$invoice] = $this->draft([$this->line($this->item['frame'], '2'), $this->line($this->item['drops'], '1', 2)]);
        [$frameLine, $dropsLine] = $this->lineIds($invoice);

        try {
            $this->reserve([$this->request($frameLine, $this->item['frame'], '2'), $this->request($dropsLine, $this->item['drops'], '1', true)]);
            $this->fail('La seconde ligne n\'a pas de stock : la demande entière devait être refusée.');
        } catch (ReservationRefused $refusal) {
            $this->assertSame($dropsLine, $refusal->invoiceLineId);
            $this->assertSame('STOCK_UNAVAILABLE', $refusal->violation->errorCode);
        }

        $this->assertSame(0, DB::table('stock_reservations')->count());
        $this->assertSame('0.000', DB::table('stock_positions')->where('id', $framePosition)->value('reserved'));
    }

    public function test_la_liberation_rend_le_disponible_et_marque_les_reserves(): void
    {
        $position = $this->seedPosition($this->item['frame'], '5');
        [$invoice] = $this->draft([$this->line($this->item['frame'], '2')]);
        [$line] = $this->lineIds($invoice);
        $planned = $this->reserve([$this->request($line, $this->item['frame'], '2')]);

        $released = DB::transaction(fn (): int => $this->service->releaseForInvoice($this->clinicId, $this->locks(), $invoice, CarbonImmutable::now('UTC')));
        $again = DB::transaction(fn (): int => $this->service->releaseForInvoice($this->clinicId, $this->locks(), $invoice, CarbonImmutable::now('UTC')));

        $this->assertSame([1, 0], [$released, $again]);
        $row = DB::table('stock_positions')->where('id', $position)->first();
        $this->assertNotNull($row);
        $this->assertSame(['5.000', '0.000', '5.000'], [$row->on_hand, $row->reserved, $row->central_available]);
        $reservation = DB::table('stock_reservations')->where('id', $planned[0]->reservationId)->first();
        $this->assertNotNull($reservation);
        $this->assertSame(['RELEASED', '2.000'], [$reservation->status, $reservation->released_quantity]);
    }

    public function test_une_reserve_sur_lot_reduit_aussi_le_lot_et_la_liberation_le_lui_rend(): void
    {
        $position = $this->seedPosition($this->item['drops'], '10');
        $lot = $this->seedLot($position, $this->item['drops'], 'LOT-A', '2027-01-31', '10');
        [$invoice] = $this->draft([$this->line($this->item['drops'], '4')]);
        [$line] = $this->lineIds($invoice);

        $planned = $this->reserve([$this->request($line, $this->item['drops'], '4', true)]);

        $this->assertSame($lot, $planned[0]->stockLotId);
        $this->assertSame('4.000', DB::table('stock_lots')->where('id', $lot)->value('reserved'));
        DB::transaction(fn (): int => $this->service->releaseForInvoice($this->clinicId, $this->locks(), $invoice, CarbonImmutable::now('UTC')));
        $this->assertSame('0.000', DB::table('stock_lots')->where('id', $lot)->value('reserved'));
        $this->assertSame('0.000', DB::table('stock_positions')->where('id', $position)->value('reserved'));
    }

    public function test_les_verrous_de_stock_se_prennent_positions_puis_lots_par_identifiant_trie(): void
    {
        $framePosition = $this->seedPosition($this->item['frame'], '5');
        $dropsPosition = $this->seedPosition($this->item['drops'], '5');
        $lot = $this->seedLot($dropsPosition, $this->item['drops'], 'LOT-A', '2027-01-31', '5');
        [$invoice] = $this->draft([$this->line($this->item['frame'], '1'), $this->line($this->item['drops'], '1', 2)]);
        [$frameLine, $dropsLine] = $this->lineIds($invoice);
        $locks = $this->locks();

        $this->reserve([$this->request($frameLine, $this->item['frame'], '1'), $this->request($dropsLine, $this->item['drops'], '1', true)], $locks);

        $positions = [$framePosition, $dropsPosition];
        sort($positions, SORT_STRING);
        $this->assertSame(
            ['10:'.$this->clinicId, '80:'.$positions[0], '80:'.$positions[1], '81:'.$lot],
            $locks->acquired(),
        );
    }

    // ------------------------------------------------------------------------------------------------------------
    // Réserve sous quota local (commande d'un poste, O05)
    // ------------------------------------------------------------------------------------------------------------

    public function test_la_reserve_sous_quota_reste_dans_le_quota_et_ne_touche_pas_au_central(): void
    {
        $position = $this->seedPosition($this->item['frame'], '10', '0', '4');
        $device = $this->seedDevice('Caisse B');
        $quota = $this->seedQuota($device, $position, null, '4');
        [$invoice] = $this->draft([$this->line($this->item['frame'], '3')]);
        [$line] = $this->lineIds($invoice);
        $reservationId = Ulid::generate();

        $planned = DB::transaction(fn (): array => $this->service->reserveUnderQuota(
            $this->clinicId,
            $this->locks(),
            $device,
            $this->today(),
            [['reservation_id' => $reservationId, 'request' => $this->request($line, $this->item['frame'], '3'), 'device_allocation_id' => $quota, 'stock_position_id' => $position, 'stock_lot_id' => null]],
            CarbonImmutable::now('UTC'),
        ));

        $this->assertSame($reservationId, $planned[0]->reservationId);
        $this->assertSame($quota, DB::table('stock_reservations')->where('id', $reservationId)->value('device_allocation_id'));
        $row = DB::table('stock_positions')->where('id', $position)->first();
        $this->assertNotNull($row);
        $this->assertSame(['10.000', '0.000', '4.000', '6.000'], [$row->on_hand, $row->reserved, $row->offline_allocated, $row->central_available], 'Le central ne bouge pas : la réserve est dans le quota.');
    }

    public function test_un_quota_epuise_ou_d_un_autre_poste_refuse_la_reserve(): void
    {
        $position = $this->seedPosition($this->item['frame'], '10', '0', '4');
        $device = $this->seedDevice('Caisse B');
        $other = $this->seedDevice('Caisse C');
        $quota = $this->seedQuota($device, $position, null, '4', '3');
        [$invoice] = $this->draft([$this->line($this->item['frame'], '2')]);
        [$line] = $this->lineIds($invoice);
        $attempt = fn (string $deviceId) => DB::transaction(fn (): array => $this->service->reserveUnderQuota(
            $this->clinicId,
            $this->locks(),
            $deviceId,
            $this->today(),
            [['reservation_id' => Ulid::generate(), 'request' => $this->request($line, $this->item['frame'], '2'), 'device_allocation_id' => $quota, 'stock_position_id' => $position, 'stock_lot_id' => null]],
            CarbonImmutable::now('UTC'),
        ));

        try {
            $attempt($device);
            $this->fail('Il ne reste qu\'une unité au quota : deux sont refusées.');
        } catch (ReservationRefused $refusal) {
            $this->assertSame('STOCK_UNAVAILABLE', $refusal->violation->errorCode);
            $this->assertSame('1.000', $refusal->violation->details['available']);
        }
        try {
            $attempt($other);
            $this->fail('Le quota d\'un autre poste ne se réserve pas.');
        } catch (ReservationRefused $refusal) {
            $this->assertSame('VALIDATION_FAILED', $refusal->violation->errorCode);
            $this->assertSame('stock.quota_unknown', $refusal->violation->rule);
        }
        $this->assertSame(0, DB::table('stock_reservations')->count());
    }

    public function test_la_part_deja_reservee_sous_le_quota_est_deduite_du_reste(): void
    {
        $position = $this->seedPosition($this->item['frame'], '10', '0', '4');
        $device = $this->seedDevice('Caisse B');
        $quota = $this->seedQuota($device, $position, null, '4');
        [$invoice] = $this->draft([$this->line($this->item['frame'], '3'), $this->line($this->item['frame'], '2', 2)]);
        [$first, $second] = $this->lineIds($invoice);
        $reserveUnder = fn (string $line, string $quantity) => DB::transaction(fn (): array => $this->service->reserveUnderQuota(
            $this->clinicId,
            $this->locks(),
            $device,
            $this->today(),
            [['reservation_id' => Ulid::generate(), 'request' => $this->request($line, $this->item['frame'], $quantity), 'device_allocation_id' => $quota, 'stock_position_id' => $position, 'stock_lot_id' => null]],
            CarbonImmutable::now('UTC'),
        ));

        $reserveUnder($first, '3');
        $this->expectException(ReservationRefused::class);
        $reserveUnder($second, '2');
    }
}
