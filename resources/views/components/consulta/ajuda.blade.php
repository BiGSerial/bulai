{{--
    Blocos de ajuda de uma resposta (QuestionConversation::helpGroups; o aplicativo recebe a mesma lista em `help_groups`).
    Cada bloco é identificável pela situação: título, texto curto e os telefones como botões de ligar grandes.
    Tons: acolhimento (azul, momento difícil), urgencia (vermelho, perigo agora), cuidado (neutro, sem telefone).
    Bloco sem título: "Precisa de ajuda agora? Toque para ligar." com os telefones do caso.
--}}
@props(['grupos' => []])
@if ($grupos !== [])
    <div {{ $attributes->class('grid gap-3') }} data-testid="ajuda">
        @foreach ($grupos as $grupo)
            @php
                [$caixa, $titulo, $icone, $principal, $secundario] = match ($grupo['tone']) {
                    'acolhimento' => ['border-brand-200 bg-brand-50', 'text-brand-900', 'coracao', 'bg-brand-700 text-white hover:bg-brand-800 focus-visible:ring-brand-600/40', 'bg-white text-brand-900 ring-1 ring-inset ring-brand-300 hover:bg-brand-100'],
                    'cuidado' => ['border-slate-200 bg-white', 'text-slate-800', 'grupo', 'bg-slate-800 text-white hover:bg-slate-900 focus-visible:ring-slate-600/40', 'bg-white text-slate-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'],
                    default => ['border-rose-300 bg-white', 'text-rose-900', 'telefone', 'bg-rose-700 text-white hover:bg-rose-800 focus-visible:ring-rose-600/40', 'bg-rose-50 text-rose-950 ring-1 ring-inset ring-rose-300 hover:bg-rose-100'],
                };
            @endphp
            <section class="grid gap-2 rounded-2xl border-2 p-3 {{ $caixa }}" wire:key="ajuda-{{ $loop->index }}" aria-label="{{ $grupo['title'] ?? 'Telefones de ajuda' }}">
                <h3 class="flex items-center gap-2 text-lg font-bold leading-snug {{ $titulo }}"><x-consulta.icone :name="$icone" plain class="size-6 shrink-0" />{{ $grupo['title'] ?? 'Precisa de ajuda agora? Toque para ligar.' }}</h3>
                @if ($grupo['text'])<p class="break-words text-base leading-relaxed text-slate-800">{{ $grupo['text'] }}</p>@endif
                @foreach ($grupo['lines'] as $linha)
                    <a href="tel:{{ $linha['tel'] }}" wire:key="aj-{{ $loop->parent->index }}-{{ $linha['tel'] }}"
                        class="flex min-h-16 min-w-0 items-center gap-3 rounded-xl px-3 py-2 focus:outline-none focus-visible:ring-4 {{ $loop->first ? $principal : $secundario }}">
                        <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-full {{ $loop->first ? 'bg-white/20' : 'bg-white' }}" aria-hidden="true"><x-consulta.icone name="telefone" plain class="size-6" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block break-words text-xl font-bold leading-tight">Ligar {{ $linha['number'] }} <span class="font-semibold">· {{ $linha['name'] }}</span></span>
                            <span class="block break-words text-base leading-snug opacity-90">{{ $linha['detail'] }}</span>
                        </span>
                    </a>
                @endforeach
            </section>
        @endforeach
    </div>
@endif
