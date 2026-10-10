<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InitAdminCommand extends Command
{
    protected $signature = 'app:init-admin';
    protected $description = 'Crea o actualiza el usuario administrador inicial desde las variables de entorno';

    public function handle(): int
    {
        $username = strtolower(trim((string) env('ADMIN_USERNAME', 'admin')));
        $password = (string) env('ADMIN_PASSWORD', 'admin1234');

        if (!$username || !$password) {
            $this->error('ADMIN_USERNAME y ADMIN_PASSWORD deben estar definidos.');
            return 1;
        }

        $hashAlgo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($password, $hashAlgo);

        $user = User::where('username', $username)->first();

        if ($user) {
            $user->password_hash = $hash;
            $user->role = 'admin';
            $user->save();
            $this->info("Administrador '{$username}' actualizado exitosamente.");
        } else {
            User::create([
                'id' => (string) Str::uuid(),
                'username' => $username,
                'password_hash' => $hash,
                'role' => 'admin',
            ]);
            $this->info("Administrador '{$username}' creado exitosamente.");
        }

        return 0;
    }
}

