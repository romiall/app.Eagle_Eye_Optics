<?php

namespace App\Domain\Access\Actions;

use App\Application\Command;
use App\Application\CommandBus;
use App\Domain\Access\Handlers\UpdateClinicSettingsHandler;
use App\Domain\Shared\BusinessError;
use App\Domain\Shared\CommandContext;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Paramètres administratifs du cabinet modifiables par la gérante. Ne sont pas modifiables par l'API :
 * le fuseau et la monnaie (dates civiles et montants existants), et `production_documents_enabled`
 * (activation des pièces officielles réservée à la procédure de bascule, paramètres réels validés : Q03).
 *
 * Cette classe valide la saisie ; l'écriture est UpdateClinicSettingsHandler, exécuté par le chemin central
 * de commandes (F07) sous le verrou du cabinet.
 */
final class UpdateClinicSettings
{
    public function __construct(private readonly CommandBus $bus) {}

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function __invoke(User $actor, array $input): Clinic
    {
        $data = Validator::make($input, [
            'name' => ['sometimes', 'string', 'min:2', 'max:180'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'tax_identifier' => ['sometimes', 'nullable', 'string', 'max:100'],
            'professional_mentions' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'document_settings' => ['sometimes', 'nullable', 'array'],
            'timezone' => ['prohibited'],
            'currency' => ['prohibited'],
            'production_documents_enabled' => ['prohibited'],
        ])->validate();

        // document_settings est un objet JSON (CHECK ck_clinics_04) : une liste est refusée, un objet vide
        // reste un objet ({} et non []).
        if (isset($data['document_settings']) && is_array($data['document_settings']) && $data['document_settings'] !== [] && array_is_list($data['document_settings'])) {
            throw BusinessError::validation(['document_settings' => ['Les paramètres des documents sont un objet de clés nommées, pas une liste.']]);
        }

        $this->bus->dispatch(
            new Command(UpdateClinicSettingsHandler::COMMAND, $data, $actor->clinic_id),
            CommandContext::direct($actor, AssignCorrelationId::current(request())),
        );

        /** @var Clinic */
        return Clinic::query()->findOrFail($actor->clinic_id);
    }
}
