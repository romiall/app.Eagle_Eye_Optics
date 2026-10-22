<?php

namespace App\Domain\Shared;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Autorisation que la GÉRANTE se donne en exécutant directement une opération sensible (fichier 03 : « Elle peut
 * exécuter directement avec la même traçabilité ») : la ligne `approvals` est écrite déjà décidée et consommée, par
 * la même personne, dans la transaction de l'opération qui la référence (par exemple `stock_movements.approval_id`,
 * obligatoire pour un stock initial, une casse ou un ajustement).
 *
 * Ce n'est PAS le flux de demande et de décision d'une approbation à distance (B06 : demande par un autre compte,
 * décision après réauthentification, consommation unique, expiration). L'appelant vérifie que l'auteur détient
 * l'autorité de décider (`approval.decide`) AVANT d'appeler cette fabrique.
 *
 * `payload_hash` lie l'autorisation au contenu exact de l'opération (empreinte canonique de ce qui est exécuté).
 *
 * `$id` : identifiant fourni par la commande (avoir, remboursement, contre-écriture : `approval_id` de la charge, connu
 * avant le commit) ; à défaut, un nouvel identifiant. Le flux de demande et de décision (B06) est
 * `App\Domain\Access\Approvals\ExactApprovals`, qui appelle cette fabrique quand la gérante exécute elle-même.
 */
final class DirectApproval
{
    public static function record(CommandContext $context, string $actionCode, string $targetType, string $targetId, string $payloadHash, string $reason, ?string $id = null): string
    {
        if ($context->actorId === null) {
            throw new LogicException('Une autorisation directe a toujours un auteur identifié.');
        }

        $id ??= Ulid::generate();
        $now = $context->now->utc()->format('Y-m-d H:i:s.u');

        DB::table('approvals')->insert([
            'id' => $id,
            'clinic_id' => $context->clinicId,
            'action_code' => $actionCode,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload_hash' => $payloadHash,
            'requested_by' => $context->actorId,
            'reason' => $reason,
            'status' => 'CONSUMED',
            'decided_by' => $context->actorId,
            'decided_at' => $now,
            'expires_at' => null,
            'consumed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}
