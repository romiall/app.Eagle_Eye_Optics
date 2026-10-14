<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuditRecorder;
use App\Domain\Access\StaffAdministration;
use App\Domain\Shared\BusinessError;
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

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly StaffAdministration $administration,
    ) {}

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

        // document_settings est un objet JSON (CHECK ck_clinics_04) : une liste est refusée, un objet vide
        // reste un objet ({} et non []).
        if (isset($data['document_settings']) && is_array($data['document_settings']) && $data['document_settings'] !== [] && array_is_list($data['document_settings'])) {
            throw BusinessError::validation(['document_settings' => ['Les paramètres des documents sont un objet de clés nommées, pas une liste.']]);
        }

        return DB::transaction(function () use ($actor, $data): Clinic {
            $actor = $this->administration->lockAndAuthorize($actor);
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
                    $changes[$field] = $field === 'document_settings' && is_array($value)
                        ? json_encode((object) $value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                        : $value;
                    // Modification utile (fichier 09) : valeurs avant/après, paramètres administratifs non secrets.
                    $diff[$field] = ['avant' => $current, 'apres' => $value];
                }
            }

            if ($changes !== []) {
                $changes['updated_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
                DB::table('clinics')->where('id', $clinic->id)->update($changes);
                $this->audit->record($clinic->id, $actor, 'clinic.settings_updated', 'clinics', $clinic->id, null, $diff);
            }

            /** @var Clinic */
            return Clinic::query()->findOrFail($actor->clinic_id);
        }, 3);
    }
}
