<?php

namespace App\Domain\Patients;

use App\Domain\Shared\BusinessError;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * Activité d'un patient (fiche patient, R02 « activité patient » ; fichier 02 : « historique jamais écrasé ») : la
 * liste chronologique, du plus récent au plus ancien, de ce qui est arrivé au dossier, lue dans les tables
 * VIVANTES. Lecture seule, toujours limitée au cabinet et au patient, requêtes paramétrées.
 *
 * Quatre sections, chacune rendue SEULEMENT si l'appelant l'a déclarée visible pour le compte (le contrôleur décide
 * d'après les droits et les modules ouverts ; fichier 09 : identité, clinique et finance séparées) :
 *
 *   VISITS         visites (motif, statut administratif)                          patient.read
 *   EXAMS          consultations (date, état, professionnel)                      clinical.read
 *   PRESCRIPTIONS  prescriptions optiques (chaque version) et ordonnances de       clinical.read
 *                  médicaments d'une consultation (date, type, version)
 *   FINANCIAL      factures émises (solde projeté), reçus, avoirs émis,           invoice.read
 *                  remboursements (numéro, date, montant)
 *
 * Une section non visible n'entre dans AUCUNE requête : ni ses lignes, ni son compte ne sont lus, donc aucun indice
 * de son existence ne peut sortir. Aucune valeur clinique n'est lue (ni motif de consultation, ni mesure, ni
 * conclusion, ni nom de médicament, ni note de prescription) : seuls identifiants, dates, états et numéros.
 *
 * Un brouillon de facture, une facture abandonnée et un avoir non émis ne sont pas des pièces : ils ne figurent pas
 * dans la liste. Le solde d'une facture vient de la projection `vw_invoice_balances` (faits append-only), jamais
 * d'une colonne de statut : aucun « payé » n'est déduit sans pièce.
 *
 * Pagination : une seule requête UNION ALL des sections retenues, triée par instant décroissant, puis par rang de
 * type (à instant égal, la pièce qui suit logiquement vient d'abord : reçu avant facture, prescription avant
 * consultation, consultation avant visite), puis par identifiant décroissant (ULID) : ordre total et stable. Les
 * comptes de chaque section visible sont lus d'une seule requête ; les détails de la page ensuite, par type.
 */
final class PatientActivity
{
    public const PER_PAGE = 25;

    /** Page maximale admise (comme la recherche de patients). */
    public const MAX_PAGE = 100000;

    public const VISITS = 'VISITS';

    public const EXAMS = 'EXAMS';

    public const PRESCRIPTIONS = 'PRESCRIPTIONS';

    public const FINANCIAL = 'FINANCIAL';

    /** Sections, dans l'ordre d'affichage des filtres. */
    public const SECTIONS = [self::VISITS, self::EXAMS, self::PRESCRIPTIONS, self::FINANCIAL];

    public const VISIT = 'VISIT';

    public const CONSULTATION = 'CONSULTATION';

    public const PRESCRIPTION = 'PRESCRIPTION';

    public const MEDICATION_ORDER = 'MEDICATION_ORDER';

    public const INVOICE = 'INVOICE';

    public const RECEIPT = 'RECEIPT';

    public const CREDIT_NOTE = 'CREDIT_NOTE';

    public const REFUND = 'REFUND';

    /**
     * Rang de départage à instant égal (croissant) : la pièce qui suit logiquement l'autre vient d'abord dans une
     * liste du plus récent au plus ancien.
     */
    private const RANKS = [
        self::REFUND => 1,
        self::RECEIPT => 2,
        self::CREDIT_NOTE => 3,
        self::INVOICE => 4,
        self::PRESCRIPTION => 5,
        self::MEDICATION_ORDER => 6,
        self::CONSULTATION => 7,
        self::VISIT => 8,
    ];

