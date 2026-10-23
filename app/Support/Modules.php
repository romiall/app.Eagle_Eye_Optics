<?php

namespace App\Support;

/**
 * Mise en service progressive des modules : un module terminé reste invisible (écran et API) tant
 * que l'exploitant ne l'a pas activé dans la configuration du serveur (`EEO_ENABLED_MODULES`). La
 * gérante ne change pas cette liste : elle appartient au déploiement, pas aux paramètres du cabinet.
 *
 * Les codes sont ceux des modules de la navigation (fichier 04). Une valeur inconnue est ignorée :
 * une faute de frappe laisse le module caché, jamais ouvert par erreur.
 */
final class Modules
{
    /** @var list<string> */
    public const CODES = [
        'today',
        'patients',
        'consultations',
        'agenda',
        'sales',
        'stock',
        'workshop',
        'communication',
        'reports',
        'settings',
        // Préparation des postes pour le hors-ligne (O01, O02) : coffre local et enrôlement ; aucune entrée de menu.
        'offline',
    ];

    /**
     * Modules activés, dans l'ordre de la navigation.
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        $configured = config('eeo.enabled_modules', []);
        $configured = is_array($configured) ? $configured : [];

        return array_values(array_filter(
            self::CODES,
            static fn (string $code): bool => in_array($code, $configured, true),
        ));
    }

    public static function isEnabled(string $code): bool
    {
        return in_array($code, self::enabled(), true);
    }
}
