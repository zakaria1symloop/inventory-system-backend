<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'غير مصرح.'], 401);
        }

        // Sub-users (non-admin) don't need email verification
        if ($user->role !== 'admin') {
            return $next($request);
        }

        // Phone-registered users have no email to verify — nothing to check.
        if (empty($user->email)) {
            return $next($request);
        }

        // Check if tenant has OTP requirement disabled
        $tenant = $request->attributes->get('tenant');
        if ($tenant && !$tenant->otp_required) {
            return $next($request);
        }

        // Check if admin email is verified
        if (!$user->email_verified_at) {
            return response()->json([
                'message' => 'يجب تأكيد البريد الإلكتروني أولاً.',
                'email_verified' => false,
            ], 403);
        }

        return $next($request);
    }
}
