<?php

namespace App\Application\Effects;

use RuntimeException;

/**
 * La commande d'un poste n'est plus la suivante de son journal au moment du commit (un autre envoi du même poste l'a
 * précédée, ou elle a déjà été finalisée). La transaction est annulée sans aucun effet ; ce n'est PAS un refus métier :
 * la synchronisation s'arrête et reprendra dans l'ordre, sans conflit ni ACK fictif (contrat 23, préfixe finalisé).
 */
final class SyncOrderViolation extends RuntimeException {}
