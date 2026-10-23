<?php

namespace App\Domain\Billing\Catalog\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\Catalog\CatalogImport;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * Import contrôlé du catalogue (X01) par la gérante (`catalog.manage`), EN LIGNE seulement, par le chemin central :
 * toutes les lignes sont revérifiées sous le verrou du cabinet (une référence créée entre l'aperçu et l'import est
 * détectée), puis TOUTES sont créées dans la même transaction, ou AUCUNE si une seule est refusée (422 ligne par
 * ligne, rien n'est écrit). Chaque article créé a son audit (`catalog.item_created`, origine import, jamais le coût
 * d'achat) et son changement publié, comme un article saisi à l'écran.
 */
final class ImportCatalogHandler implements CommandHandler
{
    public const COMMAND = 'ImportCatalog';

    public function permissions(): array
    {
        return ['catalog.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $rows = $command->payload['rows'] ?? null;
        if (($command->payload['dry_run'] ?? null) !== false) {
            throw BusinessError::validation(['dry_run' => ['L\'import enregistre les articles : dry_run vaut false (l\'aperçu ne passe pas par cette commande).']]);
        }
        if (! is_array($rows) || ! array_is_list($rows) || $rows === [] || count($rows) > CatalogImport::MAX_ROWS) {
            throw BusinessError::validation(['rows' => ['Entre 1 et '.CatalogImport::MAX_ROWS.' lignes sont attendues.']]);
        }

        $check = CatalogImport::check($context->clinicId, $rows);
        if ($check['rejected'] !== []) {
            $fields = [];
            foreach ($check['rejected'] as $rejection) {
                $path = 'rows.'.$rejection['index'].($rejection['field'] === null ? '' : '.'.$rejection['field']);
                $fields[$path][] = 'Ligne '.$rejection['line'].' : '.$rejection['message'];
            }
            throw BusinessError::validation($fields, 'Aucun article n\'a été importé : certaines lignes sont à corriger.');
        }

        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        $created = [];
        $audit = [];
        $changes = [];
        foreach ($check['accepted'] as $item) {
            $columns = $item['columns'];
            $id = Ulid::generate();
            DB::table('catalog_items')->insert($columns + [
                'id' => $id,
                'clinic_id' => $context->clinicId,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created[] = $id;
            $audit[] = new AuditEntry('catalog.item_created', 'catalog_items', $id, null, [
                'reference' => $columns['reference'],
                'category_code' => $columns['category_code'],
                'stock_mode' => $columns['stock_mode'],
                'sale_price' => $columns['sale_price'],
                'tax_rule_id' => $columns['tax_rule_id'],
                'purchase_cost_recorded' => $columns['purchase_cost'] !== null,
                'active' => $columns['active'],
                'source' => 'import',
                'line' => $item['line'],
            ]);
            $changes[] = new ChangeRecord('catalog_items', $id, 1, ChangeVisibility::Financial, ChangeKind::Created);
        }

        return new CommandOutcome(
            result: ['created_ids' => $created, 'rows' => count($rows)],
            resultType: 'catalog_items',
            resultId: $created[0],
            audit: $audit,
            changes: $changes,
        );
    }
}
