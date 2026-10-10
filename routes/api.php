<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Http\Middleware\RequireRoleMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // 1. Salud
    Route::get('/health', function () {
        return response()->json(['status' => 'ok']);
    });

    // 2. Autenticación
    Route::post('/auth/login', [AuthController::class, 'login']);

    // 3. Rutas protegidas por JWT
    Route::middleware([JwtAuthMiddleware::class])->group(function () {
        // Usuarios (solo admin)
        Route::middleware([RequireRoleMiddleware::class . ':admin'])->group(function () {
            Route::post('/users', [UserController::class, 'store']);
        });

        // Categorías (admin, librarian)
        Route::get('/categories', [CategoryController::class, 'index']);

        // Libros
        Route::get('/books', [BookController::class, 'index']);
        Route::get('/books/{id}', [BookController::class, 'show']);
        Route::get('/books/{id}/cover', [BookController::class, 'getCover']);

        // Gestión de libros (solo admin)
        Route::middleware([RequireRoleMiddleware::class . ':admin'])->group(function () {
            Route::post('/books', [BookController::class, 'store']);
            Route::put('/books/{id}', [BookController::class, 'update']);
            Route::delete('/books/{id}', [BookController::class, 'destroy']);
            Route::put('/books/{id}/cover', [BookController::class, 'uploadCover']);
            Route::delete('/books/{id}/cover', [BookController::class, 'deleteCover']);

            // Reportes (solo admin)
            Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
        });

        // Socios (admin, librarian)
        Route::get('/members', [MemberController::class, 'index']);
        Route::get('/members/{id}', [MemberController::class, 'show']);
        Route::post('/members', [MemberController::class, 'store']);

        // Préstamos (admin, librarian)
        Route::get('/loans/overdue', [LoanController::class, 'overdue']);
        Route::post('/loans', [LoanController::class, 'store']);
        Route::get('/loans', [LoanController::class, 'index']);
        Route::get('/loans/{id}', [LoanController::class, 'show']);
        Route::post('/loans/{loan_id}/items/{item_id}/return', [LoanController::class, 'returnItem']);
    });
});

