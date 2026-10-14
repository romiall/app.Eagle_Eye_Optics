<?php

namespace App\Support\Schema;

use RuntimeException;

/**
 * État de base incompatible avec l'étape demandée : refus explicite, aucune action partielle masquée.
 */
final class BaselineStateRefused extends RuntimeException {}
