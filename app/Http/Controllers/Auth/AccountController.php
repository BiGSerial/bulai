<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Página "Minha conta": disponível para qualquer perfil, fora do shell do admin.
 *
 * Aqui a troca de senha é voluntária (pede a senha atual). Quem ainda usa senha provisória não
 * chega aqui: o EnsureTemporaryPasswordIsChanged manda ao primeiro acesso.
 */
class AccountController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.account', ['user' => $request->user()]);
    }

    public function updatePassword(Request $request, UserAccountService $service): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'password.different' => 'A nova senha deve ser diferente da senha atual.',
        ], [
            'password' => 'nova senha',
        ]);

        /** @var User $user */
        $user = $request->user();
        $service->changePassword($user, $validated['password'], $request->session()->getId());

        return redirect()->route('account')->with('accountFeedback', 'Senha alterada com sucesso. As outras sessões abertas com a sua conta foram encerradas.');
    }
}
