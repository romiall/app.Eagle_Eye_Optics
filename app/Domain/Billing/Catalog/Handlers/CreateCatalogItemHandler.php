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
use App\Domain\Billing\Catalog\CatalogLookups;
use App\Domain\Billing\Catalog\Rules\CatalogItemInput;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * Création d'un article du catalogue (B01, EE020, EE027) par la gérante (`catalog.manage`), EN LIGNE seulement.
 * Articles stockés, services et sur mesure sont des modes distincts (`stock_mode`), l'unité « paire » n'est jamais
 * transformée en deux unités, la référence est unique par cabinet (comparaison de la base : casse et accents
 * ignorés). Aucun prix n'est fourni par le code : le prix de vente vient de la gérante.
 *
 * L'audit consigne le prix de vente (journal des prix, fichier 09) mais JAMAIS le coût d'achat, qui reste réservé à
 * la gérante (`catalog.purchase_cost.read`) : seul le fait qu'il est renseigné y figure.
 */
final class CreateCatalogItemHandler implements CommandHandler
{
    public const COMMAND = 'CreateCatalogItem';

    public function permissions(): array
    {
        return ['catalog.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $errors = new FieldErrors;
        $columns = CatalogItemInput::columns($command->payload, CatalogLookups::units(), CatalogLookups::categories(), CatalogLookups::taxRuleIds($context->clinicId), $errors);
        if ($columns !== null && DB::table('catalog_items')->where('clinic_id', $context->clinicId)->where('reference', $columns['reference'])->exists()) {
            $errors->add('reference', 'Cette référence existe déjà dans le catalogue.');
        }
        $errors->throwIfAny();
        if ($columns === null) {
            throw new \LogicException('Colonnes de l\'article absentes sans erreur de validation.');
        }

        $id = Ulid::generate();
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('catalog_items')->insert($columns + [
            'id' => $id,
            'clinic_id' => $context->clinicId,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: ['item_id' => $id, 'version' => 1],
            resultType: 'catalog_items',
            resultId: $id,
            audit: [new AuditEntry('catalog.item_created', 'catalog_items', $id, null, [
                'reference' => $columns['reference'],
                'category_code' => $columns['category_code'],
                'stock_mode' => $columns['stock_mode'],
                'sale_price' => $columns['sale_price'],
                'tax_rule_id' => $columns['tax_rule_id'],
                'purchase_cost_recorded' => $columns['purchase_cost'] !== null,
                'active' => $columns['active'],
            ])],
            changes: [new ChangeRecord('catalog_items', $id, 1, ChangeVisibility::Financial, ChangeKind::Created)],
        );
    }
}
