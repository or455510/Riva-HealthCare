<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ✅ Storage files with CORS headers
Route::get('/storage/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);

    if (!file_exists($fullPath)) {
        abort(404);
    }

    $mimeType = mime_content_type($fullPath);

    return response()->file($fullPath, [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => '*',
        'Content-Type'                 => $mimeType,
    ]);
})->where('path', '.*');