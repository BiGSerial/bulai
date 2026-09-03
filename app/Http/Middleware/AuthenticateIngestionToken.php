<?php

namespace App\Http\Middleware;

use App\Models\Knowledge\IngestionToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIngestionToken
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $plainToken = $request->bearerToken() ?? '';
        $token = IngestionToken::findValid($plainToken, $scope);

        if (! $token) {
            return response()->json(['message' => 'Invalid ingestion token.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('ingestion_token', $token);

        return $next($request);
    }
}
