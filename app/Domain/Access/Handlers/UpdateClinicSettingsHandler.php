<?php

namespace App\Domain\Access\Handlers;

use App\Application\Command;
use App\Application\CommandHandler;
use App\Application\CommandOutcome;
use App\Application\Effects\AuditEntry;
use App\Application\Locking\TransactionLocks;
use App\Domain\Access\StaffAdministration;
use App\Domain\Shared\CommandContext;
use App\Models\Clinic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Paramètres administratifs du cabinet modifiables par la gérante (corps de l'action UpdateClinicSettings,
 * exécuté dans la transaction du chemin central). Ne sont pas modifiables par l'API : le fuseau et la monnaie
 * (dates civiles et montants existants), et `production_documents_enabled` (activation des pièces officielles
 * réservée à la procédure de bascule, paramètres réels validés : Q03).
 */
final class UpdateClinicSettingsHandler implements CommandHandler
{
    public const COMMAND = 'UpdateClinicSettings';

    public const EDITABLE = ['name', 'address', 'phone', 'email', 'tax_identifier', 'professional_mentions', 'document_settings'];

    public function __construct(private readonly StaffAdministration $administration) {}

    public function permissions(): array
    {
        return [StaffAdministration::MANAGE_PERMISSION];
    }

    public function handle(Command $command, CommandContext $context, TransactionLocks $locks, ?string $idempotencyId): CommandOutcome
    {
        $this->administration->authorizeLocked($context->user ?? throw new LogicException('Compte connecté requis.'));
        $data = $command->payload;

        /** @var Clinic $clinic */
        $clinic = Clinic::query()->findOrFail($context->clinicId);

        $changes = [];
        $diff = [];
        foreach (self::EDITABLE as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            $current = $clinic->getAttribute($field);
            if ($value !== $current) {
                $changes[$field] = $field === 'document_settings' && is_array($value)
                    ? json_encode((object) $value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    : $value;
                // Modification utile (fichier 09) : valeurs avant/après, paramètres administratifs non secrets.
                $diff[$field] = ['avant' => $current, 'apres' => $value];
            }
        }

        if ($changes === []) {
            return new CommandOutcome(['clinic_id' => $clinic->id], 'clinics', $clinic->id);
        }

        $changes['updated_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        DB::table('clinics')->where('id', $clinic->id)->update($changes);

        return new CommandOutcome(
            result: ['clinic_id' => $clinic->id],
            resultType: 'clinics',
            resultId: $clinic->id,
            audit: [new AuditEntry('clinic.settings_updated', 'clinics', $clinic->id, null, $diff)],
        );
    }
}
