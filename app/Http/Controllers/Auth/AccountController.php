<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    /** "Sair de outros dispositivos": exige a senha atual e mantém só a sessão em que a pessoa está. */
    public function terminateOtherSessions(Request $request, UserAccountService $service): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password']], [], ['current_password' => 'senha atual']);

        /** @var User $user */
        $user = $request->user();
        $service->terminateSessions($user, $user, $request->session()->getId());
        // Com o middleware AuthenticateSession ativo, também invalida sessões em outros drivers.
        Auth::logoutOtherDevices((string) $request->input('current_password'));

        return redirect()->route('account')->with('accountFeedback', 'As outras sessões abertas com a sua conta foram encerradas. Esta permanece conectada.');
    }

    /** Histórico da própria conta (eventos de acesso e segurança), do mais recente para o mais antigo. */
    public function history(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('auth.history', ['user' => $user, 'events' => $user->auditEvents()->with('actor:id,name')->orderByDesc('created_at')->orderByDesc('id')->paginate(20)]);
    }
}
