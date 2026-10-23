<?php

namespace App\Support\Files;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Entrées-sorties du disque PRIVÉ `local` (storage/app/private, jamais servi en HTTP) pour les originaux PDF des
 * documents (D01) et les justificatifs scannés (X01). Hors du domaine : le chemin central et les actions du domaine
 * ne déclenchent aucun effet externe (`CentralPathGuardTest`) ; seuls les contrôleurs écrivent ou lisent ici, AVANT
 * (écriture) ou APRÈS (lecture) la transaction de la commande. Chemins adressés par le contenu : écrire deux fois les
 * mêmes octets au même chemin ne crée aucun doublon.
 */
final class PrivateFiles
{
    public static function write(string $path, string $bytes): void
    {
        $disk = Storage::disk('local');
        if ($disk->exists($path) && hash('sha256', (string) $disk->get($path)) === hash('sha256', $bytes)) {
            return;
        }
        if ($disk->put($path, $bytes) !== true) {
            throw new RuntimeException('Écriture du fichier privé impossible.');
        }
    }

    public static function read(string $path): ?string
    {
        $disk = Storage::disk('local');

        return $disk->exists($path) ? (string) $disk->get($path) : null;
    }
}
