<?php

namespace App\Console\Commands;

use App\Application\Command;
use App\Application\CommandBus;
use App\Application\TransactionRunner;
use App\Domain\Access\AuditRecorder;
use App\Domain\Billing\Setup\SalesBootstrap;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Domain\Shared\Ulid;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\DB;

/**
 * Données de démonstration FICTIVES des ventes : une taxe de test, quatre articles (examen, monture stockée, verres sur
 * mesure, collyre périssable) et un stock initial. Tout est marqué « fictif » dans son libellé : AUCUN prix, taxe, référence ni
 * stock de ce jeu n'est réel (Q03, Q04), et rien n'en est repris en production.
 *
 * Mêmes garde-fous que `eeo:demo:accounts` : environnements de développement, de test et de démonstration seulement,
 * dans le cabinet de démonstration « DONNÉES FICTIVES » seulement, et jamais en production. Les écritures passent par le
 * chemin central de commandes, au nom du compte `demo.gerante` (créé par `eeo:demo:accounts`) : mêmes droits, mêmes
 * contrôles, même audit que par l'API. Relançable : une référence, une règle ou un stock initial déjà présents sont
 * laissés intacts.
 */
#[Signature('eeo:demo:sales')]
#[Description('Crée la caisse, une taxe de test, un catalogue fictif et son stock initial (cabinet de démonstration seulement)')]
final class SalesDemoCommand extends ConsoleCommand
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'e2e', 'demo'];

    public const TAX_CODE = 'TEST_TAXE_FICTIVE';

    public const EXAM_REFERENCE = 'DEMO-EXAMEN';

    public const FRAME_REFERENCE = 'DEMO-MONTURE';

    public const LENS_REFERENCE = 'DEMO-VERRES';

    public const EYE_DROPS_REFERENCE = 'DEMO-COLLYRE';

    public function handle(CommandBus $bus, SalesBootstrap $bootstrap, AuditRecorder $audit, TransactionRunner $transactions): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->components->error('Jeu de démonstration refusé dans cet environnement ('.app()->environment().') : admis seulement en '.implode(', ', self::ALLOWED_ENVIRONMENTS).'. Jamais en production.');

            return self::FAILURE;
        }

        try {
            $clinicId = $this->demoClinic();
            $manager = User::query()->where('clinic_id', $clinicId)->where('login', 'demo.gerante')->where('active', true)->first();
            if (! $manager instanceof User) {
                throw new DomainException('Le compte « demo.gerante » est introuvable ou désactivé : lancez d\'abord eeo:demo:accounts.');
            }

            $setup = $transactions->run(function () use ($clinicId, $bootstrap, $audit): array {
                DB::table('clinics')->where('id', $clinicId)->lockForUpdate()->first();
                $result = $bootstrap->ensure($clinicId, CarbonImmutable::now('UTC'));
                if ($result['register_created']) {
                    $audit->record($clinicId, null, 'cash_register.created', 'cash_registers', $result['register_id'], 'Jeu de démonstration', ['code' => SalesBootstrap::REGISTER_CODE], 'Commande eeo:demo:sales');
                }
                if ($result['location_created']) {
                    $audit->record($clinicId, null, 'stock_location.created', 'stock_locations', $result['location_id'], 'Jeu de démonstration', ['code' => SalesBootstrap::LOCATION_CODE], 'Commande eeo:demo:sales');
                }

                return $result;
            });

            $taxRuleId = $this->taxRule($bus, $manager, $clinicId);
            $items = [
                self::EXAM_REFERENCE => $this->item($bus, $manager, $clinicId, [
                    'reference' => self::EXAM_REFERENCE, 'category_code' => 'SERVICE', 'name' => 'Examen de vue (fictif)', 'unit_code' => 'SERVICE',
                    'quantity_integer_only' => false, 'sale_price' => 15000, 'stock_mode' => 'NONE', 'requires_expiry' => false, 'tax_rule_id' => $taxRuleId,
                ]),
                self::FRAME_REFERENCE => $this->item($bus, $manager, $clinicId, [
                    'reference' => self::FRAME_REFERENCE, 'category_code' => 'FRAME', 'name' => 'Monture fictive (démonstration)', 'brand' => 'MARQUE FICTIVE', 'unit_code' => 'UNIT',
                    'quantity_integer_only' => true, 'sale_price' => 40000, 'purchase_cost' => 18000, 'stock_mode' => 'STOCKED', 'requires_expiry' => false, 'tax_rule_id' => $taxRuleId,
                ]),
                self::LENS_REFERENCE => $this->item($bus, $manager, $clinicId, [
                    'reference' => self::LENS_REFERENCE, 'category_code' => 'LENS', 'name' => 'Verres fictifs sur mesure (démonstration)', 'unit_code' => 'PAIR',
                    'quantity_integer_only' => true, 'sale_price' => 60000, 'stock_mode' => 'MADE_TO_ORDER', 'requires_expiry' => false, 'tax_rule_id' => $taxRuleId,
                ]),
                self::EYE_DROPS_REFERENCE => $this->item($bus, $manager, $clinicId, [
                    'reference' => self::EYE_DROPS_REFERENCE, 'category_code' => 'COLLYRE', 'name' => 'Collyre fictif (démonstration)', 'unit_code' => 'UNIT',
                    'quantity_integer_only' => true, 'sale_price' => 5000, 'stock_mode' => 'STOCKED', 'requires_expiry' => true, 'expiry_alert_days' => 90, 'tax_rule_id' => $taxRuleId,
                ]),
            ];

            $this->openingStock($bus, $manager, $clinicId, (string) $setup['location_id'], $items);
        } catch (BusinessError|DomainException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Jeu de démonstration des ventes prêt (données FICTIVES).');
        $this->components->twoColumnDetail('Caisse principale (cash_register_id)', (string) $setup['register_id']);
        $this->components->twoColumnDetail('Article des frais d\'examen', self::EXAM_REFERENCE.' (EEO_EXAM_FEE_ITEM_REFERENCE=DEMO-EXAMEN pour le contrôle du paiement de l\'examen)');
        $this->line('Ouvrez les modules avec EEO_ENABLED_MODULES=…,sales,stock ; les prix, la taxe et le stock ci-dessus sont fictifs.');

        return self::SUCCESS;
    }

    private function demoClinic(): string
    {
        $clinics = DB::table('clinics')->orderBy('id')->get(['id', 'name']);
        if ($clinics->count() !== 1 || $clinics->first()?->name !== CreateDemoAccounts::DEMO_CLINIC_NAME) {
            throw new DomainException('Le jeu de démonstration des ventes n\'existe que dans le cabinet « '.CreateDemoAccounts::DEMO_CLINIC_NAME.' » (lancez d\'abord eeo:demo:accounts).');
        }

        return (string) $clinics->first()->id;
    }

    private function taxRule(CommandBus $bus, User $manager, string $clinicId): string
    {
        $existing = DB::table('tax_rules')->where('clinic_id', $clinicId)->where('code', self::TAX_CODE)->value('id');
        if (is_string($existing)) {
            return $existing;
        }

        $result = $bus->dispatch(
            new Command('CreateTaxRule', [
                'code' => self::TAX_CODE,
                'label' => 'TAXE FICTIVE DE DÉMONSTRATION',
                'rate_percent' => '10.0000',
                'active' => true,
                'validate' => true,
                'legal_note' => 'DÉMONSTRATION — aucune valeur fiscale : le régime réel est à fixer avec la gérante et le comptable (Q03).',
            ], null, null),
            CommandContext::direct($manager, 'demo-sales-'.substr(Ulid::generate(), -10)),
            Ulid::generate(),
        );

        return (string) $result->resultId;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function item(CommandBus $bus, User $manager, string $clinicId, array $fields): string
    {
        $existing = DB::table('catalog_items')->where('clinic_id', $clinicId)->where('reference', $fields['reference'])->value('id');
        if (is_string($existing)) {
            return $existing;
        }

        $result = $bus->dispatch(
            new Command('CreateCatalogItem', $fields + [
                'brand' => null,
                'model' => null,
                'purchase_cost' => null,
                'low_stock_threshold' => null,
                'expiry_alert_days' => null,
                'active' => true,
                'public_visibility' => false,
            ], null, null),
            CommandContext::direct($manager, 'demo-sales-'.substr(Ulid::generate(), -10)),
            Ulid::generate(),
        );

        return (string) $result->resultId;
    }

    /**
     * Stock initial fictif : dix montures et vingt collyres d'un lot daté d'un an. Une position qui a déjà un mouvement
     * (stock initial déjà enregistré) n'est pas touchée.
     *
     * @param  array<string, string>  $items  référence → identifiant
     */
    private function openingStock(CommandBus $bus, User $manager, string $clinicId, string $locationId, array $items): void
    {
        $lines = [];
        $frame = $items[self::FRAME_REFERENCE];
        $drops = $items[self::EYE_DROPS_REFERENCE];
        foreach ([$frame, $drops] as $itemId) {
            $moved = DB::table('stock_movements')
                ->join('stock_positions', static function ($join): void {
                    $join->on('stock_positions.clinic_id', '=', 'stock_movements.clinic_id')->on('stock_positions.id', '=', 'stock_movements.position_id');
                })
                ->where('stock_positions.clinic_id', $clinicId)
                ->where('stock_positions.item_id', $itemId)
                ->where('stock_positions.location_id', $locationId)
                ->exists();
            if ($moved) {
                continue;
            }
            $lines[] = $itemId === $frame
                ? ['movement_id' => Ulid::generate(), 'item_id' => $frame, 'location_id' => $locationId, 'lot' => null, 'quantity' => '10', 'unit_cost' => 18000]
                : [
                    'movement_id' => Ulid::generate(), 'item_id' => $drops, 'location_id' => $locationId,
                    'lot' => ['lot_id' => Ulid::generate(), 'batch_number' => 'LOT-DEMO-1', 'expires_on' => CarbonImmutable::now('UTC')->addYear()->format('Y-m-d')],
                    'quantity' => '20', 'unit_cost' => null,
                ];
        }
        if ($lines === []) {
            return;
        }

        $bus->dispatch(
            new Command('RegisterOpeningStock', [
                'counted_at' => CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d\TH:i:s.v\Z'),
                'reason' => 'Stock initial fictif de démonstration',
                'approval_id' => null,
                'lines' => $lines,
            ], null, null),
            CommandContext::direct($manager, 'demo-sales-'.substr(Ulid::generate(), -10)),
            Ulid::generate(),
        );
    }
}
