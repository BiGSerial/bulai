<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Users\UserAccountService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Minha conta no aplicativo nativo: nome de chamada, troca de senha e apagar a conta (as mesmas regras de "Minha conta" do site: pede a
 * senha atual, a nova precisa ser diferente; este aparelho continua conectado e as outras sessões e aparelhos caem).
 */
final class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => AuthController::user($request->user())])->header('Cache-Control', 'no-store');
    }

    public function updateName(Request $request, UserAccountService $service): JsonResponse
    {
        $data = $request->validate(['preferred_name' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\pM\s\'.-]+$/u']], [
            'preferred_name.regex' => 'Use só letras, espaços, apóstrofo, ponto ou hífen.',
        ], ['preferred_name' => 'nome de chamada']);

        /** @var User $user */
        $user = $request->user();
        $service->updatePreferredName($user, $data['preferred_name'] ?? null);

        return response()->json(['user' => AuthController::user($user->refresh())]);
    }

    public function updatePassword(Request $request, UserAccountService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            // A regra current_password do Laravel olha o guard da sessão; aqui a conta vem do token do aparelho.
            'current_password' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (! is_string($value) || ! Hash::check($value, $user->password)) {
                    $fail('A senha atual não confere.');
                }
            }],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'password.different' => 'A nova senha deve ser diferente da senha atual.',
        ], [
            'current_password' => 'senha atual',
            'password' => 'nova senha',
        ]);

        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');
        $service->changePassword($user, $validated['password'], null, $device->id);

        return response()->json(['message' => 'Senha alterada. As outras sessões abertas com a sua conta foram encerradas; este celular continua conectado.']);
    }

    /** Apagar a conta: pede a senha atual; administrador não se apaga pelo aplicativo. */
    public function destroy(Request $request, UserAccountService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->isAdmin()) {
            return response()->json(['message' => 'Contas de administrador não podem ser apagadas pelo aplicativo. Fale com outro administrador.', 'code' => 'admin_account'], 403);
        }
        $request->validate([
            'password' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (! is_string($value) || ! Hash::check($value, $user->password)) {
                    $fail('A senha não confere.');
                }
            }],
        ], [], ['password' => 'senha']);

        $service->deleteOwnAccount($user);

        return response()->json(['message' => 'Sua conta foi apagada. Seus dados pessoais e de saúde foram removidos.']);
    }
}
