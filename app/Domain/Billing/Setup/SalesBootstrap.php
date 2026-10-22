<?php

namespace App\Domain\Billing\Setup;

use App\Domain\Shared\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mise en service des ventes d'un cabinet (B05, I00) : la CAISSE PRINCIPALE et l'EMPLACEMENT de stock principal. Aucune
 * route n'en crée d'autre au premier jalon (une seule caisse financière principale : hypothèse A04, fichier 15) ; ce
 * service est appelé par la commande `eeo:sales:bootstrap` de l'exploitant, une fois par cabinet, et par le jeu de
 * démonstration. Il est IDEMPOTENT : une caisse active ou un emplacement actif existants sont repris tels quels,
 * rien n'est dupliqué ni renommé.
 *
 * À appeler dans une transaction (TransactionRunner) : il ne l'ouvre pas lui-même.
 */
final class SalesBootstrap
{
    public const REGISTER_CODE = 'CAISSE-PRINCIPALE';

    public const REGISTER_NAME = 'Caisse principale';

    public const LOCATION_CODE = 'PRINCIPAL';

    public const LOCATION_NAME = 'Emplacement principal';

    /**
     * @return array{register_id: string, register_created: bool, location_id: string, location_created: bool}
     */
    public function ensure(string $clinicId, CarbonImmutable $now): array
    {
        $stored = $now->utc()->format('Y-m-d H:i:s.u');

        $register = DB::table('cash_registers')->where('clinic_id', $clinicId)->where('active', 1)->orderBy('code')->orderBy('id')->first();
        if ($register === null && DB::table('cash_registers')->where('clinic_id', $clinicId)->where('code', self::REGISTER_CODE)->exists()) {
            throw new \DomainException('La caisse « '.self::REGISTER_CODE.' » existe mais est désactivée : réactivez-la (ou créez-la sous un autre code) avant de relancer.');
        }
        $registerCreated = $register === null;
        $registerId = $register === null ? Ulid::generate() : (string) $register->id;
        if ($register === null) {
            DB::table('cash_registers')->insert([
                'id' => $registerId,
                'clinic_id' => $clinicId,
                'code' => self::REGISTER_CODE,
                'name' => self::REGISTER_NAME,
                'active' => 1,
                'created_at' => $stored,
                'updated_at' => $stored,
            ]);
        }

        $location = DB::table('stock_locations')->where('clinic_id', $clinicId)->where('active', 1)->orderBy('code')->orderBy('id')->first();
        if ($location === null && DB::table('stock_locations')->where('clinic_id', $clinicId)->where('code', self::LOCATION_CODE)->exists()) {
            throw new \DomainException('L\'emplacement « '.self::LOCATION_CODE.' » existe mais est désactivé : réactivez-le avant de relancer.');
        }
        $locationCreated = $location === null;
        $locationId = $location === null ? Ulid::generate() : (string) $location->id;
        if ($location === null) {
            DB::table('stock_locations')->insert([
                'id' => $locationId,
                'clinic_id' => $clinicId,
                'code' => self::LOCATION_CODE,
                'name' => self::LOCATION_NAME,
                'active' => 1,
                'created_at' => $stored,
                'updated_at' => $stored,
            ]);
        }

        return ['register_id' => $registerId, 'register_created' => $registerCreated, 'location_id' => $locationId, 'location_created' => $locationCreated];
    }
}
