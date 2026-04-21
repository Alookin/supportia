<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->organization?->is_active) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Aucune organisation active associée à votre compte.'], 403);
            }

            abort(403, 'Aucune organisation active associée à votre compte.');
        }

        return $next($request);
    }
}
