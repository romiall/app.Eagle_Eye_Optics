<?php

namespace App\Domain\Scheduling\Handlers;

use App\Application\Effects\ChangeKind;
use App\Application\Effects\ChangeRecord;
use App\Application\Effects\ChangeVisibility;
use App\Domain\Scheduling\AvailabilityService;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Un rendez-vous annulé ou manqué ne clôt pas le contrôle recommandé qu'il programmait : si aucun autre rendez-vous
 * actif ne le programme, le contrôle redevient `ACTIVE` (le patient reste « à revoir »). La ligne du contrôle est déjà
 * couverte par le verrou exclusif du cabinet ; elle n'est jamais supprimée.
 */
final class FollowUpReopening
{
    /**
     * @return list<ChangeRecord>
     */
    public static function reopen(string $clinicId, stdClass $appointment, string $stamp): array
    {
        if ($appointment->follow_up_id === null) {
            return [];
        }
        $planId = (string) $appointment->follow_up_id;
        $stillScheduled = DB::table('appointments')
            ->where('clinic_id', $clinicId)
            ->where('follow_up_id', $planId)
            ->where('id', '<>', $appointment->id)
            ->whereIn('status', AvailabilityService::OCCUPYING)
            ->exists();
        if ($stillScheduled) {
            return [];
        }
        $plan = DB::table('follow_up_plans')->where('clinic_id', $clinicId)->where('id', $planId)->first();
        if ($plan === null || (string) $plan->status !== 'SCHEDULED') {
            return [];
        }
        $version = (int) $plan->version + 1;
        DB::table('follow_up_plans')->where('clinic_id', $clinicId)->where('id', $planId)->update([
            'status' => 'ACTIVE',
            'version' => $version,
            'updated_at' => $stamp,
        ]);

        return [new ChangeRecord('follow_up_plans', $planId, $version, ChangeVisibility::Clinical, ChangeKind::Updated)];
    }
}
