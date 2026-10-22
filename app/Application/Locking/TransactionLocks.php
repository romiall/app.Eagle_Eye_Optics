<?php

namespace App\Application\Locking;

use App\Domain\Shared\BusinessError;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Verrous d'UNE tentative de transaction, dans l'ordre unique du contrat 23 (voir LockRank).
 *
 * Chaque méthode vérifie d'abord le rang : demander un verrou de rang inférieur à un verrou déjà tenu, ou des
 * identifiants d'un même rang dans le désordre, lève LockOrderViolation avant toute requête. Redemander un
 * verrou déjà tenu est permis (InnoDB l'accorde de nouveau sans attente).
 *
 * Toutes les lectures sont filtrées par `clinic_id` : la clé étrangère composite protège les données, elle ne
 * remplace pas ce filtre (contrat 23). Les lignes absentes rendent null ; c'est à l'action de décider si
 * l'absence est un refus.
 */
final class TransactionLocks
{
    private int $highestRank = 0;

    private string $highestKey = '';

    private bool $clinicExclusive = false;

    private bool $clinicShared = false;

    /** @var list<string> */
    private array $acquired = [];

    /** @var array<string, true> verrous déjà tenus, « rang:clé » */
    private array $held = [];

    public function __construct(private readonly string $clinicId) {}

    /**
     * Verrou exclusif du cabinet : premier verrou de toute mutation centrale, il sérialise les mutations du
     * cabinet et sert de verrou de publication du journal des changements (pull).
     */
    public function clinic(): stdClass
    {
        // Monter un verrou partagé en exclusif ferait attendre deux lecteurs l'un sur l'autre (interblocage
        // classique), et après d'autres verrous il violerait l'ordre : une mutation prend l'exclusif d'emblée.
        if ($this->clinicShared && ! $this->clinicExclusive) {
            throw new LockOrderViolation('Ordre des verrous violé : le verrou partagé du cabinet ne se transforme pas en verrou exclusif ; une mutation prend le verrou exclusif en premier.');
        }
        $this->enter(LockRank::Clinic, $this->clinicId);
        $row = DB::table('clinics')->where('id', $this->clinicId)->lockForUpdate()->first();
        if ($row === null) {
            throw BusinessError::notFound('Cabinet introuvable.');
        }
        $this->clinicExclusive = true;

        return $row;
    }

    /**
     * Verrou partagé du cabinet, pris par les LECTEURS du journal des changements : il attend la fin de toute
     * mutation en cours (qui tient le verrou exclusif jusqu'à son commit), donc ne peut jamais voir un numéro
     * de changement dont un numéro inférieur du même cabinet n'est pas encore committé.
     */
    public function clinicShared(): stdClass
    {
        $this->enter(LockRank::Clinic, $this->clinicId);
        $row = DB::table('clinics')->where('id', $this->clinicId)->sharedLock()->first();
        if ($row === null) {
            throw BusinessError::notFound('Cabinet introuvable.');
        }
        if (! $this->clinicExclusive) {
            $this->clinicShared = true;
        }

        return $row;
    }

    public function holdsClinicExclusively(): bool
    {
        return $this->clinicExclusive;
    }

    /**
     * Compte du personnel (`users`) du cabinet, au rang « compte » : après le cabinet, avant tout le reste.
     */
    public function account(string $userId): ?stdClass
    {
        $this->enter(LockRank::Account, $userId);

        return $this->byId('users', $userId);
    }

    public function device(string $deviceId): ?stdClass
    {
        $this->enter(LockRank::Device, $deviceId);

        return $this->byId('enrolled_devices', $deviceId);
    }

    /**
     * Ligne d'idempotence d'une clé (rang 3). Verrouillée même absente (verrou d'intervalle) : elle sérialise
     * deux demandes de même clé.
     */
    public function idempotency(string $namespace, string $commandName, string $requestKey): ?stdClass
    {
        $this->enter(LockRank::Idempotency, $namespace.'|'.$commandName.'|'.$requestKey);

        return DB::table('idempotency_requests')
            ->where('clinic_id', $this->clinicId)
            ->where('namespace', $namespace)
            ->where('command_name', $commandName)
            ->where('request_key', $requestKey)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Hauts-niveaux de séquences de numéros, verrouillés par (type, année) croissants. Les paires sont
     * triées ici : l'appelant n'a pas à connaître l'ordre.
     *
     * @param  list<array{0: string, 1: int}>  $keys  [document_type, document_year]
     * @return array<string, stdClass> clé « TYPE:AAAA »
     */
    public function sequences(array $keys): array
    {
        $ordered = [];
        foreach ($keys as [$type, $year]) {
            $ordered[sprintf('%s:%04d', $type, $year)] = [$type, $year];
        }
        ksort($ordered, SORT_STRING);

        $rows = [];
        foreach ($ordered as $key => [$type, $year]) {
            $this->enter(LockRank::Sequence, $key);
            $row = DB::table('document_sequences')
                ->where('clinic_id', $this->clinicId)
                ->where('document_type', $type)
                ->where('document_year', $year)
                ->lockForUpdate()
                ->first();
            if ($row !== null) {
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    /**
     * Fiche patient (`patient_records`) du cabinet, au rang « dossier patient » : après les séquences de
     * numéros, avant la propriété de facture. Lue sous verrou pour comparer la version attendue avant toute
     * modification ; null si elle n'existe pas dans ce cabinet.
     */
    public function patient(string $patientId): ?stdClass
    {
        $this->enter(LockRank::Patient, $patientId);

        return $this->byId('patient_records', $patientId);
    }

    /**
     * Propriétaires d'écriture d'une facture (une ligne par époque), du plus ancien au plus récent.
     *
     * @return list<stdClass>
     */
    public function invoiceOwners(string $invoiceId): array
    {
        $this->enter(LockRank::InvoiceOwner, $invoiceId);

        return array_values(DB::table('invoice_write_owners')
            ->where('clinic_id', $this->clinicId)
            ->where('invoice_id', $invoiceId)
            ->orderBy('epoch')
            ->lockForUpdate()
            ->get()
            ->all());
    }

    public function invoice(string $invoiceId): ?stdClass
    {
        $this->enter(LockRank::Invoice, $invoiceId);

        return $this->byId('invoices', $invoiceId);
    }

    public function cashSession(string $sessionId): ?stdClass
    {
        $this->enter(LockRank::CashSession, $sessionId);

        return $this->byId('cash_sessions', $sessionId);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, stdClass> par identifiant
     */
    public function stockPositions(array $ids): array
    {
        return $this->many(LockRank::StockPosition, 'stock_positions', $ids);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, stdClass> par identifiant
     */
    public function stockLots(array $ids): array
    {
        return $this->many(LockRank::StockLot, 'stock_lots', $ids);
    }

    /**
     * Quotas de stock des postes (`device_stock_allocations`).
     *
     * @param  list<string>  $ids
     * @return array<string, stdClass> par identifiant
     */
    public function stockQuotas(array $ids): array
    {
        return $this->many(LockRank::StockQuota, 'device_stock_allocations', $ids);
    }

    public function approval(string $approvalId): ?stdClass
    {
        $this->enter(LockRank::Approval, $approvalId);

        return $this->byId('approvals', $approvalId);
    }

    /**
     * Verrous pris dans cette tentative, dans l'ordre, « rang:clé » (diagnostic et tests).
     *
     * @return list<string>
     */
    public function acquired(): array
    {
        return $this->acquired;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, stdClass>
     */
    private function many(LockRank $rank, string $table, array $ids): array
    {
        $ordered = array_values(array_unique($ids));
        sort($ordered, SORT_STRING);

        $rows = [];
        foreach ($ordered as $id) {
            $this->enter($rank, $id);
            $row = $this->byId($table, $id);
            if ($row !== null) {
                $rows[$id] = $row;
            }
        }

        return $rows;
    }

    private function byId(string $table, string $id): ?stdClass
    {
        return DB::table($table)->where('clinic_id', $this->clinicId)->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * Contrôle d'ordre : rang jamais décroissant ; à rang égal, clé jamais décroissante. Un verrou déjà tenu
     * peut être redemandé quel que soit son rang (InnoDB l'accorde de nouveau sans attente) : relire la ligne
     * du cabinet ou d'une facture déjà verrouillée n'est pas un nouvel ordre d'acquisition.
     */
    private function enter(LockRank $rank, string $key): void
    {
        $token = $rank->value.':'.$key;
        if (isset($this->held[$token])) {
            return;
        }

        if ($rank->value < $this->highestRank) {
            throw new LockOrderViolation(sprintf(
                'Ordre des verrous violé : « %s » demandé après un verrou de rang supérieur (« %s » déjà tenu). '
                .'Ordre du contrat 23 : cabinet, compte, appareil, idempotence, séquences, dossier patient, propriété de facture, facture, session de caisse, stock, approbation.',
                $rank->label(),
                LockRank::from($this->highestRank)->label(),
            ));
        }
        if ($rank->value === $this->highestRank && strcmp($key, $this->highestKey) < 0) {
            throw new LockOrderViolation(sprintf(
                'Ordre des verrous violé : les verrous de « %s » se prennent par clé croissante (« %s » demandé après « %s »).',
                $rank->label(),
                $key,
                $this->highestKey,
            ));
        }

        $this->highestRank = $rank->value;
        $this->highestKey = $key;
        $this->held[$token] = true;
        $this->acquired[] = $token;
    }
}
