<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;

/**
 * Quem gerou cada chamada de IA e a partir de onde.
 *
 * O usuário e a origem vão para o Context oculto do Laravel (AttributeAiUsageToUser, AppServiceProvider para comandos),
 * que acompanha os jobs despachados na mesma requisição. Assim uma bula resumida na fila continua atribuída a quem
 * pediu. Avaliações e rotinas sem usuário ficam com user_id nulo e origem `artisan:<comando>`.
 */
final class AiUsageAttribution
{
    public const USER_KEY = 'ai_usage.user_id';

    public const ORIGIN_KEY = 'ai_usage.origin';

    public static function remember(?int $userId, ?string $origin): void
    {
        if ($userId !== null) {
            Context::addHidden(self::USER_KEY, $userId);
        }
        if ($origin !== null && $origin !== '') {
            Context::addHidden(self::ORIGIN_KEY, mb_substr($origin, 0, 120));
        }
    }

    public static function userId(): ?int
    {
        $userId = Auth::id() ?? Context::getHidden(self::USER_KEY);

        return is_numeric($userId) ? (int) $userId : null;
    }

    public static function origin(): ?string
    {
        try {
            $component = Livewire::current();
            if ($component instanceof Component) {
                return mb_substr('livewire:'.$component->getName(), 0, 120);
            }
        } catch (Throwable) {
            // Fora de uma requisição Livewire.
        }

        $origin = Context::getHidden(self::ORIGIN_KEY);

        return is_string($origin) ? $origin : null;
    }
}
