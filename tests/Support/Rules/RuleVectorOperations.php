<?php

namespace Tests\Support\Rules;

use App\Domain\Billing\Rules\IncludedTax;
use App\Domain\Billing\Rules\LedgerBalance;
use App\Domain\Billing\Rules\LegacyBalance;
use App\Domain\Billing\Rules\LineAmounts;
use App\Domain\Inventory\Rules\LotRules;
use App\Domain\Inventory\Rules\StockAvailability;
use App\Domain\Scheduling\Rules\FollowUpDates;
use App\Domain\Shared\ValueObjects\CivilDate;
use App\Domain\Shared\ValueObjects\Counter;
use App\Domain\Shared\ValueObjects\Diopter;
use App\Domain\Shared\ValueObjects\FixedDecimal;
use App\Domain\Shared\ValueObjects\Instant;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Quantity;
use App\Domain\Shared\ValueObjects\Rate;
use App\Domain\Shared\ValueObjects\Text;
use InvalidArgumentException;

/**
 * Adaptateur des vecteurs `contracts/vectors/` vers les règles PHP : entrées JSON → appel de la règle → sortie
 * JSON. Il ne calcule rien lui-même. Son jumeau TypeScript est `tests/frontend/domain/vectorOperations.ts` : les
 * deux exposent exactement les mêmes opérations.
 */
