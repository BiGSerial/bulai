<?php

namespace App\Http\Middleware;

use App\Services\Ai\AiUsageAttribution;
use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/** Guarda no Context quem fez a requisição e de qual tela, para atribuir o consumo de IA (inclusive em jobs). */
class AttributeAiUsageToUser
{
    public function handle(Request $request, Closure $next): Response
    {
        AiUsageAttribution::remember($request->user()?->id, $this->origin($request));

        return $next($request);
    }

    private function origin(Request $request): ?string
    {
        if (Livewire::isLivewireRequest()) {
            $snapshot = json_decode((string) $request->input('components.0.snapshot'), true);
            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;

            return is_string($name) ? 'livewire:'.$name : null;
        }

        $route = $request->route()?->getName();

        return $route !== null ? 'route:'.$route : null;
    }
}
