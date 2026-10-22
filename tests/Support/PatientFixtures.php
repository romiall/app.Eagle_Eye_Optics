<?php

namespace Tests\Support;

use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\Text;
use Illuminate\Support\Facades\DB;

/**
 * Fiches patient fictives insérées directement (sans passer par la création), pour les tests des modules qui
 * s'appuient sur un patient existant : visites, consultations.
 */
trait PatientFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedPatient(string $clinicId, string $lastName = 'Patient Fictif', ?string $firstNames = 'Test', array $overrides = []): string
    {
        $id = Ulid::generate();
        $count = DB::table('patient_records')->where('clinic_id', $clinicId)->count() + 1;
        $now = '2026-10-22 09:30:00.000000';

        DB::table('patient_records')->insert($overrides + [
            'id' => $id,
            'clinic_id' => $clinicId,
            'record_number' => sprintf('EE-2026-%04d', $count),
            'last_name' => $lastName,
            'first_names' => $firstNames,
            'sex' => 'F',
            'birth_date' => '1990-05-12',
            'birth_date_missing_reason' => null,
            'phone_primary' => '600000001',
            'phone_primary_normalized' => '600000001',
            'phone_secondary' => null,
            'phone_secondary_normalized' => null,
            'phone_missing_reason' => null,
            'name_search' => Text::searchKey($lastName.' '.($firstNames ?? '')),
            'address' => null,
            'profession' => null,
            'guardian_contact' => null,
            'legacy_record' => false,
            'first_known_visit_on' => null,
            'first_visit_certified' => false,
            'legacy_record_number' => null,
            'archived_at' => null,
            'merged_into_patient_id' => null,
            'created_by' => DB::table('users')->where('clinic_id', $clinicId)->value('id'),
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}
