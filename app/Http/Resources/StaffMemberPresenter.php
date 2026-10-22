<?php

namespace App\Http\Resources;

use App\Domain\Billing\BilledExamFeeControl;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * Représentation d'un compte du personnel pour l'API : jamais de hash, de secret MFA ni de donnée d'un
 * autre cabinet. `permissions` = permissions effectives (rôles non révoqués + dérogations non expirées).
 */
final class StaffMemberPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(User $user, bool $withPermissions = true): array
    {
        $roles = DB::table('user_roles')
            ->where('clinic_id', $user->clinic_id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderBy('role_code')
            ->pluck('role_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        $data = [
            'id' => $user->id,
            'login' => $user->login,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'active' => $user->active,
            'version' => $user->version,
            'roles' => $roles,
        ];

        if ($withPermissions) {
            $data['permissions'] = $user->permissionCodes();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function me(User $user): array
    {
        /** @var Clinic $clinic */
        $clinic = Clinic::query()->findOrFail($user->clinic_id);

        return self::present($user) + [
            'clinic' => [
                'id' => $clinic->id,
                'name' => $clinic->name,
                'timezone' => $clinic->timezone,
                'currency' => $clinic->currency,
                'production_documents_enabled' => $clinic->production_documents_enabled,
                'enabled_modules' => Modules::enabled(),
                // Contrôle du paiement de l'examen du déploiement (P03) : l'écran dit « non contrôlé » ou « à encaisser »
                // selon ce qui est réellement vérifié par le serveur, jamais l'inverse.
                'exam_fee_control' => BilledExamFeeControl::isActive() ? 'BILLED' : 'OFF',
            ],
        ];
    }
}
