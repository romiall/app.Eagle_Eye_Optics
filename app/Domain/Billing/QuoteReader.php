<?php

namespace App\Domain\Billing;

use App\Domain\Shared\BusinessError;
use App\Domain\Shared\Ulid;
use App\Domain\Shared\ValueObjects\CivilDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lecture des devis du cabinet (L1D07) : la ligne `quotes`, ses lignes actuelles, ses révisions figées (avec leur
 * document et le nom ACTUEL de leur auteur), les factures nées de sa conversion, et le destinataire. Toujours limitée
 * au cabinet ; un identifiant d'un autre cabinet est « introuvable ». Aucune donnée clinique n'est lue.
 *
 * Le présentateur (`QuotePresenter`) met en forme ce que ce lecteur rend, au format du contrat
 * (`schemas/resources/billing.json`).
 */
final class QuoteReader
{
    public const PER_PAGE = 25;

    /** Filtres d'état de la liste : statuts de la ligne, et EXPIRED (émis ou accepté, validité passée). */
    private const FILTERS = ['DRAFT', 'ISSUED', 'ACCEPTED', 'DECLINED', 'EXPIRED', 'CONVERTED'];

    public function find(string $clinicId, string $quoteId): ?stdClass
    {
        $row = DB::table('quotes')->where('clinic_id', $clinicId)->where('id', $quoteId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Devis complet.
     *
     * @return array{quote: stdClass, lines: list<stdClass>, revisions: list<stdClass>, documents: array<int, stdClass>, invoices: list<stdClass>, patient: ?stdClass, prospect: ?stdClass}
     */
    public function detail(stdClass $quote): array
    {
        $clinicId = (string) $quote->clinic_id;
        $quoteId = (string) $quote->id;

        $lines = array_values(DB::table('quote_lines')->where('clinic_id', $clinicId)->where('quote_id', $quoteId)->orderBy('position')->get()->all());
        // Nom ACTUEL de l'auteur de chaque révision ; la révision garde celui figé dans son snapshot.
        $revisions = array_values(DB::table('quote_revisions')
            ->leftJoin('users as authors', static function ($join): void {
                $join->on('authors.clinic_id', '=', 'quote_revisions.clinic_id')->on('authors.id', '=', 'quote_revisions.authored_by');
            })
            ->where('quote_revisions.clinic_id', $clinicId)
            ->where('quote_revisions.quote_id', $quoteId)
            ->orderByDesc('quote_revisions.revision_no')
            ->get(['quote_revisions.*', 'authors.display_name as authored_by_name'])
            ->all());
        $documents = [];
        foreach (DB::table('generated_documents')
            ->where('clinic_id', $clinicId)
            ->where('quote_id', $quoteId)
            ->where('document_type', 'QUOTE')
            ->orderBy('created_at')
            ->get() as $document) {
            $documents[(int) $document->revision_no] = $document;
        }
        $invoices = array_values(DB::table('invoices')
            ->where('clinic_id', $clinicId)
            ->where('quote_id', $quoteId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'number', 'status'])
            ->all());

        $patient = $quote->patient_id === null ? null : DB::table('patient_records')->where('clinic_id', $clinicId)->where('id', (string) $quote->patient_id)->first();
        $prospect = $quote->prospect_contact_id === null ? null : DB::table('prospect_contacts')->where('clinic_id', $clinicId)->where('id', (string) $quote->prospect_contact_id)->first();
        $creator = DB::table('users')->where('clinic_id', $clinicId)->where('id', (string) $quote->created_by)->value('display_name');
        $quote->created_by_name = is_string($creator) ? $creator : null;

        return [
            'quote' => $quote,
            'lines' => $lines,
            'revisions' => $revisions,
            'documents' => $documents,
            'invoices' => $invoices,
            'patient' => $patient instanceof stdClass ? $patient : null,
            'prospect' => $prospect instanceof stdClass ? $prospect : null,
        ];
    }

    /**
     * Liste des devis (plus récents d'abord), 25 par page, avec le destinataire : identité de base du patient (dossier,
     * nom, prénoms) ou nom du prospect, et la dernière révision. Filtres : état (dont EXPIRED, calculé au jour du
     * cabinet) et patient.
     *
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function page(string $clinicId, mixed $status, mixed $patientId, mixed $page, CivilDate $today): LengthAwarePaginator
    {
        $query = DB::table('quotes')
            ->leftJoin('patient_records', static function ($join): void {
                $join->on('patient_records.clinic_id', '=', 'quotes.clinic_id')->on('patient_records.id', '=', 'quotes.patient_id');
            })
            ->leftJoin('prospect_contacts', static function ($join): void {
                $join->on('prospect_contacts.clinic_id', '=', 'quotes.clinic_id')->on('prospect_contacts.id', '=', 'quotes.prospect_contact_id');
            })
            ->where('quotes.clinic_id', $clinicId)
            ->select(
                'quotes.*',
                'patient_records.record_number as patient_record_number',
                'patient_records.last_name as patient_last_name',
                'patient_records.first_names as patient_first_names',
                'prospect_contacts.name as prospect_name',
                'prospect_contacts.phone as prospect_phone',
            )
            ->selectSub(static function (Builder $sub): void {
                $sub->from('quote_revisions')
                    ->whereColumn('quote_revisions.clinic_id', 'quotes.clinic_id')
                    ->whereColumn('quote_revisions.quote_id', 'quotes.id')
                    ->selectRaw('COALESCE(MAX(quote_revisions.revision_no), 0)');
            }, 'current_revision');

        if ($status !== null && $status !== '') {
            if (! is_string($status) || ! in_array($status, self::FILTERS, true)) {
                throw BusinessError::validation(['status' => ['Statut attendu : '.implode(', ', self::FILTERS).'.']]);
            }
            $day = $today->toString();
            if ($status === 'EXPIRED') {
                $query->where(static function (Builder $where) use ($day): void {
                    $where->where('quotes.status', 'EXPIRED')
                        ->orWhere(static function (Builder $dated) use ($day): void {
                            $dated->whereIn('quotes.status', ['ISSUED', 'ACCEPTED'])->whereNotNull('quotes.valid_until')->where('quotes.valid_until', '<', $day);
                        });
                });
            } elseif ($status === 'ISSUED' || $status === 'ACCEPTED') {
                // Encore valables : un devis expiré à la lecture n'est plus présenté « émis ».
                $query->where('quotes.status', $status)->where(static function (Builder $valid) use ($day): void {
                    $valid->whereNull('quotes.valid_until')->orWhere('quotes.valid_until', '>=', $day);
                });
            } else {
                $query->where('quotes.status', $status);
            }
        }
        if ($patientId !== null && $patientId !== '') {
            if (! is_string($patientId) || ! Ulid::isValid($patientId)) {
                throw BusinessError::validation(['patient_id' => ['L\'identifiant du patient est un ULID.']]);
            }
            $query->where('quotes.patient_id', $patientId);
        }

        $pageNumber = 1;
        if ($page !== null) {
            if ((! is_string($page) && ! is_int($page)) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw BusinessError::validation(['page' => ['La page est un entier supérieur ou égal à 1.']]);
            }
            $pageNumber = (int) $page;
        }

        return $query
            ->orderByDesc('quotes.created_at')
            ->orderByDesc('quotes.id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $pageNumber);
    }
}
