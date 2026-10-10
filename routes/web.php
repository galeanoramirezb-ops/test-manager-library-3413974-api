<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    if ($request->wantsJson() || $request->is('api/*')) {
        return response()->json([
            'system' => 'Manager Library API',
            'status' => 'operational',
            'version' => '1.0.0',
            'app_url' => 'http://localhost:3000',
            'presentation_url' => 'http://localhost:8080',
            'health_check' => 'http://localhost:8000/health',
            'api_docs' => '/api/v1',
        ]);
    }
    return redirect('http://localhost:3000');
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});