final class RuleVectorOperations
{
    /**
     * @param  array<string, mixed>  $input
     */
    public static function run(string $operation, array $input): mixed
    {
        return match ($operation) {
            'text.trim' => Text::trim(self::string($input, 'value')),
            'text.optional' => Text::optional(self::nullableString($input, 'value'), self::int($input, 'max_length'), self::string($input, 'field')),
            'text.searchKey' => Text::searchKey(self::string($input, 'value')),
            'text.phone' => Text::phone(self::nullableString($input, 'value'), self::string($input, 'field')),
            'money.parse' => Money::parse(self::nullableString($input, 'text'), self::string($input, 'field')),
            'money.format' => Money::format(self::int($input, 'amount')),
            'money.positive' => Money::positive(self::int($input, 'value'), self::string($input, 'field')),
            'money.amount' => Money::amount(self::int($input, 'value'), self::string($input, 'field')),
            'decimal.parse' => self::parseDecimal($input)?->canonical(),
            'quantity.forLine' => Quantity::forLine(Quantity::of(self::string($input, 'quantity')), self::bool($input, 'integer_only'))->canonical(),
            'date.parse' => CivilDate::parse(self::nullableString($input, 'text'))?->toString(),
            'date.add' => self::addToDate(self::date($input, 'date'), self::int($input, 'amount'), self::string($input, 'unit'))->toString(),
            'date.fromInstant' => self::instant(self::string($input, 'instant'))->civilDate(self::string($input, 'timezone'))->toString(),
            'date.age' => self::date($input, 'birth_date')->ageOn(self::date($input, 'reference_date')),
            'instant.parse' => self::parsedInstant(self::string($input, 'text')),
            'counter.parse' => Counter::parse(self::nullableString($input, 'value'))->value,
            'counter.next' => Counter::parse(self::string($input, 'value'))->next()->value,
            'counter.compare' => Counter::parse(self::string($input, 'left'))->compare(Counter::parse(self::string($input, 'right'))),
            'invoice.line' => self::lineOutput(self::line($input)),
            'invoice.total' => self::invoiceTotal($input),
            'invoice.includedTax' => IncludedTax::of(self::int($input, 'net'), Rate::of(self::string($input, 'rate_percent'))),
            'ledger.balance' => self::balanceOutput(self::ledger($input)),
            'ledger.payment', 'ledger.creditNote', 'ledger.refund', 'ledger.reversal' => self::ledgerOperation($operation, $input),
            'legacy.openingBalance' => LegacyBalance::openingBalance(self::int($input, 'original_total'), self::int($input, 'already_paid')),
            'legacy.revenue' => LegacyBalance::revenue(self::string($input, 'origin'), self::int($input, 'total_gross')),
            'stock.central' => StockAvailability::central(self::quantity($input, 'physical'), self::quantity($input, 'central_reserved'), self::quantity($input, 'offline_allocated'))->canonical(),
            'stock.local' => StockAvailability::local(self::quantity($input, 'allocated'), self::quantity($input, 'consumed'), self::quantity($input, 'returned'), self::quantity($input, 'local_reserved'))->canonical(),
            'stock.take' => self::take($input),
            'stock.syncQuotaDelivery' => self::syncQuotaDelivery($input),
            'lot.deliverable' => self::deliverable($input),
            'lot.fefo' => self::fefo($input),
            'followUp.recommended' => FollowUpDates::recommended(self::date($input, 'anchor'), self::int($input, 'value'), self::string($input, 'unit'))->toString(),
            'followUp.reminder' => FollowUpDates::reminder(self::date($input, 'recommended'), self::int($input, 'advance'), self::string($input, 'unit'))->toString(),
            default => throw new InvalidArgumentException("Opération de vecteur inconnue : {$operation}"),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function parseDecimal(array $input): ?FixedDecimal
    {
        $text = self::nullableString($input, 'text');
        $field = self::string($input, 'field');

        return match (self::string($input, 'kind')) {
            'quantity' => Quantity::parse($text, $field),
            'rate' => Rate::parse($text, $field),
            'diopter' => Diopter::parse($text, $field),
            default => throw new InvalidArgumentException('Nature décimale inconnue.'),
        };
    }

    private static function addToDate(CivilDate $date, int $amount, string $unit): CivilDate
    {
        return match ($unit) {
            'DAY' => $date->addDays($amount),
            'MONTH' => $date->addMonths($amount),
            'YEAR' => $date->addYears($amount),
            default => throw new InvalidArgumentException('Unité de date inconnue.'),
        };
    }

    private static function instant(string $text): Instant
    {
        return Instant::parse($text) ?? throw new InvalidArgumentException('Instant vide.');
    }

    /**
     * @return array{canonical: string, epoch_ms: int}
     */
    private static function parsedInstant(string $text): array
    {
        $instant = self::instant($text);

        return ['canonical' => $instant->toString(), 'epoch_ms' => $instant->epochMilliseconds];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function line(array $input): LineAmounts
    {
        $rate = $input['tax_rate_percent'] ?? null;

        return LineAmounts::compute(
            Quantity::of(self::string($input, 'quantity')),
            self::int($input, 'unit_price_gross'),
            self::int($input, 'discount_gross'),
            is_string($rate) ? Rate::of($rate) : null,
            self::bool($input, 'integer_only'),
        );
    }

    /**
     * @return array{gross: int, discount: int, net: int, tax_amount: ?int}
     */
    private static function lineOutput(LineAmounts $line): array
    {
        return ['gross' => $line->gross, 'discount' => $line->discount, 'net' => $line->net, 'tax_amount' => $line->taxAmount];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{lines: list<array{gross: int, discount: int, net: int, tax_amount: ?int}>, total: int}
     */
    private static function invoiceTotal(array $input): array
    {
        $lines = array_map(static fn (mixed $line): LineAmounts => self::line(self::object($line)), self::list($input, 'lines'));

        return ['lines' => array_map(self::lineOutput(...), $lines), 'total' => LineAmounts::invoiceTotal($lines)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, list<int>>  $extra  faits ajoutés au registre (opération appliquée)
     */
    private static function ledger(array $input, array $extra = []): LedgerBalance
    {
        $facts = static fn (string $key): array => array_merge(self::intList($input, $key), $extra[$key] ?? []);

        return LedgerBalance::of(self::int($input, 'total_gross'), $facts('payments'), $facts('reversals'), $facts('credits'), $facts('refunds'));
    }

    /**
     * @return array{signed_balance: int, amount_due: int, refundable_credit: int, creditable: int, ledger_version: int}
     */
    private static function balanceOutput(LedgerBalance $balance): array
    {
        return [
            'signed_balance' => $balance->signedBalance(),
            'amount_due' => $balance->amountDue(),
            'refundable_credit' => $balance->refundableCredit(),
            'creditable' => $balance->creditable(),
            'ledger_version' => $balance->ledgerVersion,
        ];
    }

    /**
     * Opération financière : la règle accepte ou refuse sur le registre ACTUEL, puis le registre est recalculé avec
     * le nouveau fait, comme le ferait la transaction (sous verrou de facture).
     *
     * @param  array<string, mixed>  $input
     * @return array{signed_balance: int, amount_due: int, refundable_credit: int, creditable: int, ledger_version: int}
     */
    private static function ledgerOperation(string $operation, array $input): array
    {
        $facts = self::object($input['ledger'] ?? null);
        $balance = self::ledger($facts);
        $amount = self::int($input, 'amount');
        $key = match ($operation) {
            'ledger.payment' => 'payments',
            'ledger.creditNote' => 'credits',
            'ledger.refund' => 'refunds',
            default => 'reversals',
        };
        match ($operation) {
            'ledger.payment' => $balance->assertPayment($amount),
            'ledger.creditNote' => $balance->assertCreditNote($amount),
            'ledger.refund' => $balance->assertRefund($amount),
            default => LedgerBalance::assertReversal(self::int($input, 'payment_amount'), self::int($input, 'already_reversed'), $amount),
        };

        return self::balanceOutput(self::ledger($facts, [$key => [$amount]]));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function take(array $input): bool
    {
        StockAvailability::assertCanTake(self::quantity($input, 'requested'), self::quantity($input, 'available'));

        return true;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{physical: string, consumed: string, remaining_quota: string, central_available: string}
     */
    private static function syncQuotaDelivery(array $input): array
    {
        $allocated = self::quantity($input, 'allocated');
        $returned = self::quantity($input, 'returned');
        [$physical, $consumed] = StockAvailability::applySyncedQuotaDelivery(
            self::quantity($input, 'physical'),
            $allocated,
            self::quantity($input, 'consumed'),
            $returned,
            self::quantity($input, 'quantity'),
        );
        $remaining = StockAvailability::remainingQuota($allocated, $consumed, $returned);

        return [
            'physical' => $physical->canonical(),
            'consumed' => $consumed->canonical(),
            'remaining_quota' => $remaining->canonical(),
            'central_available' => StockAvailability::central($physical, self::quantity($input, 'central_reserved'), $remaining)->canonical(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function deliverable(array $input): bool
    {
        $expires = self::nullableString($input, 'expires_on');
        LotRules::assertDeliverable(self::string($input, 'status'), $expires === null ? null : CivilDate::fromString($expires), self::date($input, 'local_date'));

        return true;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private static function fefo(array $input): array
    {
        $lots = array_map(static function (mixed $lot): array {
            $lot = self::object($lot);
            $expires = self::nullableString($lot, 'expires_on');

            return ['id' => self::string($lot, 'id'), 'expires_on' => $expires === null ? null : CivilDate::fromString($expires)];
        }, self::list($input, 'lots'));

        return LotRules::fefoOrder($lots);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function date(array $input, string $key): CivilDate
    {
        return CivilDate::fromString(self::string($input, $key));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function quantity(array $input, string $key): FixedDecimal
    {
        return Quantity::of(self::string($input, $key));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function string(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException("Entrée « {$key} » : chaîne attendue.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function nullableString(array $input, string $key): ?string
    {
        if (! array_key_exists($key, $input)) {
            throw new InvalidArgumentException("Entrée « {$key} » absente.");
        }

        return $input[$key] === null ? null : self::string($input, $key);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function int(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (! is_int($value)) {
            throw new InvalidArgumentException("Entrée « {$key} » : entier attendu.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function bool(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Entrée « {$key} » : booléen attendu.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<mixed>
     */
    private static function list(array $input, string $key): array
    {
        $value = $input[$key] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Entrée « {$key} » : liste attendue.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private static function intList(array $input, string $key): array
    {
        return array_map(static function (mixed $value) use ($key): int {
            if (! is_int($value)) {
                throw new InvalidArgumentException("Entrée « {$key} » : liste d'entiers attendue.");
            }

            return $value;
        }, self::list($input, $key));
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Objet attendu.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
