<?php

namespace App\Application\Effects;

/**
 * Compartiment de lecture d'un changement publié (`sync_change_log.visibility`) : le pull ne remet à un
 * profil que les changements des compartiments que son grant l'autorise à lire (fichier 19).
 */
enum ChangeVisibility: string
{
    case Administrative = 'ADMINISTRATIVE';
    case Clinical = 'CLINICAL';
    case Financial = 'FINANCIAL';
    case Manager = 'MANAGER';
    case Public = 'PUBLIC';
}
