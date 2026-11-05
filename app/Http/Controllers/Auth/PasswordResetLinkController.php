<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "Esqueci minha senha": envia o link de redefinição. A resposta é a mesma exista ou não o e-mail, e contas
 * desativadas não recebem link (nem sabem disso), para não revelar cadastros.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, UserAccountService $service): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $email = Str::lower(trim($validated['email']));

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user !== null && $user->isActive()) {
            $status = Password::sendResetLink(['email' => $user->email]);
            if ($status === Password::RESET_LINK_SENT) {
                $service->recordPasswordResetRequested($user);
            }
        }

        return redirect()->route('password.request')->with('status', __('passwords.sent'));
    }
}
