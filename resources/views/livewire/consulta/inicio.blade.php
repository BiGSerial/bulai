@php
    // Cinco ações grandes, uma por linha. Nada mais na tela (sênior-primeiro).
    $acoes = array_values(array_filter([
        ['route' => route('consulta.perguntar'), 'title' => 'Perguntar sobre um remédio', 'text' => 'Escreva ou fale sua dúvida', 'tone' => 'bg-accent-600 text-white hover:bg-accent-700', 'icon' => 'M8.625 9.75a3.375 3.375 0 1 1 6.75 0c0 1.5-1.125 2.25-2.25 3-.75.5-1.125 1-1.125 1.875M12 18h.008M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
        ['route' => route('consulta.hoje'), 'title' => 'Remédios de hoje', 'text' => $proximaDose, 'tone' => $doseAtrasada ? 'bg-rose-700 text-white hover:bg-rose-800' : 'bg-brand-700 text-white hover:bg-brand-800', 'icon' => 'M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z'],
        $receitaPorFoto ? ['route' => route('consulta.receita.nova'), 'title' => 'Fotografar minha receita', 'text' => 'O app lê os remédios para você conferir', 'tone' => 'bg-white text-brand-900 ring-1 ring-slate-300 hover:bg-brand-50', 'icon' => 'M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18A2.25 2.25 0 0 0 4.5 20.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316ZM16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z'] : null,
        ['route' => route('consulta.perfil'), 'title' => 'Meus remédios', 'text' => 'Receitas, alergias e o que você toma', 'tone' => 'bg-white text-brand-900 ring-1 ring-slate-300 hover:bg-brand-50', 'icon' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z'],
        ['route' => route('consulta.historico'), 'title' => 'Ver o que já perguntei', 'text' => 'Suas perguntas e consultas anteriores', 'tone' => 'bg-white text-brand-900 ring-1 ring-slate-300 hover:bg-brand-50', 'icon' => 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z'],
    ]));
@endphp
<x-consulta.shell title="Olá{{ $nome !== '' ? ', '.$nome : '' }}" :conta="true">
    <p class="-mt-1 px-1 text-xl text-slate-800">O que você quer fazer?</p>
    <nav class="grid gap-3" aria-label="Ações principais">
        @foreach ($acoes as $acao)
            <a href="{{ $acao['route'] }}" wire:navigate class="flex min-h-20 items-center gap-4 rounded-2xl px-5 py-4 shadow-sm transition focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40 {{ $acao['tone'] }}">
                <svg class="size-10 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $acao['icon'] }}" /></svg>
                <span class="min-w-0"><span class="block text-xl font-semibold leading-tight">{{ $acao['title'] }}</span><span class="block text-base opacity-90">{{ $acao['text'] }}</span></span>
                <svg class="ml-auto size-7 shrink-0 opacity-70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </a>
        @endforeach
    </nav>
</x-consulta.shell>
