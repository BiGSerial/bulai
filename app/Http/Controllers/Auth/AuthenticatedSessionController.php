<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = Str::lower(trim($credentials['email']));

        // O callback só roda depois que a senha confere: a mensagem de acesso desativado
        // não revela a existência do e-mail para quem não sabe a senha.
        $isInactive = false;
        $authenticated = Auth::attemptWhen($credentials, function (User $user) use (&$isInactive): bool {
            $isInactive = ! $user->isActive();

            return ! $isInactive;
        }, $request->boolean('remember'));

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => $isInactive ? __('auth.inactive') : __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();

        // Só o ADMIN sem troca pendente segue para a página que tentou abrir; os demais vão ao destino do perfil
        // (primeiro acesso, se houver troca de senha pendente; senão, Minha conta).
        if ($user->must_change_password || ! $user->isAdmin()) {
            $request->session()->forget('url.intended');

            return redirect()->route($user->homeRouteName());
        }

        return redirect()->intended(route($user->homeRouteName()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
