<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Reports\CashDayReport;
use App\Domain\Billing\Reports\ReportDay;
use App\Http\Controllers\Controller;
use App\Http\Resources\CashPresenter;
use App\Models\Clinic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Caisse du jour » (R01 minimal, EN LIGNE), module `sales` : encaissements nets par mode d'un jour civil du cabinet,
 * sessions du jour et, pour la gérante seulement (`finance.global.read`), factures émises, avoirs et reste à encaisser.
 * Une caissière (`cash.own_session` sans `finance.global.read`) ne lit que SES encaissements et SES sessions (portée
 * `OWN`, fichier 09). Lecture seule ; aucun solde n'est stocké, tout est projeté à l'instant de la lecture.
 */
final class CashDayReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }
        $clinicId = (string) $user->clinic_id;
        $timezone = (string) Clinic::query()->whereKey($clinicId)->value('timezone');
        $now = CarbonImmutable::now('UTC');
        $day = ReportDay::resolve($timezone !== '' ? $timezone : 'Africa/Douala', $now, $request->query('date'));
        $scope = $user->can('finance.global.read') ? CashDayReport::SCOPE_CLINIC : CashDayReport::SCOPE_OWN;

        return new JsonResponse([
            'data' => CashPresenter::day($day->day->toString(), CashDayReport::build($clinicId, $day, $scope, (string) $user->getKey()), $now),
        ]);
    }
}
