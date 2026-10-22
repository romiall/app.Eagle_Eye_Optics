<?php

namespace App\Domain\Inventory;

use App\Application\Locking\TransactionLocks;
use App\Domain\Inventory\Rules\LotRules;
use App\Domain\Inventory\Rules\ReservationPlanner;
use App\Domain\Inventory\Rules\StockAvailability;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * Service transactionnel de RÉSERVE de stock (I00), utilisé par l'émission d'une facture et, plus tard, par la
 * remise (ConfirmDelivery : « fournir le même port transactionnel », aucune logique de stock à inventer après la
 * caisse). Il tourne DANS la transaction du chemin central, sous le verrou du cabinet, et prend ses verrous dans
 * l'ordre du contrat 23 : positions (rang 80), puis lots (81), puis quotas des postes (82), chacun par identifiant
 * trié (`TransactionLocks` refuse tout autre ordre avant la requête).
 *
 * Deux réserves :
 *  - CENTRALE (`reserveCentral`, route directe) : le serveur choisit position et lots (FEFO, péremption à la date
 *    locale) et augmente `reserved` de la position et du lot ; le disponible central baisse, le physique non ;
 *  - SOUS QUOTA LOCAL (`reserveUnderQuota`, commande d'un poste, O05) : le poste a choisi son quota, sa position
 *    et son lot ; la réserve reste DANS le quota, jamais soustraite deux fois du central (la vue
 *    `vw_stock_position_balances` l'exclut par `device_allocation_id`), donc `reserved` de la position ne bouge pas.
 *
 * Atomique : toutes les lignes sont planifiées AVANT la première écriture ; la dernière vente impossible d'un
 * article refuse la facture entière, sans réserve partielle. Idempotente : une ligne déjà réservée n'est jamais
 * réservée deux fois ; sa réserve est rendue telle quelle (même quantité, sinon défaut de programmation).
 * Aucune quantité n'est jamais décrémentée du physique ici : seule la remise sort l'article.
 *
 * Aucun appel externe, aucune transaction propre.
 */
final class ReservationService
{
    /**
     * @param  list<ReservationRequest>  $requests
     * @return list<PlannedReservation>
     *
     * @throws ReservationRefused
     */
    public function reserveCentral(string $clinicId, TransactionLocks $locks, CivilDate $localDate, array $requests, CarbonImmutable $at): array
    {
        if ($requests === []) {
            return [];
        }

        $now = $at->utc()->format('Y-m-d H:i:s.u');
        [$already, $toPlan] = $this->splitAlreadyReserved($clinicId, $requests);
        if ($toPlan === []) {
            return $already;
        }

        $itemIds = array_values(array_unique(array_map(static fn (ReservationRequest $request): string => $request->itemId, $toPlan)));
        /** @var array<string, string> $positionItem position → article */
        $positionItem = DB::table('stock_positions')->where('clinic_id', $clinicId)->whereIn('item_id', $itemIds)->pluck('item_id', 'id')->all();
        $lotMeta = [];
        foreach (DB::table('stock_lots')->where('clinic_id', $clinicId)->whereIn('item_id', $itemIds)->get(['id', 'item_id', 'stock_position_id']) as $lot) {
            $lotMeta[(string) $lot->id] = ['item_id' => (string) $lot->item_id, 'position_id' => (string) $lot->stock_position_id];
        }

        // Verrous : positions (80) puis lots (81), par identifiant trié. Les lignes relues ici font foi.
        $positions = $locks->stockPositions(array_map('strval', array_keys($positionItem)));
        $lots = $locks->stockLots(array_map('strval', array_keys($lotMeta)));

        $positionAvailable = [];
        foreach ($positions as $id => $row) {
            $positionAvailable[(string) $id] = Quantity::of((string) $row->on_hand)->minus(Quantity::of((string) $row->reserved))->minus(Quantity::of((string) $row->offline_allocated));
        }
        $lotAvailable = [];
        foreach ($lots as $id => $row) {
            $lotAvailable[(string) $id] = Quantity::of((string) $row->on_hand)->minus(Quantity::of((string) $row->reserved))->minus(Quantity::of((string) $row->offline_allocated));
        }

        $planned = [];
        $positionDelta = [];
        $lotDelta = [];
        foreach ($toPlan as $request) {
            $itemPositions = [];
            foreach ($positionItem as $positionId => $itemId) {
                if ((string) $itemId === $request->itemId && isset($positionAvailable[(string) $positionId])) {
                    $itemPositions[] = ['id' => (string) $positionId, 'available' => $positionAvailable[(string) $positionId]];
                }
            }
            $itemLots = [];
            foreach ($lotMeta as $lotId => $meta) {
                if ($meta['item_id'] === $request->itemId && isset($lotAvailable[$lotId])) {
                    $row = $lots[$lotId];
                    $itemLots[] = [
                        'id' => $lotId,
                        'position_id' => $meta['position_id'],
                        'available' => $lotAvailable[$lotId],
                        'status' => (string) $row->status,
                        'expires_on' => $row->expires_on === null ? null : CivilDate::fromString((string) $row->expires_on),
                    ];
                }
            }

            try {
                $allocations = ReservationPlanner::plan($request->quantity, $request->requiresExpiry, $itemPositions, $itemLots, $localDate);
            } catch (RuleViolation $violation) {
                throw new ReservationRefused($violation, $request->invoiceLineId);
            }

            foreach ($allocations as $allocation) {
                $positionId = $allocation['position_id'];
                $positionAvailable[$positionId] = $positionAvailable[$positionId]->minus($allocation['quantity']);
                $positionDelta[$positionId] = ($positionDelta[$positionId] ?? self::zero())->plus($allocation['quantity']);
                if ($allocation['lot_id'] !== null) {
                    $lotAvailable[$allocation['lot_id']] = $lotAvailable[$allocation['lot_id']]->minus($allocation['quantity']);
                    $lotDelta[$allocation['lot_id']] = ($lotDelta[$allocation['lot_id']] ?? self::zero())->plus($allocation['quantity']);
                }
                $planned[] = new PlannedReservation(Ulid::generate(), $request->invoiceLineId, $request->itemId, $positionId, $allocation['lot_id'], null, $allocation['quantity']);
            }
        }

        // Écritures, seulement une fois TOUTES les lignes planifiées.
        foreach ($planned as $reservation) {
            DB::table('stock_reservations')->insert([
                'id' => $reservation->reservationId,
                'clinic_id' => $clinicId,
                'item_id' => $reservation->itemId,
                'invoice_line_id' => $reservation->invoiceLineId,
                'stock_position_id' => $reservation->stockPositionId,
                'stock_lot_id' => $reservation->stockLotId,
                'device_allocation_id' => null,
                'quantity' => $reservation->quantity->canonical(),
                'consumed_quantity' => '0.000',
                'released_quantity' => '0.000',
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->moveReserved($clinicId, 'stock_positions', $positions, $positionDelta, 1, $now);
        $this->moveReserved($clinicId, 'stock_lots', $lots, $lotDelta, 1, $now);

        return [...$already, ...$planned];
    }

    /**
     * Réserve SOUS le quota local d'un poste (commande synchronisée) : le poste a déjà choisi quota, position et lot,
     * le serveur contrôle seulement qu'ils sont cohérents et que le quota restant suffit. Ni `reserved` de la
     * position ni celui du lot ne bougent : la réserve est dans le quota (`offline_allocated`), déjà retiré du central.
     *
     * @param  list<array{reservation_id: string, request: ReservationRequest, device_allocation_id: string, stock_position_id: string, stock_lot_id: ?string}>  $reservations
     * @return list<PlannedReservation>
     *
     * @throws ReservationRefused
     */
    public function reserveUnderQuota(string $clinicId, TransactionLocks $locks, string $deviceId, CivilDate $localDate, array $reservations, CarbonImmutable $at): array
    {
        if ($reservations === []) {
            return [];
        }

        $now = $at->utc()->format('Y-m-d H:i:s.u');
        $lotIds = array_values(array_unique(array_filter(array_map(static fn (array $reservation): ?string => $reservation['stock_lot_id'], $reservations))));
        $quotaIds = array_values(array_unique(array_map(static fn (array $reservation): string => $reservation['device_allocation_id'], $reservations)));

        // Verrous : lots (81) puis quotas (82). Les positions ne sont pas modifiées : seulement lues.
        $lots = $locks->stockLots($lotIds);
        $quotas = $locks->stockQuotas($quotaIds);

        // Part déjà engagée par des réserves actives sous chaque quota (la nouvelle réserve s'y ajoute).
        $engaged = [];
        foreach (DB::table('stock_reservations')
            ->where('clinic_id', $clinicId)
            ->whereIn('device_allocation_id', $quotaIds)
            ->where('status', 'ACTIVE')
            ->get(['device_allocation_id', 'quantity', 'consumed_quantity', 'released_quantity']) as $row) {
            $key = (string) $row->device_allocation_id;
            $open = Quantity::of((string) $row->quantity)->minus(Quantity::of((string) $row->consumed_quantity))->minus(Quantity::of((string) $row->released_quantity));
            $engaged[$key] = ($engaged[$key] ?? self::zero())->plus($open);
        }

        $planned = [];
        foreach ($reservations as $reservation) {
            $request = $reservation['request'];
            try {
                $quota = $quotas[$reservation['device_allocation_id']] ?? null;
                if ($quota === null || (string) $quota->device_id !== $deviceId || (string) $quota->status !== 'ACTIVE') {
                    throw RuleViolation::invalid('device_allocation_id', 'stock.quota_unknown', 'Quota de stock inconnu de ce poste ou clos.');
                }
                if ((string) $quota->stock_position_id !== $reservation['stock_position_id'] || ($quota->stock_lot_id === null ? null : (string) $quota->stock_lot_id) !== $reservation['stock_lot_id']) {
                    throw RuleViolation::invalid('device_allocation_id', 'stock.quota_mismatch', 'Le quota ne porte pas cette position ou ce lot.');
                }
                $position = DB::table('stock_positions')->where('clinic_id', $clinicId)->where('id', $reservation['stock_position_id'])->first();
                if (! $position instanceof stdClass || (string) $position->item_id !== $request->itemId) {
                    throw RuleViolation::invalid('stock_position_id', 'stock.position_mismatch', 'La position de stock ne correspond pas à cet article.');
                }
                if ($reservation['stock_lot_id'] === null && $request->requiresExpiry) {
                    throw RuleViolation::invalid('stock_lot_id', 'stock.lot_required', 'Un article périssable ne se réserve que sur un lot.');
                }
                if ($reservation['stock_lot_id'] !== null) {
                    $lot = $lots[$reservation['stock_lot_id']] ?? null;
                    if ($lot === null) {
                        throw RuleViolation::invalid('stock_lot_id', 'stock.lot_unknown', 'Lot introuvable dans ce cabinet.');
                    }
                    LotRules::assertDeliverable((string) $lot->status, $lot->expires_on === null ? null : CivilDate::fromString((string) $lot->expires_on), $localDate);
                }

                $remaining = StockAvailability::remainingQuota(
                    Quantity::of((string) $quota->allocated_quantity),
                    Quantity::of((string) $quota->consumed_quantity),
                    Quantity::of((string) $quota->returned_quantity),
                )->minus($engaged[$reservation['device_allocation_id']] ?? self::zero());
                StockAvailability::assertCanTake($request->quantity, $remaining, 'stock.quota_exceeded');
            } catch (RuleViolation $violation) {
                throw new ReservationRefused($violation, $request->invoiceLineId);
            }

            $engaged[$reservation['device_allocation_id']] = ($engaged[$reservation['device_allocation_id']] ?? self::zero())->plus($request->quantity);
            $planned[] = new PlannedReservation(
                $reservation['reservation_id'],
                $request->invoiceLineId,
                $request->itemId,
                $reservation['stock_position_id'],
                $reservation['stock_lot_id'],
                $reservation['device_allocation_id'],
                $request->quantity,
            );
        }

        foreach ($planned as $reservation) {
            DB::table('stock_reservations')->insert([
                'id' => $reservation->reservationId,
                'clinic_id' => $clinicId,
                'item_id' => $reservation->itemId,
                'invoice_line_id' => $reservation->invoiceLineId,
                'stock_position_id' => $reservation->stockPositionId,
                'stock_lot_id' => $reservation->stockLotId,
                'device_allocation_id' => $reservation->deviceAllocationId,
                'quantity' => $reservation->quantity->canonical(),
                'consumed_quantity' => '0.000',
                'released_quantity' => '0.000',
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $planned;
    }

    /**
     * Libère les réserves ACTIVES d'une facture (la quantité non encore remise) : les positions et lots reprennent leur
     * disponible, jamais le physique. Une réserve sous quota n'a rien à rendre au central : elle revient au quota
     * du poste. Rend le nombre de réserves libérées.
     */
    public function releaseForInvoice(string $clinicId, TransactionLocks $locks, string $invoiceId, CarbonImmutable $at): int
    {
        $now = $at->utc()->format('Y-m-d H:i:s.u');
        $rows = DB::table('stock_reservations')
            ->join('invoice_lines', static function ($join): void {
                $join->on('invoice_lines.clinic_id', '=', 'stock_reservations.clinic_id')->on('invoice_lines.id', '=', 'stock_reservations.invoice_line_id');
            })
            ->where('stock_reservations.clinic_id', $clinicId)
            ->where('invoice_lines.invoice_id', $invoiceId)
            ->where('stock_reservations.status', 'ACTIVE')
            ->orderBy('stock_reservations.id')
            ->get(['stock_reservations.*'])
            ->all();
        if ($rows === []) {
            return 0;
        }

        $positionIds = [];
        $lotIds = [];
        foreach ($rows as $row) {
            if ($row->device_allocation_id === null) {
                $positionIds[] = (string) $row->stock_position_id;
                if ($row->stock_lot_id !== null) {
                    $lotIds[] = (string) $row->stock_lot_id;
                }
            }
        }
        $positions = $locks->stockPositions($positionIds);
        $lots = $locks->stockLots($lotIds);

        $positionDelta = [];
        $lotDelta = [];
        foreach ($rows as $row) {
            $quantity = Quantity::of((string) $row->quantity);
            $consumed = Quantity::of((string) $row->consumed_quantity);
            $open = $quantity->minus($consumed)->minus(Quantity::of((string) $row->released_quantity));

            DB::table('stock_reservations')->where('clinic_id', $clinicId)->where('id', $row->id)->update([
                'released_quantity' => $quantity->minus($consumed)->canonical(),
                'status' => $open->isZero() ? 'CONSUMED' : 'RELEASED',
                'version' => (int) $row->version + 1,
                'updated_at' => $now,
            ]);

            if ($row->device_allocation_id === null && ! $open->isZero()) {
                $positionDelta[(string) $row->stock_position_id] = ($positionDelta[(string) $row->stock_position_id] ?? self::zero())->plus($open);
                if ($row->stock_lot_id !== null) {
                    $lotDelta[(string) $row->stock_lot_id] = ($lotDelta[(string) $row->stock_lot_id] ?? self::zero())->plus($open);
                }
            }
        }
        $this->moveReserved($clinicId, 'stock_positions', $positions, $positionDelta, -1, $now);
        $this->moveReserved($clinicId, 'stock_lots', $lots, $lotDelta, -1, $now);

        return count($rows);
    }

    /**
     * @param  list<ReservationRequest>  $requests
     * @return array{0: list<PlannedReservation>, 1: list<ReservationRequest>} réserves déjà écrites, demandes à planifier
     */
    private function splitAlreadyReserved(string $clinicId, array $requests): array
    {
        $existing = DB::table('stock_reservations')
            ->where('clinic_id', $clinicId)
            ->whereIn('invoice_line_id', array_map(static fn (ReservationRequest $request): string => $request->invoiceLineId, $requests))
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->get();
        $byLine = [];
        foreach ($existing as $row) {
            $byLine[(string) $row->invoice_line_id][] = $row;
        }

        $already = [];
        $toPlan = [];
        foreach ($requests as $request) {
            $rows = $byLine[$request->invoiceLineId] ?? [];
            if ($rows === []) {
                $toPlan[] = $request;

                continue;
            }
            $sum = self::zero();
            foreach ($rows as $row) {
                $quantity = Quantity::of((string) $row->quantity);
                $sum = $sum->plus($quantity);
                $already[] = new PlannedReservation(
                    (string) $row->id,
                    $request->invoiceLineId,
                    $request->itemId,
                    (string) $row->stock_position_id,
                    $row->stock_lot_id === null ? null : (string) $row->stock_lot_id,
                    $row->device_allocation_id === null ? null : (string) $row->device_allocation_id,
                    $quantity,
                );
            }
            if ($sum->compare($request->quantity) !== 0) {
                throw new LogicException('La ligne '.$request->invoiceLineId.' est déjà réservée pour une autre quantité : une réserve existante ne se modifie pas par une nouvelle demande.');
            }
        }

        return [$already, $toPlan];
    }

    /**
     * Augmente (`$sign` = 1) ou diminue (-1) la quantité réservée d'une position ou d'un lot, une ligne à la fois,
     * sur les lignes déjà verrouillées. Le CHECK `on_hand >= reserved + offline_allocated` reste le dernier garde-fou.
     *
     * @param  array<string, stdClass>  $rows  lignes verrouillées par identifiant
     * @param  array<string, FixedDecimal>  $deltas
     */
    private function moveReserved(string $clinicId, string $table, array $rows, array $deltas, int $sign, string $now): void
    {
        foreach ($deltas as $id => $delta) {
            $row = $rows[(string) $id];
            $current = Quantity::of((string) $row->reserved);
            $next = $sign > 0 ? $current->plus($delta) : $current->minus($delta);

            DB::table($table)->where('clinic_id', $clinicId)->where('id', (string) $id)->update([
                'reserved' => Quantity::bounded($next->units, 'reserved')->canonical(),
                'version' => (int) $row->version + 1,
                'updated_at' => $now,
            ]);
        }
    }

    private static function zero(): FixedDecimal
    {
        return FixedDecimal::fromUnits(0, Quantity::SCALE);
    }
}
