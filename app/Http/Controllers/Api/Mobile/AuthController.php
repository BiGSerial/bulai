<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Services\Users\UserAccountService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Conta pelo aplicativo nativo: entrar (e-mail e senha geram o token do aparelho), cadastro, "esqueci minha senha",
 * troca da senha provisória e os dados da pessoa. Mesmas regras e mensagens das telas do site (docs/app-android.md).
 */
final class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'installation_id' => ['required', 'uuid'],
            'platform' => ['nullable', Rule::in(MobileDevice::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $user = User::query()->whereRaw('lower(email) = ?', [Str::lower(trim($data['email']))])->first();
        // Como no site: a mensagem de bloqueio (pendente, recusado, desativado) só aparece para quem acertou a senha.
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        if (($blocked = $user->signInBlockedMessage()) !== null) {
            throw ValidationException::withMessages(['email' => $blocked]);
        }

        ['device' => $device, 'token' => $token] = MobileDevice::issueFor($user, $data);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        if ($device->wasRecentlyCreated) {
            UserAuditEvent::query()->create(['user_id' => $user->id, 'actor_id' => $user->id, 'event' => UserAuditEvent::EVENT_MOBILE_DEVICE_CONNECTED, 'details' => ['device_id' => $device->id, 'device_name' => $device->device_name, 'platform' => $device->platform], 'ip' => $request->ip()]);
        }

        return response()->json(['token' => $token, 'user' => self::user($user)])->header('Cache-Control', 'no-store');
    }

    /** Cadastro: a conta nasce aguardando aprovação, então não devolve token (a pessoa entra depois de aprovada). */
    public function register(Request $request, UserAccountService $service): JsonResponse
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

        return response()->json(['message' => 'Recebemos o seu cadastro, '.trim(explode(' ', $user->name)[0]).'. A equipe vai analisar e você receberá um e-mail quando puder entrar.'], 201);
    }

    /** Mesma resposta exista ou não o e-mail (não revela cadastros). */
    public function forgotPassword(Request $request, UserAccountService $service): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $user = User::query()->whereRaw('lower(email) = ?', [Str::lower(trim($validated['email']))])->first();
        if ($user !== null && $user->signInBlockedMessage() === null && Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT) {
            $service->recordPasswordResetRequested($user);
        }

        return response()->json(['message' => __('passwords.sent')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => self::user($request->user())])->header('Cache-Control', 'no-store');
    }

    /** Troca da senha provisória: este aparelho continua conectado; sessões do site e outros aparelhos caem. */
    public function firstAccess(Request $request, UserAccountService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->must_change_password) {
            return response()->json(['user' => self::user($user)]);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults(), function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (is_string($value) && Hash::check($value, $user->password)) {
                    $fail('A nova senha deve ser diferente da senha provisória.');
                }
            }],
        ], [], ['password' => 'nova senha']);

        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');
        $service->completeFirstAccess($user, $validated['password'], null, $device->id);

        return response()->json(['user' => self::user($user->refresh())]);
    }

    /**
     * @return array{id: int, name: string, first_name: string, preferred_name: string|null, email: string, must_change_password: bool, health_consent: bool, timezone: string, is_admin: bool}
     */
    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->callName(),
            'preferred_name' => $user->preferred_name,
            'email' => $user->email,
            'must_change_password' => (bool) $user->must_change_password,
            'health_consent' => $user->hasHealthConsent(),
            'timezone' => $user->treatmentTimezone(),
            'is_admin' => $user->isAdmin(),
        ];
    }
}
