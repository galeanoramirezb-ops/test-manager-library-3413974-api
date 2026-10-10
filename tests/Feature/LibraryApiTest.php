<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Tests\TestCase;

class LibraryApiTest extends TestCase
{
    public function test_health_check_returns_ok(): void
    {
        $response = $this->get('/health');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }

    public function test_admin_can_login_and_receive_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'admin1234',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'access_token',
            'token_type',
            'expires_in',
            'role',
        ]);
        $this->assertEquals('admin', $response->json('role'));
    }

    public function test_invalid_login_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'code' => 'INVALID_CREDENTIALS',
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/categories');
        $response->assertStatus(401);
        $response->assertJson([
            'code' => 'INVALID_CREDENTIALS',
        ]);
    }

    public function test_authenticated_user_can_list_categories(): void
    {
        $admin = User::where('username', 'admin')->first();
        $token = JwtService::generateToken($admin->id, $admin->role);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/categories');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json());
    }

    public function test_authenticated_user_can_list_books(): void
    {
        $admin = User::where('username', 'admin')->first();
        $token = JwtService::generateToken($admin->id, $admin->role);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/books?page=1&size=10');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'items',
            'page',
            'size',
            'total',
        ]);
    }

    public function test_overdue_loans_endpoint_returns_data(): void
    {
        $admin = User::where('username', 'admin')->first();
        $token = JwtService::generateToken($admin->id, $admin->role);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/loans/overdue');

        $response->assertStatus(200);
        $response->assertJsonStructure(['items']);
    }

    public function test_most_borrowed_report_requires_date_range(): void
    {
        $admin = User::where('username', 'admin')->first();
        $token = JwtService::generateToken($admin->id, $admin->role);

        // Sin parámetros debe fallar con 422
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/reports/most-borrowed');

        $response->assertStatus(422);
        $response->assertJson(['code' => 'VALIDATION_ERROR']);

        // Con parámetros debe responder 200
        $responseValid = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/reports/most-borrowed?from=2026-01-01&to=2026-12-31');

        $responseValid->assertStatus(200);
        $responseValid->assertJsonStructure(['from', 'to', 'rows']);
    }
}

