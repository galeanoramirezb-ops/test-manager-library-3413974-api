<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $username = strtolower(trim((string) $request->input('username', '')));
        $password = (string) $request->input('password', '');

        if (!$username || !$password) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Usuario o contraseña incorrectos.',
                [],
                401
            );
        }

        $user = User::where('username', $username)->first();

        if (!$user || !password_verify($password, $user->password_hash)) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Usuario o contraseña incorrectos.',
                [],
                401
            );
        }

        $token = JwtService::generateToken($user->id, $user->role, 28800);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 28800,
            'role' => $user->role,
        ], 200);
    }
}

