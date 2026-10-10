<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Token de autenticación no proporcionado.',
                'details' => [],
            ], 401);
        }

        $token = substr($authHeader, 7);
        $payload = JwtService::validateToken($token);

        if (!$payload) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Token inválido o expirado.',
                'details' => [],
            ], 401);
        }

        $user = User::find($payload['sub']);
        if (!$user) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Usuario no encontrado.',
                'details' => [],
            ], 401);
        }

        $request->attributes->set('auth_user', $user);

        return $next($request);
    }
}

