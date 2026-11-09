@props(['links' => []])
{{-- Cabeçalho fixo e leve. O menu do celular usa <details>: funciona sem JavaScript e é acessível por teclado. --}}
<header class="sticky top-0 z-40 border-b border-brand-100/70 bg-white/90 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
        <a class="flex shrink-0 items-center rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="#inicio" aria-label="BULA-IA — início da página">
            <img class="h-9 w-auto max-w-full" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULA-IA — Bulas Inteligentes" width="160" height="36">
        </a>

        <nav class="hidden items-center gap-1 md:flex" aria-label="Seções da página">
            @foreach ($links as $href => $label)
                <a class="rounded-lg px-3 py-2 text-base font-medium text-slate-700 transition hover:bg-brand-50 hover:text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ $href }}">{{ $label }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            @auth
                <a class="inline-flex min-h-11 items-center justify-center rounded-xl bg-brand-700 px-4 text-base font-semibold text-white shadow-sm transition hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2" href="{{ route(auth()->user()->homeRouteName()) }}">Abrir o aplicativo</a>
            @else
                <a class="hidden min-h-11 items-center justify-center rounded-xl px-3 text-base font-semibold text-brand-800 transition hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 sm:inline-flex" href="{{ route('register') }}">Criar conta</a>
                <a class="inline-flex min-h-11 items-center justify-center rounded-xl bg-brand-700 px-4 text-base font-semibold text-white shadow-sm transition hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2" href="{{ route('login') }}">Entrar</a>
            @endauth

            <details class="relative md:hidden">
                <summary class="flex size-11 cursor-pointer list-none items-center justify-center rounded-xl text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 [&::-webkit-details-marker]:hidden" aria-label="Abrir menu">
                    <x-landing.icon name="menu" class="size-7" />
                </summary>
                <nav class="absolute right-0 mt-2 grid w-64 gap-1 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" aria-label="Seções da página (celular)">
                    @foreach ($links as $href => $label)
                        <a class="min-h-12 rounded-xl px-4 py-3 text-lg font-medium text-slate-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ $href }}">{{ $label }}</a>
                    @endforeach
                    @guest
                        <a class="min-h-12 rounded-xl px-4 py-3 text-lg font-semibold text-brand-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('register') }}">Criar conta</a>
                    @endguest
                </nav>
            </details>
        </div>
    </div>
</header>
