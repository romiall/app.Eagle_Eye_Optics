<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\StaffRoles;
use App\Models\Clinic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Paramètres administratifs du cabinet modifiables par la gérante. Ne sont pas modifiables par l'API :
 * le fuseau et la monnaie (dates civiles et montants existants), et `production_documents_enabled`
 * (activation des pièces officielles réservée à la procédure de bascule, paramètres réels validés : Q03).
 */
final class UpdateClinicSettings
{
    private const EDITABLE = ['name', 'address', 'phone', 'email', 'tax_identifier', 'professional_mentions', 'document_settings'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, array $input): Clinic
    {
        $data = Validator::make($input, [
            'name' => ['sometimes', 'string', 'min:2', 'max:180'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:100'],
            'professional_mentions' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'document_settings' => ['sometimes', 'nullable', 'array'],
            'timezone' => ['prohibited'],
            'currency' => ['prohibited'],
            'production_documents_enabled' => ['prohibited'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): Clinic {
            StaffRoles::lockClinic($actor->clinic_id);
            /** @var Clinic $clinic */
            $clinic = Clinic::query()->findOrFail($actor->clinic_id);

            $changes = [];
            $diff = [];
            foreach (self::EDITABLE as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $value = $data[$field];
                $current = $clinic->getAttribute($field);
                if ($value !== $current) {
                    $changes[$field] = $field === 'document_settings' && $value !== null
                        ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                        : $value;
                    $diff[] = $field;
                }
            }

            if ($changes !== []) {
                $changes['updated_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
                DB::table('clinics')->where('id', $clinic->id)->update($changes);
                $this->audit->record($clinic->id, $actor, 'clinic.settings_updated', 'clinics', $clinic->id, null, ['champs' => $diff]);
            }

            /** @var Clinic */
            return Clinic::query()->findOrFail($actor->clinic_id);
        });
    }
}
