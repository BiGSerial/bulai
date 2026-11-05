{{-- Menu lateral em grupos (fonte única: App\AdminNavigation::groups()). `collapsed` é uma expressão Alpine. --}}
@props(['groups', 'pendingCount' => 0, 'collapsed' => 'false'])
<nav {{ $attributes->class('grid content-start gap-4 overflow-y-auto px-2 py-3') }} aria-label="Navegação principal">
    @foreach ($groups as $group)
        <div class="grid gap-0.5">
            <p class="truncate px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-brand-300" x-show="!({{ $collapsed }})" x-cloak>{{ $group['label'] }}</p>
            @foreach ($group['items'] as ['route' => $routeName, 'label' => $label, 'pattern' => $pattern, 'icon' => $icon, 'hint' => $hint])
                <x-knowledge-admin.sidebar-link :href="route($routeName)" :active="request()->routeIs($pattern)" :icon="$icon" :hint="$hint" :collapsed="$collapsed">
                    {{ $label }}
                    @if ($routeName === 'admin.knowledge.pending.index' && $pendingCount > 0)
                        <x-slot:badge><x-knowledge-admin.badge tone="warning">{{ $pendingCount }}</x-knowledge-admin.badge></x-slot:badge>
                    @endif
                </x-knowledge-admin.sidebar-link>
            @endforeach
        </div>
    @endforeach
</nav>
