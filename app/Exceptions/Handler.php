<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return $this->handleApiException($e);
            }
        });
    }

    private function handleApiException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'error' => 'Error de validación',
                'message' => 'Los datos proporcionados no son válidos',
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof ModelNotFoundException) {
            $model = strtolower(class_basename($e->getModel()));
            $modelNames = [
                'player' => 'jugador',
                'team' => 'equipo',
                'tournament' => 'torneo',
                'matchs' => 'partido',
                'matchday' => 'jornada',
                'matchevent' => 'evento',
                'advertisement' => 'anuncio',
            ];
            $name = $modelNames[$model] ?? $model;

            return response()->json([
                'error' => 'Recurso no encontrado',
                'message' => "El {$name} solicitado no existe",
            ], 404);
        }

        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'error' => 'Ruta no encontrada',
                'message' => 'La ruta solicitada no existe',
            ], 404);
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return response()->json([
                'error' => 'Método no permitido',
                'message' => 'El método HTTP utilizado no está permitido para esta ruta',
            ], 405);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'error' => 'No autenticado',
                'message' => 'Debes iniciar sesión para acceder a este recurso',
            ], 401);
        }

        if ($e instanceof HttpException) {
            return response()->json([
                'error' => 'Error HTTP',
                'message' => $e->getMessage() ?: 'Ha ocurrido un error',
            ], $e->getStatusCode());
        }

        // Error genérico para excepciones no manejadas
        $message = config('app.debug')
            ? $e->getMessage()
            : 'Ha ocurrido un error interno en el servidor';

        return response()->json([
            'error' => 'Error del servidor',
            'message' => $message,
            'exception' => config('app.debug') ? get_class($e) : null,
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ], 500);
    }
}
