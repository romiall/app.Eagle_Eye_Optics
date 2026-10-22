<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\ExamFeeScope;
use App\Domain\Billing\Rules\DiscountPolicy;
use App\Domain\Billing\Rules\PaymentRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Règles de vente qui s'appliquent au compte connecté (module `sales`), pour que l'écran dise la limite AVANT l'envoi :
 * autorité de prix (`catalog.manage`), plafond de remise effectif de CE compte (100 % avec l'autorité de prix, sinon
 * `EEO_DISCOUNT_CAP_PERCENT`, 0 par défaut), modes de règlement admis et ceux dont la référence est obligatoire
 * (`EEO_PAYMENT_REFERENCE_REQUIRED`), et la référence de l'article « frais d'examen » s'il est configuré. Le serveur
 * reste seul juge à chaque commande : ces valeurs servent à l'affichage, jamais à décider.
 */
final class SalesSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }
        $authority = $user->can('catalog.manage');
        $cap = config('eeo.billing.discount_cap_percent');
        $required = config('eeo.billing.payment_reference_required');

        return new JsonResponse(['data' => [
            'price_authority' => $authority,
            'discount_cap_percent' => DiscountPolicy::capFor($authority, is_scalar($cap) ? (string) $cap : null)->canonical(),
            'payment_methods' => PaymentRules::METHODS,
            'payment_reference_required' => is_array($required)
                ? array_values(array_filter($required, static fn (mixed $method): bool => is_string($method) && in_array($method, PaymentRules::METHODS, true)))
                : PaymentRules::DEFAULT_REFERENCE_REQUIRED,
            'exam_fee_item_reference' => ExamFeeScope::configuredReference(),
        ]]);
    }
}
