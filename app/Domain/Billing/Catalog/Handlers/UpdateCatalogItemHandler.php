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
use App\Domain\Billing\BillingErrors;
use App\Domain\Billing\Catalog\CatalogLookups;
use App\Domain\Billing\Catalog\Rules\CatalogItemInput;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use Illuminate\Support\Facades\DB;

/**
 * Modification d'un article du catalogue (B01) avec la version attendue (`catalog_items.version`, optimiste) : les
 * champs de la requête REMPLACENT ceux de l'article. Un changement de prix ne touche jamais une facture émise
 * (chaque ligne garde son prix, sa désignation et sa taxe figés) ; il s'applique aux brouillons suivants.
 *
 * Une demande qui ne change rien n'incrémente pas la version et n'écrit ni audit ni changement publié. L'audit d'une
 * modification garde l'avant et l'après du prix de vente et des autres champs (journal des prix, fichier 09) ; le
 * coût d'achat n'y figure que comme « modifié ».
 */
final class UpdateCatalogItemHandler implements CommandHandler
{
    public const COMMAND = 'UpdateCatalogItem';

    /** Champs comparés, dans l'ordre de l'audit. */
    private const FIELDS = [
        'reference', 'category_code', 'name', 'brand', 'model', 'unit_code', 'quantity_integer_only', 'sale_price', 'purchase_cost',
        'tax_rule_id', 'stock_mode', 'requires_expiry', 'low_stock_threshold', 'expiry_alert_days', 'active', 'public_visibility',
    ];

    public function permissions(): array
    {
        return ['catalog.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $id = (string) $command->entityId;
        $current = DB::table('catalog_items')->where('clinic_id', $context->clinicId)->where('id', $id)->first();
        if ($current === null) {
            throw BusinessError::notFound('Article introuvable dans ce cabinet.');
        }
        if ($command->expectedVersion === null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue est obligatoire pour modifier un article.']]);
        }
        if ((int) $current->version !== $command->expectedVersion) {
            throw BillingErrors::versionConflict((int) $current->version);
        }

        $errors = new FieldErrors;
        $columns = CatalogItemInput::columns($command->payload, CatalogLookups::units(), CatalogLookups::categories(), CatalogLookups::taxRuleIds($context->clinicId), $errors);
        if ($columns !== null && DB::table('catalog_items')->where('clinic_id', $context->clinicId)->where('reference', $columns['reference'])->where('id', '<>', $id)->exists()) {
            $errors->add('reference', 'Cette référence existe déjà dans le catalogue.');
        }
        $errors->throwIfAny();
        if ($columns === null) {
            throw new \LogicException('Colonnes de l\'article absentes sans erreur de validation.');
        }

        $before = self::normalized($current);
        $changed = [];
        foreach (self::FIELDS as $field) {
            if ($before[$field] !== $columns[$field]) {
                $changed[$field] = $field === 'purchase_cost' ? ['modified' => true] : ['before' => $before[$field], 'after' => $columns[$field]];
            }
        }

        $version = (int) $current->version;
        if ($changed === []) {
            return new CommandOutcome(['item_id' => $id, 'version' => $version], 'catalog_items', $id);
        }

        $version++;
        DB::table('catalog_items')->where('clinic_id', $context->clinicId)->where('id', $id)->update($columns + [
            'version' => $version,
            'updated_at' => $context->now->utc()->format('Y-m-d H:i:s.u'),
        ]);

        return new CommandOutcome(
            result: ['item_id' => $id, 'version' => $version],
            resultType: 'catalog_items',
            resultId: $id,
            audit: [new AuditEntry('catalog.item_updated', 'catalog_items', $id, null, ['changes' => $changed])],
            changes: [new ChangeRecord('catalog_items', $id, $version, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }

    /**
     * Ligne actuelle ramenée à la forme des colonnes validées (types stricts), pour reconnaître un changement.
     *
     * @return array<string, mixed>
     */
    private static function normalized(object $row): array
    {
        return [
            'reference' => (string) $row->reference,
            'category_code' => (string) $row->category_code,
            'name' => (string) $row->name,
            'brand' => $row->brand === null ? null : (string) $row->brand,
            'model' => $row->model === null ? null : (string) $row->model,
            'unit_code' => (string) $row->unit_code,
            'quantity_integer_only' => (bool) $row->quantity_integer_only,
            'sale_price' => (int) $row->sale_price,
            'purchase_cost' => $row->purchase_cost === null ? null : (int) $row->purchase_cost,
            'tax_rule_id' => $row->tax_rule_id === null ? null : (string) $row->tax_rule_id,
            'stock_mode' => (string) $row->stock_mode,
            'requires_expiry' => (bool) $row->requires_expiry,
            'low_stock_threshold' => $row->low_stock_threshold === null ? null : (string) $row->low_stock_threshold,
            'expiry_alert_days' => $row->expiry_alert_days === null ? null : (int) $row->expiry_alert_days,
            'active' => (bool) $row->active,
            'public_visibility' => (bool) $row->public_visibility,
        ];
    }
}
