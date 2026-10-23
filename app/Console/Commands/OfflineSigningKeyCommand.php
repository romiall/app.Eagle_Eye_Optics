<?php

namespace App\Console\Commands;

use App\Offline\ServerSigningKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Clé ES256 du serveur qui signe les grants hors-ligne (O02). Acte d'exploitation, une fois à l'installation du
 * module `offline` :
 *
 *  - sans option : crée la clé si elle est absente ; refuse d'en écraser une (une clé existante reste en place) ;
 *  - `--rotate` : crée une nouvelle clé et garde la clé publique retirée, pour que les grants déjà remis aux postes
 *    restent vérifiables ; les nouveaux grants sont signés par la nouvelle clé (les postes relisent les clés à leur
 *    prochaine préparation) ;
 *  - `--show` : affiche l'identifiant (`kid`) et la clé PUBLIQUE courante, jamais la clé privée.
 *
 * Le fichier PEM est privé au serveur (`EEO_OFFLINE_SIGNING_KEY_PATH`, par défaut `storage/app/private/offline/`),
 * hors de la racine web, hors du dépôt et de MySQL ; il entre dans la sauvegarde des clés (deploiement.md).
 */
#[Signature('eeo:offline:signing-key
    {--rotate : Remplace la clé courante (la clé publique retirée reste vérifiable)}
    {--show : Affiche le kid et la clé publique courante}')]
#[Description('Crée, remplace ou affiche la clé ES256 qui signe les grants hors-ligne (jamais la clé privée)')]
final class OfflineSigningKeyCommand extends Command
{
    public function handle(ServerSigningKey $key): int
    {
        try {
            if ((bool) $this->option('show')) {
                if (! $key->exists()) {
                    $this->error('Aucune clé de signature installée : lancer la commande sans option pour la créer.');

                    return self::FAILURE;
                }
                $this->line('kid : '.$key->kid());
                $this->line('Clé publique (JWK) : '.json_encode($key->publicJwk(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $rotate = (bool) $this->option('rotate');
            if ($key->exists() && ! $rotate) {
                $this->info('Une clé de signature est déjà installée (kid '.$key->kid().') : rien n\'est modifié. --rotate pour la remplacer.');

                return self::SUCCESS;
            }

            $created = $key->generate($rotate);
            $this->info('Clé de signature des grants installée (kid '.$created['kid'].').');
            if ($created['rotated_from'] !== null) {
                $this->line('Ancienne clé publique conservée pour vérification : '.$created['rotated_from'].'.');
            }
            $this->line('Fichier privé : '.$key->path().' — à inclure dans la sauvegarde des clés, jamais dans le dépôt.');

            return self::SUCCESS;
        } catch (RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }
}
