<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $role = $request->input('role');
        if ($role !== 'librarian') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El rol solicitado solo puede ser "librarian".',
                [['field' => 'role', 'issue' => 'solo admite librarian']],
                422
            );
        }

        $rawUsername = $request->input('username');
        if (!$rawUsername || !is_string($rawUsername)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El nombre de usuario es obligatorio.',
                [['field' => 'username', 'issue' => 'obligatorio']],
                422
            );
        }

        $username = strtolower(trim($rawUsername));
        if ($username === '') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El nombre de usuario no puede estar vacío.',
                [['field' => 'username', 'issue' => 'no puede estar vacío']],
                422
            );
        }

        $password = (string) $request->input('password', '');
        if ($password === '') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'La contraseña es obligatoria.',
                [['field' => 'password', 'issue' => 'obligatorio']],
                422
            );
        }

        if (User::where('username', $username)->exists()) {
            return ApiResponse::error(
                'USERNAME_TAKEN',
                "El nombre de usuario '{$username}' ya está registrado.",
                [['field' => 'username', 'issue' => 'ya existe']],
                409
            );
        }

        $hashAlgo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $hashAlgo);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'username' => $username,
            'password_hash' => $hash,
            'role' => 'librarian',
        ]);

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
        ], 201);
    }
}

