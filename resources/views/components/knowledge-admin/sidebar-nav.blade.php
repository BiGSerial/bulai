{{--
    Menu lateral em grupos (fonte única: App\AdminNavigation::groups()). `collapsed` é uma expressão Alpine.
    Compacto de propósito: um item por linha (a descrição curta só aparece em telas altas e sempre na dica `title`), grupos com
    pouco espaço entre si, sem rolagem horizontal; a rolagem vertical só existe se a janela for muito baixa, e o rodapé do menu
    (recolher) continua fixo.
--}}
@props(['groups', 'pendingCount' => 0, 'collapsed' => 'false'])
<nav {{ $attributes->class('admin-scroll grid content-start gap-2 overflow-y-auto overflow-x-hidden px-2 py-2') }} aria-label="Navegação principal">
    @foreach ($groups as $group)
        <div class="grid gap-0.5">
            <p class="truncate px-3 pb-0.5 pt-1 text-xs font-semibold uppercase tracking-wider text-brand-300" x-show="!({{ $collapsed }})" x-cloak>{{ $group['label'] }}</p>
            <hr class="mx-3 border-brand-700" x-show="{{ $collapsed }}" x-cloak aria-hidden="true">
            @foreach ($group['items'] as ['route' => $routeName, 'label' => $label, 'pattern' => $pattern, 'icon' => $icon, 'hint' => $hint, 'description' => $description])
                <x-knowledge-admin.sidebar-link :href="route($routeName)" :active="request()->routeIs($pattern)" :icon="$icon" :hint="$hint" :description="$description" :collapsed="$collapsed">
                    {{ $label }}
                    @if ($routeName === 'admin.knowledge.pending.index' && $pendingCount > 0)
                        <x-slot:badge><x-knowledge-admin.badge tone="warning">{{ $pendingCount }}</x-knowledge-admin.badge></x-slot:badge>
                    @endif
                </x-knowledge-admin.sidebar-link>
            @endforeach
        </div>
    @endforeach
</nav>
