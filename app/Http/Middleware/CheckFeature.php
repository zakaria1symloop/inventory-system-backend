<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = $request->attributes->get('tenant');

        if (!$tenant) {
            return $next($request);
        }

        if (!$tenant->hasFeature($feature)) {
            return response()->json([
                'message' => 'هذه الميزة غير متوفرة في خطتك الحالية.',
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
