<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Users\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Cadastro pela própria pessoa (tela pública). A conta nasce aguardando aprovação: ninguém entra logo após se cadastrar.
 * Não pede dado de saúde; registra o aceite do aviso de privacidade. Limite de envios no limiter `register`.
 */
class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request, UserAccountService $service): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'Para continuar, marque que você leu e concorda com o aviso sobre o projeto e os seus dados.',
        ], ['name' => 'nome']);

        $user = $service->register($validated);
        $service->notifyAdministratorsOfPendingRegistration($user);

        return redirect()->route('register')->with('registered', $user->name);
    }
}
