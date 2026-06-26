<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyProfileStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->status !== 'validated') {
            return response()->json([
                'message' => 'Action restreinte. Votre profil doit être vérifié par un administrateur pour effectuer cette action.',
                'status' => $user ? $user->status : 'unauthenticated'
            ], 403);
        }

        return $next($request);
    }
}
