<?php

namespace App\Domain\Access\Approvals;

use App\Application\Locking\TransactionLocks;
use App\Domain\Billing\BillingErrors;
use App\Domain\Billing\Guards\ActorRights;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\DirectApproval;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Consommation des approbations EXACTES dans la transaction de l'opération approuvée (B06 : « Consommer une seule fois
 * après vérification payload » ; fichier 03 : « Approbation consommable une fois ; si le contenu change, nouvelle
 * demande »).
 *
 * Une approbation n'est valable que si TOUT concorde : cabinet, statut `APPROVED` décidé, non expirée à l'instant du
 * serveur, jamais consommée, même code d'action, même type et identifiant de cible, même empreinte du contenu exact
 * (`ApprovalSubject::hash`). Elle est alors passée `CONSUMED` dans la même transaction que l'opération : un refus plus
 * loin annule aussi la consommation (l'approbation reste utilisable), un succès la brûle définitivement. Deux
 * opérations concurrentes ne peuvent pas la consommer deux fois : le chemin central sérialise les mutations du cabinet,
 * et la ligne est en plus verrouillée au rang « approbation » (90, le dernier de l'ordre du contrat 23).
 *
 * Exécution directe (fichier 03 : « Elle peut exécuter directement avec la même traçabilité ») : quand l'identifiant
 * d'approbation fourni par une commande n'existe pas et que l'auteur est la gérante (`approval.decide` ET la permission
 * de l'action), l'autorisation est écrite déjà décidée et consommée sous CET identifiant (`DirectApproval`), liée à la
 * même empreinte. Tout autre compte reçoit 422 `APPROVAL_REQUIRED_OR_EXPIRED`.
 *
 * Le statut `EXPIRED` n'est jamais écrit : l'expiration se juge à l'instant (`effectiveStatus`). Aucune tâche planifiée
 * n'est nécessaire et une approbation expirée ne redevient jamais valable.
 */
final class ExactApprovals
{
    /** Durée de validité d'une approbation accordée, à défaut de configuration (proposition à confirmer : Q07). */
    public const DEFAULT_TTL_MINUTES = 60;

    public const REQUESTED = 'REQUESTED';

    public const APPROVED = 'APPROVED';

    public const REFUSED = 'REFUSED';

    public const EXPIRED = 'EXPIRED';

    public const CONSUMED = 'CONSUMED';

    public function __construct(private readonly ActorRights $rights) {}

    /** Durée de validité d'une approbation accordée (`eeo.approvals.ttl_minutes`), bornée de 1 minute à 24 heures. */
    public static function ttlMinutes(): int
    {
        $configured = config('eeo.approvals.ttl_minutes', self::DEFAULT_TTL_MINUTES);
        $minutes = is_numeric($configured) ? (int) $configured : self::DEFAULT_TTL_MINUTES;

        return max(1, min(24 * 60, $minutes));
    }

    /**
     * Statut à l'instant donné : une demande ou un accord dont l'échéance est passée est `EXPIRED`.
     */
    public static function effectiveStatus(stdClass $approval, CarbonImmutable $now): string
    {
        $status = (string) $approval->status;
        if (in_array($status, [self::REQUESTED, self::APPROVED], true) && self::isPast($approval->expires_at, $now)) {
            return self::EXPIRED;
        }

        return $status;
    }

    /**
     * Vrai si l'approbation autorise EXACTEMENT ce contenu à cet instant.
     */
    public static function authorizes(stdClass $approval, ApprovalSubject $subject, CarbonImmutable $now): bool
    {
        return (string) $approval->status === self::APPROVED
            && $approval->decided_by !== null
            && $approval->decided_at !== null
            && $approval->consumed_at === null
            && ! self::isPast($approval->expires_at, $now)
            && (string) $approval->action_code === $subject->actionCode()
            && (string) $approval->target_type === $subject->targetType()
            && (string) $approval->target_id === $subject->targetId
            && hash_equals((string) $approval->payload_hash, $subject->hash());
    }

    /**
     * Autorisation d'une commande qui porte l'identifiant de son approbation (`approval_id` : contre-écriture, avoir,
     * remboursement) : approbation exacte consommée, ou exécution directe de la gérante. À appeler APRÈS tous les autres
     * verrous de la commande (le rang « approbation » est le dernier).
     *
     * @return array{id: string, mode: string} mode `APPROVED` (demande accordée) ou `DIRECT` (gérante)
     */
    public function authorize(TransactionLocks $locks, CommandContext $context, string $approvalId, ApprovalSubject $subject, string $reason, string $field = 'approval_id'): array
    {
        $approval = $locks->approval($approvalId);
        if ($approval !== null) {
            $this->assertAndConsume($context->clinicId, $approval, $subject, $context->now, $field, 'Cette approbation n\'autorise pas exactement cette opération (contenu différent, expirée, refusée, déjà utilisée ou non décidée) : demandez-en une nouvelle.');

            return ['id' => $approvalId, 'mode' => self::APPROVED];
        }

        // Clé primaire GLOBALE : un identifiant pris dans un autre cabinet est refusé sans le dire (aucun oracle).
        if (DB::table('approvals')->where('id', $approvalId)->exists()) {
            throw BusinessError::validation([$field => ['Cet identifiant d\'approbation est déjà utilisé.']]);
        }
        if (! $this->rights->manager($context) || ! $this->rights->has($context, $subject->actionCode())) {
            throw BillingErrors::approvalRequired(
                [$field => ['Aucune approbation de la gérante ne porte cet identifiant.']],
                'Cette opération exige l\'approbation exacte de la gérante : demandez-la, puis reprenez avec son identifiant.',
            );
        }

        DirectApproval::record($context, $subject->actionCode(), $subject->targetType(), $subject->targetId, $subject->hash(), $reason, $approvalId);

        return ['id' => $approvalId, 'mode' => 'DIRECT'];
    }

    /**
     * Consommation d'une approbation DÉSIGNÉE (dérogation fournie au démarrage d'un examen). Refus 422 sur `$field`.
     */
    public function consume(TransactionLocks $locks, string $clinicId, CarbonImmutable $now, string $approvalId, ApprovalSubject $subject, string $field, string $message): void
    {
        $approval = $locks->approval($approvalId);
        if ($approval === null) {
            throw BillingErrors::approvalRequired([$field => ['Approbation introuvable, expirée, déjà utilisée ou ne concernant pas cette opération.']], $message);
        }
        $this->assertAndConsume($clinicId, $approval, $subject, $now, $field, $message);
    }

    /**
     * Consommation IMPLICITE : l'approbation accordée de même contenu exact (même action, même cible, même empreinte),
     * la plus ancienne accordée d'abord. `$requestedBy` restreint à celles demandées par ce compte (brouillon de facture :
     * l'approbation de la caissière ne sert qu'à elle). Rend l'identifiant consommé, ou null s'il n'y en a aucune.
     */
    public function consumeMatching(TransactionLocks $locks, string $clinicId, CarbonImmutable $now, ApprovalSubject $subject, ?string $requestedBy = null): ?string
    {
        $candidate = self::findValid($clinicId, $subject, $now, $requestedBy);
        if ($candidate === null) {
            return null;
        }
        $approval = $locks->approval((string) $candidate->id);
        if ($approval === null || ! self::authorizes($approval, $subject, $now)) {
            return null;
        }
        self::markConsumed($clinicId, (string) $approval->id, $now);

        return (string) $approval->id;
    }

    /**
     * Lecture sans verrou : approbation accordée et encore valable pour ce contenu exact, ou null.
     */
    public static function findValid(string $clinicId, ApprovalSubject $subject, CarbonImmutable $now, ?string $requestedBy = null): ?stdClass
    {
        foreach (self::sameContent($clinicId, $subject, $requestedBy)->where('status', self::APPROVED)->whereNull('consumed_at')->get() as $row) {
            if (self::authorizes($row, $subject, $now)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Lecture sans verrou : demande en attente (non décidée, non expirée) pour ce contenu exact, ou null.
     */
    public static function findPending(string $clinicId, ApprovalSubject $subject, CarbonImmutable $now): ?stdClass
    {
        foreach (self::sameContent($clinicId, $subject, null)->where('status', self::REQUESTED)->get() as $row) {
            if (self::effectiveStatus($row, $now) === self::REQUESTED) {
                return $row;
            }
        }

        return null;
    }

    private function assertAndConsume(string $clinicId, stdClass $approval, ApprovalSubject $subject, CarbonImmutable $now, string $field, string $message): void
    {
        if (! self::authorizes($approval, $subject, $now)) {
            throw BillingErrors::approvalRequired([$field => ['Approbation introuvable, expirée, déjà utilisée ou ne concernant pas cette opération.']], $message);
        }
        self::markConsumed($clinicId, (string) $approval->id, $now);
    }

    private static function markConsumed(string $clinicId, string $approvalId, CarbonImmutable $now): void
    {
        $stored = $now->utc()->format('Y-m-d H:i:s.u');
        DB::table('approvals')->where('clinic_id', $clinicId)->where('id', $approvalId)->update([
            'status' => self::CONSUMED,
            'consumed_at' => $stored,
            'updated_at' => $stored,
        ]);
    }

    /**
     * @return Builder
     */
    private static function sameContent(string $clinicId, ApprovalSubject $subject, ?string $requestedBy)
    {
        $query = DB::table('approvals')
            ->where('clinic_id', $clinicId)
            ->where('action_code', $subject->actionCode())
            ->where('target_type', $subject->targetType())
            ->where('target_id', $subject->targetId)
            ->where('payload_hash', $subject->hash())
            ->orderBy('decided_at')
            ->orderBy('id');
        if ($requestedBy !== null) {
            $query->where('requested_by', $requestedBy);
        }

        return $query;
    }

    private static function isPast(mixed $expiresAt, CarbonImmutable $now): bool
    {
        return $expiresAt !== null && ! CarbonImmutable::parse((string) $expiresAt, 'UTC')->greaterThan($now);
    }
}
