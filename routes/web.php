<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'system' => 'Manager Library API',
        'status' => 'operational',
        'version' => '1.0.0',
        'app_url' => 'http://localhost:3000',
        'presentation_url' => 'http://localhost:8080',
        'health_check' => 'http://localhost:8000/health',
        'api_docs' => '/api/v1',
    ]);
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});
