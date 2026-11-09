<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Encerra a sessão de quem teve o acesso desativado (ou cujo cadastro não está aprovado) e devolve ao login.
     *
     * É a rede de segurança da desativação: mesmo que uma sessão escape da limpeza feita pelo
     * UserAccountService (driver de sessão que não é o banco), ela cai na próxima requisição.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $blockedMessage = $user?->signInBlockedMessage();

        if ($blockedMessage !== null) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $blockedMessage]);
        }

        return $next($request);
    }
}
