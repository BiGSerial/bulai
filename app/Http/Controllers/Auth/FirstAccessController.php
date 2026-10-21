<?php

namespace App\Http\Controllers\Auth;

use App\AdminNavigation;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\UserAccountService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Página "Primeiro acesso": troca obrigatória da senha provisória, fora do shell do admin.
 *
 * Só atende quem tem must_change_password; os demais voltam ao destino do perfil. Como a pessoa
 * acabou de digitar a senha provisória no login, o formulário pede apenas a nova senha.
 */
class FirstAccessController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->must_change_password) {
            return redirect()->route($user->homeRouteName());
        }

        return view('auth.first-access', [
            'user' => $user,
            'isWelcome' => ! $user->hasSetOwnPassword(),
            'areas' => AdminNavigation::items(),
        ]);
    }

    public function updatePassword(Request $request, UserAccountService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Sem troca pendente, esta rota não troca senha: a troca voluntária exige a senha atual (Minha conta).
        if (! $user->must_change_password) {
            return redirect()->route($user->homeRouteName());
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults(), function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (is_string($value) && Hash::check($value, $user->password)) {
                    $fail('A nova senha deve ser diferente da senha provisória.');
                }
            }],
        ], [], [
            'password' => 'nova senha',
        ]);

        $service->completeFirstAccess($user, $validated['password'], $request->session()->getId());

        return redirect()->route($user->refresh()->homeRouteName())->with('accountFeedback', 'Senha definida com sucesso. Use a nova senha nos próximos acessos.');
    }
}
