<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Command;
use App\Domain\Patients\MergePatients;
use App\Domain\Shared\BusinessError;
use App\Http\Controllers\CommandController;
use App\Models\User;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Fusion manuelle de deux fiches patient (L1D08), module `patients`. `{id}` est toujours la fiche ABSORBÉE.
 *
 *   GET  /patients/{id}/merge-preview?into=  aperçu : identités côte à côte, éléments rattachés, garde-fous
 *                                           (patient.merge et patient.read, middleware de la route)
 *   POST /patients/{id}/merge               fusion par la commande centrale `MergePatients` (patient.merge, mot de
 *                                           passe confirmé récemment : middleware `reauthenticated`)
 *
 * Les comptes de l'aperçu suivent les droits ACTUELS du compte : consultations et prescriptions avec clinical.read
 * (module « consultations » ouvert), pièces émises avec invoice.read (module « sales » ouvert), null sinon.
 */
final class PatientMergeController extends CommandController
{
    public function preview(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $into = $request->query('into');
        if (! is_string($into) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $into) !== 1) {
            throw BusinessError::validation(['into' => ['Choisissez la fiche à conserver.']]);
        }
        if ($into === $id) {
            throw BusinessError::validation(['into' => ['Choisissez une autre fiche que celle à absorber.']]);
        }

        $clinicId = (string) $actor->clinic_id;
        $source = $this->find($clinicId, $id);
        $survivor = $this->find($clinicId, $into);
        $now = CarbonImmutable::now('UTC');
        $clinical = $actor->can('clinical.read') && Modules::isEnabled('consultations');
        $financial = $actor->can('invoice.read') && Modules::isEnabled('sales');
        $blockers = MergePatients::blockers($clinicId, $source, $survivor, $now);

        return new JsonResponse(['data' => [
            'source' => self::identity($source),
            'survivor' => self::identity($survivor),
            'linked' => MergePatients::linked($clinicId, $id, $clinical, $financial, $now),
            'blockers' => $blockers,
            'can_merge' => $blockers === [],
        ]]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $expected = $payload['expected_version'] ?? null;
        unset($payload['expected_version']);
        if (! is_int($expected)) {
            throw BusinessError::validation(['expected_version' => ['La version attendue de la fiche à absorber est obligatoire.']]);
        }
        if (($payload['source_patient_id'] ?? null) !== $id) {
            throw BusinessError::validation(['source_patient_id' => ['L\'identifiant du corps doit être celui de l\'adresse.']]);
        }

        $result = $this->dispatchCommand($request, new Command('MergePatients', $payload, $id, $expected));

        $merge = DB::table('patient_merges')
            ->where('clinic_id', $this->actor($request)->clinic_id)
            ->where('id', (string) $result->resultId)
            ->first();
        if ($merge === null) {
            throw BusinessError::notFound();
        }
        $version = DB::table('patient_records')->where('clinic_id', $merge->clinic_id)->where('id', $merge->source_patient_id)->value('version');

        return new JsonResponse(['data' => [
            'id' => (string) $merge->id,
            'source_patient_id' => (string) $merge->source_patient_id,
            'survivor_patient_id' => (string) $merge->survivor_patient_id,
            'approved_by' => (string) $merge->approved_by,
            'merged_at' => self::instant((string) $merge->merged_at),
            'reason' => (string) $merge->reason,
            'source_version' => (int) $version,
        ]], 201);
    }

    /**
     * Identité comparée (`PatientMergeIdentity`) : administrative seulement.
     *
     * @return array<string, mixed>
     */
    private static function identity(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'record_number' => (string) $row->record_number,
            'last_name' => $row->last_name,
            'first_names' => $row->first_names,
            'sex' => $row->sex,
            'birth_date' => $row->birth_date,
            'phone_primary' => $row->phone_primary,
            'archived' => $row->archived_at !== null,
            'merged_into_patient_id' => $row->merged_into_patient_id,
            'version' => (int) $row->version,
            'legacy_record_number' => $row->legacy_record_number,
            'created_at' => self::instant((string) $row->created_at),
        ];
    }

    private function find(string $clinicId, string $id): stdClass
    {
        $row = DB::table('patient_records')->where('clinic_id', $clinicId)->where('id', $id)->first();
        if ($row === null) {
            throw BusinessError::notFound();
        }

        return $row;
    }

    private static function instant(string $stored): string
    {
        return CarbonImmutable::parse($stored, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
