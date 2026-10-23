<?php

namespace App\Domain\Shared;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Contexte d'exécution d'une commande (contrat 23, « Chemin transactionnel central ») : cabinet, auteur,
 * origine (DIRECT, SYNC, JOB), corrélation, poste et grant éventuels, temps de confiance du serveur.
 *
 * Il distingue deux autorités : la Policy ACTUELLE d'une action directe (droits effectifs relus en base) et
 * le contexte d'autorisation HISTORIQUE d'une commande locale (grant signé et horloge du poste). Une
 * révocation d'aujourd'hui ne détruit pas une pièce valable hier ; les cas ambigus passent en revue.
 *
 * L'auteur vient toujours du compte ou du grant, jamais d'un champ libre de la charge (fichier 08).
 */
final readonly class CommandContext
{
    private function __construct(
        public string $clinicId,
        public CommandSource $source,
        public ?string $actorId,
        public string $actorName,
        public string $correlationId,
        public string $idempotencyNamespace,
        public CarbonImmutable $now,
        public ?User $user = null,
        public ?string $deviceId = null,
        public ?GrantSnapshot $grant = null,
        public ?CarbonImmutable $occurredAtLocal = null,
        public ?CarbonImmutable $lastServerTime = null,
        public ?CarbonImmutable $receivedAt = null,
        public ?SyncReferences $syncReferences = null,
    ) {}

    /**
     * Requête d'un compte connecté. Les clés d'idempotence sont propres à chaque compte.
     */
    public static function direct(User $user, string $correlationId, ?CarbonImmutable $now = null): self
    {
        return new self(
            clinicId: (string) $user->clinic_id,
            source: CommandSource::Direct,
            actorId: (string) $user->getKey(),
            actorName: (string) $user->display_name,
            correlationId: $correlationId,
            idempotencyNamespace: 'user:'.$user->getKey(),
            now: $now ?? CarbonImmutable::now('UTC'),
            user: $user,
        );
    }

    /**
     * Commande d'un poste, déjà vérifiée (signature, hash, appareil enrôlé) par la couche de synchronisation.
     * `occurredAtLocal` et `lastServerTime` viennent du cœur signé ; `now` reste l'heure du serveur.
     *
     * `receivedAt` est l'heure de la PREMIÈRE réception de cette commande par le serveur, conservée avec elle
     * par l'inbox de synchronisation (O05) : le jugement d'horloge s'y réfère, pas à `now`, pour qu'un simple
     * renvoi plus tard ne transforme jamais une revue en acceptation.
     *
     * Les clés d'idempotence sont propres à l'appareil (la clé est l'identifiant de la commande) ; l'empreinte
     * comparée au rejeu lie en plus l'auteur et le grant (Command::fingerprint).
     *
     * `references` (O05) : place de la commande dans le journal du poste, plage de numéros et quotas de son cœur
     * signé ; avec elles, le chemin central enregistre l'ACK et le préfixe finalisé dans la transaction de l'effet.
     */
    public static function sync(
        string $clinicId,
        string $deviceId,
        string $actorId,
        string $actorName,
        GrantSnapshot $grant,
        string $correlationId,
        CarbonImmutable $occurredAtLocal,
        CarbonImmutable $lastServerTime,
        CarbonImmutable $receivedAt,
        ?CarbonImmutable $now = null,
        ?SyncReferences $references = null,
    ): self {
        return new self(
            clinicId: $clinicId,
            source: CommandSource::Sync,
            actorId: $actorId,
            actorName: $actorName,
            correlationId: $correlationId,
            idempotencyNamespace: 'device:'.$deviceId,
            now: $now ?? CarbonImmutable::now('UTC'),
            deviceId: $deviceId,
            grant: $grant,
            occurredAtLocal: $occurredAtLocal,
            lastServerTime: $lastServerTime,
            receivedAt: $receivedAt,
            syncReferences: $references,
        );
    }

    /**
     * Instant de la PIÈCE : celui où le poste a agi pour une commande synchronisée (le reçu ou la facture a été imprimé à
     * ce moment, jugé plausible par l'autorisation historique), sinon l'instant du serveur donné.
     */
    public function pieceInstant(CarbonImmutable $serverInstant): CarbonImmutable
    {
        return $this->source === CommandSource::Sync && $this->occurredAtLocal !== null ? $this->occurredAtLocal->utc() : $serverInstant;
    }

    /**
     * Traitement serveur sans auteur humain : l'audit enregistre « Système (<tâche>) ».
     */
    public static function job(string $clinicId, string $jobName, string $correlationId, ?CarbonImmutable $now = null): self
    {
        return new self(
            clinicId: $clinicId,
            source: CommandSource::Job,
            actorId: null,
            actorName: mb_substr('Système ('.$jobName.')', 0, 180),
            correlationId: $correlationId,
            idempotencyNamespace: 'job:'.mb_substr($jobName, 0, 90),
            now: $now ?? CarbonImmutable::now('UTC'),
        );
    }
}
