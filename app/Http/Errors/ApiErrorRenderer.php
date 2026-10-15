<?php

namespace App\Http\Errors;

use App\Domain\Shared\BusinessError;
use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Enveloppe d'erreur de l'API privée (fichier 08) : `error.code` stable, `error.message` en français,
 * `error.fields` éventuel et `correlation_id`. Jamais de trace, de requête SQL ni de secret.
 */
final class ApiErrorRenderer
{
    public static function render(Throwable $exception, Request $request): JsonResponse
    {
        [$status, $code, $message, $fields, $headers] = self::describe($exception);

        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        // Valeurs actualisées d'un refus d'état (fichier 08 : « solde actualisé », « quantité disponible
        // actualisée ») : jamais une donnée personnelle ni clinique.
        if ($exception instanceof BusinessError && $exception->details !== []) {
            $error['details'] = $exception->details;
        }

        return new JsonResponse(
            ['error' => $error, 'correlation_id' => AssignCorrelationId::current($request)],
            $status,
            $headers,
        );
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, list<string>>, 4: array<string, string>}
     */
    private static function describe(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof BusinessError => [
                $exception->httpStatus, $exception->errorCode, $exception->getMessage(), $exception->fields, $exception->headers,
            ],
            $exception instanceof AuthenticationException => [
                401, 'AUTH_REQUIRED', 'Connexion requise : reconnectez-vous. Les saisies en cours sont conservées.', [], [],
            ],
            $exception instanceof AuthorizationException, $exception instanceof AccessDeniedHttpException => [
                403, 'ACTION_FORBIDDEN', 'Action non autorisée pour ce compte.', [], [],
            ],
            $exception instanceof TokenMismatchException => self::sessionExpired(),
            $exception instanceof ValidationException => [
                422, 'VALIDATION_FAILED', 'Certaines informations sont invalides.', self::fields($exception), [],
            ],
            $exception instanceof ThrottleRequestsException => [
                429, 'RATE_LIMITED', 'Trop de tentatives : patientez avant de réessayer.', [], self::stringHeaders($exception->getHeaders()),
            ],
            $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => [
                404, 'RESOURCE_NOT_FOUND', 'Ressource introuvable.', [], [],
            ],
            $exception instanceof MethodNotAllowedHttpException => [
                405, 'METHOD_NOT_ALLOWED', 'Méthode non autorisée pour cette adresse.', [], self::stringHeaders($exception->getHeaders()),
            ],
            $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 419 => self::sessionExpired(),
            $exception instanceof HttpExceptionInterface => [
                $exception->getStatusCode(), 'HTTP_ERROR', 'La demande n\'a pas pu être traitée.', [], self::stringHeaders($exception->getHeaders()),
            ],
            default => [500, 'INTERNAL_ERROR', 'Erreur interne : la demande n\'a pas été appliquée.', [], []],
        };
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, list<string>>, 4: array<string, string>}
     */
    private static function sessionExpired(): array
    {
        return [
            419,
            'SESSION_CONTEXT_EXPIRED',
            'Session expirée : renouvelez la session puis renvoyez la même demande. Les saisies sont conservées.',
            [],
            [],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function fields(ValidationException $exception): array
    {
        $fields = [];
        foreach ($exception->errors() as $field => $messages) {
            $fields[(string) $field] = array_values(array_map('strval', (array) $messages));
        }

        return $fields;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            if (is_scalar($value)) {
                $result[(string) $name] = (string) $value;
            }
        }

        return $result;
    }
}
