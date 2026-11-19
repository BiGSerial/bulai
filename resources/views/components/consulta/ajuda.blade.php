{{--
    Blocos de ajuda de uma resposta (QuestionConversation::helpGroups; o aplicativo recebe a mesma lista em `help_groups`).
    Cada bloco é identificável pela situação: título, texto curto e os telefones como botões de ligar grandes.
    Tons: acolhimento (verde-água), urgencia (vermelho, perigo agora), cuidado (azul da marca, acompanhamento).
    Bloco sem título: "Precisa de ajuda agora? Toque para ligar." com os telefones do caso.
--}}
@props(['grupos' => []])
@if ($grupos !== [])
    <div {{ $attributes->class('grid gap-3') }} data-testid="ajuda">
        @foreach ($grupos as $grupo)
            @php
                // Cada situação com a sua cor, a mesma no aplicativo: vermelho é perigo agora, verde-água é acolhimento e
                // azul da marca é acompanhamento. O primeiro telefone é o botão cheio; os outros, contornados na mesma cor.
                [$caixa, $titulo, $selo, $icone, $principal, $secundario] = match ($grupo['tone']) {
                    'acolhimento' => ['border-teal-200 bg-teal-50', 'text-teal-900', 'bg-teal-700', 'coracao', 'bg-teal-700 text-white hover:bg-teal-800 focus-visible:ring-teal-600/40', 'bg-white text-teal-900 ring-1 ring-inset ring-teal-300 hover:bg-teal-100'],
                    'cuidado' => ['border-brand-200 bg-brand-50', 'text-brand-900', 'bg-brand-700', 'grupo', 'bg-brand-700 text-white hover:bg-brand-800 focus-visible:ring-brand-600/40', 'bg-white text-brand-900 ring-1 ring-inset ring-brand-300 hover:bg-brand-100'],
                    default => ['border-red-200 bg-red-50', 'text-red-800', 'bg-red-700', 'telefone', 'bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-600/40', 'bg-white text-red-900 ring-1 ring-inset ring-red-300 hover:bg-red-100'],
                };
            @endphp
            <section class="grid gap-2.5 rounded-2xl border p-3.5 {{ $caixa }}" wire:key="ajuda-{{ $loop->index }}" aria-label="{{ $grupo['title'] ?? 'Telefones de ajuda' }}">
                <h3 class="flex items-start gap-2.5 text-lg font-bold leading-snug {{ $titulo }}">
                    <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full text-white {{ $selo }}" aria-hidden="true"><x-consulta.icone :name="$icone" plain class="size-5" /></span>
                    <span class="pt-0.5">{{ $grupo['title'] ?? 'Precisa de ajuda agora? Toque para ligar.' }}</span>
                </h3>
                @if ($grupo['text'])<p class="break-words text-base leading-relaxed text-slate-800">{{ $grupo['text'] }}</p>@endif
                @foreach ($grupo['lines'] as $linha)
                    <a href="tel:{{ $linha['tel'] }}" wire:key="aj-{{ $loop->parent->index }}-{{ $linha['tel'] }}"
                        class="flex min-h-16 min-w-0 items-center gap-3 rounded-xl px-3 py-2.5 shadow-sm focus:outline-none focus-visible:ring-4 {{ $loop->first ? $principal : $secundario }}">
                        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-full {{ $loop->first ? 'bg-white/20' : $selo.' text-white' }}" aria-hidden="true"><x-consulta.icone name="telefone" plain class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xl font-bold leading-tight">Ligar {{ $linha['number'] }}</span>
                            <span class="block break-words text-base font-semibold leading-snug">{{ $linha['name'] }}</span>
                            <span class="block break-words text-sm leading-snug opacity-90">{{ $linha['detail'] }}</span>
                        </span>
                    </a>
                @endforeach
            </section>
        @endforeach
    </div>
@endif
