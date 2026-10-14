<?php

namespace App\Domain\Access;

use Illuminate\Support\Facades\DB;

/**
 * Sessions serveur du personnel (driver « database », table fw_sessions) : une désactivation ou une
 * réinitialisation de mot de passe ferme toutes les sessions du compte, sans attendre leur expiration.
 * Sans effet avec un autre driver (tests en mémoire) : la vérification « active » de chaque requête
 * (EnsureUserIsActive) reste la garantie principale.
 */
final class StaffSessions
{
    public function closeAll(string $userId): int
    {
        return $this->closeAllExcept($userId, null);
    }

    public function closeAllExcept(string $userId, ?string $keptSessionId): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $query = DB::table((string) config('session.table', 'fw_sessions'))->where('user_id', $userId);
        if ($keptSessionId !== null) {
            $query->where('id', '<>', $keptSessionId);
        }

        return $query->delete();
    }
}
