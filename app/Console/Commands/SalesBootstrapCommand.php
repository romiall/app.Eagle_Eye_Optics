<?php

namespace App\Console\Commands;

use App\Application\TransactionRunner;
use App\Domain\Access\AuditRecorder;
use App\Domain\Access\ClinicContext;
use App\Domain\Billing\Setup\SalesBootstrap;
use App\Domain\Shared\BusinessError;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mise en service des ventes du cabinet servi (B05, I00) : crée la caisse principale et l'emplacement de stock principal
 * s'ils n'existent pas. À lancer UNE fois par l'exploitant avant d'ouvrir le module « sales » (aucune route
 * n'en crée) ; relançable sans effet (une caisse active ou un emplacement actif existants sont repris tels quels).
 * Elle ne crée ni prix, ni taxe, ni catalogue, ni stock : ceux-ci viennent de la gérante.
 */
#[Signature('eeo:sales:bootstrap')]
#[Description('Crée la caisse principale et l\'emplacement de stock principal du cabinet (idempotent)')]
final class SalesBootstrapCommand extends Command
{
    public function handle(ClinicContext $clinics, SalesBootstrap $bootstrap, AuditRecorder $audit, TransactionRunner $transactions): int
    {
        try {
            $clinicId = $clinics->configuredClinicId();
            $result = $transactions->run(function () use ($clinicId, $bootstrap, $audit): array {
                // Même verrou que toute mutation du cabinet : aucune écriture concurrente pendant la mise en service.
                DB::table('clinics')->where('id', $clinicId)->lockForUpdate()->first();
                $result = $bootstrap->ensure($clinicId, CarbonImmutable::now('UTC'));
                if ($result['register_created']) {
                    $audit->record($clinicId, null, 'cash_register.created', 'cash_registers', $result['register_id'], 'Mise en service des ventes', ['code' => SalesBootstrap::REGISTER_CODE], 'Commande eeo:sales:bootstrap');
                }
                if ($result['location_created']) {
                    $audit->record($clinicId, null, 'stock_location.created', 'stock_locations', $result['location_id'], 'Mise en service des ventes', ['code' => SalesBootstrap::LOCATION_CODE], 'Commande eeo:sales:bootstrap');
                }

                return $result;
            });
        } catch (BusinessError|DomainException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Ventes prêtes : caisse principale et emplacement de stock principal.');
        $this->components->twoColumnDetail('Caisse principale (cash_register_id)', $result['register_id'].($result['register_created'] ? ' (créée)' : ' (existante)'));
        $this->components->twoColumnDetail('Emplacement de stock (location_id)', $result['location_id'].($result['location_created'] ? ' (créé)' : ' (existant)'));
        $this->line('Prochaines étapes : catalogue et règles fiscales par la gérante, stock initial, puis EEO_ENABLED_MODULES=…,sales,stock.');

        return self::SUCCESS;
    }
}
