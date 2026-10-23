<?php

namespace App\Domain\Cash\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Application\Locking\TransactionLocks;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\CommandSource;
use App\Domain\Shared\RuleViolation;
use App\Domain\Shared\ValueObjects\Text;
use Illuminate\Support\Facades\DB;

/**
 * Catégorie de dépenses (L1D05 ; table `expense_categories` : « Catégories de dépenses validées, pas un plan comptable
 * réglementaire ») créée ou modifiée par la gérante (`cash.manage`), en ligne. Le code est unique dans le cabinet et ne
 * change plus après la création (les dépenses passées gardent leur catégorie) ; une catégorie se désactive, jamais ne
 * se supprime. Aucune catégorie n'est préconfigurée : la liste vient du cabinet (rien n'est inventé).
 */
final class SaveExpenseCategoryHandler implements CommandHandler
{
    public const COMMAND = 'SaveExpenseCategory';

    public function permissions(): array
    {
        return ['cash.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        if ($context->source !== CommandSource::Direct) {
            throw BusinessError::forbidden('Les catégories de dépenses se gèrent en ligne.');
        }

        $payload = $command->payload;
        $categoryId = (string) $payload['category_id'];
        try {
            $name = Text::optional(is_string($payload['name'] ?? null) ? $payload['name'] : null, 180, 'name');
            $code = Text::optional(is_string($payload['code'] ?? null) ? $payload['code'] : null, 40, 'code');
        } catch (RuleViolation $violation) {
            throw BusinessError::validation([$violation->field => [$violation->getMessage()]]);
        }
        if ($name === null || mb_strlen($name) < 2) {
            throw BusinessError::validation(['name' => ['Nom de la catégorie obligatoire (2 caractères au moins).']]);
        }
        $active = (bool) ($payload['active'] ?? true);
        $stamp = $context->now->utc()->format('Y-m-d H:i:s.u');

        // Verrou du cabinet : deux créations simultanées du même code se sérialisent.
        $locks->clinic();
        $existing = DB::table('expense_categories')->where('clinic_id', $context->clinicId)->where('id', $categoryId)->lockForUpdate()->first();
        if ($existing === null) {
            if ($code === null || preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $code) !== 1) {
                throw BusinessError::validation(['code' => ['Code en majuscules (lettres, chiffres, _), 2 à 40 caractères.']]);
            }
            if (DB::table('expense_categories')->where('clinic_id', $context->clinicId)->where('code', $code)->exists()) {
                throw BusinessError::validation(['code' => ['Ce code de catégorie existe déjà.']]);
            }
            if (DB::table('expense_categories')->where('id', $categoryId)->exists()) {
                throw BusinessError::validation(['category_id' => ['Cet identifiant est déjà utilisé.']]);
            }
            DB::table('expense_categories')->insert([
                'id' => $categoryId,
                'clinic_id' => $context->clinicId,
                'code' => $code,
                'name' => $name,
                'active' => $active,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            return new CommandOutcome(
                result: ['category_id' => $categoryId],
                resultType: 'expense_categories',
                resultId: $categoryId,
                audit: [new AuditEntry('expense_category.created', 'expense_categories', $categoryId, null, ['code' => $code, 'name' => $name, 'active' => $active])],
                changes: [new ChangeRecord('expense_categories', $categoryId, 1, ChangeVisibility::Financial, ChangeKind::Created)],
            );
        }

        if ($code !== null && $code !== (string) $existing->code) {
            throw BusinessError::validation(['code' => ['Le code d\'une catégorie ne change plus après sa création.']]);
        }
        $diff = [];
        if ($name !== (string) $existing->name) {
            $diff['name'] = ['avant' => (string) $existing->name, 'apres' => $name];
        }
        if ($active !== (bool) $existing->active) {
            $diff['active'] = ['avant' => (bool) $existing->active, 'apres' => $active];
        }
        if ($diff === []) {
            return new CommandOutcome(['category_id' => $categoryId], 'expense_categories', $categoryId);
        }
        DB::table('expense_categories')->where('clinic_id', $context->clinicId)->where('id', $categoryId)
            ->update(['name' => $name, 'active' => $active, 'updated_at' => $stamp]);

        return new CommandOutcome(
            result: ['category_id' => $categoryId],
            resultType: 'expense_categories',
            resultId: $categoryId,
            audit: [new AuditEntry('expense_category.updated', 'expense_categories', $categoryId, null, $diff)],
            changes: [new ChangeRecord('expense_categories', $categoryId, 2, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
