<?php

namespace App\Http\Middleware;

use App\Models\Knowledge\IngestionToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer Token com escopo (hash SHA-256, validade e revogação). Vários escopos na rota: vale qualquer um deles. */
class AuthenticateIngestionToken
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $plainToken = $request->bearerToken() ?? '';
        $token = IngestionToken::findValid($plainToken, $scopes);

        if (! $token) {
            return response()->json(['message' => 'Invalid, expired or revoked token.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('ingestion_token', $token);

        return $next($request);
    }
}
