@php
    // Página pública (visitantes). Usuários autenticados são redirecionados pela rota raiz; aqui só trocamos o CTA.
    $titulo = 'BULA-IA · Entenda seus remédios em linguagem simples';
    $descricao = 'Bula humanizada, remédios de hoje com lembretes, foto da receita e respostas baseadas nas bulas oficiais da ANVISA. Feito para quem toma remédio todo dia e para quem cuida.';
    $url = url('/');
    $links = ['#como-funciona' => 'Como funciona', '#recursos' => 'Recursos', '#seguranca' => 'Segurança', '#perguntas' => 'Perguntas'];
    $entrar = auth()->check() ? route(auth()->user()->homeRouteName()) : route('login');
    $rotuloEntrar = auth()->check() ? 'Abrir o aplicativo' : 'Entrar no aplicativo';
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'BULA-IA',
        'applicationCategory' => 'HealthApplication',
        'operatingSystem' => 'Web, Android, iOS (aplicativo instalável)',
        'description' => $descricao,
        'url' => $url,
        'inLanguage' => 'pt-BR',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'BRL'],
    ];
@endphp
<!DOCTYPE html>
<html lang="pt-BR" class="scroll-smooth motion-reduce:scroll-auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#12344d">
    <title>{{ $titulo }}</title>
    <meta name="description" content="{{ $descricao }}">
    <link rel="canonical" href="{{ $url }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="BULA-IA">
    <meta property="og:title" content="{{ $titulo }}">
    <meta property="og:description" content="{{ $descricao }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:locale" content="pt_BR">
    <meta property="og:image" content="{{ url('/brand/bulai-icone-512.png') }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $titulo }}">
    <meta name="twitter:description" content="{{ $descricao }}">
    <meta name="twitter:image" content="{{ url('/brand/bulai-icone-512.png') }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/brand/bulai-icone-app.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/bulai-icone-192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/brand/bulai-icone-180.png" sizes="180x180">
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen overflow-x-clip bg-white text-lg leading-relaxed text-slate-900 antialiased">
    <a class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:font-medium focus:text-brand-800 focus:shadow" href="#inicio">Ir para o conteúdo</a>

    <x-landing.header :links="$links" />

    <main>
        {{-- 1. Hero --}}
        <section id="inicio" class="relative overflow-hidden bg-surface-50" aria-labelledby="hero-titulo">
            <div class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[32rem] bg-gradient-to-b from-brand-50 via-surface-50 to-surface-50" aria-hidden="true"></div>
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 pb-16 pt-12 sm:pt-16 lg:grid-cols-[1.15fr_0.85fr] lg:gap-8 lg:pb-24 lg:pt-20">
                <div class="grid min-w-0 gap-6 text-center lg:text-left">
                    <p class="mx-auto inline-flex max-w-full items-center gap-2 rounded-full bg-white px-4 py-1.5 text-sm font-semibold text-brand-800 shadow-sm ring-1 ring-brand-600/15 lg:mx-0">
                        <x-landing.icon name="shield" class="size-5 shrink-0 text-accent-600" />
                        <span class="min-w-0">Baseado nas bulas oficiais da ANVISA</span>
                    </p>
                    <h1 id="hero-titulo" class="text-balance text-4xl font-semibold leading-[1.1] tracking-tight text-brand-900 sm:text-5xl lg:text-6xl">Entenda seus remédios. <span class="text-accent-600">Em linguagem simples.</span></h1>
                    <p class="mx-auto max-w-2xl text-xl leading-relaxed text-slate-700 lg:mx-0">Bula traduzida para o dia a dia, os remédios de hoje com lembretes na hora certa, a receita lida pela foto e suas dúvidas respondidas com base na bula. Tudo em letras grandes, para você ou para quem você cuida.</p>
                    <div class="flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center lg:justify-start">
                        <a class="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl bg-accent-600 px-7 text-xl font-semibold text-white shadow-md transition hover:bg-accent-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-accent-500/40" href="{{ $entrar }}">{{ $rotuloEntrar }}<x-landing.icon name="arrow-right" class="size-6" /></a>
                        <a class="inline-flex min-h-14 items-center justify-center rounded-2xl border-2 border-brand-700 bg-white px-7 text-xl font-semibold text-brand-900 transition hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/30" href="#como-funciona">Ver como funciona</a>
                    </div>
                    <ul class="flex flex-wrap justify-center gap-x-6 gap-y-2 text-base text-slate-700 lg:justify-start" aria-label="Destaques">
                        <li class="flex items-center gap-2"><x-landing.icon name="check" class="size-5 text-emerald-700" />Gratuito</li>
                        <li class="flex items-center gap-2"><x-landing.icon name="check" class="size-5 text-emerald-700" />Funciona no celular, sem loja de aplicativos</li>
                        <li class="flex items-center gap-2"><x-landing.icon name="check" class="size-5 text-emerald-700" />Letras grandes e poucos toques</li>
                    </ul>
                </div>
                <x-landing.phone-mockup class="lg:justify-self-end" />
            </div>
        </section>

        {{-- 2. Confiança em uma linha --}}
        <section class="border-y border-brand-100 bg-white" aria-label="Fontes e garantias">
            <ul class="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-8 gap-y-3 px-4 py-6 text-base font-semibold text-brand-900">
                <li class="flex items-center gap-2"><x-landing.icon name="beaker" class="size-6 text-brand-600" />Catálogo ANVISA</li>
                <li class="flex items-center gap-2"><x-landing.icon name="link" class="size-6 text-brand-600" />Interações DDInter</li>
                <li class="flex items-center gap-2"><x-landing.icon name="document" class="size-6 text-brand-600" />Respostas conferidas contra a bula</li>
                <li class="flex items-center gap-2"><x-landing.icon name="lock" class="size-6 text-brand-600" />Dados de saúde com consentimento</li>
            </ul>
        </section>

        {{-- 3. Como funciona --}}
        <x-landing.section id="como-funciona" eyebrow="Como funciona" title="Da receita ao lembrete em três passos" lead="Sem cadastro complicado. Você fotografa, confere e o aplicativo cuida do resto." tone="surface">
            <ol class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <x-landing.step number="1" icon="camera" title="Fotografe a receita">O aplicativo lê os remédios, a dose e a frequência da foto. Se um nome não estiver no catálogo, ele entra assim mesmo e a equipe confere depois.</x-landing.step>
                <x-landing.step number="2" icon="clock" title="Confira os horários">"De 8 em 8 horas" vira 06:00, 14:00 e 22:00, já na sua tela. Ajuste com um toque se o seu dia for diferente.</x-landing.step>
                <x-landing.step number="3" icon="bell" title="Receba lembretes e tire dúvidas">Na hora de cada dose você recebe um aviso e marca "Tomei". Qualquer dúvida, pergunte por texto ou por voz.</x-landing.step>
            </ol>
        </x-landing.section>

        {{-- 4. Recursos --}}
        <x-landing.section id="recursos" eyebrow="Recursos" title="Tudo o que você precisa, em um só lugar" lead="Cada recurso foi desenhado para quem toma vários remédios por dia: letras grandes, uma decisão por tela e nada escondido em menus.">
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <x-landing.feature-card icon="document" title="Bula humanizada">Para que serve, como usar, cuidados e efeitos colaterais explicados em frases curtas, sempre com a página da bula de onde a informação veio.</x-landing.feature-card>
                <x-landing.feature-card icon="pill" title="Remédios de hoje">Uma linha por horário, agrupados em manhã, tarde e noite. Dois botões grandes: "Tomei" e "Pular". Errou? "Desfazer" em um toque.</x-landing.feature-card>
                <x-landing.feature-card icon="bell" title="Lembretes na hora certa">Aviso antes de cada dose, alerta quando atrasa e um recado quando o tratamento termina. Por notificação ou, se preferir, por e-mail.</x-landing.feature-card>
                <x-landing.feature-card icon="camera" title="Foto da receita">Tire uma foto e confira item por item. Nenhum remédio fica de fora por causa de grafia diferente ou produto novo.</x-landing.feature-card>
                <x-landing.feature-card icon="microphone" title="Pergunte do seu jeito">Escreva ou fale. O aplicativo entende erros de digitação ("lozartana") e responde só com o que está na bula, dizendo quando não sabe.</x-landing.feature-card>
                <x-landing.feature-card icon="link" title="Interações e alergias">Combine dois ou mais remédios e veja a gravidade registrada nas fontes. Alergias cadastradas acendem um alerta antes da consulta.</x-landing.feature-card>
            </div>
        </x-landing.section>

        {{-- 5. Para quem --}}
        <x-landing.section id="para-quem" eyebrow="Para quem" title="Feito para você e para quem está ao seu lado" tone="surface">
            <div class="grid gap-6 lg:grid-cols-3">
                <div class="grid min-w-0 gap-3 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <span class="flex size-12 items-center justify-center rounded-2xl bg-accent-100 text-accent-700"><x-landing.icon name="user" class="size-7" /></span>
                    <h3 class="text-xl font-semibold text-brand-900">Para você</h3>
                    <p class="text-base leading-relaxed text-slate-700">Saiba o que cada remédio faz, quando tomar e o que observar. Sem letra miúda, sem termos difíceis.</p>
                </div>
                <div class="grid min-w-0 gap-3 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700"><x-landing.icon name="heart" class="size-7" /></span>
                    <h3 class="text-xl font-semibold text-brand-900">Para quem cuida</h3>
                    <p class="text-base leading-relaxed text-slate-700">Acompanhe o tratamento de um familiar, veja o que já foi tomado no dia e tire dúvidas antes da próxima consulta.</p>
                </div>
                <div class="grid min-w-0 gap-3 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700"><x-landing.icon name="briefcase" class="size-7" /></span>
                    <h3 class="text-xl font-semibold text-brand-900">Para profissionais</h3>
                    <p class="text-base leading-relaxed text-slate-700">Uma forma simples de o paciente chegar à consulta sabendo o que toma e com as dúvidas organizadas. As fontes de cada resposta ficam visíveis.</p>
                </div>
            </div>
        </x-landing.section>

        {{-- 6. Segurança e limites --}}
        <x-landing.section id="seguranca" eyebrow="Segurança e transparência" title="Limites desta ferramenta" lead="Um aplicativo de saúde precisa ser honesto sobre o que faz e o que não faz. Estas regras valem em todas as telas." tone="brand">
            <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="grid min-w-0 gap-4 rounded-3xl bg-white/10 p-6 ring-1 ring-white/15 sm:p-8">
                    <p class="flex items-start gap-3 text-lg leading-relaxed text-white"><x-landing.icon name="warning" class="mt-1 size-6 shrink-0 text-accent-200" /><span>{{ config('bulai.guidelines.disclaimer') }}</span></p>
                    <ul class="grid gap-3 text-base leading-relaxed text-brand-50">
                        <li class="flex items-start gap-3"><x-landing.icon name="check" class="mt-1 size-5 shrink-0 text-accent-200" /><span>Cobre apenas medicamentos do catálogo da ANVISA e as interações presentes nas fontes cadastradas.</span></li>
                        <li class="flex items-start gap-3"><x-landing.icon name="check" class="mt-1 size-5 shrink-0 text-accent-200" /><span>“Nenhuma interação encontrada” <strong>não</strong> significa que a combinação seja segura.</span></li>
                        <li class="flex items-start gap-3"><x-landing.icon name="check" class="mt-1 size-5 shrink-0 text-accent-200" /><span>As explicações geradas por inteligência artificial são conferidas automaticamente contra os trechos da bula; quando não há trecho que sustente a resposta, o aplicativo diz que não sabe.</span></li>
                        <li class="flex items-start gap-3"><x-landing.icon name="check" class="mt-1 size-5 shrink-0 text-accent-200" /><span>Em caso de emergência ou mal-estar, procure atendimento médico. O aplicativo não é um serviço de urgência.</span></li>
                    </ul>
                </div>
                <div class="grid min-w-0 content-start gap-4">
                    <h3 class="text-2xl font-semibold text-white">Seus dados, suas regras</h3>
                    <ul class="grid gap-3">
                        <li class="flex items-start gap-3 rounded-2xl bg-white/10 p-4 ring-1 ring-white/15"><x-landing.icon name="lock" class="mt-0.5 size-6 shrink-0 text-accent-200" /><span class="min-w-0 text-base leading-relaxed text-brand-50"><strong class="text-white">Consentimento explícito.</strong> Alergias, remédios em uso e receitas só são guardados depois que você autoriza.</span></li>
                        <li class="flex items-start gap-3 rounded-2xl bg-white/10 p-4 ring-1 ring-white/15"><x-landing.icon name="shield" class="mt-0.5 size-6 shrink-0 text-accent-200" /><span class="min-w-0 text-base leading-relaxed text-brand-50"><strong class="text-white">Senhas protegidas</strong> com Argon2id, acesso com bloqueio progressivo e sessões que você pode encerrar em outros aparelhos.</span></li>
                        <li class="flex items-start gap-3 rounded-2xl bg-white/10 p-4 ring-1 ring-white/15"><x-landing.icon name="trash" class="mt-0.5 size-6 shrink-0 text-accent-200" /><span class="min-w-0 text-base leading-relaxed text-brand-50"><strong class="text-white">Exclusão a qualquer momento.</strong> Você apaga suas perguntas e consultas quando quiser; nada identificável é enviado ao provedor de inteligência artificial.</span></li>
                    </ul>
                </div>
            </div>
        </x-landing.section>

        {{-- 7. Instale no celular --}}
        <x-landing.section id="instalar" eyebrow="No seu celular" title="Instale como um aplicativo, sem loja" lead="O BULA-IA abre no navegador e pode ficar na tela inicial do celular, com ícone próprio e tela cheia. Leva menos de um minuto." tone="surface">
            <div class="grid gap-6 lg:grid-cols-[0.8fr_1.2fr] lg:items-center">
                <div class="order-2 flex justify-center lg:order-1">
                    <div class="grid w-full max-w-sm gap-3 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                        <span class="flex size-14 items-center justify-center rounded-2xl bg-brand-700 text-white"><x-landing.icon name="phone" class="size-8" /></span>
                        <p class="text-xl font-semibold text-brand-900">Ícone na tela inicial</p>
                        <p class="text-base text-slate-700">Abre em tela cheia, lembra do seu acesso e mostra o sino de avisos. Precisa de internet para consultar as bulas.</p>
                        <a class="mt-2 inline-flex min-h-12 items-center justify-center rounded-xl bg-brand-700 px-5 text-base font-semibold text-white hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2" href="{{ $entrar }}">Abrir e instalar</a>
                    </div>
                </div>
                <div class="order-1 grid gap-4 lg:order-2 sm:grid-cols-2">
                    <div class="grid min-w-0 gap-3 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                        <h3 class="flex items-center gap-2 text-xl font-semibold text-brand-900"><span class="flex size-9 items-center justify-center rounded-xl bg-emerald-100 text-emerald-800 text-sm font-bold">A</span>Android (Chrome)</h3>
                        <ol class="grid gap-2 text-base text-slate-700">
                            <li class="flex gap-3"><span class="font-bold text-brand-800">1.</span><span>Entre no aplicativo pelo Chrome.</span></li>
                            <li class="flex gap-3"><span class="font-bold text-brand-800">2.</span><span>Toque em <strong>Instalar aplicativo</strong> quando o aviso aparecer, ou abra o menu ⋮ e escolha <strong>Adicionar à tela inicial</strong>.</span></li>
                            <li class="flex gap-3"><span class="font-bold text-brand-800">3.</span><span>Confirme. O ícone do BULA-IA aparece junto dos seus outros aplicativos.</span></li>
                        </ol>
                    </div>
                    <div class="grid min-w-0 gap-3 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                        <h3 class="flex items-center gap-2 text-xl font-semibold text-brand-900"><span class="flex size-9 items-center justify-center rounded-xl bg-slate-900 text-white"><x-landing.icon name="share" class="size-5" /></span>iPhone (Safari)</h3>
                        <ol class="grid gap-2 text-base text-slate-700">
                            <li class="flex gap-3"><span class="font-bold text-brand-800">1.</span><span>Entre no aplicativo pelo Safari.</span></li>
                            <li class="flex gap-3"><span class="font-bold text-brand-800">2.</span><span>Toque no botão <strong>Compartilhar</strong> (o quadrado com a seta para cima).</span></li>
                            <li class="flex gap-3"><span class="font-bold text-brand-800">3.</span><span>Escolha <strong>Adicionar à Tela de Início</strong> e confirme.</span></li>
                        </ol>
                    </div>
                </div>
            </div>
        </x-landing.section>

        {{-- 8. Perguntas frequentes --}}
        <x-landing.section id="perguntas" eyebrow="Perguntas frequentes" title="O que as pessoas costumam perguntar">
            <div class="mx-auto grid max-w-3xl gap-3">
                <x-landing.faq-item question="O BULA-IA substitui o meu médico ou farmacêutico?">Não. Ele ajuda a entender a bula e a organizar o tratamento que o seu médico prescreveu. O aplicativo nunca indica começar, parar, trocar ou mudar a dose de um remédio. Para qualquer decisão, fale com o profissional que acompanha você.</x-landing.faq-item>
                <x-landing.faq-item question="De onde vêm as informações?">Do catálogo oficial de medicamentos da ANVISA, das bulas registradas e da base de interações DDInter. Cada resposta mostra a bula, a seção e a página de onde a informação saiu. Se não houver trecho que sustente a resposta, o aplicativo diz que não encontrou.</x-landing.faq-item>
                <x-landing.faq-item question="Preciso baixar algo na loja de aplicativos?">Não. O BULA-IA funciona no navegador do celular e pode ser adicionado à tela inicial como um aplicativo, no Android e no iPhone. Veja o passo a passo na seção "No seu celular".</x-landing.faq-item>
                <x-landing.faq-item question="E se eu errar o nome do remédio ao digitar?">O aplicativo reconhece erros comuns de digitação, como "lozartana" ou "omeprasol", e mostra o que entendeu para você confirmar. Também dá para falar a pergunta em vez de digitar.</x-landing.faq-item>
                <x-landing.faq-item question="Meus dados de saúde ficam seguros?">Alergias, remédios e receitas só são guardados com o seu consentimento e podem ser apagados por você a qualquer momento. Nenhum dado que identifique você é enviado ao provedor de inteligência artificial: só nomes de remédios e trechos de bula.</x-landing.faq-item>
                <x-landing.faq-item question="O aplicativo é gratuito? Quem está por trás dele?">Sim, é gratuito. O BULA-IA é um projeto acadêmico (Trabalho de Conclusão de Curso) que aplica leitura de bulas e inteligência artificial com conferência automática, pensado primeiro para pessoas idosas e para quem cuida delas.</x-landing.faq-item>
            </div>
        </x-landing.section>

        {{-- 9. CTA final --}}
        <section class="bg-surface-100 py-16 sm:py-20" aria-labelledby="cta-titulo">
            <div class="mx-auto max-w-6xl px-4">
                <div class="relative overflow-hidden rounded-[2rem] bg-brand-700 px-6 py-12 text-center text-white shadow-xl sm:px-12">
                    <div class="pointer-events-none absolute -right-16 -top-16 size-64 rounded-full bg-accent-500/30 blur-3xl" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute -bottom-20 -left-10 size-64 rounded-full bg-brand-400/30 blur-3xl" aria-hidden="true"></div>
                    <h2 id="cta-titulo" class="text-balance text-3xl font-semibold leading-tight sm:text-4xl">Comece hoje a entender o que você toma</h2>
                    <p class="mx-auto mt-4 max-w-2xl text-lg text-brand-100">Entre, fotografe a receita e veja seus remédios de hoje organizados em minutos.</p>
                    <div class="mt-8 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
                        <a class="inline-flex min-h-14 items-center justify-center gap-2 rounded-2xl bg-accent-500 px-8 text-xl font-semibold text-white shadow-md transition hover:bg-accent-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/50" href="{{ $entrar }}">{{ $rotuloEntrar }}<x-landing.icon name="arrow-right" class="size-6" /></a>
                        <a class="inline-flex min-h-14 items-center justify-center rounded-2xl border-2 border-white/70 px-8 text-xl font-semibold text-white transition hover:bg-white/10 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/50" href="#instalar">Como instalar</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <x-landing.footer :links="$links" />
</body>
</html>