    /**
     * @param  list<string>  $visibleSections  sections que le compte peut voir (sous-ensemble de SECTIONS)
     * @param  mixed  $section  filtre facultatif (paramètre `section`), null ou vide pour toutes les sections visibles
     * @param  mixed  $page  page demandée (paramètre `page`), 1 par défaut
     */
    public function __invoke(string $clinicId, string $patientId, array $visibleSections, mixed $section, mixed $page): PatientActivityPage
    {
        $visible = array_values(array_filter(self::SECTIONS, static fn (string $code): bool => in_array($code, $visibleSections, true)));
        $filter = $this->section($section, $visible);
        $pageNumber = $this->page($page);
        $selected = $filter === null ? $visible : [$filter];

        $totals = $this->totals($clinicId, $patientId, $visible);
        $total = 0;
        foreach ($selected as $code) {
            $total += $totals[$code];
        }

        // Aucune section retenue : le total est nul, aucune requête.
        $rows = $total === 0 ? [] : DB::query()
            ->fromSub($this->union($clinicId, $patientId, $selected), 'activity')
            ->select(['item_type', 'item_id', 'occurred_at'])
            ->orderByDesc('occurred_at')
            ->orderBy('sort_rank')
            ->orderByDesc('item_id')
            ->offset(($pageNumber - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get()
            ->all();

        return new PatientActivityPage(
            $this->details($clinicId, $patientId, $rows),
            $totals,
            $filter,
            $pageNumber,
            self::PER_PAGE,
            $total,
        );
    }

    /**
     * Nombre d'éléments de chaque section visible (zéro pour une section vide), en une requête.
     *
     * @param  list<string>  $visible
     * @return array<string, int>
     */
    private function totals(string $clinicId, string $patientId, array $visible): array
    {
        $totals = array_fill_keys($visible, 0);
        if ($visible === []) {
            return $totals;
        }

        $counts = DB::query()
            ->fromSub($this->union($clinicId, $patientId, $visible), 'activity')
            ->select('section_code')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('section_code')
            ->get();
        foreach ($counts as $count) {
            $code = (string) $count->section_code;
            if (array_key_exists($code, $totals)) {
                $totals[$code] = (int) $count->total;
            }
        }

        return $totals;
    }

    /**
     * UNION ALL des sections demandées. Chaque partie rend les mêmes colonnes : section_code, item_type, item_id,
     * occurred_at, sort_rank. Un nouveau constructeur à chaque appel (unionAll modifie la première requête).
     *
     * @param  list<string>  $sections
     */
    private function union(string $clinicId, string $patientId, array $sections): Builder
    {
        $parts = [];
        foreach ($sections as $code) {
            array_push($parts, ...$this->parts($clinicId, $patientId, $code));
        }
        $union = array_shift($parts);
        if ($union === null) {
            throw new LogicException('Aucune section à lire.');
        }
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return $union;
    }

    /**
     * @return list<Builder>
     */
    private function parts(string $clinicId, string $patientId, string $section): array
    {
        return match ($section) {
            self::VISITS => [
                DB::table('visits')
                    ->where('visits.clinic_id', $clinicId)
                    ->where('visits.patient_id', $patientId)
                    ->selectRaw(self::columns(self::VISITS, self::VISIT, 'visits.id', 'visits.occurred_at')),
            ],
            self::EXAMS => [
                DB::table('consultations')
                    ->where('consultations.clinic_id', $clinicId)
                    ->where('consultations.patient_id', $patientId)
                    ->selectRaw(self::columns(self::EXAMS, self::CONSULTATION, 'consultations.id', 'consultations.effective_at')),
            ],
            self::PRESCRIPTIONS => [
                // Chaque version d'une série est une entrée : une nouvelle version ne remplace pas la précédente.
                DB::table('optical_prescriptions')
                    ->where('optical_prescriptions.clinic_id', $clinicId)
                    ->where('optical_prescriptions.patient_id', $patientId)
                    ->selectRaw(self::columns(self::PRESCRIPTIONS, self::PRESCRIPTION, 'optical_prescriptions.id', 'COALESCE(optical_prescriptions.validated_at, optical_prescriptions.created_at)')),
                // Ordonnance de médicaments : les lignes de la rubrique « médicaments » d'une consultation. Elle n'a ni
                // table ni numéro propres : l'entrée porte l'identifiant et la date de la consultation.
                DB::table('consultations')
                    ->where('consultations.clinic_id', $clinicId)
                    ->where('consultations.patient_id', $patientId)
                    ->whereExists(static function (Builder $query): void {
                        $query->selectRaw('1')
                            ->from('medication_orders')
                            ->whereColumn('medication_orders.clinic_id', 'consultations.clinic_id')
                            ->whereColumn('medication_orders.consultation_id', 'consultations.id');
                    })
                    ->selectRaw(self::columns(self::PRESCRIPTIONS, self::MEDICATION_ORDER, 'consultations.id', 'consultations.effective_at')),
            ],
            self::FINANCIAL => [
                DB::table('invoices')
                    ->where('invoices.clinic_id', $clinicId)
                    ->where('invoices.patient_id', $patientId)
                    ->where('invoices.status', 'ISSUED')
                    ->selectRaw(self::columns(self::FINANCIAL, self::INVOICE, 'invoices.id', 'invoices.issued_at')),
                DB::table('receipts')
                    ->join('invoices', self::invoiceOf('receipts'))
                    ->where('receipts.clinic_id', $clinicId)
                    ->where('invoices.patient_id', $patientId)
                    ->selectRaw(self::columns(self::FINANCIAL, self::RECEIPT, 'receipts.id', 'receipts.issued_at')),
                DB::table('credit_notes')
                    ->join('invoices', self::invoiceOf('credit_notes'))
                    ->where('credit_notes.clinic_id', $clinicId)
                    ->where('invoices.patient_id', $patientId)
                    ->where('credit_notes.status', 'ISSUED')
                    ->selectRaw(self::columns(self::FINANCIAL, self::CREDIT_NOTE, 'credit_notes.id', 'credit_notes.issued_at')),
                DB::table('refunds')
                    ->join('invoices', self::invoiceOf('refunds'))
                    ->where('refunds.clinic_id', $clinicId)
                    ->where('invoices.patient_id', $patientId)
                    ->selectRaw(self::columns(self::FINANCIAL, self::REFUND, 'refunds.id', 'refunds.effective_at')),
            ],
            default => throw new LogicException('Section inconnue : '.$section),
        };
    }

    /**
     * Colonnes communes d'une partie de l'union. Section et type sont des constantes de cette classe (jamais une
     * saisie) ; identifiant et instant sont des colonnes du schéma.
     *
     * @param  literal-string  $section
     * @param  literal-string  $type
     * @param  literal-string  $id
     * @param  literal-string  $occurredAt
     * @return literal-string
     */
    private static function columns(string $section, string $type, string $id, string $occurredAt): string
    {
        return "'".$section."' AS section_code, '".$type."' AS item_type, ".$id.' AS item_id, '.$occurredAt.' AS occurred_at, '
            .self::RANKS[$type].' AS sort_rank';
    }

    /**
     * Jointure d'une pièce à sa facture, dans le même cabinet.
     */
    private static function invoiceOf(string $table): Closure
    {
        return static function (JoinClause $join) use ($table): void {
            $join->on('invoices.clinic_id', '=', $table.'.clinic_id')->on('invoices.id', '=', $table.'.invoice_id');
        };
    }

    /**
     * Détails des éléments de la page, dans l'ordre de la page. Un élément dont la ligne a disparu entre les deux
     * lectures (brouillon de prescription supprimé) est omis plutôt que rendu incomplet.
     *
     * @param  array<int, stdClass>  $rows
     * @return list<array{type: string, occurred_at: string, row: stdClass}>
     */
    private function details(string $clinicId, string $patientId, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[(string) $row->item_type][] = (string) $row->item_id;
        }

        $loaded = [];
        foreach ($ids as $type => $list) {
            $loaded[$type] = $this->load($clinicId, $patientId, $type, $list);
        }

        $items = [];
        foreach ($rows as $row) {
            $type = (string) $row->item_type;
            $detail = $loaded[$type][(string) $row->item_id] ?? null;
            if ($detail instanceof stdClass) {
                $items[] = ['type' => $type, 'occurred_at' => (string) $row->occurred_at, 'row' => $detail];
            }
        }

        return $items;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, stdClass>
     */
    private function load(string $clinicId, string $patientId, string $type, array $ids): array
    {
        $query = match ($type) {
            self::VISIT => DB::table('visits')
                ->where('visits.clinic_id', $clinicId)
                ->where('visits.patient_id', $patientId)
                ->whereIn('visits.id', $ids)
                ->select(['visits.id', 'visits.reason', 'visits.status']),
            self::CONSULTATION => DB::table('consultations')
                ->join('users', static function (JoinClause $join): void {
                    $join->on('users.clinic_id', '=', 'consultations.clinic_id')->on('users.id', '=', 'consultations.clinician_id');
                })
                ->where('consultations.clinic_id', $clinicId)
                ->where('consultations.patient_id', $patientId)
                ->whereIn('consultations.id', $ids)
                ->select(['consultations.id', 'consultations.status', 'users.display_name as clinician_name']),
            self::PRESCRIPTION => DB::table('optical_prescriptions')
                ->where('optical_prescriptions.clinic_id', $clinicId)
                ->where('optical_prescriptions.patient_id', $patientId)
                ->whereIn('optical_prescriptions.id', $ids)
                ->select([
                    'optical_prescriptions.id', 'optical_prescriptions.origin', 'optical_prescriptions.status',
                    'optical_prescriptions.version_no', 'optical_prescriptions.consultation_id',
                    'optical_prescriptions.external_prescribed_on',
                ]),
            self::MEDICATION_ORDER => DB::table('consultations')
                ->where('consultations.clinic_id', $clinicId)
                ->where('consultations.patient_id', $patientId)
                ->whereIn('consultations.id', $ids)
                ->select(['consultations.id', 'consultations.status']),
            self::INVOICE => DB::table('invoices')
                ->leftJoin('vw_invoice_balances as balances', static function (JoinClause $join): void {
                    $join->on('balances.clinic_id', '=', 'invoices.clinic_id')->on('balances.invoice_id', '=', 'invoices.id');
                })
                ->where('invoices.clinic_id', $clinicId)
                ->where('invoices.patient_id', $patientId)
                ->whereIn('invoices.id', $ids)
                ->select([
                    'invoices.id', 'invoices.number', 'invoices.origin', 'invoices.total_gross',
                    'balances.payments_confirmed', 'balances.payment_reversals', 'balances.signed_balance',
                    'balances.refundable_credit',
                ]),
            self::RECEIPT => DB::table('receipts')
                ->join('invoices', self::invoiceOf('receipts'))
                ->join('payments', static function (JoinClause $join): void {
                    $join->on('payments.clinic_id', '=', 'receipts.clinic_id')->on('payments.id', '=', 'receipts.payment_id');
                })
                ->where('receipts.clinic_id', $clinicId)
                ->where('invoices.patient_id', $patientId)
                ->whereIn('receipts.id', $ids)
                ->select([
                    'receipts.id', 'receipts.number', 'receipts.invoice_id', 'invoices.number as invoice_number',
                    'payments.amount', 'payments.method',
                ])
                ->selectSub(static function (Builder $query): void {
                    $query->from('payment_reversals')
                        ->selectRaw('COALESCE(SUM(payment_reversals.amount), 0)')
                        ->whereColumn('payment_reversals.clinic_id', 'receipts.clinic_id')
                        ->whereColumn('payment_reversals.payment_id', 'receipts.payment_id');
                }, 'reversed_amount'),
            self::CREDIT_NOTE => DB::table('credit_notes')
                ->join('invoices', self::invoiceOf('credit_notes'))
                ->where('credit_notes.clinic_id', $clinicId)
                ->where('invoices.patient_id', $patientId)
                ->whereIn('credit_notes.id', $ids)
                ->select([
                    'credit_notes.id', 'credit_notes.number', 'credit_notes.amount_gross', 'credit_notes.invoice_id',
                    'invoices.number as invoice_number',
                ]),
            self::REFUND => DB::table('refunds')
                ->join('invoices', self::invoiceOf('refunds'))
                ->where('refunds.clinic_id', $clinicId)
                ->where('invoices.patient_id', $patientId)
                ->whereIn('refunds.id', $ids)
                ->select([
                    'refunds.id', 'refunds.number', 'refunds.amount', 'refunds.method', 'refunds.invoice_id',
                    'invoices.number as invoice_number',
                ]),
            default => throw new LogicException('Type d\'élément inconnu : '.$type),
        };

        $byId = [];
        foreach ($query->get() as $row) {
            $byId[(string) $row->id] = $row;
        }

        return $byId;
    }

    /**
     * Filtre de section : absent ou vide = toutes les sections visibles. Une section inconnue est un 422 ; une
     * section connue mais non visible pour ce compte est un refus (403) qui ne dit rien du dossier.
     *
     * @param  list<string>  $visible
     */
    private function section(mixed $section, array $visible): ?string
    {
        if ($section === null || $section === '') {
            return null;
        }
        if (! is_string($section) || ! in_array($section, self::SECTIONS, true)) {
            throw BusinessError::validation(['section' => ['Section inconnue : VISITS, EXAMS, PRESCRIPTIONS ou FINANCIAL.']]);
        }
        if (! in_array($section, $visible, true)) {
            throw BusinessError::forbidden();
        }

        return $section;
    }

    private function page(mixed $page): int
    {
        if ($page === null) {
            return 1;
        }
        $number = (is_string($page) || is_int($page)) ? filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_PAGE]]) : false;
        if ($number === false) {
            throw BusinessError::validation(['page' => ['La page est un entier compris entre 1 et '.self::MAX_PAGE.'.']]);
        }

        return $number;
    }
}
