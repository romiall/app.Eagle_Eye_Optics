<?php

namespace Tests\Support\Http;

use App\Application\Command;
use App\Http\Controllers\Concerns\DispatchesCommands;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\Commands\TestCommands;

/**
 * Contrôleur de TEST : une route directe qui n'a que le rôle d'un contrôleur de mutation (fichier 23) —
 * construire la commande, la confier au chemin central, rendre le résultat.
 */
final class ConsumeCounterController extends Controller
{
    use DispatchesCommands;

    public function store(Request $request, string $counter): JsonResponse
    {
        $command = new Command(
            (string) $request->query('command', TestCommands::CONSUME),
            ['amount' => (int) $request->input('amount')],
            $counter,
            $request->has('expected_version') ? (int) $request->input('expected_version') : null,
        );

        return new JsonResponse(['data' => $this->dispatchCommand($request, $command)->result]);
    }
}
