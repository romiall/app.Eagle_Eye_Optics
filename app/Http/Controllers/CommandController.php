<?php

namespace App\Http\Controllers;

use App\Application\Command;
use App\Application\CommandBus;
use App\Application\CommandResult;
use App\Domain\Shared\CommandContext;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Route directe → chemin central de commandes (F07). Un contrôleur de mutation ne verrouille rien, ne calcule
 * rien et n'ouvre aucune transaction : il construit la commande, la confie au bus et rend le résultat.
 *
 * Le contexte est celui du compte connecté (droits ACTUELS, relus sous le verrou du cabinet) avec la
 * corrélation de la requête. La clé d'idempotence est l'en-tête `Idempotency-Key` : obligatoire pour les
 * commandes qui l'exigent (le bus répond alors VALIDATION_FAILED), rejouée à l'identique si la première
 * réponse s'est perdue, refusée si elle est renvoyée avec un contenu différent.
 *
 * Classe abstraite plutôt que trait : l'analyse statique vérifie ce chemin même avant le premier contrôleur
 * métier qui en hérite (un trait sans utilisateur n'est pas analysé).
 */
abstract class CommandController extends Controller
{
    protected function dispatchCommand(Request $request, Command $command): CommandResult
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $header = $request->header('Idempotency-Key');
        $key = is_string($header) && trim($header) !== '' ? trim($header) : null;

        return app(CommandBus::class)->dispatch(
            $command,
            CommandContext::direct($user, AssignCorrelationId::current($request)),
            $key,
        );
    }
}
