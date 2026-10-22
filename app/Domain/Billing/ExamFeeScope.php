<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Guards\ActorRights;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use Illuminate\Support\Facades\DB;

/**
 * Périmètre de la permission spéciale « encaisser seulement les frais d'examen » (`exam_fee.collect`, fichier 09) :
 * elle permet de créer la ligne de prestation fixe, d'émettre la pièce et de prendre le règlement ; elle ne permet
 * ni d'ajouter un article, ni de changer un tarif, ni de consulter le chiffre d'affaires.
 *
 * L'article « frais d'examen » est désigné par sa RÉFÉRENCE de catalogue (`eeo.billing.exam_fee_item_reference`,
 * variable `EEO_EXAM_FEE_ITEM_REFERENCE`) : la référence réelle dépend du catalogue de la cliente (Q04), le code n'en
 * invente aucune. Non configurée, le profil « frais d'examen » ne peut rien facturer.
 *
 * Un compte qui détient la permission générale de l'opération (`invoice.create`, `invoice.issue`, `payment.collect`)
 * n'est PAS limité : le périmètre ne restreint que qui n'a que `exam_fee.collect`.
 */
final class ExamFeeScope
{
    public function __construct(private readonly ActorRights $rights) {}

    public static function configuredReference(): ?string
    {
        $reference = config('eeo.billing.exam_fee_item_reference');

        return is_string($reference) && trim($reference) !== '' ? trim($reference) : null;
    }

    /**
     * Identifiant de l'article « frais d'examen » du cabinet (actif ou non : une facture déjà ouverte le reste).
     */
    public static function itemId(string $clinicId): ?string
    {
        $reference = self::configuredReference();
        if ($reference === null) {
            return null;
        }
        $id = DB::table('catalog_items')->where('clinic_id', $clinicId)->where('reference', $reference)->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * Vrai si l'auteur ne détient PAS la permission générale de l'opération : il n'agit alors que grâce à
     * `exam_fee.collect` et reste dans son périmètre.
     */
    public function restricted(CommandContext $context, string $generalPermission): bool
    {
        return ! $this->rights->has($context, $generalPermission);
    }

    /**
     * Toutes les lignes de la facture sont l'article « frais d'examen » : seule une telle facture peut être émise ou
     * encaissée par le profil restreint.
     */
    public function assertExamFeeInvoice(string $clinicId, string $invoiceId): void
    {
        $itemId = self::itemId($clinicId);
        $total = DB::table('invoice_lines')->where('clinic_id', $clinicId)->where('invoice_id', $invoiceId)->count();
        $exam = $itemId === null ? 0 : DB::table('invoice_lines')
            ->where('clinic_id', $clinicId)->where('invoice_id', $invoiceId)->where('catalog_item_id', $itemId)->count();

        if ($itemId === null || $total === 0 || $total !== $exam) {
            throw BusinessError::forbidden('Votre profil ne permet de traiter que les factures de frais d\'examen.');
        }
    }
}
