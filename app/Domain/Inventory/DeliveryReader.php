<?php

namespace App\Domain\Inventory;

use App\Domain\Billing\InvoiceLedger;
use App\Domain\Inventory\Rules\DeliveryRules;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lecture des remises d'une facture (I01, I02 ; écran « Remettre au patient » de la fiche facture) : pour chaque ligne,
 * quantité facturée, déjà remise, retournée, reste à remettre et réserve ouverte ; l'historique des remises et des
 * retours physiques ; le reste dû à l'instant (une remise d'une facture non soldée exige l'approbation de la gérante).
 *
 * Projection recalculée à chaque lecture, jamais stockée. Aucun coût d'achat, aucune donnée clinique. Le serveur rejuge
 * tout à la remise : cette lecture n'est qu'un affichage.
 */
final class DeliveryReader
{
    /**
     * @return array<string, mixed>
     */
    public function forInvoice(stdClass $invoice): array
    {
        $clinicId = (string) $invoice->clinic_id;
        $invoiceId = (string) $invoice->id;
        $zero = Quantity::of('0');

        $invoiceLines = DB::table('invoice_lines')
            ->leftJoin('catalog_items', static function ($join): void {
                $join->on('catalog_items.clinic_id', '=', 'invoice_lines.clinic_id')->on('catalog_items.id', '=', 'invoice_lines.catalog_item_id');
            })
            ->where('invoice_lines.clinic_id', $clinicId)
            ->where('invoice_lines.invoice_id', $invoiceId)
            ->orderBy('invoice_lines.position')
            ->get(['invoice_lines.id', 'invoice_lines.position', 'invoice_lines.catalog_item_id', 'invoice_lines.description_snapshot', 'invoice_lines.quantity',
                'catalog_items.stock_mode', 'catalog_items.requires_expiry', 'catalog_items.quantity_integer_only'])
            ->all();

        $deliveryRows = DB::table('deliveries')
            ->leftJoin('users as deliverers', static function ($join): void {
                $join->on('deliverers.clinic_id', '=', 'deliveries.clinic_id')->on('deliverers.id', '=', 'deliveries.delivered_by');
            })
            ->where('deliveries.clinic_id', $clinicId)
            ->where('deliveries.invoice_id', $invoiceId)
            ->where('deliveries.status', 'CONFIRMED')
            ->orderBy('deliveries.delivered_at')
            ->orderBy('deliveries.id')
            ->get(['deliveries.*', 'deliverers.display_name as delivered_by_name'])
            ->all();
        $deliveryLines = DB::table('delivery_lines')
            ->leftJoin('stock_lots', static function ($join): void {
                $join->on('stock_lots.clinic_id', '=', 'delivery_lines.clinic_id')->on('stock_lots.id', '=', 'delivery_lines.stock_lot_id');
            })
            ->where('delivery_lines.clinic_id', $clinicId)
            ->where('delivery_lines.invoice_id', $invoiceId)
            ->whereIn('delivery_lines.delivery_id', array_map(static fn (stdClass $row): string => (string) $row->id, $deliveryRows))
            ->orderBy('delivery_lines.id')
            ->get(['delivery_lines.*', 'stock_lots.batch_number', 'stock_lots.expires_on'])
            ->all();
        $returns = DB::table('physical_returns')
            ->where('clinic_id', $clinicId)
            ->whereIn('delivery_line_id', array_map(static fn (stdClass $row): string => (string) $row->id, $deliveryLines))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->all();
        $movementByReturn = DB::table('stock_movements')
            ->where('clinic_id', $clinicId)
            ->whereIn('physical_return_id', array_map(static fn (stdClass $row): string => (string) $row->id, $returns))
            ->pluck('id', 'physical_return_id')
            ->all();
        $reservations = DB::table('stock_reservations')
            ->where('clinic_id', $clinicId)
            ->whereIn('invoice_line_id', array_map(static fn (stdClass $row): string => (string) $row->id, $invoiceLines))
            ->where('status', 'ACTIVE')
            ->get(['invoice_line_id', 'quantity', 'consumed_quantity', 'released_quantity'])
            ->all();

        /** @var array<string, FixedDecimal> $returnedByDeliveryLine */
        $returnedByDeliveryLine = [];
        foreach ($returns as $row) {
            $key = (string) $row->delivery_line_id;
            $returnedByDeliveryLine[$key] = ($returnedByDeliveryLine[$key] ?? $zero)->plus(Quantity::of((string) $row->quantity));
        }
        $deliveredByLine = [];
        $returnedByLine = [];
        $linesByDelivery = [];
        $descriptions = [];
        foreach ($invoiceLines as $line) {
            $descriptions[(string) $line->id] = (string) $line->description_snapshot;
        }
        foreach ($deliveryLines as $row) {
            $lineId = (string) $row->invoice_line_id;
            $quantity = Quantity::of((string) $row->quantity);
            $returned = $returnedByDeliveryLine[(string) $row->id] ?? $zero;
            $deliveredByLine[$lineId] = ($deliveredByLine[$lineId] ?? $zero)->plus($quantity);
            $returnedByLine[$lineId] = ($returnedByLine[$lineId] ?? $zero)->plus($returned);
            $linesByDelivery[(string) $row->delivery_id][] = [
                'id' => (string) $row->id,
                'invoice_line_id' => $lineId,
                'item_id' => (string) $row->item_id,
                'description' => $descriptions[$lineId] ?? '',
                'quantity' => $quantity->canonical(),
                'returned' => $returned->canonical(),
                'returnable' => DeliveryRules::returnable($quantity, $returned)->canonical(),
                'stock_position_id' => $row->stock_position_id === null ? null : (string) $row->stock_position_id,
                'stock_lot_id' => $row->stock_lot_id === null ? null : (string) $row->stock_lot_id,
                'batch_number' => $row->batch_number === null ? null : (string) $row->batch_number,
                'expires_on' => $row->expires_on === null ? null : substr((string) $row->expires_on, 0, 10),
            ];
        }
        $openByLine = [];
        foreach ($reservations as $row) {
            $key = (string) $row->invoice_line_id;
            $open = Quantity::of((string) $row->quantity)->minus(Quantity::of((string) $row->consumed_quantity))->minus(Quantity::of((string) $row->released_quantity));
            $openByLine[$key] = ($openByLine[$key] ?? $zero)->plus($open);
        }

        $lines = [];
        foreach ($invoiceLines as $line) {
            $lineId = (string) $line->id;
            $invoiced = Quantity::of((string) $line->quantity);
            $delivered = $deliveredByLine[$lineId] ?? $zero;
            $remaining = DeliveryRules::remaining($invoiced, $delivered);
            $stockMode = $line->stock_mode === null ? null : (string) $line->stock_mode;
            $open = $openByLine[$lineId] ?? $zero;
            $lines[] = [
                'invoice_line_id' => $lineId,
                'position' => (int) $line->position,
                'item_id' => $line->catalog_item_id === null ? null : (string) $line->catalog_item_id,
                'description' => (string) $line->description_snapshot,
                'stock_mode' => $stockMode,
                'quantity_integer_only' => $line->quantity_integer_only === null ? true : (bool) $line->quantity_integer_only,
                'quantity' => $invoiced->canonical(),
                'delivered' => $delivered->canonical(),
                'returned' => ($returnedByLine[$lineId] ?? $zero)->canonical(),
                'remaining' => $remaining->canonical(),
                'reserved_open' => $open->canonical(),
                // Remettable maintenant : article du catalogue, reste à remettre, et réserve ouverte pour un article en stock.
                'deliverable' => $line->catalog_item_id !== null && ! $remaining->isZero()
                    && ($stockMode !== 'STOCKED' || ! $open->isZero()),
            ];
        }

        $deliveries = [];
        foreach ($deliveryRows as $row) {
            $deliveries[] = [
                'id' => (string) $row->id,
                'status' => (string) $row->status,
                'delivered_at' => CarbonImmutable::parse((string) $row->delivered_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'delivered_by' => (string) $row->delivered_by,
                'delivered_by_name' => $row->delivered_by_name === null ? null : (string) $row->delivered_by_name,
                'credit_delivery_approval_id' => $row->credit_delivery_approval_id === null ? null : (string) $row->credit_delivery_approval_id,
                'lines' => $linesByDelivery[(string) $row->id] ?? [],
            ];
        }

        $returnEntries = [];
        foreach ($returns as $row) {
            $returnEntries[] = [
                'id' => (string) $row->id,
                'delivery_line_id' => (string) $row->delivery_line_id,
                'item_id' => (string) $row->item_id,
                'quantity' => Quantity::of((string) $row->quantity)->canonical(),
                'condition_code' => (string) $row->condition_code,
                'accepted_for_restock' => (bool) $row->accepted_for_restock,
                'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'stock_movement_id' => isset($movementByReturn[(string) $row->id]) ? (string) $movementByReturn[(string) $row->id] : null,
                'credit_note_id' => $row->credit_note_id === null ? null : (string) $row->credit_note_id,
            ];
        }

        $issued = (string) $invoice->status === 'ISSUED';

        return [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice->number === null ? null : (string) $invoice->number,
            'invoice_status' => (string) $invoice->status,
            'amount_due' => $issued ? InvoiceLedger::load($clinicId, $invoiceId, (int) $invoice->total_gross)->amountDue() : null,
            'lines' => $lines,
            'deliveries' => $deliveries,
            'returns' => $returnEntries,
        ];
    }
}
