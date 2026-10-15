<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRadarSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('user_id')) {
            return response()->json(['error' => 'Inicia sesión para continuar.'], 401);
        }

        return $next($request);
    }
}
