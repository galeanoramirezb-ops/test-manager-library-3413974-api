<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                $details = [];
                foreach ($e->errors() as $field => $messages) {
                    $details[] = [
                        'field' => $field,
                        'issue' => implode(', ', $messages),
                    ];
                }
                return response()->json([
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Los datos proporcionados no son válidos.',
                    'details' => $details,
                ], 422);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'code' => 'NOT_FOUND',
                    'message' => 'El recurso solicitado no fue encontrado.',
                    'details' => [],
                ], 404);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'code' => 'FORBIDDEN_ROLE',
                    'message' => 'No tiene permisos suficientes para realizar esta acción.',
                    'details' => [],
                ], 403);
            }
        });
    })->create();
