<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StorageCors
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', '*');
        return $response;
    }
}