<?php

namespace App\Domain\Billing\Catalog;

use App\Domain\Shared\CanonicalJson;
use Illuminate\Support\Facades\DB;

/**
 * Empreinte du catalogue et des règles fiscales tels que le serveur les applique (fichier 21 : « catalogue/tarif/taxe
 * préparé pour un grant hors-ligne, conservé comme snapshot privé immuable »). Elle ne contient JAMAIS un coût
 * d'achat : seulement ce qui détermine un prix de vente, une unité, un mode de stock et une taxe.
 *
 * Usage actuel (EN LIGNE) : l'émission enregistre dans l'audit l'empreinte réellement appliquée, à côté de celle que
 * le client annonce. La préparation d'un poste (O03) figera ce contenu pour son grant et le serveur le comparera à
 * `catalog_snapshot_sha256` de chaque commande synchronisée ; aucun contrôle de ce type n'a lieu avant O05.
 *
 * Déterministe : lignes triées par identifiant, clés triées par la sérialisation canonique.
 */
final class CatalogSnapshot
{
    /**
     * @return array{catalog_version: string, sha256: string, items: int, tax_rules: int}
     */
    public static function of(string $clinicId): array
    {
        $content = self::content($clinicId);
        $sha = CanonicalJson::hash($content);

        return ['catalog_version' => self::version($sha), 'sha256' => $sha, 'items' => count($content['items']), 'tax_rules' => count($content['tax_rules'])];
    }

    /**
     * Contenu figé remis à un poste préparé (O03) : ce que le poste applique hors-ligne, vérifiable par son empreinte
     * signée dans le grant (`CanonicalJson::hash` du même tableau). Jamais de coût d'achat.
     *
     * @return array{items: list<array<string, mixed>>, tax_rules: list<array<string, mixed>>}
     */
    public static function content(string $clinicId): array
    {
        $items = DB::table('catalog_items')
            ->where('clinic_id', $clinicId)
            ->orderBy('id')
            ->get(['id', 'reference', 'category_code', 'name', 'unit_code', 'quantity_integer_only', 'sale_price', 'tax_rule_id', 'stock_mode', 'requires_expiry', 'active', 'version'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'reference' => (string) $row->reference,
                'category_code' => (string) $row->category_code,
                'name' => (string) $row->name,
                'unit_code' => (string) $row->unit_code,
                'quantity_integer_only' => (bool) $row->quantity_integer_only,
                'sale_price' => (int) $row->sale_price,
                'tax_rule_id' => $row->tax_rule_id === null ? null : (string) $row->tax_rule_id,
                'stock_mode' => (string) $row->stock_mode,
                'requires_expiry' => (bool) $row->requires_expiry,
                'active' => (bool) $row->active,
                'version' => (int) $row->version,
            ])
            ->all();

        $rules = DB::table('tax_rules')
            ->where('clinic_id', $clinicId)
            ->orderBy('id')
            ->get(['id', 'code', 'rate_percent', 'active', 'validated_at', 'version'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'rate_percent' => $row->rate_percent === null ? null : (string) $row->rate_percent,
                'active' => (bool) $row->active,
                'validated_at' => $row->validated_at === null ? null : (string) $row->validated_at,
                'version' => (int) $row->version,
            ])
            ->all();

        return ['items' => array_values($items), 'tax_rules' => array_values($rules)];
    }

    public static function version(string $sha): string
    {
        return 'catalogue-'.substr($sha, 0, 12);
    }
}
