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
use App\Domain\Billing\Catalog\Rules\TaxRuleInput;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use Illuminate\Support\Facades\DB;

/**
 * Création d'une règle fiscale (B01, EE020) par la gérante réauthentifiée (la route l'exige). Aucune taxe n'est
 * préconfigurée : la règle, son taux et sa validation viennent de la gérante, le régime réel du cabinet n'étant pas
 * connu (Q03). `validate = true` enregistre la validation par l'auteur à cet instant ; une règle active exige taux et
 * validation (CHECK `ck_tax_rules_02`). Le code est unique par cabinet.
 */
final class CreateTaxRuleHandler implements CommandHandler
{
    public const COMMAND = 'CreateTaxRule';

    public function permissions(): array
    {
        return ['catalog.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $errors = new FieldErrors;
        $columns = TaxRuleInput::columns($command->payload, null, $errors);
        if ($columns !== null && DB::table('tax_rules')->where('clinic_id', $context->clinicId)->where('code', $columns['code'])->exists()) {
            $errors->add('code', 'Ce code de règle fiscale existe déjà.');
        }
        $errors->throwIfAny();
        if ($columns === null) {
            throw new \LogicException('Colonnes de la règle absentes sans erreur de validation.');
        }

        $id = Ulid::generate();
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        DB::table('tax_rules')->insert([
            'id' => $id,
            'clinic_id' => $context->clinicId,
            'code' => $columns['code'],
            'label' => $columns['label'],
            'rate_percent' => $columns['rate_percent'],
            'active' => $columns['active'],
            'validated_by' => $columns['validated'] ? $context->actorId : null,
            'validated_at' => $columns['validated'] ? $now : null,
            'legal_note' => $columns['legal_note'],
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new CommandOutcome(
            result: ['tax_rule_id' => $id, 'version' => 1],
            resultType: 'tax_rules',
            resultId: $id,
            audit: [new AuditEntry('tax_rule.created', 'tax_rules', $id, null, [
                'code' => $columns['code'],
                'rate_percent' => $columns['rate_percent'],
                'active' => $columns['active'],
                'validated' => $columns['validated'],
            ])],
            changes: [new ChangeRecord('tax_rules', $id, 1, ChangeVisibility::Financial, ChangeKind::Created)],
        );
    }
}
