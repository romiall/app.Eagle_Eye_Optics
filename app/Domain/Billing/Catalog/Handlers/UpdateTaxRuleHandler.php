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
use App\Domain\Billing\Catalog\Rules\TaxRuleInput;
use App\Domain\Billing\Rules\FieldErrors;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use Illuminate\Support\Facades\DB;

/**
 * Modification ou validation d'une règle fiscale (B01) avec sa version attendue (`tax_rules.version`). Un changement
 * de taux retire la validation (voir `TaxRuleInput`) ; il ne touche jamais une pièce déjà émise (le snapshot fiscal
 * d'une facture est figé). Une demande sans effet n'incrémente pas la version et n'écrit rien.
 */
final class UpdateTaxRuleHandler implements CommandHandler
{
    public const COMMAND = 'UpdateTaxRule';

    public function permissions(): array
    {
        return ['catalog.manage'];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $id = (string) $command->entityId;
        $current = DB::table('tax_rules')->where('clinic_id', $context->clinicId)->where('id', $id)->first();
        if ($current === null) {
            throw BusinessError::notFound('Règle fiscale introuvable dans ce cabinet.');
        }
        if ($command->expectedVersion === null) {
            throw BusinessError::validation(['expected_version' => ['La version attendue est obligatoire pour modifier une règle fiscale.']]);
        }
        if ((int) $current->version !== $command->expectedVersion) {
            throw BillingErrors::versionConflict((int) $current->version);
        }

        $errors = new FieldErrors;
        $columns = TaxRuleInput::columns($command->payload, $current, $errors);
        if ($columns !== null && DB::table('tax_rules')->where('clinic_id', $context->clinicId)->where('code', $columns['code'])->where('id', '<>', $id)->exists()) {
            $errors->add('code', 'Ce code de règle fiscale existe déjà.');
        }
        $errors->throwIfAny();
        if ($columns === null) {
            throw new \LogicException('Colonnes de la règle absentes sans erreur de validation.');
        }

        $before = [
            'code' => (string) $current->code,
            'label' => (string) $current->label,
            'rate_percent' => $current->rate_percent === null ? null : (string) $current->rate_percent,
            'active' => (bool) $current->active,
            'legal_note' => $current->legal_note === null ? null : (string) $current->legal_note,
        ];
        $after = [
            'code' => $columns['code'],
            'label' => $columns['label'],
            'rate_percent' => $columns['rate_percent'],
            'active' => $columns['active'],
            'legal_note' => $columns['legal_note'],
        ];
        $changed = [];
        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changed[$field] = ['before' => $before[$field], 'after' => $value];
            }
        }
        $validationLost = $current->validated_at !== null && ! $columns['keep_validation'] && ! $columns['validated'];
        if ($columns['validated']) {
            $changed['validated'] = true;
        }
        if ($validationLost) {
            $changed['validation_removed'] = true;
        }

        $version = (int) $current->version;
        if ($changed === []) {
            return new CommandOutcome(['tax_rule_id' => $id, 'version' => $version], 'tax_rules', $id);
        }

        $version++;
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');
        $update = $after + ['version' => $version, 'updated_at' => $now];
        if ($columns['validated']) {
            $update['validated_by'] = $context->actorId;
            $update['validated_at'] = $now;
        } elseif (! $columns['keep_validation']) {
            $update['validated_by'] = null;
            $update['validated_at'] = null;
        }
        DB::table('tax_rules')->where('clinic_id', $context->clinicId)->where('id', $id)->update($update);

        return new CommandOutcome(
            result: ['tax_rule_id' => $id, 'version' => $version],
            resultType: 'tax_rules',
            resultId: $id,
            audit: [new AuditEntry('tax_rule.updated', 'tax_rules', $id, null, ['changes' => $changed])],
            changes: [new ChangeRecord('tax_rules', $id, $version, ChangeVisibility::Financial, ChangeKind::Updated)],
        );
    }
}
