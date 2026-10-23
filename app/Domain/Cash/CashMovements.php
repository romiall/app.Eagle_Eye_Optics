<?php

namespace App\Domain\Cash;

use App\Domain\Shared\RuleViolation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Mouvements d'espèces hors vente d'une session de caisse (L1D05 ; fichier 03 : « Les sorties de caisse réelles
 * (remboursement, dépôt, retrait, dépense) sont enregistrées et autorisées […] Aucune sortie d'espèces ne doit être
 * masquée par une remise commerciale fictive » ; EE030 ; AT25, AT30).
 *
 * Natures (CHECK de `cash_movements`) :
 *   DEPOSIT              entrée d'espèces dans le tiroir (fonds de caisse complété)           montant > 0
 *   WITHDRAWAL           sortie réelle (versement en banque, mise en coffre)                  montant < 0
 *   EXPENSE              dépense payée en espèces, avec sa catégorie                          montant < 0
 *   OPENING_CORRECTION   correction d'une erreur de saisie de l'ouverture (correction          ≠ 0, signe libre
 *                        historique, PAS une sortie réelle)
 *
 * Un mouvement n'est jamais un paiement client : il n'a ni facture, ni reçu, ni chiffre d'affaires. Il entre dans les
 * espèces théoriques de la session (`CashClosing::theoretical`), est figé avec elle à la clôture et ne se modifie ni ne
 * se supprime (triggers) ; une session clôturée n'en reçoit plus (refus 409, et trigger `tr_cash_movements_insert_session`).
 * Chaque mouvement porte son approbation exacte (`CASH_MOVEMENT`) ou l'exécution directe de la gérante.
 */
final class CashMovements
{
    public const KINDS = ['DEPOSIT', 'WITHDRAWAL', 'EXPENSE', 'OPENING_CORRECTION'];

    public const REASON_MIN = 3;

    public const REASON_MAX = 500;

    public const REFERENCE_MAX = 180;

    /** Plafond d'un mouvement (même borne que les montants d'une session). */
    public const MAX_AMOUNT = 1_000_000_000_000;

    /**
     * Forme d'un mouvement : nature connue, montant non nul dans la borne, signe imposé par la nature, catégorie
     * présente pour une dépense et seulement pour elle.
     *
     * @throws RuleViolation
     */
    public static function assertShape(string $kind, int $signedAmount, ?string $categoryId, string $prefix = ''): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw RuleViolation::invalid($prefix.'movement_kind', 'cash.movement_kind', 'Nature de mouvement inconnue.');
        }
        if ($signedAmount === 0 || abs($signedAmount) > self::MAX_AMOUNT) {
            throw RuleViolation::invalid($prefix.'signed_amount', 'cash.movement_amount', 'Montant non nul attendu (en FCFA, sans décimale).');
        }
        if ($kind === 'DEPOSIT' && $signedAmount < 0) {
            throw RuleViolation::invalid($prefix.'signed_amount', 'cash.movement_sign', 'Un dépôt est une entrée : montant positif.');
        }
        if (in_array($kind, ['WITHDRAWAL', 'EXPENSE'], true) && $signedAmount > 0) {
            throw RuleViolation::invalid($prefix.'signed_amount', 'cash.movement_sign', 'Une sortie ou une dépense est négative.');
        }
        if ($kind === 'EXPENSE' && $categoryId === null) {
            throw RuleViolation::invalid($prefix.'expense_category_id', 'cash.expense_category_required', 'Choisissez la catégorie de la dépense.');
        }
        if ($kind !== 'EXPENSE' && $categoryId !== null) {
            throw RuleViolation::invalid($prefix.'expense_category_id', 'cash.expense_category_forbidden', 'Seule une dépense a une catégorie.');
        }
    }

    public static function activeCategory(string $clinicId, string $categoryId): bool
    {
        return DB::table('expense_categories')->where('clinic_id', $clinicId)->where('id', $categoryId)->where('active', 1)->exists();
    }

    /**
     * Catégories de dépenses du cabinet, actives d'abord puis par nom.
     *
     * @return list<stdClass>
     */
    public static function categories(string $clinicId, bool $activeOnly = false): array
    {
        $query = DB::table('expense_categories')->where('clinic_id', $clinicId);
        if ($activeOnly) {
            $query->where('active', 1);
        }

        return array_values($query->orderByDesc('active')->orderBy('name')->orderBy('id')->get()->all());
    }

    /**
     * Mouvements d'une session, du plus ancien au plus récent, avec la catégorie, l'auteur (nom actuel) et la forme de
     * l'autorisation (APPROVED : demande accordée ; DIRECT : exécution de la gérante).
     *
     * @return list<stdClass>
     */
    public static function forSession(string $clinicId, string $sessionId): array
    {
        return array_values(DB::table('cash_movements as m')
            ->leftJoin('expense_categories as c', static function (JoinClause $join): void {
                $join->on('c.clinic_id', '=', 'm.clinic_id')->on('c.id', '=', 'm.expense_category_id');
            })
            ->leftJoin('users as u', static function (JoinClause $join): void {
                $join->on('u.clinic_id', '=', 'm.clinic_id')->on('u.id', '=', 'm.created_by');
            })
            ->leftJoin('approvals as a', static function (JoinClause $join): void {
                $join->on('a.clinic_id', '=', 'm.clinic_id')->on('a.id', '=', 'm.approval_id');
            })
            ->where('m.clinic_id', $clinicId)
            ->where('m.cash_session_id', $sessionId)
            ->orderBy('m.occurred_at')
            ->orderBy('m.id')
            ->get([
                'm.id', 'm.cash_session_id', 'm.kind', 'm.signed_amount', 'm.reason', 'm.reference', 'm.occurred_at',
                'm.approval_id', 'm.expense_category_id', 'm.created_by', 'c.code as category_code', 'c.name as category_name',
                'u.display_name as created_by_name', 'a.requested_by as approval_requested_by', 'a.decided_by as approval_decided_by',
            ])
            ->all());
    }

    /**
     * Revue de l'écart d'une session clôturée par la gérante (dernier événement `cash.variance_reviewed`), ou null.
     */
    public static function varianceReview(string $clinicId, string $sessionId): ?stdClass
    {
        return DB::table('audit_events')
            ->where('clinic_id', $clinicId)
            ->where('action', 'cash.variance_reviewed')
            ->where('target_type', 'cash_sessions')
            ->where('target_id', $sessionId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first(['actor_id', 'actor_name_snapshot', 'recorded_at', 'reason', 'sanitized_diff']);
    }
}
